<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transfer;
use Illuminate\Http\Request;

class TransferReportController extends Controller
{
    public function index(Request $request)
    {
        $payload = $this->buildPayload($request);
        return response()->json($payload);
    }

    public function exportExcel(Request $request)
    {
        $payload = $this->buildPayload($request);
        $escape = static fn ($value): string => '"'.str_replace('"', '""', (string) ($value ?? '')).'"';
        $output = "\xEF\xBB\xBF".implode(',', ['Date', 'Time', 'Reference', 'From Outlet', 'To Outlet', 'Type', 'Item', 'Qty', 'Unit', 'Unit Cost', 'Stock Value', 'Status'])."\n";
        foreach ($payload['items'] as $row) {
            $output .= implode(',', [$escape($row['date']), $escape($row['time']), $escape($row['reference_no']), $escape($row['from_location']), $escape($row['to_location']), $escape($row['item_type']), $escape($row['item_name']), $row['quantity'], $escape($row['unit_name']), $row['unit_cost'], $row['total_value'], $escape($row['status'])])."\n";
        }
        return response($output, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="transfer_report_'.date('Y-m-d').'.csv"']);
    }

    public function exportPdf(Request $request)
    {
        $payload = $this->buildPayload($request);
        $html = '<!doctype html><html><head><meta charset="UTF-8"><title>Transfer Report</title><style>body{font-family:Arial,sans-serif;margin:24px;color:#0f172a}h1{color:#2563eb}table{width:100%;border-collapse:collapse}th,td{border:1px solid #dbe3ef;padding:7px;text-align:left}th{background:#2563eb;color:#fff}</style></head><body><h1>Transfer Report</h1><p>Total stock value: '.number_format((float) $payload['total_transfer_value'], 2).'</p><table><thead><tr><th>Date</th><th>Time</th><th>From</th><th>To</th><th>Item</th><th>Qty</th><th>Unit</th><th>Stock Value</th></tr></thead><tbody>';
        foreach ($payload['items'] as $row) {
            $html .= '<tr><td>'.e($row['date']).'</td><td>'.e($row['time']).'</td><td>'.e($row['from_location']).'</td><td>'.e($row['to_location']).'</td><td>'.e($row['item_name']).'</td><td>'.e($row['quantity']).'</td><td>'.e($row['unit_name']).'</td><td>'.number_format((float) $row['total_value'], 2).'</td></tr>';
        }
        $html .= '</tbody></table></body></html>';
        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="transfer_report_'.date('Y-m-d').'.html"']);
    }

    private function buildPayload(Request $request): array
    {
        $query = Transfer::with(['items.ingredient.purchaseUnit', 'items.ingredient.consumptionUnit', 'items.product.productUnit', 'items.foodMenu.unit', 'fromLocation', 'toLocation'])
            ->orderByDesc('transfer_date')->orderByDesc('id');
        if ($request->outlet_id) {
            $query->where(fn ($q) => $q->where('from_location_id', $request->outlet_id)->orWhere('to_location_id', $request->outlet_id));
        }
        if ($request->from_location_id) $query->where('from_location_id', $request->from_location_id);
        if ($request->to_location_id) $query->where('to_location_id', $request->to_location_id);
        if ($request->start_date) $query->whereDate('transfer_date', '>=', $request->start_date);
        if ($request->end_date) $query->whereDate('transfer_date', '<=', $request->end_date);

        $items = [];
        $total = 0.0;
        foreach ($query->get() as $transfer) {
            foreach ($transfer->items as $item) {
                $itemName = match ($item->item_type) {
                    'ingredient' => $item->ingredient?->name ?? 'Unknown',
                    'product' => $item->product?->name ?? 'Unknown',
                    'food_menu' => $item->foodMenu?->name ?? 'Unknown',
                    default => 'Unknown',
                };
                if ($request->item_type && $request->item_type !== 'all' && $item->item_type !== $request->item_type) continue;
                if ($request->search) {
                    $search = trim((string) $request->search);
                    $haystack = implode(' ', [
                        $transfer->ref_no,
                        $transfer->fromLocation?->name,
                        $transfer->toLocation?->name,
                        $item->item_type,
                        $itemName,
                        $item->ingredient?->sku_code,
                        $item->ingredient?->barcode,
                        $item->product?->code,
                        $item->product?->barcode,
                        $item->foodMenu?->code,
                        $transfer->status,
                    ]);
                    if (stripos($haystack, $search) === false) continue;
                }
                $unitName = match ($item->item_type) {
                    'ingredient' => ($item->unit_type ?? 'consumption') === 'purchase' ? ($item->ingredient?->purchaseUnit?->name ?? '') : ($item->ingredient?->consumptionUnit?->name ?? ''),
                    'product' => $item->product?->productUnit?->name ?? '',
                    default => $item->foodMenu?->unit?->name ?? '',
                };
                $row = [
                    'date' => $transfer->transfer_date?->format('Y-m-d'),
                    'time' => $transfer->transferred_at?->format('H:i:s') ?? $transfer->created_at?->format('H:i:s'),
                    'reference_no' => $transfer->ref_no,
                    'from_location' => $transfer->fromLocation?->name ?? '',
                    'to_location' => $transfer->toLocation?->name ?? '',
                    'item_type' => $item->item_type,
                    'item_name' => $itemName,
                    'quantity' => round((float) $item->quantity, 4),
                    'unit_type' => $item->unit_type ?? 'consumption',
                    'unit_name' => $unitName,
                    'unit_cost' => round((float) $item->unit_cost, 4),
                    'total_value' => round((float) $item->subtotal, 2),
                    'status' => $transfer->status,
                ];
                $items[] = $row;
                $total += $row['total_value'];
            }
        }
        return ['items' => $items, 'total_transfer_value' => round($total, 2)];
    }
}
