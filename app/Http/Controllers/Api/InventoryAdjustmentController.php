<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\IngredientBatch;
use App\Models\IngredientStockMovement;
use App\Services\FifoInventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class InventoryAdjustmentController extends Controller
{
    protected $fifoService;

    public function __construct(FifoInventoryService $fifoService)
    {
        $this->fifoService = $fifoService;
    }

    /**
     * Yield Prep Adjustment: trims raw item batch, inflating the unit cost.
     */
    public function prepYield(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ingredient_id' => 'required|exists:ingredients,id',
            'location_id' => 'required|exists:locations,id',
            'usable_quantity' => 'required|numeric|gt:0',
        ]);

        $ingredientId = (int) $validated['ingredient_id'];
        $locationId = (int) $validated['location_id'];
        $newUsable = (float) $validated['usable_quantity'];

        return DB::transaction(function () use ($ingredientId, $locationId, $newUsable) {
            $batch = IngredientBatch::where('ingredient_id', $ingredientId)
                ->where('location_id', $locationId)
                ->where('usable_qty', '>', 0)
                ->orderBy('received_at', 'asc')
                ->lockForUpdate()
                ->first();

            if (!$batch) {
                return response()->json([
                    'message' => 'No active stock batch found to adjust for this ingredient/location.'
                ], 422);
            }

            if ($newUsable >= $batch->usable_qty) {
                return response()->json([
                    'message' => 'New usable quantity must be less than the current active batch quantity (' . $batch->usable_qty . ').'
                ], 422);
            }

            $originalQty = $batch->usable_qty;
            $lossQty = $originalQty - $newUsable;
            $originalTotalCost = $originalQty * $batch->unit_cost;

            // Inflate unit cost
            $newUnitCost = $originalTotalCost / $newUsable;

            $batch->usable_qty = $newUsable;
            $batch->unit_cost = $newUnitCost;
            $batch->save();

            // Log yield loss stock movement
            IngredientStockMovement::create([
                'ingredient_id' => $ingredientId,
                'location_id' => $locationId,
                'ingredient_batch_id' => $batch->id,
                'direction' => 'OUT',
                'reason_code' => 'yield_prep_loss',
                'unit_type' => 'consumption',
                'quantity_input' => $lossQty,
                'quantity_consumption' => $lossQty,
                'batch_unit_cost' => $batch->unit_cost,
                'reference' => 'Yield Prep Adjustment',
                'note' => 'Usable yield adjusted from ' . $originalQty . ' to ' . $newUsable,
                'occurred_at' => Carbon::now(),
            ]);

            return response()->json([
                'message' => 'Yield prep completed successfully.',
                'original_qty' => $originalQty,
                'new_qty' => $newUsable,
                'new_unit_cost' => round($newUnitCost, 4),
                'yield_percent' => round(($newUsable / $originalQty) * 100, 2)
            ]);
        });
    }

    /**
     * Inter-Location stock transfer preserving FIFO timestamps/costs.
     */
    public function transfer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ingredient_id' => 'required|exists:ingredients,id',
            'from_location_id' => 'required|exists:locations,id',
            'to_location_id' => 'required|exists:locations,id',
            'quantity' => 'required|numeric|gt:0',
            'note' => 'nullable|string|max:250',
        ]);

        try {
            $this->fifoService->transferStock(
                $validated['ingredient_id'],
                $validated['from_location_id'],
                $validated['to_location_id'],
                $validated['quantity'],
                'Inter-Location Transfer',
                $validated['note'] ?? 'FIFO Transfer'
            );

            return response()->json([
                'message' => 'Stock transferred successfully.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Physical audit count variance reconciliation.
     */
    public function audit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'location_id' => 'required|exists:locations,id',
            'items' => 'required|array|min:1',
            'items.*.ingredient_id' => 'required|exists:ingredients,id',
            'items.*.physical_qty' => 'required|numeric|min:0',
        ]);

        $locationId = (int) $validated['location_id'];
        $results = [];

        DB::transaction(function () use ($locationId, $validated, &$results) {
            foreach ($validated['items'] as $item) {
                $ingredientId = (int) $item['ingredient_id'];
                $physicalQty = (float) $item['physical_qty'];

                $ingredient = Ingredient::findOrFail($ingredientId);

                // Calculate current system stock: Legacy + Movements
                $initialStock = 0;
                if ($ingredient->initial_stock_data) {
                    $initialData = is_array($ingredient->initial_stock_data)
                        ? $ingredient->initial_stock_data
                        : json_decode($ingredient->initial_stock_data, true);
                    if (is_array($initialData)) {
                        foreach ($initialData as $entry) {
                            if (!is_array($entry)) continue;
                            $loc = isset($entry['location_id']) ? (int) $entry['location_id'] : null;
                            if ($loc === $locationId) {
                                $initialStock += (float) ($entry['quantity'] ?? 0);
                            }
                        }
                    }
                }

                $netMovement = (float) IngredientStockMovement::where('ingredient_id', $ingredientId)
                    ->where('location_id', $locationId)
                    ->selectRaw("SUM(CASE WHEN LOWER(direction) = 'in' THEN quantity_consumption ELSE -quantity_consumption END) as net")
                    ->value('net') ?? 0.0;

                $systemStock = $initialStock + $netMovement;
                $variance = $physicalQty - $systemStock;

                if (abs($variance) < 0.0001) {
                    $results[] = [
                        'ingredient_id' => $ingredientId,
                        'name' => $ingredient->name,
                        'variance' => 0.0,
                        'status' => 'Match'
                    ];
                    continue;
                }

                if ($variance < 0) {
                    // Shortage: consume from oldest active batches
                    $shortageQty = abs($variance);
                    $this->fifoService->consumeStock(
                        $ingredientId,
                        $locationId,
                        $shortageQty,
                        'OUT',
                        'audit_shortage_variance',
                        'Stocktake Audit',
                        'Physical audit shortage reconciliation'
                    );

                    $results[] = [
                        'ingredient_id' => $ingredientId,
                        'name' => $ingredient->name,
                        'variance' => $variance,
                        'status' => 'Shortage resolved'
                    ];
                } else {
                    // Surplus: create new reconciliation batch
                    $surplusQty = $variance;

                    // Fetch most recent purchase price or fallback
                    $recentBatchPrice = IngredientBatch::where('ingredient_id', $ingredientId)
                        ->where('usable_qty', '>', 0)
                        ->orderBy('received_at', 'desc')
                        ->value('unit_cost');

                    $unitCost = $recentBatchPrice ?? (
                        $ingredient->conversion_rate > 0 
                            ? ($ingredient->purchase_price / $ingredient->conversion_rate) 
                            : 0.0
                    );

                    $batch = IngredientBatch::create([
                        'ingredient_id' => $ingredientId,
                        'location_id' => $locationId,
                        'original_qty' => $surplusQty,
                        'usable_qty' => $surplusQty,
                        'unit_cost' => $unitCost,
                        'received_at' => Carbon::now(),
                        'expiry_date' => null,
                    ]);

                    IngredientStockMovement::create([
                        'ingredient_id' => $ingredientId,
                        'location_id' => $locationId,
                        'ingredient_batch_id' => $batch->id,
                        'direction' => 'IN',
                        'reason_code' => 'audit_surplus_reconciliation',
                        'unit_type' => 'consumption',
                        'quantity_input' => $surplusQty,
                        'quantity_consumption' => $surplusQty,
                        'batch_unit_cost' => $unitCost,
                        'reference' => 'Stocktake Audit',
                        'note' => 'Physical audit surplus reconciliation',
                        'occurred_at' => Carbon::now(),
                    ]);

                    $results[] = [
                        'ingredient_id' => $ingredientId,
                        'name' => $ingredient->name,
                        'variance' => $variance,
                        'status' => 'Surplus resolved'
                    ];
                }
            }
        });

        return response()->json([
            'message' => 'Physical audit count reconciled successfully.',
            'results' => $results
        ]);
    }

    /**
     * Ingredient Decomposition (Breakdown parent ingredient to children ingredients).
     */
    public function decompose(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'parent_ingredient_id' => 'required|exists:ingredients,id',
            'location_id' => 'required|exists:locations,id',
            'parent_qty' => 'required|numeric|gt:0',
            'children' => 'required|array|min:1',
            'children.*.ingredient_id' => 'required|exists:ingredients,id',
            'children.*.qty' => 'required|numeric|gt:0',
            'children.*.cost_percentage' => 'required|numeric|min:0|max:100',
        ]);

        $parentId = (int) $validated['parent_ingredient_id'];
        $locationId = (int) $validated['location_id'];
        $parentQty = (float) $validated['parent_qty'];

        // Validate cost percentages sum to 100%
        $totalPercentage = array_sum(array_column($validated['children'], 'cost_percentage'));
        if (abs($totalPercentage - 100) > 0.1) {
            return response()->json([
                'message' => 'Total cost percentages of children must sum to exactly 100% (Sum: ' . $totalPercentage . '%).'
            ], 422);
        }

        return DB::transaction(function () use ($parentId, $locationId, $parentQty, $validated) {
            // Deplete parent stock and capture actual total cost consumed and oldest timestamp
            $depleted = $this->fifoService->consumeStock(
                $parentId,
                $locationId,
                $parentQty,
                'OUT',
                'decomposition_parent_depletion',
                'Ingredient Breakdown',
                'Decomposing ' . $parentQty . ' unit(s)'
            );

            $parentTotalCost = $depleted['total_cost'];
            $timestamp = $depleted['oldest_timestamp'];

            $childrenCreated = [];
            foreach ($validated['children'] as $child) {
                $childId = (int) $child['ingredient_id'];
                $childQty = (float) $child['qty'];
                $pct = (float) $child['cost_percentage'];

                $childTotalCost = $parentTotalCost * ($pct / 100);
                $childUnitCost = $childQty > 0 ? ($childTotalCost / $childQty) : 0.0;

                // Create child batch inheriting parent timestamp
                $batch = IngredientBatch::create([
                    'ingredient_id' => $childId,
                    'location_id' => $locationId,
                    'original_qty' => $childQty,
                    'usable_qty' => $childQty,
                    'unit_cost' => $childUnitCost,
                    'received_at' => $timestamp,
                    'expiry_date' => null,
                ]);

                // Create child stock movement
                IngredientStockMovement::create([
                    'ingredient_id' => $childId,
                    'location_id' => $locationId,
                    'ingredient_batch_id' => $batch->id,
                    'direction' => 'IN',
                    'reason_code' => 'decomposition_child_creation',
                    'unit_type' => 'consumption',
                    'quantity_input' => $childQty,
                    'quantity_consumption' => $childQty,
                    'batch_unit_cost' => $childUnitCost,
                    'reference' => 'Ingredient Breakdown',
                    'note' => 'Decomposed from parent batch cost of $' . number_format($parentTotalCost, 2),
                    'occurred_at' => Carbon::now(),
                ]);

                $childrenCreated[] = [
                    'ingredient_id' => $childId,
                    'qty' => $childQty,
                    'unit_cost' => round($childUnitCost, 4)
                ];
            }

            return response()->json([
                'message' => 'Decomposition completed successfully.',
                'parent_cost_depleted' => round($parentTotalCost, 2),
                'children' => $childrenCreated
            ]);
        });
    }
}
