<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transfer;
use App\Models\Ingredient;
use App\Models\IngredientBatch;
use App\Models\IngredientStockMovement;
use App\Models\FoodMenu;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Services\FifoInventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Str;

class TransferController extends Controller
{
    public function index(Request $request)
    {
        $query = Transfer::with(['fromLocation', 'toLocation', 'createdBy', 'items.ingredient', 'items.product', 'items.foodMenu'])->orderBy('id', 'desc');

        if ($request->search) {
            $search = trim((string) $request->search);
            $query->where(function ($q) use ($search): void {
                $q->where('ref_no', 'like', "%{$search}%")
                    ->orWhereHas('fromLocation', fn ($locationQuery) => $locationQuery->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('toLocation', fn ($locationQuery) => $locationQuery->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('items.ingredient', function ($itemQuery) use ($search): void {
                        $itemQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('sku_code', 'like', "%{$search}%")
                            ->orWhere('barcode', 'like', "%{$search}%");
                    })
                    ->orWhereHas('items.product', function ($itemQuery) use ($search): void {
                        $itemQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%")
                            ->orWhere('barcode', 'like', "%{$search}%");
                    })
                    ->orWhereHas('items.foodMenu', function ($itemQuery) use ($search): void {
                        $itemQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%");
                    });
            });
        }
        if ($request->outlet_id) {
            $query->where(function ($q) use ($request): void {
                $q->where('from_location_id', $request->outlet_id)
                    ->orWhere('to_location_id', $request->outlet_id);
            });
        }
        if ($request->from_location_id) {
            $query->where('from_location_id', $request->from_location_id);
        }
        if ($request->to_location_id) {
            $query->where('to_location_id', $request->to_location_id);
        }

        return $query->paginate($request->page ?? 15);
    }

    public function store(Request $request, FifoInventoryService $fifoService)
    {
        $validated = $request->validate([
            'ref_no' => 'nullable|string|unique:transfers,ref_no',
            'from_location_id' => 'required|exists:locations,id',
            'to_location_id' => 'required|exists:locations,id|different:from_location_id',
            'status' => 'nullable|in:completed,pending,in_transit',
            'note' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|integer',
            'items.*.type' => 'required|in:ingredient,product,food_menu',
            'items.*.unit_id' => 'nullable|integer',
            'items.*.unit_type' => 'nullable|in:purchase,consumption',
            'items.*.quantity' => 'required|numeric|min:0.0001',
            'items.*.unit_cost' => 'required|numeric|min:0',
        ]);
        $this->validateTransferItems($validated['items']);

        try {
            return DB::transaction(function () use ($validated, $request, $fifoService) {
                $total_value = 0;
                
                $refNo = $validated['ref_no'] ?? 'TRF-' . Carbon::now()->format('Ymd') . '-' . strtoupper(Str::random(4));

                $transferStatus = $validated['status'] ?? 'completed';

                $transfer = Transfer::create([
                    'ref_no' => $refNo,
                    'from_location_id' => $validated['from_location_id'],
                    'to_location_id' => $validated['to_location_id'],
                    'transfer_date' => Carbon::today(),
                    'transferred_at' => $transferStatus === 'completed' ? Carbon::now() : null,
                    'status' => $transferStatus,
                    'total_value' => 0, // we will update this
                    'note' => $validated['note'] ?? null,
                    'created_by' => $request->user()->id ?? null,
                ]);

                foreach ($validated['items'] as $item) {
                    $subtotal = $item['quantity'] * $item['unit_cost'];
                    $total_value += $subtotal;

                    $transfer->items()->create([
                        'item_type' => $item['type'],
                        'item_id' => $item['item_id'],
                        'unit_id' => $item['unit_id'] ?? null,
                        'unit_type' => $item['unit_type'] ?? 'consumption',
                        'quantity' => $item['quantity'],
                        'unit_cost' => $item['unit_cost'],
                        'subtotal' => $subtotal,
                    ]);

                    // Only move physical stock via FIFO when transfer is immediately completed.
                    // Pending / in-transit transfers are recorded but do not deduct stock yet.
                    if ($transferStatus === 'completed') {
                        // Determine consumption quantity base
                        $consumptionQty = $item['quantity'];
                        if ($item['type'] === 'ingredient') {
                            $ingredient = Ingredient::find($item['item_id']);
                            if ($ingredient && ($item['unit_type'] ?? 'consumption') === 'purchase') {
                                $conversionRate = (float) ($ingredient->conversion_rate ?? 1);
                                $consumptionQty = $item['quantity'] * $conversionRate;
                            }
                        }

                        // Delegate exact batch/stock operations to FifoInventoryService
                        $fifoService->transferStock(
                            ingredientId: $item['type'] === 'ingredient' ? $item['item_id'] : null,
                            fromLocationId: $validated['from_location_id'],
                            toLocationId: $validated['to_location_id'],
                            quantity: $consumptionQty,
                            reference: 'Transfer: ' . $refNo,
                            note: $validated['note'] ?? null,
                            productId: $item['type'] === 'product' ? $item['item_id'] : null,
                            foodMenuId: $item['type'] === 'food_menu' ? $item['item_id'] : null
                        );
                    }
                }

                $transfer->update(['total_value' => $total_value]);

                return response()->json($transfer->load('items'));
            });
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function show($id)
    {
        return Transfer::with(['fromLocation', 'toLocation', 'createdBy', 'items.ingredient', 'items.product', 'items.foodMenu'])->findOrFail($id);
    }

    public function update($id, Request $request, FifoInventoryService $fifoService)
    {
        $transfer = Transfer::findOrFail($id);

        $validated = $request->validate([
            'from_location_id' => 'sometimes|required|exists:locations,id',
            'to_location_id' => 'sometimes|required|exists:locations,id',
            'status' => 'nullable|in:completed,pending,in_transit',
            'note' => 'nullable|string',
            'items' => 'sometimes|required|array|min:1',
            'items.*.item_id' => 'required_with:items|integer',
            'items.*.type' => 'required_with:items|in:ingredient,product,food_menu',
            'items.*.unit_id' => 'nullable|integer',
            'items.*.unit_type' => 'nullable|in:purchase,consumption',
            'items.*.quantity' => 'required_with:items|numeric|min:0.0001',
            'items.*.unit_cost' => 'required_with:items|numeric|min:0',
        ]);

        // Ensure from/to locations are different when both are present
        $fromId = $validated['from_location_id'] ?? $transfer->from_location_id;
        $toId = $validated['to_location_id'] ?? $transfer->to_location_id;
        if ($fromId == $toId) {
            return response()->json(['message' => 'From and to locations must be different.'], 422);
        }
        if (isset($validated['items'])) {
            $this->validateTransferItems($validated['items']);
        }

        return DB::transaction(function () use ($transfer, $validated, $fromId, $toId, $fifoService) {
            $newStatus = $validated['status'] ?? $transfer->status;
            $this->reverseTransferStock($transfer);

            $updateData = [
                'from_location_id' => $fromId,
                'to_location_id' => $toId,
                'status' => $newStatus,
                'transferred_at' => $newStatus === 'completed' ? ($transfer->transferred_at ?? Carbon::now()) : null,
            ];
            if (array_key_exists('note', $validated)) {
                $updateData['note'] = $validated['note'];
            }
            $transfer->update($updateData);

            if (isset($validated['items'])) {
                $transfer->items()->delete();

                $totalValue = 0;
                foreach ($validated['items'] as $item) {
                    $subtotal = $item['quantity'] * $item['unit_cost'];
                    $totalValue += $subtotal;

                    $transfer->items()->create([
                        'item_type' => $item['type'],
                        'item_id' => $item['item_id'],
                        'unit_id' => $item['unit_id'] ?? null,
                        'unit_type' => $item['unit_type'] ?? 'consumption',
                        'quantity' => $item['quantity'],
                        'unit_cost' => $item['unit_cost'],
                        'subtotal' => $subtotal,
                    ]);

                }

                $transfer->update(['total_value' => $totalValue]);
            }

            if ($newStatus === 'completed') {
                $transfer->load('items');
                foreach ($transfer->items as $item) {
                    $consumptionQty = (float) $item->quantity;
                    if ($item->item_type === 'ingredient' && $item->unit_type === 'purchase') {
                        $ingredient = Ingredient::find((int) $item->item_id);
                        if ($ingredient) {
                            $conversionRate = (float) ($ingredient->conversion_rate ?? 1);
                            $consumptionQty *= $conversionRate > 0 ? $conversionRate : 1;
                        }
                    }

                    $fifoService->transferStock(
                        ingredientId: $item->item_type === 'ingredient' ? (int) $item->item_id : null,
                        fromLocationId: $fromId,
                        toLocationId: $toId,
                        quantity: $consumptionQty,
                        reference: 'Transfer: ' . $transfer->ref_no,
                        note: $validated['note'] ?? $transfer->note,
                        productId: $item->item_type === 'product' ? (int) $item->item_id : null,
                        foodMenuId: $item->item_type === 'food_menu' ? (int) $item->item_id : null
                    );
                }
            }

            return response()->json(
                $transfer->load(['fromLocation', 'toLocation', 'createdBy', 'items.ingredient', 'items.product', 'items.foodMenu'])
            );
        });
    }

    public function destroy($id)
    {
        $transfer = Transfer::findOrFail($id);
        return DB::transaction(function () use ($transfer) {
            $this->reverseTransferStock($transfer);
            $transfer->items()->delete();
            $transfer->delete();

            return response()->noContent();
        });
    }

    private function validateTransferItems(array $items): void
    {
        foreach ($items as $index => $item) {
            $type = (string) ($item['type'] ?? '');
            $id = (int) ($item['item_id'] ?? 0);
            if ($type === 'ingredient' && ! Ingredient::query()->whereKey($id)->exists()) {
                abort(422, sprintf('Transfer item %d ingredient was not found.', $index + 1));
            }
            if ($type === 'product' && ! Product::query()->whereKey($id)->exists()) {
                abort(422, sprintf('Transfer item %d product was not found.', $index + 1));
            }
            if ($type === 'food_menu') {
                $menu = FoodMenu::query()->whereKey($id)->first();
                if ($menu === null || $menu->stock_deduction_method !== 'production_stock') {
                    abort(422, sprintf('Transfer item %d must be a produced Food Menu.', $index + 1));
                }
            }
        }
    }

    private function reverseTransferStock(Transfer $transfer): void
    {
        $reference = 'Transfer: ' . $transfer->ref_no;

        $productMovements = ProductStockMovement::query()
            ->where('reference', $reference)
            ->lockForUpdate()
            ->get();

        $productInboundGroups = $productMovements
            ->filter(fn (ProductStockMovement $movement): bool => strtolower((string) $movement->direction) === 'in')
            ->groupBy(fn (ProductStockMovement $movement): string => $movement->product_id . ':' . $movement->location_id);

        foreach ($productInboundGroups as $group) {
            $first = $group->first();
            if ($first === null) {
                continue;
            }

            $currentQty = (float) ProductStockMovement::query()
                ->where('product_id', $first->product_id)
                ->where('location_id', $first->location_id)
                ->selectRaw("COALESCE(SUM(CASE WHEN LOWER(direction) = 'in' THEN quantity ELSE -quantity END), 0) as qty")
                ->value('qty');

            $rollbackQty = (float) $group->sum(fn (ProductStockMovement $movement): float => (float) $movement->quantity);
            if ($currentQty + 0.000001 < $rollbackQty) {
                abort(422, 'Cannot change this transfer because some transferred product stock has already been used at the destination outlet.');
            }
        }

        $movements = IngredientStockMovement::withoutGlobalScopes()
            ->where('reference', $reference)
            ->lockForUpdate()
            ->get();

        if ($movements->isEmpty()) {
            ProductStockMovement::query()->where('reference', $reference)->delete();
            return;
        }

        $inMovements = $movements->filter(fn (IngredientStockMovement $movement): bool => strtolower((string) $movement->direction) === 'in');
        $outMovements = $movements->filter(fn (IngredientStockMovement $movement): bool => strtolower((string) $movement->direction) === 'out');

        foreach ($inMovements as $movement) {
            if ($movement->ingredient_batch_id === null) {
                continue;
            }

            $batch = IngredientBatch::withoutGlobalScopes()->lockForUpdate()->find($movement->ingredient_batch_id);
            if ($batch === null) {
                continue;
            }

            $qty = (float) $movement->quantity_consumption;
            if ((float) $batch->usable_qty + 0.000001 < $qty) {
                abort(422, 'Cannot change this transfer because some transferred stock has already been used at the destination outlet.');
            }

            $batch->usable_qty = round((float) $batch->usable_qty - $qty, 4);
            if ($batch->usable_qty <= 0.000001) {
                $batch->delete();
            } else {
                $batch->save();
            }
        }

        foreach ($outMovements as $movement) {
            if ($movement->ingredient_batch_id === null) {
                continue;
            }

            $batch = IngredientBatch::withoutGlobalScopes()->lockForUpdate()->find($movement->ingredient_batch_id);
            if ($batch === null) {
                continue;
            }

            $batch->usable_qty = round((float) $batch->usable_qty + (float) $movement->quantity_consumption, 4);
            $batch->save();
        }

        IngredientStockMovement::withoutGlobalScopes()->where('reference', $reference)->delete();
        ProductStockMovement::query()->where('reference', $reference)->delete();
    }
}
