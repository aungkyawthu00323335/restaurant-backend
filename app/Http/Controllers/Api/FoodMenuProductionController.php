<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FoodMenu;
use App\Models\FoodMenuProduction;
use App\Models\Ingredient;
use App\Models\IngredientStockMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FoodMenuProductionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'food_menu_id' => ['nullable', 'integer', 'exists:food_menus,id'],
            'ingredient_id' => ['nullable', 'integer', 'exists:ingredients,id'],
            'created_by_name' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', Rule::in(['completed', 'reversed'])],
            'search' => ['nullable', 'string', 'max:120'],
            'sort_col' => ['nullable', 'string', Rule::in(['production_date', 'ref_no', 'created_at', 'total_ingredient_cost', 'production_qty'])],
            'sort_dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer'],
        ]);

        $sortCol = $payload['sort_col'] ?? 'production_date';
        $sortDir = $payload['sort_dir'] ?? 'desc';

        $query = FoodMenuProduction::query()
            ->with(['location', 'foodMenu', 'unit']);
        $this->applyFilters($query, $payload);
        $query
            ->orderBy($sortCol, $sortDir)
            ->orderBy('id', 'desc');

        $perPage = (int) ($payload['per_page'] ?? 20);
        $perPage = in_array($perPage, [10, 20, 30, 50, 100], true) ? $perPage : 20;

        $records = $query->paginate($perPage)->through(
            fn (FoodMenuProduction $production): array => $this->listResource($production)
        );

        $today = Carbon::today();
        $startOfMonth = Carbon::today()->startOfMonth();
        $endOfMonth = Carbon::today()->endOfMonth();

        $filteredQuery = FoodMenuProduction::query();
        $this->applyFilters($filteredQuery, $payload);

        $summaryBase = FoodMenuProduction::query();
        $summaryPayload = $payload;
        unset($summaryPayload['status']);
        $this->applyFilters($summaryBase, $summaryPayload);

        return response()->json([
            'data' => [
                'summary' => [
                    'today_production_amount' => round(
                        (float) (clone $summaryBase)
                            ->where('status', 'completed')
                            ->whereDate('production_date', $today)
                            ->sum('total_ingredient_cost'),
                        4
                    ),
                    'this_month_production_amount' => round(
                        (float) (clone $summaryBase)
                            ->where('status', 'completed')
                            ->whereBetween('production_date', [$startOfMonth->toDateString(), $endOfMonth->toDateString()])
                            ->sum('total_ingredient_cost'),
                        4
                    ),
                    'all_time_production_amount' => round(
                        (float) (clone $summaryBase)
                            ->where('status', 'completed')
                            ->sum('total_ingredient_cost'),
                        4
                    ),
                    'filtered_production_amount' => round((float) (clone $filteredQuery)->sum('total_ingredient_cost'), 4),
                    'total_production_count' => (int) (clone $filteredQuery)->count(),
                    'total_produced_qty' => round((float) (clone $filteredQuery)->sum('production_qty'), 4),
                ],
                'records' => $records,
            ],
        ]);
    }

    public function createData(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
        ]);
        $locationId = isset($payload['location_id']) ? (int) $payload['location_id'] : null;

        $locations = \App\Models\Location::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $foodMenus = FoodMenu::query()
            ->where('food_menus.is_active', true)
            ->where('food_menus.stock_deduction_method', 'production_stock')
            ->when($locationId !== null, function (Builder $query) use ($locationId): void {
                $query->whereHas('locations', function (Builder $locationQuery) use ($locationId): void {
                    $locationQuery->where('locations.id', $locationId)
                        ->where('location_food_menu.is_active', true);
                });
            })
            ->with(['unit:id,name', 'ingredientMappings.ingredient.consumptionUnit', 'ingredientMappings.unit:id,name'])
            ->orderBy('food_menus.name')
            ->get(['food_menus.id', 'food_menus.name', 'food_menus.unit_id']);

        $formatted = $foodMenus->map(function (FoodMenu $fm) use ($locationId): array {
            $mappings = $fm->ingredientMappings->map(function ($mapping) use ($locationId): array {
                $ingredient = $mapping->ingredient;
                return [
                    'ingredient_id' => $mapping->ingredient_id,
                    'ingredient_name' => $ingredient?->name ?? '',
                    'required_qty' => round((float) $mapping->required_qty, 4),
                    'unit_id' => $mapping->unit_id,
                    'unit_name' => $mapping->unit?->name ?? $ingredient?->consumptionUnit?->name ?? '',
                    'unit_cost' => $ingredient ? round((float) $ingredient->cost_per_consumption_unit, 4) : 0,
                    'available_qty' => $ingredient && $locationId !== null
                        ? round($this->currentIngredientStockForLocation($ingredient, $locationId), 4)
                        : null,
                ];
            })->values()->all();

            return [
                'id' => $fm->id,
                'name' => $fm->name,
                'unit_id' => $fm->unit_id,
                'unit_name' => $fm->unit?->name ?? '',
                'current_stock_qty' => $locationId !== null
                    ? $this->currentFoodMenuStockForLocation($fm->id, $locationId)
                    : null,
                'mappings' => $mappings,
            ];
        });

        return response()->json([
            'data' => [
                'locations' => $locations,
                'food_menus' => $formatted,
            ],
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'food_menu_id' => ['required', 'integer', 'exists:food_menus,id'],
            'production_qty' => ['required', 'numeric', 'gt:0'],
            'production_date' => ['required', 'date'],
        ]);

        $productionDate = Carbon::parse((string) $payload['production_date']);

        $preview = $this->buildProductionPreview(
            (int) $payload['location_id'],
            (int) $payload['food_menu_id'],
            (float) $payload['production_qty'],
            $productionDate
        );

        return response()->json([
            'data' => $preview,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'production_date' => ['required', 'date'],
            'food_menu_id' => ['required', 'integer', 'exists:food_menus,id'],
            'production_qty' => ['required', 'numeric', 'gt:0'],
            'note' => ['nullable', 'string', 'max:500'],
            'actor_name' => ['nullable', 'string', 'max:120'],
        ]);

        $productionDate = Carbon::parse((string) $payload['production_date']);
        $actorName = trim((string) ($payload['actor_name'] ?? '')) ?: 'System';

        $preview = $this->buildProductionPreview(
            (int) $payload['location_id'],
            (int) $payload['food_menu_id'],
            (float) $payload['production_qty'],
            $productionDate
        );

        if (! $preview['stock_sufficient']) {
            abort(422, $preview['stock_message'] ?? 'Insufficient stock for production.');
        }

        $production = DB::transaction(function () use ($payload, $preview, $productionDate, $actorName): FoodMenuProduction {
            $foodMenu = FoodMenu::query()->findOrFail((int) $payload['food_menu_id']);
            $locationId = (int) $payload['location_id'];

            $production = FoodMenuProduction::query()->create([
                'ref_no' => $this->nextRefNo($productionDate),
                'location_id' => $locationId,
                'food_menu_id' => (int) $payload['food_menu_id'],
                'production_date' => $productionDate->toDateString(),
                'production_qty' => $preview['production_qty'],
                'unit_id' => $foodMenu->unit_id,
                'total_ingredient_cost' => $preview['total_ingredient_cost'],
                'production_cost_per_unit' => $preview['production_cost_per_unit'],
                'status' => 'completed',
                'note' => $payload['note'] ?? null,
                'created_by_name' => $actorName,
                'updated_by_name' => $actorName,
            ]);

            $fifoService = app(\App\Services\FifoInventoryService::class);
            $actualTotalIngredientCost = 0.0;

            foreach ($preview['details'] as $detail) {
                // Consume ingredient stock dynamically via FIFO and capture actual cost
                $consumed = $fifoService->consumeStock(
                    ingredientId: (int) $detail['ingredient_id'],
                    locationId: $locationId,
                    quantity: (float) $detail['required_qty'],
                    direction: 'OUT',
                    reasonCode: 'production_out',
                    reference: $production->ref_no,
                    note: $payload['note'] ?? null
                );

                $requiredQty = (float) $detail['required_qty'];
                $consumedCost = $this->fifoCost($consumed);
                $actualUnitCost = $requiredQty > 0
                    ? round($consumedCost / $requiredQty, 4)
                    : (float) $detail['unit_cost'];
                $actualAmount = round($consumedCost, 4);
                $actualTotalIngredientCost += $actualAmount;

                $production->details()->create([
                    'ingredient_id' => $detail['ingredient_id'],
                    'ingredient_name_snapshot' => $detail['ingredient_name'],
                    'required_qty' => $requiredQty,
                    'unit_id' => $detail['unit_id'],
                    'unit_name_snapshot' => $detail['unit_name'],
                    'unit_cost_snapshot' => $actualUnitCost,
                    'amount' => $actualAmount,
                ]);
            }

            // Create FIFO batch layer for the newly produced Food Menu using actual FIFO cost
            $productionQty = (float) $preview['production_qty'];
            $totalCost = $actualTotalIngredientCost > 0 ? $actualTotalIngredientCost : (float) $preview['total_ingredient_cost'];
            $costPerUnit = $productionQty > 0 ? ($totalCost / $productionQty) : 0.0;

            $production->update([
                'total_ingredient_cost' => $totalCost,
                'production_cost_per_unit' => $costPerUnit,
            ]);

            $batch = \App\Models\IngredientBatch::create([
                'food_menu_id' => $foodMenu->id,
                'location_id' => $locationId,
                'original_qty' => $productionQty,
                'usable_qty' => $productionQty,
                'unit_cost' => $costPerUnit,
                'received_at' => $productionDate->toDateTimeString(),
                'expiry_date' => null,
            ]);

            // Create stock movement for produced Food Menu
            IngredientStockMovement::create([
                'food_menu_id' => $foodMenu->id,
                'location_id' => $locationId,
                'ingredient_batch_id' => $batch->id,
                'direction' => 'IN',
                'reason_code' => 'production_in',
                'unit_type' => 'consumption',
                'quantity_input' => $productionQty,
                'quantity_consumption' => $productionQty,
                'batch_unit_cost' => $costPerUnit,
                'reference' => $production->ref_no,
                'note' => $payload['note'] ?? null,
                'occurred_at' => $productionDate->toDateTimeString(),
            ]);

            return $production;
        });

        return response()->json(['data' => $this->detailResource($production)], 201);
    }

    public function show(FoodMenuProduction $foodMenuProduction): JsonResponse
    {
        return response()->json([
            'data' => $this->detailResource(
                $foodMenuProduction->load(['location', 'foodMenu', 'unit', 'details.ingredient', 'details.unit'])
            ),
        ]);
    }

    public function reverse(Request $request, FoodMenuProduction $foodMenuProduction): JsonResponse
    {
        $payload = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
            'actor_name' => ['nullable', 'string', 'max:120'],
        ]);

        if ($foodMenuProduction->status !== 'completed') {
            abort(422, 'Only completed production can be reversed.');
        }

        $foodMenuProduction->loadMissing(['location', 'foodMenu', 'details.ingredient']);

        $foodMenu = $foodMenuProduction->foodMenu;
        $currentStock = $this->currentFoodMenuStockForLocation(
            (int) $foodMenu->id,
            (int) $foodMenuProduction->location_id
        );
        $requiredQty = (float) $foodMenuProduction->production_qty;

        if ($currentStock + 0.000001 < $requiredQty) {
            $unit = $foodMenuProduction->unit?->name ?? '';
            abort(
                422,
                sprintf(
                    'Cannot reverse this production. Required: %s %s, Available: %s %s.',
                    $this->formatNumber($requiredQty),
                    $unit,
                    $this->formatNumber($currentStock),
                    $unit
                )
            );
        }

        $actorName = trim((string) ($payload['actor_name'] ?? '')) ?: 'System';
        $reverseDate = Carbon::now();

        DB::transaction(function () use ($foodMenuProduction, $foodMenu, $payload, $actorName, $reverseDate, $requiredQty): void {
            $producedMovements = IngredientStockMovement::withoutGlobalScopes()
                ->where('reference', $foodMenuProduction->ref_no)
                ->where('food_menu_id', $foodMenu->id)
                ->where('location_id', $foodMenuProduction->location_id)
                ->whereRaw("LOWER(direction) = 'in'")
                ->where('reason_code', 'production_in')
                ->lockForUpdate()
                ->get();

            $producedQty = round((float) $producedMovements->sum('quantity_consumption'), 4);
            if ($producedMovements->isEmpty() || $producedQty + 0.000001 < $requiredQty) {
                abort(422, 'Cannot reverse this production because its produced stock movement was not found.');
            }

            foreach ($producedMovements as $movement) {
                $batch = \App\Models\IngredientBatch::withoutGlobalScopes()
                    ->lockForUpdate()
                    ->find($movement->ingredient_batch_id);
                $qty = (float) $movement->quantity_consumption;

                if ($batch === null || (float) $batch->usable_qty + 0.000001 < $qty) {
                    abort(422, 'Cannot reverse this production because produced food menu stock has already been used.');
                }
            }

            foreach ($foodMenuProduction->details as $detail) {
                // Restore consumed FIFO batches by creating a new batch layer
                $batch = \App\Models\IngredientBatch::create([
                    'ingredient_id' => $detail->ingredient_id,
                    'location_id' => $foodMenuProduction->location_id,
                    'original_qty' => $detail->required_qty,
                    'usable_qty' => $detail->required_qty,
                    'unit_cost' => $detail->unit_cost_snapshot,
                    'received_at' => Carbon::now(),
                    'expiry_date' => null,
                ]);

                IngredientStockMovement::create([
                    'ingredient_id' => $detail->ingredient_id,
                    'location_id' => $foodMenuProduction->location_id,
                    'ingredient_batch_id' => $batch->id,
                    'direction' => 'IN',
                    'reason_code' => 'production_reverse_in',
                    'unit_type' => 'consumption',
                    'quantity_input' => $detail->required_qty,
                    'quantity_consumption' => $detail->required_qty,
                    'batch_unit_cost' => $detail->unit_cost_snapshot,
                    'reference' => $foodMenuProduction->ref_no,
                    'note' => $payload['note'] ?? $foodMenuProduction->note,
                    'occurred_at' => $reverseDate,
                ]);
            }

            foreach ($producedMovements as $movement) {
                $batch = \App\Models\IngredientBatch::withoutGlobalScopes()
                    ->lockForUpdate()
                    ->findOrFail($movement->ingredient_batch_id);
                $qty = round((float) $movement->quantity_consumption, 4);

                $batch->usable_qty = round((float) $batch->usable_qty - $qty, 4);
                $batch->save();

                IngredientStockMovement::create([
                    'food_menu_id' => $foodMenu->id,
                    'location_id' => $foodMenuProduction->location_id,
                    'ingredient_batch_id' => $batch->id,
                    'direction' => 'OUT',
                    'reason_code' => 'production_reverse_out',
                    'unit_type' => 'consumption',
                    'quantity_input' => $qty,
                    'quantity_consumption' => $qty,
                    'batch_unit_cost' => $movement->batch_unit_cost,
                    'reference' => $foodMenuProduction->ref_no,
                    'note' => $payload['note'] ?? $foodMenuProduction->note,
                    'occurred_at' => $reverseDate,
                ]);
            }

            $foodMenuProduction->update([
                'status' => 'reversed',
                'reverse_note' => $payload['note'] ?? null,
                'reversed_at' => $reverseDate,
                'reversed_by_name' => $actorName,
                'updated_by_name' => $actorName,
            ]);
        });

        return response()->json([
            'data' => $this->detailResource(
                $foodMenuProduction->fresh(['location', 'foodMenu', 'unit', 'details.ingredient', 'details.unit'])
            ),
        ]);
    }

    public function exportExcel(Request $request)
    {
        $query = FoodMenuProduction::query()->with(['location', 'foodMenu', 'unit', 'details']);
        $this->applyFilters($query, $request->all());
        $records = $query->orderByDesc('production_date')->orderByDesc('id')->get();
        $escape = static fn ($value): string => '"'.str_replace('"', '""', (string) ($value ?? '')).'"';
        $output = "\xEF\xBB\xBF".implode(',', ['Reference', 'Production Date', 'Outlet', 'Food Menu', 'Production Qty', 'Unit', 'Ingredient Items', 'Total Cost', 'Cost Per Unit', 'Status', 'Created By'])."\n";
        foreach ($records as $record) {
            $output .= implode(',', [
                $escape($record->ref_no),
                $escape($record->production_date?->toDateString()),
                $escape($record->location?->name),
                $escape($record->foodMenu?->name),
                $record->production_qty,
                $escape($record->unit?->name),
                $record->details->count(),
                $record->total_ingredient_cost,
                $record->production_cost_per_unit,
                $escape($record->status),
                $escape($record->created_by_name),
            ])."\n";
        }

        return response($output, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="food_menu_production_report_'.date('Y-m-d').'.csv"',
        ]);
    }

    public function exportPdf(Request $request)
    {
        $query = FoodMenuProduction::query()->with(['location', 'foodMenu', 'unit', 'details']);
        $this->applyFilters($query, $request->all());
        $records = $query->orderByDesc('production_date')->orderByDesc('id')->get();
        $html = '<!doctype html><html><head><meta charset="UTF-8"><title>Food Menu Production Report</title><style>body{font-family:Arial,sans-serif;color:#0f172a;margin:24px}h1{color:#2563eb}table{width:100%;border-collapse:collapse}th,td{border:1px solid #dbe3ef;padding:8px;text-align:left}th{background:#2563eb;color:#fff}tr:nth-child(even){background:#f8fafc}</style></head><body><h1>Food Menu Production Report</h1><p>Generated '.date('Y-m-d H:i:s').' · Total '.count($records).'</p><table><thead><tr><th>Reference</th><th>Date</th><th>Outlet</th><th>Food Menu</th><th>Qty</th><th>Ingredient Items</th><th>Total Cost</th><th>Unit Cost</th><th>Status</th></tr></thead><tbody>';
        foreach ($records as $record) {
            $html .= '<tr><td>'.e($record->ref_no).'</td><td>'.e($record->production_date?->toDateString()).'</td><td>'.e($record->location?->name ?? '-').'</td><td>'.e($record->foodMenu?->name ?? '-').'</td><td>'.e($record->production_qty).' '.e($record->unit?->name ?? '').'</td><td>'.$record->details->count().'</td><td>'.number_format((float) $record->total_ingredient_cost, 2).'</td><td>'.number_format((float) $record->production_cost_per_unit, 2).'</td><td>'.e($record->status).'</td></tr>';
        }
        $html .= '</tbody></table></body></html>';

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="food_menu_production_report_'.date('Y-m-d').'.html"',
        ]);
    }

    private function applyFilters(Builder $query, array $payload): void
    {
        $query
            ->when(isset($payload['date_from']), function (Builder $q) use ($payload): void {
                $q->whereDate('production_date', '>=', (string) $payload['date_from']);
            })
            ->when(isset($payload['date_to']), function (Builder $q) use ($payload): void {
                $q->whereDate('production_date', '<=', (string) $payload['date_to']);
            })
            ->when(isset($payload['location_id']), function (Builder $q) use ($payload): void {
                $q->where('location_id', (int) $payload['location_id']);
            })
            ->when(isset($payload['food_menu_id']), function (Builder $q) use ($payload): void {
                $q->where('food_menu_id', (int) $payload['food_menu_id']);
            })
            ->when(isset($payload['ingredient_id']), function (Builder $q) use ($payload): void {
                $q->whereHas('details', function (Builder $detailQuery) use ($payload): void {
                    $detailQuery->where('ingredient_id', (int) $payload['ingredient_id']);
                });
            })
            ->when(isset($payload['created_by_name']) && trim((string) $payload['created_by_name']) !== '', function (Builder $q) use ($payload): void {
                $q->where('created_by_name', 'like', '%'.trim((string) $payload['created_by_name']).'%');
            })
            ->when(isset($payload['status']), function (Builder $q) use ($payload): void {
                $q->where('status', (string) $payload['status']);
            })
            ->when(isset($payload['search']) && trim((string) $payload['search']) !== '', function (Builder $q) use ($payload): void {
                $search = trim((string) $payload['search']);
                $q->where(function (Builder $qq) use ($search): void {
                    $qq->where('ref_no', 'like', "%{$search}%")
                        ->orWhere('note', 'like', "%{$search}%")
                        ->orWhere('created_by_name', 'like', "%{$search}%")
                        ->orWhereHas('location', fn (Builder $locationQuery) => $locationQuery->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('foodMenu', function (Builder $foodMenuQuery) use ($search): void {
                            $foodMenuQuery->where('name', 'like', "%{$search}%")
                                ->orWhere('code', 'like', "%{$search}%");
                        })
                        ->orWhereHas('details', function (Builder $detailQuery) use ($search): void {
                            $detailQuery->where('ingredient_name_snapshot', 'like', "%{$search}%");
                        });
                });
            });
    }

    private function buildProductionPreview(
        int $locationId,
        int $foodMenuId,
        float $productionQty,
        Carbon $productionDate
    ): array {
        $foodMenu = FoodMenu::query()
            ->with(['unit', 'ingredientMappings.ingredient.consumptionUnit', 'ingredientMappings.unit'])
            ->findOrFail($foodMenuId);

        if ($foodMenu->stock_deduction_method !== 'production_stock') {
            abort(422, 'This food menu is not a production stock food menu.');
        }

        $mappings = $foodMenu->ingredientMappings;
        if ($mappings->isEmpty()) {
            abort(422, 'This food menu has no ingredient mapping.');
        }

        $details = [];
        $stockSufficient = true;
        $stockMessage = null;

        foreach ($mappings as $mapping) {
            $ingredient = $mapping->ingredient;
            if ($ingredient === null) {
                continue;
            }

            $requiredQty = round($productionQty * (float) $mapping->required_qty, 4);
            if ($requiredQty <= 0) {
                abort(422, 'Required ingredient qty must be greater than 0.');
            }

            $unitCost = round((float) $ingredient->cost_per_consumption_unit, 4);
            $amount = round($requiredQty * $unitCost, 4);

            $availableQty = round($this->currentIngredientStockForLocation($ingredient, $locationId), 4);
            $isStockSufficient = $availableQty + 0.000001 >= $requiredQty;

            if (! $isStockSufficient) {
                $stockSufficient = false;
                $unitName = $mapping->unit?->name ?? $ingredient->consumptionUnit?->name ?? '';
                $stockMessage ??= sprintf(
                    'Insufficient stock for %s. Required: %s %s, Available: %s %s.',
                    $ingredient->name,
                    $this->formatNumber($requiredQty),
                    $unitName,
                    $this->formatNumber($availableQty),
                    $unitName
                );
            }

            $details[] = [
                'ingredient_id' => $ingredient->id,
                'ingredient_name' => $ingredient->name,
                'required_qty' => $requiredQty,
                'unit_id' => $mapping->unit_id,
                'unit_name' => $mapping->unit?->name ?? $ingredient->consumptionUnit?->name ?? '',
                'unit_cost' => $unitCost,
                'amount' => $amount,
                'available_qty' => $availableQty,
                'is_stock_sufficient' => $isStockSufficient,
            ];
        }

        $totalIngredientCost = round(collect($details)->sum('amount'), 4);
        $productionCostPerUnit = $productionQty > 0 ? round($totalIngredientCost / $productionQty, 4) : 0;

        return [
            'location_id' => $locationId,
            'food_menu_id' => $foodMenu->id,
            'food_menu_name' => $foodMenu->name,
            'production_qty' => round($productionQty, 4),
            'production_date' => $productionDate->toDateString(),
            'ref_no_preview' => $this->nextRefNo($productionDate),
            'unit_id' => $foodMenu->unit_id,
            'unit_name' => $foodMenu->unit?->name ?? '',
            'stock_sufficient' => $stockSufficient,
            'stock_message' => $stockMessage,
            'details' => $details,
            'total_ingredient_cost' => $totalIngredientCost,
            'production_cost_per_unit' => $productionCostPerUnit,
        ];
    }

    private function createIngredientStockMovement(
        int $ingredientId,
        int $locationId,
        string $direction,
        string $reasonCode,
        string $unitType,
        float $quantityInput,
        float $quantityConsumption,
        ?string $reference,
        ?string $note,
        Carbon $occurredAt
    ): void {
        IngredientStockMovement::query()->create([
            'ingredient_id' => $ingredientId,
            'location_id' => $locationId,
            'direction' => $direction,
            'reason_code' => $reasonCode,
            'unit_type' => $unitType,
            'quantity_input' => round($quantityInput, 4),
            'quantity_consumption' => round($quantityConsumption, 4),
            'reference' => $reference,
            'note' => $note,
            'occurred_at' => $occurredAt,
        ]);
    }

    private function currentIngredientStockForLocation(Ingredient $ingredient, int $locationId): float
    {
        $initial = $this->initialStockForLocation($ingredient, $locationId);
        $movementNet = (float) IngredientStockMovement::withoutGlobalScopes()
            ->where('ingredient_id', $ingredient->id)
            ->where('location_id', $locationId)
            ->selectRaw("
                COALESCE(SUM(CASE WHEN LOWER(direction) = 'in' THEN quantity_consumption ELSE -quantity_consumption END), 0) AS net
            ")
            ->value('net');

        return round($initial + $movementNet, 4);
    }

    private function currentFoodMenuStockForLocation(int $foodMenuId, int $locationId): float
    {
        return round((float) IngredientStockMovement::withoutGlobalScopes()
            ->where('food_menu_id', $foodMenuId)
            ->where('location_id', $locationId)
            ->selectRaw("COALESCE(SUM(CASE WHEN LOWER(direction) = 'in' THEN quantity_consumption ELSE -quantity_consumption END), 0) AS net")
            ->value('net'), 4);
    }

    private function initialStockForLocation(Ingredient $ingredient, int $locationId): float
    {
        $data = is_array($ingredient->initial_stock_data) ? $ingredient->initial_stock_data : [];
        foreach ($data as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            if ((int) ($entry['location_id'] ?? 0) === $locationId) {
                return (float) ($entry['quantity'] ?? 0);
            }
        }

        return 0;
    }

    private function listResource(FoodMenuProduction $production): array
    {
        return [
            'id' => $production->id,
            'ref_no' => $production->ref_no,
            'production_date' => $production->production_date?->toDateString(),
            'location_id' => $production->location_id,
            'location_name' => $production->location?->name,
            'food_menu_id' => $production->food_menu_id,
            'food_menu_name' => $production->foodMenu?->name,
            'production_qty' => round((float) $production->production_qty, 4),
            'unit_id' => $production->unit_id,
            'unit_name' => $production->unit?->name ?? '',
            'total_ingredient_cost' => round((float) $production->total_ingredient_cost, 4),
            'production_cost_per_unit' => round((float) $production->production_cost_per_unit, 4),
            'status' => $production->status,
            'note' => $production->note,
            'created_by_name' => $production->created_by_name ?: 'System',
            'created_at' => $production->created_at?->toIso8601String(),
            'updated_at' => $production->updated_at?->toIso8601String(),
            'can_reverse' => $production->status === 'completed',
        ];
    }

    private function detailResource(FoodMenuProduction $production): array
    {
        return [
            ...$this->listResource($production),
            'updated_by_name' => $production->updated_by_name,
            'reversed_by_name' => $production->reversed_by_name,
            'reversed_at' => $production->reversed_at?->toIso8601String(),
            'reverse_note' => $production->reverse_note,
            'details' => $production->details->map(function ($detail): array {
                return [
                    'id' => $detail->id,
                    'ingredient_id' => $detail->ingredient_id,
                    'ingredient_name' => $detail->ingredient_name_snapshot,
                    'required_qty' => round((float) $detail->required_qty, 4),
                    'unit_id' => $detail->unit_id,
                    'unit_name' => $detail->unit_name_snapshot,
                    'unit_cost' => round((float) $detail->unit_cost_snapshot, 4),
                    'amount' => round((float) $detail->amount, 4),
                ];
            })->values()->all(),
        ];
    }

    private function nextRefNo(Carbon $productionDate): string
    {
        $datePart = $productionDate->format('Ymd');
        $next = FoodMenuProduction::query()
            ->whereDate('production_date', $productionDate->toDateString())
            ->count() + 1;

        return sprintf('FMP%s%s', $datePart, str_pad((string) $next, 4, '0', STR_PAD_LEFT));
    }

    private function fifoCost(mixed $result): float
    {
        if (is_array($result)) {
            return round((float) ($result['total_cost'] ?? 0), 4);
        }

        return round((float) $result, 4);
    }

    private function formatNumber(float $value): string
    {
        $formatted = number_format($value, 4, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }
}
