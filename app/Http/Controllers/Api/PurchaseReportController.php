<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $payload = $this->validatedFilters($request);
        $items = $this->filteredItems($payload);

        $rows = $items->map(fn (PurchaseItem $item): array => $this->formatItem($item));
        $totalSpend = (float) $rows->sum('subtotal');
        $totalItemsCount = (float) $rows->sum('quantity');
        $totalStockQty = (float) $rows->sum('stock_quantity');
        $uniquePurchaseIds = [];

        foreach ($items as $item) {
            $uniquePurchaseIds[$item->purchase_id] = true;
        }

        $page = (int) ($payload['page'] ?? 1);
        $perPage = (int) ($payload['per_page'] ?? 15);
        $offset = ($page - 1) * $perPage;

        $formatted = $rows->slice($offset, $perPage)->values()->toArray();

        return response()->json([
            'data' => $formatted,
            'summary' => [
                'total_spend' => round($totalSpend, 2),
                'purchase_count' => count($uniquePurchaseIds),
                'total_items_count' => round($totalItemsCount, 2),
                'total_stock_qty' => round($totalStockQty, 4),
            ],
            'total' => $items->count(),
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => max(1, (int) ceil($items->count() / max($perPage, 1))),
        ]);
    }

    public function exportExcel(Request $request)
    {
        $items = $this->filteredItems($this->validatedFilters($request));
        $escape = static fn ($value): string => '"'.str_replace('"', '""', (string) ($value ?? '')).'"';
        $output = "\xEF\xBB\xBF".implode(',', ['Date', 'Reference', 'Status', 'Item', 'Type', 'Purchased Qty', 'Purchased Unit', 'Stock In Qty', 'Stock Unit', 'Unit Price', 'Subtotal', 'Outlet', 'Supplier'])."\n";
        foreach ($items as $item) {
            $row = $this->formatItem($item);
            $output .= implode(',', [
                $escape($row['purchase_date']), $escape($row['ref_no']), $escape($row['status']),
                $escape($row['item_name']), $escape($row['item_type']), $row['quantity'], $escape($row['unit_name']),
                $row['stock_quantity'], $escape($row['stock_unit_name']), $row['unit_price'], $row['subtotal'],
                $escape($row['location_name']), $escape($row['supplier_name']),
            ])."\n";
        }
        return response($output, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="purchase_report_'.date('Y-m-d').'.csv"',
        ]);
    }

    public function exportPdf(Request $request)
    {
        $items = $this->filteredItems($this->validatedFilters($request));
        $html = '<!doctype html><html><head><meta charset="UTF-8"><title>Purchase Report</title><style>body{font-family:Arial,sans-serif;color:#0f172a;margin:24px}h1{color:#2563eb}table{width:100%;border-collapse:collapse}th,td{border:1px solid #dbe3ef;padding:8px;text-align:left}th{background:#2563eb;color:#fff}tr:nth-child(even){background:#f8fafc}</style></head><body><h1>Purchase Report</h1><p>Generated '.date('Y-m-d H:i:s').' · Total '.count($items).'</p><table><thead><tr><th>Date</th><th>Reference</th><th>Status</th><th>Item</th><th>Type</th><th>Purchased Qty</th><th>Stock In</th><th>Unit Price</th><th>Subtotal</th><th>Outlet</th><th>Supplier</th></tr></thead><tbody>';
        foreach ($items as $item) {
            $row = $this->formatItem($item);
            $html .= '<tr><td>'.e($row['purchase_date']).'</td><td>'.e($row['ref_no']).'</td><td>'.e($row['status']).'</td><td>'.e($row['item_name']).'</td><td>'.e($row['item_type']).'</td><td>'.e($row['quantity'].' '.$row['unit_name']).'</td><td>'.e($row['stock_quantity'].' '.$row['stock_unit_name']).'</td><td>'.number_format($row['unit_price'], 2).'</td><td>'.number_format($row['subtotal'], 2).'</td><td>'.e($row['location_name']).'</td><td>'.e($row['supplier_name']).'</td></tr>';
        }
        $html .= '</tbody></table></body></html>';
        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="purchase_report_'.date('Y-m-d').'.html"',
        ]);
    }

    private function validatedFilters(Request $request): array
    {
        return $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'item_type' => ['nullable', 'string', 'in:all,ingredient,product'],
            'status' => ['nullable', 'string', 'in:all,pending,received,canceled,cancelled,ordered'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
    }

    private function filteredItems(array $payload)
    {
        $query = PurchaseItem::query()
            ->with([
                'purchase.location',
                'purchase.supplier',
                'ingredient.purchaseUnit',
                'ingredient.consumptionUnit',
                'product.productUnit',
                'purchaseUnit',
            ])
            ->whereHas('purchase', function ($q) use ($payload): void {
                if (isset($payload['location_id'])) {
                    $q->where('location_id', $payload['location_id']);
                }
                if (isset($payload['supplier_id'])) {
                    $q->where('supplier_id', $payload['supplier_id']);
                }
                if (! empty($payload['date_from'])) {
                    $q->whereDate('purchase_date', '>=', $payload['date_from']);
                }
                if (! empty($payload['date_to'])) {
                    $q->whereDate('purchase_date', '<=', $payload['date_to']);
                }

                $status = $payload['status'] ?? 'all';
                if ($status === 'cancelled') {
                    $q->whereIn('status', ['cancelled', 'canceled']);
                } elseif ($status !== 'all') {
                    $q->where('status', $status);
                }
            });

        if (($payload['item_type'] ?? 'all') === 'ingredient') {
            $query->whereNotNull('ingredient_id');
        } elseif (($payload['item_type'] ?? 'all') === 'product') {
            $query->whereNotNull('product_id');
        }

        $search = trim((string) ($payload['search'] ?? ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->whereHas('ingredient', function ($iq) use ($search): void {
                    $iq->where('name', 'like', "%{$search}%")
                        ->orWhere('sku_code', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%");
                })
                    ->orWhereHas('product', function ($pq) use ($search): void {
                        $pq->where('name', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%")
                            ->orWhere('barcode', 'like', "%{$search}%");
                    })
                    ->orWhereHas('purchase', function ($pq) use ($search): void {
                        $pq->where('ref_no', 'like', "%{$search}%")
                            ->orWhere('status', 'like', "%{$search}%")
                            ->orWhereHas('location', fn ($lq) => $lq->where('name', 'like', "%{$search}%"))
                            ->orWhereHas('supplier', fn ($sq) => $sq->where('name', 'like', "%{$search}%"));
                    });
            });
        }

        return $query->orderByDesc('id')->get();
    }

    private function formatItem(PurchaseItem $item): array
    {
        $isProduct = $item->product_id !== null;
        $quantity = round((float) $item->quantity, 4);
        $unitName = '';
        $stockQuantity = $quantity;
        $stockUnitName = '';

        if ($isProduct) {
            $unitName = $item->product?->productUnit?->name ?? 'pcs';
            $stockUnitName = $unitName;
        } else {
            $unitName = $item->unit_type === 'consumption'
                ? ($item->ingredient?->consumptionUnit?->name ?? '')
                : ($item->purchaseUnit?->name ?? $item->ingredient?->purchaseUnit?->name ?? '');
            $stockUnitName = $item->ingredient?->consumptionUnit?->name ?? $unitName;
            $conversionRate = (float) ($item->ingredient?->conversion_rate ?: 1);
            $stockQuantity = $item->unit_type === 'consumption'
                ? $quantity
                : round($quantity * ($conversionRate > 0 ? $conversionRate : 1), 4);
        }

        return [
            'purchase_date' => $item->purchase?->purchase_date?->toDateString() ?? '',
            'ref_no' => $item->purchase?->ref_no ?? '',
            'status' => $item->purchase?->status ?? '',
            'item_name' => $isProduct ? ($item->product?->name ?? '') : ($item->ingredient?->name ?? ''),
            'item_type' => $isProduct ? 'Product' : 'Ingredient',
            'quantity' => $quantity,
            'unit_name' => $unitName,
            'stock_quantity' => $stockQuantity,
            'stock_unit_name' => $stockUnitName,
            'unit_price' => round((float) $item->unit_price, 2),
            'subtotal' => round((float) $item->subtotal, 2),
            'location_name' => $item->purchase?->location?->name ?? '',
            'supplier_name' => $item->purchase?->supplier?->name ?? '',
        ];
    }
}
