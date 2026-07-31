<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\IngredientStockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class IngredientStockMovementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sortCol = in_array($request->string('sort_col')->toString(), ['occurred_at', 'created_at'], true)
            ? $request->string('sort_col')->toString()
            : 'occurred_at';
        $sortDir = $request->string('sort_dir')->toString() === 'asc' ? 'asc' : 'desc';

        $query = IngredientStockMovement::query()
            ->with(['ingredient.consumptionUnit', 'ingredient.purchaseUnit', 'location'])
            ->when($request->integer('location_id') !== null, function ($query) use ($request): void {
                $query->where('location_id', (int) $request->integer('location_id'));
            })
            ->when($request->integer('ingredient_id') !== null, function ($query) use ($request): void {
                $query->where('ingredient_id', (int) $request->integer('ingredient_id'));
            })
            ->when($request->string('direction')->isNotEmpty(), function ($query) use ($request): void {
                $dir = strtolower($request->string('direction')->toString());
                $query->whereRaw('LOWER(direction) = ?', [$dir]);
            })
            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request): void {
                $search = $request->string('search')->toString();
                $query->whereHas('ingredient', function ($q) use ($search): void {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('sku_code', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%");
                });
            })
            ->when($request->string('date_from')->isNotEmpty(), function ($query) use ($request): void {
                $from = Carbon::parse($request->string('date_from')->toString())->startOfDay();
                $query->where('occurred_at', '>=', $from);
            })
            ->when($request->string('date_to')->isNotEmpty(), function ($query) use ($request): void {
                $to = Carbon::parse($request->string('date_to')->toString())->endOfDay();
                $query->where('occurred_at', '<=', $to);
            })
            ->orderBy($sortCol, $sortDir);

        $perPage = (int) $request->integer('per_page', 20);
        $perPage = in_array($perPage, [10, 20, 30, 50, 100], true) ? $perPage : 20;

        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'ingredient_id' => ['required', 'integer', 'exists:ingredients,id'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'direction' => ['required', 'string', Rule::in(['in', 'out'])],
            'reason_code' => ['nullable', 'string', Rule::in(['purchase', 'adjustment', 'waste', 'return', 'transfer_in', 'transfer_out'])],
            'unit_type' => ['nullable', 'string', Rule::in(['purchase', 'consumption'])],
            'quantity' => ['required', 'numeric', 'min:0.0001'],
            'reference' => ['nullable', 'string', 'max:80'],
            'note' => ['nullable', 'string', 'max:500'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        $ingredient = Ingredient::query()->findOrFail((int) $payload['ingredient_id']);
        $unitType = $payload['unit_type'] ?? 'consumption';
        $qty = (float) $payload['quantity'];

        if ($unitType === 'purchase') {
            if ((float) $ingredient->conversion_rate <= 0) {
                abort(422, 'Conversion rate must be greater than 0 for purchase unit quantity.');
            }
            $qtyConsumption = $qty * (float) $ingredient->conversion_rate;
        } else {
            $qtyConsumption = $qty;
        }

        $movement = IngredientStockMovement::create([
            'ingredient_id' => (int) $payload['ingredient_id'],
            'location_id' => (int) $payload['location_id'],
            'direction' => $payload['direction'],
            'reason_code' => $payload['reason_code'] ?? null,
            'unit_type' => $unitType,
            'quantity_input' => $qty,
            'quantity_consumption' => $qtyConsumption,
            'reference' => $payload['reference'] ?? null,
            'note' => $payload['note'] ?? null,
            'occurred_at' => isset($payload['occurred_at'])
                ? Carbon::parse((string) $payload['occurred_at'])
                : Carbon::now(),
        ]);

        return response()->json(
            $movement->load(['ingredient.consumptionUnit', 'ingredient.purchaseUnit', 'location']),
            201
        );
    }

    public function destroy(IngredientStockMovement $ingredientStockMovement): JsonResponse
    {
        $ingredientStockMovement->delete();

        return response()->json(['message' => 'Stock movement deleted.']);
    }

    public function report(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'ingredient_id' => ['nullable', 'integer', 'exists:ingredients,id'],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date'],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer'],
        ]);

        $locationId = (int) $payload['location_id'];
        $from = Carbon::parse((string) $payload['date_from'])->startOfDay();
        $to = Carbon::parse((string) $payload['date_to'])->endOfDay();

        $ingredientQuery = Ingredient::query()
            ->with(['consumptionUnit', 'purchaseUnit'])
            ->when(isset($payload['ingredient_id']), function ($q) use ($payload): void {
                $q->where('id', (int) $payload['ingredient_id']);
            })
            ->when(isset($payload['search']) && trim((string) $payload['search']) !== '', function ($q) use ($payload): void {
                $search = trim((string) $payload['search']);
                $q->where(function ($qq) use ($search): void {
                    $qq->where('name', 'like', "%{$search}%")
                        ->orWhere('sku_code', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%");
                });
            })
            ->orderBy('name');

        $ingredients = $ingredientQuery->get();
        $ingredientIds = $ingredients->pluck('id')->all();

        $openingNet = IngredientStockMovement::query()
            ->selectRaw("
                ingredient_id,
                SUM(CASE WHEN LOWER(direction) = 'in' THEN quantity_consumption ELSE -quantity_consumption END) AS net
            ")
            ->where('location_id', $locationId)
            ->whereIn('ingredient_id', $ingredientIds)
            ->where('occurred_at', '<', $from)
            ->groupBy('ingredient_id')
            ->pluck('net', 'ingredient_id');

        $periodIn = IngredientStockMovement::query()
            ->selectRaw('ingredient_id, SUM(quantity_consumption) AS qty')
            ->where('location_id', $locationId)
            ->whereIn('ingredient_id', $ingredientIds)
            ->whereRaw("LOWER(direction) = 'in'")
            ->whereBetween('occurred_at', [$from, $to])
            ->groupBy('ingredient_id')
            ->pluck('qty', 'ingredient_id');

        $periodOut = IngredientStockMovement::query()
            ->selectRaw('ingredient_id, SUM(quantity_consumption) AS qty')
            ->where('location_id', $locationId)
            ->whereIn('ingredient_id', $ingredientIds)
            ->whereRaw("LOWER(direction) = 'out'")
            ->whereBetween('occurred_at', [$from, $to])
            ->groupBy('ingredient_id')
            ->pluck('qty', 'ingredient_id');

        $summaryByIngredient = $ingredients->map(function (Ingredient $ingredient) use ($locationId, $openingNet, $periodIn, $periodOut): array {
            $initial = $this->initialStockForLocation($ingredient, $locationId);
            $opening = $initial + (float) ($openingNet[$ingredient->id] ?? 0);
            $in = (float) ($periodIn[$ingredient->id] ?? 0);
            $out = (float) ($periodOut[$ingredient->id] ?? 0);
            $closing = $opening + $in - $out;

            return [
                'ingredient_id' => $ingredient->id,
                'name' => $ingredient->name,
                'sku_code' => $ingredient->sku_code,
                'barcode' => $ingredient->barcode,
                'consumption_unit' => $ingredient->consumptionUnit?->name,
                'purchase_unit' => $ingredient->purchaseUnit?->name,
                'opening' => round($opening, 4),
                'qty_in' => round($in, 4),
                'qty_out' => round($out, 4),
                'closing' => round($closing, 4),
            ];
        })->values();

        $dailySummary = IngredientStockMovement::query()
            ->selectRaw("
                DATE(occurred_at) AS date,
                SUM(CASE WHEN LOWER(direction) = 'in' THEN quantity_consumption ELSE 0 END) AS qty_in,
                SUM(CASE WHEN LOWER(direction) = 'out' THEN quantity_consumption ELSE 0 END) AS qty_out
            ")
            ->where('location_id', $locationId)
            ->whereIn('ingredient_id', $ingredientIds)
            ->whereBetween('occurred_at', [$from, $to])
            ->groupByRaw('DATE(occurred_at)')
            ->orderByRaw('DATE(occurred_at)')
            ->get();

        $movementsQuery = IngredientStockMovement::query()
            ->with(['ingredient.consumptionUnit', 'ingredient.purchaseUnit', 'location'])
            ->where('location_id', $locationId)
            ->whereIn('ingredient_id', $ingredientIds)
            ->whereBetween('occurred_at', [$from, $to])
            ->orderBy('occurred_at', 'desc');

        $perPage = (int) ($payload['per_page'] ?? 50);
        $perPage = in_array($perPage, [10, 20, 30, 50, 100], true) ? $perPage : 50;
        $movements = $movementsQuery->paginate($perPage);

        return response()->json([
            'data' => [
                'filters' => [
                    'location_id' => $locationId,
                    'ingredient_id' => $payload['ingredient_id'] ?? null,
                    'date_from' => $from->toDateString(),
                    'date_to' => $to->toDateString(),
                ],
                'summary_by_ingredient' => $summaryByIngredient,
                'daily_summary' => $dailySummary,
                'movements' => $movements,
            ],
        ]);
    }

    private function initialStockForLocation(Ingredient $ingredient, int $locationId): float
    {
        $data = is_array($ingredient->initial_stock_data) ? $ingredient->initial_stock_data : [];
        foreach ($data as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $loc = isset($entry['location_id']) ? (int) $entry['location_id'] : null;
            if ($loc === $locationId) {
                return (float) ($entry['quantity'] ?? 0);
            }
        }

        return 0;
    }

    public function history(Request $request): JsonResponse
    {
        $payload = $this->historyPayload($request, true);

        return response()->json($payload);
    }

    public function exportHistoryExcel(Request $request)
    {
        $payload = $this->historyPayload($request, false);
        $escape = static fn ($value): string => '"'.str_replace('"', '""', (string) ($value ?? '')).'"';

        $output = "\xEF\xBB\xBF".implode(',', [
            'Date & Time',
            'Item Type',
            'Item Name',
            'SKU/Code',
            'Location',
            'Direction',
            'Reason',
            'Quantity',
            'Unit',
            'Unit Cost',
            'Stock Value',
            'Reference',
            'Note',
        ])."\n";

        foreach ($payload['rows'] as $row) {
            $signedQty = strtoupper((string) $row['direction']) === 'OUT'
                ? -1 * (float) $row['quantity']
                : (float) $row['quantity'];

            $output .= implode(',', [
                $escape($row['occurred_at']),
                $escape($row['item_type']),
                $escape($row['item_name']),
                $escape($row['item_code']),
                $escape($row['location_name']),
                $escape($row['direction']),
                $escape($row['reason_code']),
                $signedQty,
                $escape($row['unit_name']),
                $row['unit_cost'],
                $row['stock_value'],
                $escape($row['reference']),
                $escape($row['note']),
            ])."\n";
        }

        return response($output, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="stock_movement_history_'.date('Y-m-d').'.csv"',
        ]);
    }

    public function exportHistoryPdf(Request $request)
    {
        $payload = $this->historyPayload($request, false);
        $summary = $payload['summary'];
        $headers = [
            ['label' => 'Date & Time', 'align' => 'left'],
            ['label' => 'Type', 'align' => 'left'],
            ['label' => 'Item', 'align' => 'left'],
            ['label' => 'Location', 'align' => 'left'],
            ['label' => 'Direction', 'align' => 'left'],
            ['label' => 'Qty', 'align' => 'right'],
            ['label' => 'Unit Cost', 'align' => 'right'],
            ['label' => 'Value', 'align' => 'right'],
            ['label' => 'Reference', 'align' => 'left'],
        ];

        $rows = [];
        foreach ($payload['rows'] as $row) {
            $qty = strtoupper((string) $row['direction']) === 'OUT'
                ? -1 * (float) $row['quantity']
                : (float) $row['quantity'];
            $rows[] = [
                ['val' => $row['occurred_at'], 'align' => 'left'],
                ['val' => $row['item_type'], 'align' => 'left'],
                ['val' => $row['item_name'], 'align' => 'left'],
                ['val' => $row['location_name'], 'align' => 'left'],
                ['val' => $row['direction'].' '.$row['reason_code'], 'align' => 'left'],
                ['val' => number_format($qty, 4).' '.$row['unit_name'], 'align' => 'right'],
                ['val' => number_format((float) $row['unit_cost'], 2), 'align' => 'right'],
                ['val' => number_format((float) $row['stock_value'], 2), 'align' => 'right'],
                ['val' => $row['reference'], 'align' => 'left'],
            ];
        }

        $summaryData = [
            'Total Movements' => $summary['total_movements'] ?? 0,
            'Total IN Qty' => number_format((float) ($summary['total_in_qty'] ?? 0), 4),
            'Total OUT Qty' => number_format((float) ($summary['total_out_qty'] ?? 0), 4),
            'Net Qty' => number_format((float) ($summary['net_qty'] ?? 0), 4),
            'Net Value' => number_format((float) ($summary['net_value'] ?? 0), 2),
        ];

        $dateRange = ($request->date_from ?? 'Start').' to '.($request->date_to ?? 'Today');
        $html = \App\Services\PdfReportService::renderReportHtml(
            'Stock Movement History Report',
            $headers,
            $rows,
            $summaryData,
            $request->location_id ? (int) $request->location_id : null,
            $dateRange
        );

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="stock_movement_history_'.date('Y-m-d').'.html"',
        ]);
    }

    private function historyBaseQuery(Request $request)
    {
        $query = IngredientStockMovement::query()
            ->with([
                'ingredient.consumptionUnit',
                'ingredient.purchaseUnit',
                'ingredient.category',
                'product.productUnit',
                'product.productCategory',
                'foodMenu.unit',
                'foodMenu.category',
                'location'
            ]);

        if ($request->location_id) {
            $query->where('location_id', (int) $request->location_id);
        }

        if ($request->date_from) {
            $query->where('occurred_at', '>=', Carbon::parse($request->date_from)->startOfDay());
        }

        if ($request->date_to) {
            $query->where('occurred_at', '<=', Carbon::parse($request->date_to)->endOfDay());
        }

        if ($request->direction && $request->direction !== 'all') {
            $dir = strtoupper($request->direction);
            $query->whereRaw('UPPER(direction) = ?', [$dir]);
        }

        if ($request->item_type && $request->item_type !== 'all') {
            if ($request->item_type === 'ingredient') {
                $query->whereNotNull('ingredient_id');
            } elseif ($request->item_type === 'product') {
                $query->whereNotNull('product_id');
            } elseif ($request->item_type === 'food_menu') {
                $query->whereNotNull('food_menu_id');
            }
        }

        if ($request->reason_code && $request->reason_code !== 'all') {
            $query->where('reason_code', 'like', "%{$request->reason_code}%");
        }

        if ($request->search) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                  ->orWhere('note', 'like', "%{$search}%")
                  ->orWhereHas('ingredient', fn($qq) => $qq->where('name', 'like', "%{$search}%")->orWhere('sku_code', 'like', "%{$search}%"))
                  ->orWhereHas('product', fn($qq) => $qq->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"))
                  ->orWhereHas('foodMenu', fn($qq) => $qq->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"));
            });
        }

        return $query;
    }

    private function historyPayload(Request $request, bool $paginate): array
    {
        $query = $this->historyBaseQuery($request);

        $summaryQuery = clone $query;
        $totalMovements = $summaryQuery->count();

        $inStats = (clone $query)->whereRaw("UPPER(direction) = 'IN'")
            ->selectRaw('SUM(quantity_consumption) as qty, SUM(quantity_consumption * COALESCE(batch_unit_cost, 0)) as val')
            ->first();

        $outStats = (clone $query)->whereRaw("UPPER(direction) = 'OUT'")
            ->selectRaw('SUM(quantity_consumption) as qty, SUM(quantity_consumption * COALESCE(batch_unit_cost, 0)) as val')
            ->first();

        $totalInQty = (float) ($inStats->qty ?? 0);
        $totalInValue = (float) ($inStats->val ?? 0);
        $totalOutQty = (float) ($outStats->qty ?? 0);
        $totalOutValue = (float) ($outStats->val ?? 0);

        $sortCol = in_array($request->string('sort_col')->toString(), ['occurred_at', 'created_at', 'quantity_consumption'], true)
            ? $request->string('sort_col')->toString()
            : 'occurred_at';
        $sortDir = $request->string('sort_dir')->toString() === 'asc' ? 'asc' : 'desc';

        $summary = [
            'total_movements' => $totalMovements,
            'total_in_qty' => round($totalInQty, 4),
            'total_in_value' => round($totalInValue, 2),
            'total_out_qty' => round($totalOutQty, 4),
            'total_out_value' => round($totalOutValue, 2),
            'net_qty' => round($totalInQty - $totalOutQty, 4),
            'net_value' => round($totalInValue - $totalOutValue, 2),
        ];

        $orderedQuery = $query->orderBy($sortCol, $sortDir);

        if ($paginate) {
            $perPage = (int) $request->integer('per_page', 20);
            $perPage = in_array($perPage, [10, 20, 30, 50, 100], true) ? $perPage : 20;
            $movements = $orderedQuery->paginate($perPage);

            return [
                'summary' => $summary,
                'movements' => $movements,
            ];
        }

        $rows = $orderedQuery
            ->limit(5000)
            ->get()
            ->map(fn (IngredientStockMovement $movement): array => $this->historyExportRow($movement))
            ->all();

        return [
            'summary' => [
                ...$summary,
                'exported_rows' => count($rows),
            ],
            'rows' => $rows,
        ];
    }

    private function historyExportRow(IngredientStockMovement $movement): array
    {
        $ingredient = $movement->ingredient;
        $product = $movement->product;
        $foodMenu = $movement->foodMenu;

        $itemType = 'Ingredient';
        $itemName = $ingredient?->name ?? 'Unknown Item';
        $itemCode = $ingredient?->sku_code ?? $ingredient?->barcode ?? '';
        $unitName = $ingredient?->consumptionUnit?->name ?? '';

        if ($product) {
            $itemType = 'Product';
            $itemName = $product->name;
            $itemCode = $product->code ?? $product->barcode ?? '';
            $unitName = $product->productUnit?->name ?? '';
        } elseif ($foodMenu) {
            $itemType = 'Food Menu';
            $itemName = $foodMenu->name;
            $itemCode = $foodMenu->code ?? '';
            $unitName = $foodMenu->unit?->name ?? '';
        }

        $quantity = (float) $movement->quantity_consumption;
        $unitCost = (float) ($movement->batch_unit_cost ?? 0);

        return [
            'occurred_at' => $movement->occurred_at?->format('Y-m-d H:i:s') ?? '',
            'item_type' => $itemType,
            'item_name' => $itemName,
            'item_code' => $itemCode,
            'location_name' => $movement->location?->name ?? '',
            'direction' => strtoupper((string) $movement->direction),
            'reason_code' => $movement->reason_code ?? '',
            'quantity' => round($quantity, 4),
            'unit_name' => $unitName,
            'unit_cost' => round($unitCost, 4),
            'stock_value' => round($quantity * $unitCost, 2),
            'reference' => $movement->reference ?? '',
            'note' => $movement->note ?? '',
        ];
    }
}
