<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\IngredientProcessing;
use App\Models\IngredientStockMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class IngredientProcessingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'date_basis' => ['nullable', 'string', Rule::in(['processing_date', 'created_at'])],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'location_ids' => ['nullable', 'string', 'max:500'],
            'output_ingredient_id' => ['nullable', 'integer', 'exists:ingredients,id'],
            'output_ingredient_ids' => ['nullable', 'string', 'max:500'],
            'input_ingredient_id' => ['nullable', 'integer', 'exists:ingredients,id'],
            'input_ingredient_ids' => ['nullable', 'string', 'max:500'],
            'created_by_name' => ['nullable', 'string', 'max:120'],
            'created_by' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', Rule::in(['posted', 'completed', 'reversed', 'cancelled'])],
            'statuses' => ['nullable', 'string', 'max:200'],
            'ref_no' => ['nullable', 'string', 'max:120'],
            'search' => ['nullable', 'string', 'max:120'],
            'sort_col' => ['nullable', 'string', Rule::in(['processing_date', 'ref_no', 'created_at', 'total_input_cost', 'processing_qty'])],
            'sort_dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer'],
        ]);

        $sortCol = $payload['sort_col'] ?? 'processing_date';
        $sortDir = $payload['sort_dir'] ?? 'desc';

        $query = IngredientProcessing::query()
            ->with(['location', 'outputIngredient', 'details']);
        $this->applyFilters($query, $payload);
        $query
            ->orderBy($sortCol, $sortDir)
            ->orderBy('id', 'desc');

        $perPage = (int) ($payload['per_page'] ?? 20);
        $perPage = in_array($perPage, [10, 20, 30, 50, 100], true) ? $perPage : 20;

        $records = $query->paginate($perPage)->through(
            fn (IngredientProcessing $processing): array => $this->listResource($processing)
        );

        $today = Carbon::today();
        $startOfMonth = Carbon::today()->startOfMonth();
        $endOfMonth = Carbon::today()->endOfMonth();

        $filteredQuery = IngredientProcessing::query();
        $this->applyFilters($filteredQuery, $payload);

        $summaryBase = IngredientProcessing::query();
        $summaryPayload = $payload;
        unset($summaryPayload['status'], $summaryPayload['statuses']);
        $this->applyFilters($summaryBase, $summaryPayload);
        $completedQuery = (clone $summaryBase)->whereIn('status', ['posted', 'completed']);
        $cancelledQuery = (clone $summaryBase)->whereIn('status', ['reversed', 'cancelled']);
        $completedCount = (int) (clone $completedQuery)->count();
        $completedCost = round((float) (clone $completedQuery)->sum('total_input_cost'), 4);
        $outputGroups = (clone $completedQuery)
            ->selectRaw('output_ingredient_id, output_unit, SUM(processing_qty) as total_qty, COUNT(*) as batch_count')
            ->groupBy('output_ingredient_id', 'output_unit')
            ->get()
            ->map(fn ($row): array => [
                'output_ingredient_id' => (int) $row->output_ingredient_id,
                'output_unit' => $row->output_unit,
                'total_qty' => round((float) $row->total_qty, 4),
                'batch_count' => (int) $row->batch_count,
            ])->values()->all();
        $totalInputItems = (int) (clone $completedQuery)->withCount('details')->get()->sum('details_count');

        return response()->json([
            'data' => [
                'summary' => [
                    'today_processing_amount' => round(
                        (float) (clone $summaryBase)
                            ->whereIn('status', ['posted', 'completed'])
                            ->whereDate('processing_date', $today)
                            ->sum('total_input_cost'),
                        4
                    ),
                    'this_month_processing_amount' => round(
                        (float) (clone $summaryBase)
                            ->whereIn('status', ['posted', 'completed'])
                            ->whereBetween('processing_date', [$startOfMonth->toDateString(), $endOfMonth->toDateString()])
                            ->sum('total_input_cost'),
                        4
                    ),
                    'all_time_processing_amount' => round(
                        (float) (clone $summaryBase)
                            ->whereIn('status', ['posted', 'completed'])
                            ->sum('total_input_cost'),
                        4
                    ),
                    'filtered_processing_amount' => round((float) (clone $filteredQuery)->sum('total_input_cost'), 4),
                    'total_processing_count' => (int) (clone $filteredQuery)->count(),
                    'total_output_qty' => round((float) (clone $filteredQuery)->sum('processing_qty'), 4),
                    'average_output_unit_cost' => $this->averageOutputUnitCost($filteredQuery),
                    'completed_count' => $completedCount,
                    'cancelled_count' => (int) (clone $cancelledQuery)->count(),
                    'completed_input_cost' => $completedCost,
                    'unique_outputs' => (int) (clone $completedQuery)->distinct('output_ingredient_id')->count('output_ingredient_id'),
                    'total_input_items' => $totalInputItems,
                    'output_groups' => $outputGroups,
                ],
                'records' => $records,
            ],
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'processing_date' => ['nullable', 'date'],
            'output_ingredient_id' => ['required', 'integer', 'exists:ingredients,id'],
            'processing_qty' => ['required', 'numeric', 'gt:0'],
            'processing_unit_type' => ['nullable', 'string', 'in:purchase,consumption'],
        ]);

        $processingDate = isset($payload['processing_date'])
            ? Carbon::parse((string) $payload['processing_date'])
            : Carbon::today();

        $processingUnitType = $payload['processing_unit_type'] ?? 'consumption';

        return response()->json([
            'data' => $this->buildPreview(
                (int) $payload['location_id'],
                (int) $payload['output_ingredient_id'],
                (float) $payload['processing_qty'],
                $processingDate,
                $processingUnitType
            ),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'processing_date' => ['required', 'date'],
            'output_ingredient_id' => ['required', 'integer', 'exists:ingredients,id'],
            'processing_qty' => ['required', 'numeric', 'gt:0'],
            'processing_unit_type' => ['nullable', 'string', 'in:purchase,consumption'],
            'note' => ['nullable', 'string', 'max:500'],
            'actor_name' => ['nullable', 'string', 'max:120'],
        ]);

        $processingDate = Carbon::parse((string) $payload['processing_date']);
        $actorName = trim((string) ($payload['actor_name'] ?? '')) ?: 'System';
        $processingUnitType = $payload['processing_unit_type'] ?? 'consumption';

        $preview = $this->buildPreview(
            (int) $payload['location_id'],
            (int) $payload['output_ingredient_id'],
            (float) $payload['processing_qty'],
            $processingDate,
            $processingUnitType
        );

        if (! $preview['stock_sufficient']) {
            abort(422, $preview['stock_message'] ?? 'Insufficient stock for processing.');
        }

        $processing = DB::transaction(function () use ($payload, $preview, $processingDate, $actorName, $processingUnitType): IngredientProcessing {
            $processing = IngredientProcessing::query()->create([
                'ref_no' => $this->nextRefNo($processingDate),
                'processing_date' => $processingDate->toDateString(),
                'location_id' => (int) $payload['location_id'],
                'output_ingredient_id' => (int) $payload['output_ingredient_id'],
                'output_ingredient_name' => $preview['output_ingredient_name'],
                'processing_qty' => $preview['processing_qty'],
                'output_unit' => $preview['output_unit'],
                'total_input_cost' => $preview['total_input_cost'],
                'output_unit_cost' => $preview['output_unit_cost'],
                'status' => 'posted',
                'note' => $payload['note'] ?? null,
                'created_by_name' => $actorName,
                'updated_by_name' => $actorName,
            ]);

            $fifoService = app(\App\Services\FifoInventoryService::class);

            foreach ($preview['details'] as $detail) {
                $processing->details()->create([
                    'input_ingredient_id' => $detail['input_ingredient_id'],
                    'input_ingredient_name' => $detail['input_ingredient_name'],
                    'input_qty' => $detail['required_qty'],
                    'input_qty_consumption' => $detail['required_qty_consumption'],
                    'input_unit' => $detail['input_unit'],
                    'input_unit_type' => $detail['input_unit_type'],
                    'input_unit_cost' => $detail['input_unit_cost'],
                    'input_amount' => $detail['input_amount'],
                ]);

                $fifoService->consumeStock(
                    ingredientId: (int) $detail['input_ingredient_id'],
                    locationId: (int) $payload['location_id'],
                    quantity: (float) $detail['required_qty_consumption'],
                    direction: 'OUT',
                    reasonCode: 'processing_out',
                    reference: $processing->ref_no,
                    note: $payload['note'] ?? null
                );
            }

            // Keep the stock-in conversion identical to the preview calculation.
            $outputConversionRate = (float) ($preview['output_conversion_rate'] ?? 1);
            $qtyConsumption = $processingUnitType === 'purchase'
                ? $preview['processing_qty'] * $outputConversionRate
                : $preview['processing_qty'];

            $outputUnitCost = $qtyConsumption > 0 ? ($preview['total_input_cost'] / $qtyConsumption) : 0.0;

            $batch = \App\Models\IngredientBatch::create([
                'ingredient_id' => (int) $payload['output_ingredient_id'],
                'location_id' => (int) $payload['location_id'],
                'original_qty' => $qtyConsumption,
                'usable_qty' => $qtyConsumption,
                'unit_cost' => $outputUnitCost,
                'received_at' => $processingDate->toDateTimeString(),
            ]);

            IngredientStockMovement::create([
                'ingredient_id' => (int) $payload['output_ingredient_id'],
                'location_id' => (int) $payload['location_id'],
                'ingredient_batch_id' => $batch->id,
                'direction' => 'IN',
                'reason_code' => 'processing_in',
                'unit_type' => $processingUnitType,
                'quantity_input' => (float) $preview['processing_qty'],
                'quantity_consumption' => (float) $qtyConsumption,
                'batch_unit_cost' => $outputUnitCost,
                'reference' => $processing->ref_no,
                'note' => $payload['note'] ?? null,
                'occurred_at' => $processingDate->toDateTimeString(),
            ]);

            return $processing->fresh(['location', 'outputIngredient', 'details.inputIngredient']);
        });

        return response()->json(['data' => $this->detailResource($processing)], 201);
    }

    public function show(IngredientProcessing $ingredientProcessing): JsonResponse
    {
        return response()->json([
            'data' => $this->detailResource(
                $ingredientProcessing->load(['location', 'outputIngredient', 'details.inputIngredient'])
            ),
        ]);
    }

    public function reverse(Request $request, IngredientProcessing $ingredientProcessing): JsonResponse
    {
        $payload = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
            'actor_name' => ['nullable', 'string', 'max:120'],
        ]);

        if (! in_array($ingredientProcessing->status, ['posted', 'completed'], true)) {
            abort(422, 'Only posted processing can be reversed.');
        }

        $ingredientProcessing->loadMissing([
            'location',
            'outputIngredient.purchaseUnit',
            'outputIngredient.consumptionUnit',
            'details.inputIngredient'
        ]);

        $outputIngredient = $ingredientProcessing->outputIngredient;
        $outputConversionRate = (float) ($outputIngredient?->conversion_rate ?? 1);

        $isPurchaseUnit = false;
        if ($outputIngredient && $ingredientProcessing->output_unit) {
            $purchaseUnitName = $outputIngredient->purchaseUnit?->name;
            if ($purchaseUnitName && $ingredientProcessing->output_unit === $purchaseUnitName) {
                $isPurchaseUnit = true;
            }
        }

        $qtyConsumption = $isPurchaseUnit
            ? $ingredientProcessing->processing_qty * $outputConversionRate
            : $ingredientProcessing->processing_qty;

        $availableOutputStock = $this->currentStockForLocation(
            $outputIngredient,
            (int) $ingredientProcessing->location_id
        );

        if ($availableOutputStock + 0.000001 < $qtyConsumption) {
            $unit = $ingredientProcessing->output_unit ?: ($outputIngredient?->consumptionUnit?->name ?? '');
            $availableDisplayQty = $isPurchaseUnit && $outputConversionRate > 0
                ? $availableOutputStock / $outputConversionRate
                : $availableOutputStock;

            abort(
                422,
                sprintf(
                    'Cannot reverse %s. Required: %s %s, Available: %s %s.',
                    $ingredientProcessing->output_ingredient_name,
                    $this->formatNumber($ingredientProcessing->processing_qty),
                    $unit,
                    $this->formatNumber($availableDisplayQty),
                    $unit
                )
            );
        }

        $actorName = trim((string) ($payload['actor_name'] ?? '')) ?: 'System';
        $reverseDate = Carbon::now();

        DB::transaction(function () use ($ingredientProcessing, $payload, $actorName, $reverseDate, $isPurchaseUnit, $qtyConsumption): void {
            foreach ($ingredientProcessing->details as $detail) {
                $this->createStockMovement(
                    ingredientId: (int) $detail->input_ingredient_id,
                    locationId: (int) $ingredientProcessing->location_id,
                    direction: 'in',
                    reasonCode: 'processing_reverse_in',
                    unitType: (string) $detail->input_unit_type,
                    quantityInput: (float) $detail->input_qty,
                    quantityConsumption: (float) $detail->input_qty_consumption,
                    reference: $ingredientProcessing->ref_no,
                    note: $payload['note'] ?? $ingredientProcessing->note,
                    occurredAt: $reverseDate
                );
            }

            $this->createStockMovement(
                ingredientId: (int) $ingredientProcessing->output_ingredient_id,
                locationId: (int) $ingredientProcessing->location_id,
                direction: 'out',
                reasonCode: 'processing_reverse_out',
                unitType: $isPurchaseUnit ? 'purchase' : 'consumption',
                quantityInput: (float) $ingredientProcessing->processing_qty,
                quantityConsumption: (float) $qtyConsumption,
                reference: $ingredientProcessing->ref_no,
                note: $payload['note'] ?? $ingredientProcessing->note,
                occurredAt: $reverseDate
            );

            $ingredientProcessing->update([
                'status' => 'reversed',
                'reverse_note' => $payload['note'] ?? null,
                'reversed_at' => $reverseDate,
                'reversed_by_name' => $actorName,
                'updated_by_name' => $actorName,
            ]);
        });

        return response()->json([
            'data' => $this->detailResource(
                $ingredientProcessing->fresh(['location', 'outputIngredient', 'details.inputIngredient'])
            ),
        ]);
    }

    public function exportExcel(Request $request)
    {
        $query = IngredientProcessing::query()->with(['location', 'outputIngredient', 'details']);
        $this->applyFilters($query, $request->all());
        $records = $query->orderByDesc('processing_date')->orderByDesc('id')->get();
        $escape = static fn ($value): string => '"'.str_replace('"', '""', (string) ($value ?? '')).'"';
        $output = "\xEF\xBB\xBF".implode(',', ['Reference', 'Processing Date', 'Outlet', 'Output Ingredient', 'Output Qty', 'Output Unit', 'Input Items', 'Total Input Cost', 'Output Unit Cost', 'Status', 'Created By'])."\n";
        foreach ($records as $record) {
            $output .= implode(',', [
                $escape($record->ref_no),
                $escape($record->processing_date),
                $escape($record->location?->name),
                $escape($record->output_ingredient_name),
                $record->processing_qty,
                $escape($record->output_unit),
                $record->details->count(),
                $record->total_input_cost,
                $record->output_unit_cost,
                $escape($record->status),
                $escape($record->created_by_name),
            ])."\n";
        }
        return response($output, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="ingredient_processing_report_'.date('Y-m-d').'.csv"',
        ]);
    }

    public function exportPdf(Request $request)
    {
        $query = IngredientProcessing::query()->with(['location', 'details']);
        $this->applyFilters($query, $request->all());
        $records = $query->orderByDesc('processing_date')->orderByDesc('id')->get();
        $html = '<!doctype html><html><head><meta charset="UTF-8"><title>Ingredient Processing Report</title><style>body{font-family:Arial,sans-serif;color:#0f172a;margin:24px}h1{color:#2563eb}table{width:100%;border-collapse:collapse}th,td{border:1px solid #dbe3ef;padding:8px;text-align:left}th{background:#2563eb;color:#fff}tr:nth-child(even){background:#f8fafc}</style></head><body><h1>Ingredient Processing Report</h1><p>Generated '.date('Y-m-d H:i:s').' · Total '.count($records).'</p><table><thead><tr><th>Reference</th><th>Date</th><th>Outlet</th><th>Output</th><th>Qty</th><th>Input Items</th><th>Total Cost</th><th>Unit Cost</th><th>Status</th></tr></thead><tbody>';
        foreach ($records as $record) {
            $html .= '<tr><td>'.e($record->ref_no).'</td><td>'.e($record->processing_date).'</td><td>'.e($record->location?->name ?? '-').'</td><td>'.e($record->output_ingredient_name).'</td><td>'.e($record->processing_qty).' '.e($record->output_unit).'</td><td>'.$record->details->count().'</td><td>'.number_format((float) $record->total_input_cost, 2).'</td><td>'.number_format((float) $record->output_unit_cost, 2).'</td><td>'.e($record->status).'</td></tr>';
        }
        $html .= '</tbody></table></body></html>';
        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="ingredient_processing_report_'.date('Y-m-d').'.html"',
        ]);
    }

    private function applyFilters(Builder $query, array $payload): void
    {
        $query
            ->when(isset($payload['date_from']), function (Builder $q) use ($payload): void {
                $q->whereDate('processing_date', '>=', (string) $payload['date_from']);
            })
            ->when(isset($payload['date_to']), function (Builder $q) use ($payload): void {
                $q->whereDate('processing_date', '<=', (string) $payload['date_to']);
            })
            ->when(isset($payload['location_id']), function (Builder $q) use ($payload): void {
                $q->where('location_id', (int) $payload['location_id']);
            })
            ->when(isset($payload['output_ingredient_id']), function (Builder $q) use ($payload): void {
                $q->where('output_ingredient_id', (int) $payload['output_ingredient_id']);
            })
            ->when(isset($payload['input_ingredient_id']), function (Builder $q) use ($payload): void {
                $q->whereHas('details', function (Builder $detailQuery) use ($payload): void {
                    $detailQuery->where('input_ingredient_id', (int) $payload['input_ingredient_id']);
                });
            })
            ->when(isset($payload['created_by_name']) && trim((string) $payload['created_by_name']) !== '', function (Builder $q) use ($payload): void {
                $q->where('created_by_name', 'like', '%'.trim((string) $payload['created_by_name']).'%');
            })
            ->when(isset($payload['status']), function (Builder $q) use ($payload): void {
                $status = (string) $payload['status'];
                if ($status === 'posted') {
                    $q->whereIn('status', ['posted', 'completed']);
                    return;
                }
                $q->where('status', $status);
            })
            ->when(isset($payload['search']) && trim((string) $payload['search']) !== '', function (Builder $q) use ($payload): void {
                $search = trim((string) $payload['search']);
                $q->where(function (Builder $qq) use ($search): void {
                    $qq->where('ref_no', 'like', "%{$search}%")
                        ->orWhere('output_ingredient_name', 'like', "%{$search}%");
                });
            });
    }

    private function buildPreview(
        int $locationId,
        int $outputIngredientId,
        float $processingQty,
        Carbon $processingDate,
        string $processingUnitType = 'consumption'
    ): array {
        $outputIngredient = Ingredient::query()
            ->with([
                'consumptionUnit',
                'purchaseUnit',
                'compositions.child.consumptionUnit',
                'compositions.child.purchaseUnit',
                'compositions.child.compositions',
            ])
            ->findOrFail($outputIngredientId);

        if (! $outputIngredient->has_ingredient_mapping || $outputIngredient->type !== 'composite') {
            abort(422, 'This ingredient has no ingredient mapping and cannot be processed.');
        }

        $compositions = $outputIngredient->compositions;
        if ($compositions->isEmpty()) {
            abort(422, 'Input ingredient mapping must not be empty.');
        }

        $outputConversionRate = (float) ($outputIngredient->conversion_rate ?? 1);
        $effectiveProcessingQty = $processingUnitType === 'purchase'
            ? $processingQty * $outputConversionRate
            : $processingQty;

        $seenChildIds = [];
        $details = [];
        $stockSufficient = true;
        $stockMessage = null;

        foreach ($compositions as $composition) {
            $child = $composition->child;
            if ($child === null) {
                continue;
            }

            if ($child->id === $outputIngredient->id) {
                abort(422, 'Output ingredient cannot be the same as input ingredient.');
            }

            if (in_array($child->id, $seenChildIds, true)) {
                abort(422, 'Duplicate input ingredient rows are not allowed.');
            }
            $seenChildIds[] = $child->id;

            if ($this->ingredientDependsOn($child->id, $outputIngredient->id)) {
                abort(422, 'Circular mapping is not allowed.');
            }

            $unitType = $composition->unit_type === 'purchase' ? 'purchase' : 'consumption';
            $requiredQty = round($effectiveProcessingQty * (float) $composition->quantity, 4);
            if ($requiredQty <= 0) {
                abort(422, 'Required input qty must be greater than 0.');
            }

            $conversionRate = (float) $child->conversion_rate;
            if ($unitType === 'purchase' && $conversionRate <= 0) {
                abort(422, sprintf('Conversion rate must be greater than 0 for %s.', $child->name));
            }

            $requiredQtyConsumption = $unitType === 'purchase'
                ? round($requiredQty * $conversionRate, 4)
                : $requiredQty;

            $unitName = $unitType === 'purchase'
                ? ($child->purchaseUnit?->name ?? 'Purchase Unit')
                : ($child->consumptionUnit?->name ?? 'Consumption Unit');

            $unitCost = $unitType === 'purchase'
                ? round((float) $child->purchase_price, 4)
                : round((float) $child->cost_per_consumption_unit, 4);

            if ($unitCost < 0) {
                abort(422, sprintf('Input ingredient unit cost must be greater than or equal 0 for %s.', $child->name));
            }

            $inputAmount = round($requiredQty * $unitCost, 4);
            $availableQtyConsumption = round($this->currentStockForLocation($child, $locationId), 4);
            $availableQty = $unitType === 'purchase' && $conversionRate > 0
                ? round($availableQtyConsumption / $conversionRate, 4)
                : $availableQtyConsumption;

            $isStockSufficient = $availableQtyConsumption + 0.000001 >= $requiredQtyConsumption;
            if (! $isStockSufficient) {
                $stockSufficient = false;
                $stockMessage ??= sprintf(
                    'Insufficient stock for %s. Required: %s %s, Available: %s %s.',
                    $child->name,
                    $this->formatNumber($requiredQty),
                    $unitName,
                    $this->formatNumber($availableQty),
                    $unitName
                );
            }

            $details[] = [
                'input_ingredient_id' => $child->id,
                'input_ingredient_name' => $child->name,
                'required_qty' => $requiredQty,
                'required_qty_consumption' => $requiredQtyConsumption,
                'input_unit' => $unitName,
                'input_unit_type' => $unitType,
                'input_unit_cost' => $unitCost,
                'input_amount' => $inputAmount,
                'available_qty' => $availableQty,
                'available_qty_consumption' => $availableQtyConsumption,
                'is_stock_sufficient' => $isStockSufficient,
            ];
        }

        $totalInputCost = round(collect($details)->sum('input_amount'), 4);
        $outputUnitCost = $effectiveProcessingQty > 0
            ? round($totalInputCost / $effectiveProcessingQty, 4)
            : 0;

        $outputUnit = $processingUnitType === 'purchase'
            ? ($outputIngredient->purchaseUnit?->name ?? 'Purchase Unit')
            : ($outputIngredient->consumptionUnit?->name ?? 'Consumption Unit');

        return [
            'ref_no_preview' => $this->nextRefPreview($processingDate),
            'processing_date' => $processingDate->toDateString(),
            'location_id' => $locationId,
            'output_ingredient_id' => $outputIngredient->id,
            'output_ingredient_name' => $outputIngredient->name,
            'processing_qty' => round($processingQty, 4),
            'output_conversion_rate' => $outputConversionRate,
            'output_unit' => $outputUnit,
            'stock_sufficient' => $stockSufficient,
            'stock_message' => $stockMessage,
            'details' => $details,
            'total_input_cost' => $totalInputCost,
            'output_unit_cost' => $outputUnitCost,
        ];
    }

    private function createStockMovement(
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

    private function currentStockForLocation(Ingredient $ingredient, int $locationId): float
    {
        $initial = $this->initialStockForLocation($ingredient, $locationId);
        $movementNet = (float) IngredientStockMovement::query()
            ->where('ingredient_id', $ingredient->id)
            ->where('location_id', $locationId)
            ->selectRaw("
                COALESCE(SUM(CASE WHEN LOWER(direction) = 'in' THEN quantity_consumption ELSE -quantity_consumption END), 0) AS net
            ")
            ->value('net');

        return round($initial + $movementNet, 4);
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

    private function ingredientDependsOn(int $ingredientId, int $targetIngredientId, array $visited = []): bool
    {
        if (in_array($ingredientId, $visited, true)) {
            return false;
        }

        $visited[] = $ingredientId;

        $childIds = Ingredient::query()
            ->find($ingredientId)
            ?->compositions()
            ->pluck('child_ingredient_id')
            ->map(fn ($id): int => (int) $id)
            ->all() ?? [];

        foreach ($childIds as $childId) {
            if ($childId === $targetIngredientId) {
                return true;
            }

            if ($this->ingredientDependsOn($childId, $targetIngredientId, $visited)) {
                return true;
            }
        }

        return false;
    }

    private function listResource(IngredientProcessing $processing): array
    {
        return [
            'id' => $processing->id,
            'ref_no' => $processing->ref_no,
            'processing_date' => $processing->processing_date?->toDateString(),
            'location_id' => $processing->location_id,
            'location_name' => $processing->location?->name,
            'output_ingredient_id' => $processing->output_ingredient_id,
            'output_ingredient_name' => $processing->output_ingredient_name,
            'processing_qty' => round((float) $processing->processing_qty, 4),
            'output_unit' => $processing->output_unit,
            'total_input_cost' => round((float) $processing->total_input_cost, 4),
            'output_unit_cost' => round((float) $processing->output_unit_cost, 4),
            // `completed` was used by the original release. Keep old rows
            // operable while exposing the requirement's canonical POSTED state.
            'status' => $processing->status === 'completed' ? 'posted' : $processing->status,
            'note' => $processing->note,
            'created_by_name' => $processing->created_by_name ?: 'System',
            'created_at' => $processing->created_at?->toIso8601String(),
            'updated_at' => $processing->updated_at?->toIso8601String(),
            'can_reverse' => in_array($processing->status, ['posted', 'completed'], true),
        ];
    }

    private function detailResource(IngredientProcessing $processing): array
    {
        return [
            ...$this->listResource($processing),
            'updated_by_name' => $processing->updated_by_name,
            'reversed_by_name' => $processing->reversed_by_name,
            'reversed_at' => $processing->reversed_at?->toIso8601String(),
            'reverse_note' => $processing->reverse_note,
            'details' => $processing->details->map(function ($detail): array {
                return [
                    'id' => $detail->id,
                    'input_ingredient_id' => $detail->input_ingredient_id,
                    'input_ingredient_name' => $detail->input_ingredient_name,
                    'input_qty' => round((float) $detail->input_qty, 4),
                    'input_qty_consumption' => round((float) $detail->input_qty_consumption, 4),
                    'input_unit' => $detail->input_unit,
                    'input_unit_type' => $detail->input_unit_type,
                    'input_unit_cost' => round((float) $detail->input_unit_cost, 4),
                    'input_amount' => round((float) $detail->input_amount, 4),
                ];
            })->values()->all(),
        ];
    }

    private function nextRefNo(Carbon $processingDate): string
    {
        $datePart = $processingDate->format('Ymd');
        $next = IngredientProcessing::query()
            ->whereDate('processing_date', $processingDate->toDateString())
            ->count() + 1;

        return sprintf('PRC%s%s', $datePart, str_pad((string) $next, 4, '0', STR_PAD_LEFT));
    }

    private function nextRefPreview(Carbon $processingDate): string
    {
        return $this->nextRefNo($processingDate);
    }

    private function averageOutputUnitCost(Builder $query): float
    {
        $filteredAmount = (float) (clone $query)->sum('total_input_cost');
        $filteredQty = (float) (clone $query)->sum('processing_qty');

        if ($filteredQty <= 0) {
            return 0;
        }

        return round($filteredAmount / $filteredQty, 4);
    }

    private function formatNumber(float $value): string
    {
        $formatted = number_format($value, 4, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }
}
