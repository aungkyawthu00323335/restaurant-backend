<?php

namespace App\Services;

use App\Models\IngredientBatch;
use App\Models\IngredientStockMovement;
use App\Models\Scopes\OutletScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class FifoInventoryService
{
    /**
     * Consume stock using FIFO ordering.
     */
    public function consumeStock($ingredientId, $locationId, $quantity, $direction = 'OUT', $reasonCode = null, $reference = null, $note = null, $productId = null, $foodMenuId = null)
    {
        return DB::transaction(function () use ($ingredientId, $locationId, $quantity, $direction, $reasonCode, $reference, $note, $productId, $foodMenuId) {
            $remainingToConsume = $quantity;
            $totalCost = 0.0;
            $oldestTimestamp = null;

            // Fetch active batches for this item and location, ordered by oldest received_at first
            // NOTE: withoutGlobalScope(OutletScope::class) is required because IngredientBatch uses
            // HasOutlet which auto-filters by current_outlet_id. In FIFO we MUST query by explicit
            // location_id across all outlets, so we bypass the global scope.
            $query = IngredientBatch::withoutGlobalScope(OutletScope::class)
                ->where('location_id', $locationId)
                ->where('usable_qty', '>', 0)
                ->orderBy('received_at', 'asc')
                ->lockForUpdate();

            if ($productId) {
                $query->where('product_id', $productId);
            } elseif ($foodMenuId) {
                $query->where('food_menu_id', $foodMenuId);
            } else {
                $query->where('ingredient_id', $ingredientId);
            }

            $activeBatches = $query->get();
            $ledgerAvailableQty = $this->currentStockForTransfer(
                $ingredientId,
                (int) $locationId,
                $productId,
                $foodMenuId
            );
            $availableQty = max($ledgerAvailableQty, (float) $activeBatches->sum('usable_qty'));

            if ($availableQty + 0.000001 < $quantity) {
                throw ValidationException::withMessages([
                    'stock' => sprintf(
                        'Insufficient stock. Required: %s, Available: %s at outlet %d.',
                        $this->formatQty((float) $quantity),
                        $this->formatQty((float) $availableQty),
                        $locationId
                    ),
                ]);
            }

            foreach ($activeBatches as $batch) {
                if ($remainingToConsume <= 0) break;

                if ($oldestTimestamp === null) {
                    $oldestTimestamp = $batch->received_at;
                }

                $consumeFromBatch = min($batch->usable_qty, $remainingToConsume);

                // Update batch
                $batch->usable_qty -= $consumeFromBatch;
                $batch->save();

                $totalCost += ($consumeFromBatch * $batch->unit_cost);

                // Log movement tied to this specific batch
                IngredientStockMovement::create([
                    'ingredient_id' => $productId || $foodMenuId ? null : $ingredientId,
                    'product_id' => $productId,
                    'food_menu_id' => $foodMenuId,
                    'location_id' => $locationId,
                    'ingredient_batch_id' => $batch->id,
                    'direction' => strtoupper($direction),
                    'reason_code' => $this->normalizeReasonCode($reasonCode),
                    'unit_type' => 'consumption',
                    'quantity_input' => $consumeFromBatch,
                    'quantity_consumption' => $consumeFromBatch,
                    'batch_unit_cost' => $batch->unit_cost,
                    'reference' => $reference,
                    'note' => $note,
                    'occurred_at' => Carbon::now(),
                ]);
                if ($productId) {
                    $this->recordProductMovement(
                        productId: $productId,
                        locationId: $locationId,
                        direction: $direction,
                        reasonCode: $reasonCode,
                        quantity: $consumeFromBatch,
                        unitCost: (float) $batch->unit_cost,
                        reference: $reference,
                        note: $note,
                    );
                }

                $remainingToConsume -= $consumeFromBatch;
            }

            // Legacy opening-stock rows may have a positive stock ledger without a FIFO batch.
            // The availability check above prevents negative stock, so a generic movement can
            // safely keep the ledger balanced until all opening stock is consumed.
            if ($remainingToConsume > 0) {
                $fallbackUnitCost = $this->fallbackUnitCost($ingredientId, $productId, $foodMenuId);
                $totalCost += ($remainingToConsume * $fallbackUnitCost);

                IngredientStockMovement::create([
                    'ingredient_id' => $productId || $foodMenuId ? null : $ingredientId,
                    'product_id' => $productId,
                    'food_menu_id' => $foodMenuId,
                    'location_id' => $locationId,
                    'ingredient_batch_id' => null,
                    'direction' => strtoupper($direction),
                    'reason_code' => $this->normalizeReasonCode($reasonCode),
                    'unit_type' => 'consumption',
                    'quantity_input' => $remainingToConsume,
                    'quantity_consumption' => $remainingToConsume,
                    'batch_unit_cost' => $fallbackUnitCost,
                    'reference' => $reference,
                    'note' => $note,
                    'occurred_at' => Carbon::now(),
                ]);
                if ($productId) {
                    $this->recordProductMovement(
                        productId: $productId,
                        locationId: $locationId,
                        direction: $direction,
                        reasonCode: $reasonCode,
                        quantity: $remainingToConsume,
                        unitCost: $fallbackUnitCost,
                        reference: $reference,
                        note: $note,
                    );
                }
            }

            return [
                'total_cost' => $totalCost,
                'oldest_timestamp' => $oldestTimestamp ?? Carbon::now(),
            ];
        });
    }

    private function normalizeReasonCode(?string $reasonCode): ?string
    {
        if ($reasonCode === null) {
            return null;
        }

        return substr(trim($reasonCode), 0, 100);
    }

    private function recordProductMovement(
        int $productId,
        int $locationId,
        string $direction,
        ?string $reasonCode,
        float $quantity,
        float $unitCost,
        ?string $reference,
        ?string $note
    ): void {
        \App\Models\ProductStockMovement::create([
            'product_id' => $productId,
            'location_id' => $locationId,
            'direction' => strtolower($direction) === 'in' ? 'in' : 'out',
            'reason_code' => substr((string) $this->normalizeReasonCode($reasonCode), 0, 40),
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'amount' => round($quantity * $unitCost, 2),
            'reference' => $reference,
            'note' => $note,
            'occurred_at' => Carbon::now(),
        ]);
    }

    /**
     * Transfer stock between locations preserving FIFO layers and costs.
     */
    public function transferStock($ingredientId, $fromLocationId, $toLocationId, $quantity, $reference = null, $note = null, $productId = null, $foodMenuId = null)
    {
        DB::transaction(function () use ($ingredientId, $fromLocationId, $toLocationId, $quantity, $reference, $note, $productId, $foodMenuId) {
            $remainingToTransfer = $quantity;
            $availableQty = $this->currentStockForTransfer(
                $ingredientId,
                $fromLocationId,
                $productId,
                $foodMenuId
            );

            $query = IngredientBatch::withoutGlobalScope(OutletScope::class)
                ->where('location_id', $fromLocationId)
                ->where('usable_qty', '>', 0)
                ->orderBy('received_at', 'asc')
                ->lockForUpdate();

            if ($productId) {
                $query->where('product_id', $productId);
            } elseif ($foodMenuId) {
                $query->where('food_menu_id', $foodMenuId);
            } else {
                $query->where('ingredient_id', $ingredientId);
            }

            $activeBatches = $query->get();
            $availableQty = max($availableQty, (float) $activeBatches->sum('usable_qty'));

            if ($availableQty + 0.000001 < $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => sprintf(
                        'Insufficient stock to transfer. Required: %s, Available: %s.',
                        $this->formatQty((float) $quantity),
                        $this->formatQty((float) $availableQty)
                    ),
                ]);
            }

            foreach ($activeBatches as $batch) {
                if ($remainingToTransfer <= 0) break;

                $transferFromBatch = min($batch->usable_qty, $remainingToTransfer);

                // Deduct from source batch
                $batch->usable_qty -= $transferFromBatch;
                $batch->save();

                // Log OUT movement from source
                IngredientStockMovement::create([
                    'ingredient_id' => $productId || $foodMenuId ? null : $ingredientId,
                    'product_id' => $productId,
                    'food_menu_id' => $foodMenuId,
                    'location_id' => $fromLocationId,
                    'ingredient_batch_id' => $batch->id,
                    'direction' => 'OUT',
                    'reason_code' => 'TRANSFER_OUT',
                    'unit_type' => 'consumption',
                    'quantity_input' => $transferFromBatch,
                    'quantity_consumption' => $transferFromBatch,
                    'batch_unit_cost' => $batch->unit_cost,
                    'reference' => $reference,
                    'note' => $note,
                    'occurred_at' => Carbon::now(),
                ]);

                // Create exact identical batch at destination preserving received_at and unit_cost
                $newBatch = IngredientBatch::create([
                    'ingredient_id' => $productId || $foodMenuId ? null : $ingredientId,
                    'product_id' => $productId,
                    'food_menu_id' => $foodMenuId,
                    'location_id' => $toLocationId,
                    'purchase_item_id' => $batch->purchase_item_id, // preserve link if any
                    'original_qty' => $transferFromBatch,
                    'usable_qty' => $transferFromBatch,
                    'unit_cost' => $batch->unit_cost,
                    'received_at' => $batch->received_at, // Preserved FIFO timestamp
                    'expiry_date' => $batch->expiry_date,
                ]);

                // Log IN movement at destination
                IngredientStockMovement::create([
                    'ingredient_id' => $productId || $foodMenuId ? null : $ingredientId,
                    'product_id' => $productId,
                    'food_menu_id' => $foodMenuId,
                    'location_id' => $toLocationId,
                    'ingredient_batch_id' => $newBatch->id,
                    'direction' => 'IN',
                    'reason_code' => 'TRANSFER_IN',
                    'unit_type' => 'consumption',
                    'quantity_input' => $transferFromBatch,
                    'quantity_consumption' => $transferFromBatch,
                    'batch_unit_cost' => $newBatch->unit_cost,
                    'reference' => $reference,
                    'note' => $note,
                    'occurred_at' => Carbon::now(),
                ]);

                if ($productId) {
                    \App\Models\ProductStockMovement::create([
                        'product_id' => $productId,
                        'location_id' => $fromLocationId,
                        'direction' => 'out',
                        'reason_code' => 'TRANSFER_OUT',
                        'quantity' => $transferFromBatch,
                        'unit_cost' => $batch->unit_cost,
                        'amount' => $transferFromBatch * $batch->unit_cost,
                        'reference' => $reference,
                        'note' => $note,
                        'occurred_at' => Carbon::now(),
                    ]);

                    \App\Models\ProductStockMovement::create([
                        'product_id' => $productId,
                        'location_id' => $toLocationId,
                        'direction' => 'in',
                        'reason_code' => 'TRANSFER_IN',
                        'quantity' => $transferFromBatch,
                        'unit_cost' => $batch->unit_cost,
                        'amount' => $transferFromBatch * $batch->unit_cost,
                        'reference' => $reference,
                        'note' => $note,
                        'occurred_at' => Carbon::now(),
                    ]);
                }

                $remainingToTransfer -= $transferFromBatch;
            }

            if ($remainingToTransfer > 0.000001) {
                $fallbackUnitCost = $this->fallbackUnitCost($ingredientId, $productId, $foodMenuId);

                IngredientStockMovement::create([
                    'ingredient_id' => $productId || $foodMenuId ? null : $ingredientId,
                    'product_id' => $productId,
                    'food_menu_id' => $foodMenuId,
                    'location_id' => $fromLocationId,
                    'ingredient_batch_id' => null,
                    'direction' => 'OUT',
                    'reason_code' => 'TRANSFER_OUT',
                    'unit_type' => 'consumption',
                    'quantity_input' => $remainingToTransfer,
                    'quantity_consumption' => $remainingToTransfer,
                    'batch_unit_cost' => $fallbackUnitCost,
                    'reference' => $reference,
                    'note' => $note,
                    'occurred_at' => Carbon::now(),
                ]);

                if ($productId) {
                    \App\Models\ProductStockMovement::create([
                        'product_id' => $productId,
                        'location_id' => $fromLocationId,
                        'direction' => 'out',
                        'reason_code' => 'TRANSFER_OUT',
                        'quantity' => $remainingToTransfer,
                        'unit_cost' => $fallbackUnitCost,
                        'amount' => $remainingToTransfer * $fallbackUnitCost,
                        'reference' => $reference,
                        'note' => $note,
                        'occurred_at' => Carbon::now(),
                    ]);
                }

                $newBatch = IngredientBatch::create([
                    'ingredient_id' => $productId || $foodMenuId ? null : $ingredientId,
                    'product_id' => $productId,
                    'food_menu_id' => $foodMenuId,
                    'location_id' => $toLocationId,
                    'purchase_item_id' => null,
                    'original_qty' => $remainingToTransfer,
                    'usable_qty' => $remainingToTransfer,
                    'unit_cost' => $fallbackUnitCost,
                    'received_at' => Carbon::now(),
                    'expiry_date' => null,
                ]);

                IngredientStockMovement::create([
                    'ingredient_id' => $productId || $foodMenuId ? null : $ingredientId,
                    'product_id' => $productId,
                    'food_menu_id' => $foodMenuId,
                    'location_id' => $toLocationId,
                    'ingredient_batch_id' => $newBatch->id,
                    'direction' => 'IN',
                    'reason_code' => 'TRANSFER_IN',
                    'unit_type' => 'consumption',
                    'quantity_input' => $remainingToTransfer,
                    'quantity_consumption' => $remainingToTransfer,
                    'batch_unit_cost' => $fallbackUnitCost,
                    'reference' => $reference,
                    'note' => $note,
                    'occurred_at' => Carbon::now(),
                ]);

                if ($productId) {
                    \App\Models\ProductStockMovement::create([
                        'product_id' => $productId,
                        'location_id' => $toLocationId,
                        'direction' => 'in',
                        'reason_code' => 'TRANSFER_IN',
                        'quantity' => $remainingToTransfer,
                        'unit_cost' => $fallbackUnitCost,
                        'amount' => $remainingToTransfer * $fallbackUnitCost,
                        'reference' => $reference,
                        'note' => $note,
                        'occurred_at' => Carbon::now(),
                    ]);
                }
            }
        });
    }

    private function fallbackUnitCost($ingredientId, $productId = null, $foodMenuId = null): float
    {
        if ($productId) {
            $product = \App\Models\Product::find($productId);

            return $product ? (float) $product->purchase_price_per_unit : 0.0;
        }

        if ($foodMenuId) {
            $foodMenu = \App\Models\FoodMenu::find($foodMenuId);

            return $foodMenu ? (float) $foodMenu->cost_per_unit : 0.0;
        }

        $ingredient = \App\Models\Ingredient::find($ingredientId);

        return ($ingredient && $ingredient->conversion_rate > 0)
            ? ((float) $ingredient->purchase_price / (float) $ingredient->conversion_rate)
            : 0.0;
    }

    private function currentStockForTransfer($ingredientId, int $locationId, $productId = null, $foodMenuId = null): float
    {
        if ($productId) {
            return round(
                (float) \App\Models\ProductStockMovement::query()
                    ->where('product_id', $productId)
                    ->where('location_id', $locationId)
                    ->selectRaw("COALESCE(SUM(CASE WHEN LOWER(direction) = 'in' THEN quantity ELSE -quantity END), 0) AS net")
                    ->value('net'),
                4
            );
        }

        if ($foodMenuId) {
            return round(
                (float) IngredientStockMovement::withoutGlobalScope(OutletScope::class)
                    ->where('food_menu_id', $foodMenuId)
                    ->where('location_id', $locationId)
                    ->selectRaw("COALESCE(SUM(CASE WHEN LOWER(direction) = 'in' THEN quantity_consumption ELSE -quantity_consumption END), 0) AS net")
                    ->value('net'),
                4
            );
        }

        $ingredient = \App\Models\Ingredient::find($ingredientId);
        $initial = 0.0;
        if ($ingredient && is_array($ingredient->initial_stock_data)) {
            foreach ($ingredient->initial_stock_data as $entry) {
                if (is_array($entry) && (int) ($entry['location_id'] ?? 0) === $locationId) {
                    $initial = (float) ($entry['quantity'] ?? 0);
                    break;
                }
            }
        }

        $movementNet = (float) IngredientStockMovement::withoutGlobalScope(OutletScope::class)
            ->where('ingredient_id', $ingredientId)
            ->where('location_id', $locationId)
            ->selectRaw("COALESCE(SUM(CASE WHEN LOWER(direction) = 'in' THEN quantity_consumption ELSE -quantity_consumption END), 0) AS net")
            ->value('net');

        return round($initial + $movementNet, 4);
    }

    private function formatQty(float $value): string
    {
        $formatted = number_format($value, 4, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }

    /**
     * Process Yield Adjustments for prep waste.
     * Inflates unit_cost so the total asset value remains identical.
     */
    public function adjustYield($batchId, $newUsableQty)
    {
        DB::transaction(function () use ($batchId, $newUsableQty) {
            $batch = IngredientBatch::lockForUpdate()->findOrFail($batchId);
            
            if ($newUsableQty >= $batch->usable_qty) {
                throw new \Exception("Yield adjustment must reduce usable quantity.");
            }

            $currentAssetValue = $batch->usable_qty * $batch->unit_cost;
            $batch->usable_qty = $newUsableQty;
            // Inflate cost
            $batch->unit_cost = $currentAssetValue / $newUsableQty;
            $batch->save();
        });
    }
}
