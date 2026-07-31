<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\FoodMenu;
use App\Models\IngredientBatch;
use App\Models\IngredientStockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockReportController extends Controller
{
    public function index(Request $request)
    {
        $type = $request->string('type', 'ingredient')->toString();

        if ($type === 'product') {
            return $this->productReport($request);
        } elseif ($type === 'food_menu') {
            return $this->foodMenuReport($request);
        }

        return $this->ingredientReport($request);
    }

    public function exportExcel(Request $request)
    {
        $payload = $this->reportPayload($request);
        $escape = static fn ($value): string => '"'.str_replace('"', '""', (string) ($value ?? '')).'"';
        $output = "\xEF\xBB\xBF".implode(',', ['Type', 'Item', 'SKU', 'Category', 'Stock Qty', 'Unit', 'Unit Cost', 'Stock Value', 'Batches', 'Status'])."\n";
        foreach ($payload['data'] as $row) {
            $output .= implode(',', [
                $escape($row['type_label']), $escape($row['name']), $escape($row['sku_code']),
                $escape($row['category_name']), $row['current_stock'], $escape($row['stock_display']),
                $row['unit_price'], $row['estimated_value'], $row['batch_count'], $escape($row['status']),
            ])."\n";
        }
        return response($output, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="stock_report_'.date('Y-m-d').'.csv"',
        ]);
    }

    public function exportPdf(Request $request)
    {
        $payload = $this->reportPayload($request);
        $html = '<!doctype html><html><head><meta charset="UTF-8"><title>Stock Report</title><style>body{font-family:Arial,sans-serif;margin:24px;color:#0f172a}h1{color:#2563eb}table{width:100%;border-collapse:collapse}th,td{border:1px solid #dbe3ef;padding:7px;text-align:left}th{background:#2563eb;color:#fff}</style></head><body><h1>Stock Report</h1><p>Total stock value: '.number_format((float) $payload['summary']['total_stock_value'], 2).'</p><table><thead><tr><th>Type</th><th>Item</th><th>SKU</th><th>Stock Qty</th><th>Unit</th><th>Unit Cost</th><th>Stock Value</th></tr></thead><tbody>';
        foreach ($payload['data'] as $row) {
            $html .= '<tr><td>'.e($row['type_label']).'</td><td>'.e($row['name']).'</td><td>'.e($row['sku_code']).'</td><td>'.e($row['stock_display']).'</td><td>'.e($row['consumption_unit']).'</td><td>'.number_format((float) $row['unit_price'], 2).'</td><td>'.number_format((float) $row['estimated_value'], 2).'</td></tr>';
        }
        $html .= '</tbody></table></body></html>';
        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="stock_report_'.date('Y-m-d').'.html"',
        ]);
    }

    private function reportPayload(Request $request): array
    {
        $response = match ($request->string('type', 'ingredient')->toString()) {
            'product' => $this->productReport($request),
            'food_menu' => $this->foodMenuReport($request),
            default => $this->ingredientReport($request),
        };
        return $response->getData(true);
    }

    private function ingredientReport(Request $request)
    {
        $query = Ingredient::with(['category', 'consumptionUnit', 'purchaseUnit'])->where('is_active', true);

        if ($request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku_code', 'like', "%{$search}%");
            });
        }
        if ($request->category_id) {
            $query->where('ingredient_category_id', $request->category_id);
        }

        $ingredients = $query->get();

        $movementsQuery = IngredientStockMovement::select(
            'ingredient_id',
            'location_id',
            DB::raw("SUM(CASE WHEN UPPER(direction) = 'IN' THEN quantity_consumption ELSE -quantity_consumption END) as net_movement")
        )->whereNotNull('ingredient_id');

        if ($request->location_id) {
            $movementsQuery->where('location_id', $request->location_id);
        }

        $movements = $movementsQuery
            ->groupBy('ingredient_id', 'location_id')
            ->get()
            ->groupBy('ingredient_id')
            ->map(fn($group) => $group->sum('net_movement'));

        $batchQuery = IngredientBatch::select(
            'id', 'ingredient_id', 'location_id',
            'usable_qty', 'unit_cost', 'received_at', 'expiry_date'
        )->whereNotNull('ingredient_id')->where('usable_qty', '>', 0);

        if ($request->location_id) {
            $batchQuery->where('location_id', $request->location_id);
        }

        $allBatches = $batchQuery->orderBy('received_at', 'asc')->get()->groupBy('ingredient_id');

        $report = [];
        $totalStockValue = 0;
        $totalItems = 0;

        foreach ($ingredients as $ingredient) {
            $initialStock = 0;
            if ($ingredient->initial_stock_data) {
                $initialData = is_array($ingredient->initial_stock_data)
                    ? $ingredient->initial_stock_data
                    : json_decode($ingredient->initial_stock_data, true);
                if (is_array($initialData)) {
                    foreach ($initialData as $entry) {
                        if (!is_array($entry)) continue;
                        $loc = isset($entry['location_id']) ? (int) $entry['location_id'] : null;
                        $qty = (float) ($entry['quantity'] ?? 0);
                        if ($request->location_id) {
                            if ($loc === (int) $request->location_id) {
                                $initialStock += $qty;
                            }
                        } else {
                            $initialStock += $qty;
                        }
                    }
                }
            }

            $movementStock = $movements[$ingredient->id] ?? 0;
            $currentStock = $initialStock + $movementStock;

            $batches = $allBatches[$ingredient->id] ?? collect();
            $batchCount = $batches->count();

            if ($batchCount > 0) {
                $totalBatchQty = $batches->sum('usable_qty');
                $totalBatchValue = $batches->sum(fn($b) => $b->usable_qty * $b->unit_cost);
                $unitPrice = $totalBatchQty > 0 ? ($totalBatchValue / $totalBatchQty) : 0;
            } else {
                $unitPrice = $ingredient->conversion_rate > 0
                    ? ($ingredient->purchase_price / $ingredient->conversion_rate)
                    : 0;
            }

            $estimatedValue = $currentStock * $unitPrice;
            $earliestExpiry = $batches->whereNotNull('expiry_date')->sortBy('expiry_date')->first();

            $totalStockValue += $estimatedValue;
            $totalItems++;

            $report[] = [
                'type' => 'ingredient',
                'type_label' => 'Ingredient',
                'ingredient_id' => $ingredient->id,
                'name' => $ingredient->name,
                'sku_code' => $ingredient->sku_code,
                'category_name' => $ingredient->category ? $ingredient->category->name : 'Uncategorized',
                'consumption_unit' => $ingredient->consumptionUnit ? $ingredient->consumptionUnit->name : '',
                'purchase_unit' => $ingredient->purchaseUnit ? $ingredient->purchaseUnit->name : '',
                'conversion_rate' => (float) ($ingredient->conversion_rate ?: 1),
                'unit_price' => round($unitPrice, 2),
                'current_stock' => round($currentStock, 4),
                'estimated_value' => round($estimatedValue, 2),
                'batch_count' => $batchCount,
                'earliest_expiry' => $earliestExpiry ? $earliestExpiry->expiry_date->toDateString() : null,
                'alert_quantity' => $ingredient->alert_quantity ?? null,
                'batches' => $batches->map(fn($b) => [
                    'id' => $b->id,
                    'usable_qty' => round($b->usable_qty, 4),
                    'unit_cost' => round($b->unit_cost, 4),
                    'received_at' => $b->received_at->toDateTimeString(),
                    'expiry_date' => $b->expiry_date ? $b->expiry_date->toDateString() : null,
                ])->values()->toArray(),
            ];
            $report[array_key_last($report)]['stock_display'] = $this->formatStockDisplay($currentStock, $ingredient->purchaseUnit?->name, $ingredient->consumptionUnit?->name, (float) ($ingredient->conversion_rate ?: 1));
        }

        return $this->paginateReport($report, $totalItems, $totalStockValue, $request);
    }

    private function productReport(Request $request)
    {
        $query = Product::with(['productCategory', 'productUnit'])->where('products.is_active', true);

        if ($request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('products.name', 'like', "%{$search}%")
                  ->orWhere('products.code', 'like', "%{$search}%");
            });
        }
        if ($request->category_id) {
            $query->where('products.product_category_id', $request->category_id);
        }

        $products = $query->get();

        $movementsQuery = IngredientStockMovement::select(
            'product_id',
            'location_id',
            DB::raw("SUM(CASE WHEN UPPER(direction) = 'IN' THEN quantity_consumption ELSE -quantity_consumption END) as net_movement")
        )->whereNotNull('product_id');

        if ($request->location_id) {
            $movementsQuery->where('location_id', $request->location_id);
        }

        $movements = $movementsQuery
            ->groupBy('product_id', 'location_id')
            ->get()
            ->groupBy('product_id')
            ->map(fn($group) => $group->sum('net_movement'));

        $batchQuery = IngredientBatch::select(
            'id', 'product_id', 'location_id',
            'usable_qty', 'unit_cost', 'received_at', 'expiry_date'
        )->whereNotNull('product_id')->where('usable_qty', '>', 0);

        if ($request->location_id) {
            $batchQuery->where('location_id', $request->location_id);
        }

        $allBatches = $batchQuery->orderBy('received_at', 'asc')->get()->groupBy('product_id');

        $report = [];
        $totalStockValue = 0;
        $totalItems = 0;

        foreach ($products as $product) {
            $movementStock = $movements[$product->id] ?? 0;
            $currentStock = $movementStock; // Products have no initial stock json

            $batches = $allBatches[$product->id] ?? collect();
            $batchCount = $batches->count();

            if ($batchCount > 0) {
                $totalBatchQty = $batches->sum('usable_qty');
                $totalBatchValue = $batches->sum(fn($b) => $b->usable_qty * $b->unit_cost);
                $unitPrice = $totalBatchQty > 0 ? ($totalBatchValue / $totalBatchQty) : 0;
            } else {
                $unitPrice = (float) $product->purchase_price_per_unit;
            }

            $estimatedValue = $currentStock * $unitPrice;
            $earliestExpiry = $batches->whereNotNull('expiry_date')->sortBy('expiry_date')->first();

            $totalStockValue += $estimatedValue;
            $totalItems++;

            $report[] = [
                'type' => 'product',
                'type_label' => 'Product',
                'ingredient_id' => $product->id, // map to key 'ingredient_id' for front-end structure compatibility
                'name' => $product->name,
                'sku_code' => $product->code,
                'category_name' => $product->productCategory ? $product->productCategory->name : 'Uncategorized',
                'consumption_unit' => $product->productUnit ? $product->productUnit->name : '',
                'purchase_unit' => $product->productUnit ? $product->productUnit->name : '',
                'conversion_rate' => 1,
                'unit_price' => round($unitPrice, 2),
                'current_stock' => round($currentStock, 4),
                'estimated_value' => round($estimatedValue, 2),
                'batch_count' => $batchCount,
                'earliest_expiry' => $earliestExpiry ? $earliestExpiry->expiry_date->toDateString() : null,
                'alert_quantity' => (float) $product->low_stock_qty,
                'batches' => $batches->map(fn($b) => [
                    'id' => $b->id,
                    'usable_qty' => round($b->usable_qty, 4),
                    'unit_cost' => round($b->unit_cost, 4),
                    'received_at' => $b->received_at->toDateTimeString(),
                    'expiry_date' => $b->expiry_date ? $b->expiry_date->toDateString() : null,
                ])->values()->toArray(),
            ];
            $report[array_key_last($report)]['stock_display'] = $this->formatStockDisplay($currentStock, null, $product->productUnit?->name, 1);
        }

        return $this->paginateReport($report, $totalItems, $totalStockValue, $request);
    }

    private function foodMenuReport(Request $request)
    {
        $query = FoodMenu::with(['category', 'unit'])->where('food_menus.is_active', true);

        if ($request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('food_menus.name', 'like', "%{$search}%")
                  ->orWhere('food_menus.code', 'like', "%{$search}%");
            });
        }
        if ($request->category_id) {
            $query->where('food_menus.category_id', $request->category_id);
        }

        $foodMenus = $query->get();

        $movementsQuery = IngredientStockMovement::select(
            'food_menu_id',
            'location_id',
            DB::raw("SUM(CASE WHEN UPPER(direction) = 'IN' THEN quantity_consumption ELSE -quantity_consumption END) as net_movement")
        )->whereNotNull('food_menu_id');

        if ($request->location_id) {
            $movementsQuery->where('location_id', $request->location_id);
        }

        $movements = $movementsQuery
            ->groupBy('food_menu_id', 'location_id')
            ->get()
            ->groupBy('food_menu_id')
            ->map(fn($group) => $group->sum('net_movement'));

        $batchQuery = IngredientBatch::select(
            'id', 'food_menu_id', 'location_id',
            'usable_qty', 'unit_cost', 'received_at', 'expiry_date'
        )->whereNotNull('food_menu_id')->where('usable_qty', '>', 0);

        if ($request->location_id) {
            $batchQuery->where('location_id', $request->location_id);
        }

        $allBatches = $batchQuery->orderBy('received_at', 'asc')->get()->groupBy('food_menu_id');

        $report = [];
        $totalStockValue = 0;
        $totalItems = 0;

        foreach ($foodMenus as $foodMenu) {
            $movementStock = $movements[$foodMenu->id] ?? 0;
            $currentStock = $movementStock; // Calculated purely from productions

            $batches = $allBatches[$foodMenu->id] ?? collect();
            $batchCount = $batches->count();

            if ($batchCount > 0) {
                $totalBatchQty = $batches->sum('usable_qty');
                $totalBatchValue = $batches->sum(fn($b) => $b->usable_qty * $b->unit_cost);
                $unitPrice = $totalBatchQty > 0 ? ($totalBatchValue / $totalBatchQty) : 0;
            } else {
                $unitPrice = (float) $foodMenu->cost_per_unit;
            }

            $estimatedValue = $currentStock * $unitPrice;
            $earliestExpiry = $batches->whereNotNull('expiry_date')->sortBy('expiry_date')->first();

            $totalStockValue += $estimatedValue;
            $totalItems++;

            $report[] = [
                'type' => 'food_menu',
                'type_label' => 'Production Food Menu',
                'ingredient_id' => $foodMenu->id, // map to key 'ingredient_id' for front-end structure compatibility
                'name' => $foodMenu->name,
                'sku_code' => $foodMenu->code,
                'category_name' => $foodMenu->category ? $foodMenu->category->name : 'Uncategorized',
                'consumption_unit' => $foodMenu->unit ? $foodMenu->unit->name : '',
                'purchase_unit' => $foodMenu->unit ? $foodMenu->unit->name : '',
                'conversion_rate' => 1,
                'unit_price' => round($unitPrice, 2),
                'current_stock' => round($currentStock, 4),
                'estimated_value' => round($estimatedValue, 2),
                'batch_count' => $batchCount,
                'earliest_expiry' => $earliestExpiry ? $earliestExpiry->expiry_date->toDateString() : null,
                'alert_quantity' => (float) $foodMenu->low_stock_qty,
                'batches' => $batches->map(fn($b) => [
                    'id' => $b->id,
                    'usable_qty' => round($b->usable_qty, 4),
                    'unit_cost' => round($b->unit_cost, 4),
                    'received_at' => $b->received_at->toDateTimeString(),
                    'expiry_date' => $b->expiry_date ? $b->expiry_date->toDateString() : null,
                ])->values()->toArray(),
            ];
            $report[array_key_last($report)]['stock_display'] = $this->formatStockDisplay($currentStock, null, $foodMenu->unit?->name, 1);
        }

        return $this->paginateReport($report, $totalItems, $totalStockValue, $request);
    }

    private function paginateReport($report, $totalItems, $totalStockValue, Request $request)
    {
        $alertFilter = $request->string('alert_filter')->toString();
        if ($alertFilter === 'out_of_stock') {
            $report = array_values(array_filter($report, fn ($row) => (float) $row['current_stock'] <= 0));
        } elseif ($alertFilter === 'low_stock') {
            $report = array_values(array_filter($report, fn ($row) => (float) $row['current_stock'] > 0 && $row['alert_quantity'] !== null && (float) $row['current_stock'] <= (float) $row['alert_quantity']));
        }
        $totalItems = count($report);
        $totalStockValue = array_sum(array_map(fn ($row) => (float) $row['estimated_value'], $report));
        $page = (int) ($request->page ?? 1);
        $perPage = (int) ($request->perPage ?? 15);
        $offset = ($page - 1) * $perPage;

        $paginatedItems = array_slice($report, $offset, $perPage);

        return response()->json([
            'data' => $paginatedItems,
            'summary' => [
                'total_items' => $totalItems,
                'total_stock_value' => round($totalStockValue, 2),
            ],
            'total' => count($report),
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => (int) ceil(count($report) / max($perPage, 1)),
        ]);
    }

    private function formatStockDisplay(float $quantity, ?string $purchaseUnit, ?string $consumptionUnit, float $conversionRate): string
    {
        if ($conversionRate <= 1 || !$purchaseUnit || !$consumptionUnit) {
            return number_format($quantity, 4, '.', '').' '.($consumptionUnit ?: 'units');
        }
        $whole = floor($quantity / $conversionRate);
        $remainder = $quantity - ($whole * $conversionRate);
        return number_format($whole, 0, '.', '').' '.$purchaseUnit.' + '.number_format($remainder, 2, '.', '').' '.$consumptionUnit;
    }
}
