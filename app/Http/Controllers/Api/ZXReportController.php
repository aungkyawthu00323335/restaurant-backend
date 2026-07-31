<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashRegister;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ZXReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $payload = $this->validatedFilters($request);
        $items = $this->filteredRegisters($payload);

        $page = (int)($payload['page'] ?? 1);
        $perPage = (int)($payload['per_page'] ?? 15);
        $offset = ($page - 1) * $perPage;
        $formatted = $items->slice($offset, $perPage)->values();

        return response()->json([
            'data' => $formatted,
            'summary' => [
                'total_cash_sales' => round($items->sum('cash_sale_amount'), 2),
                'total_other_payments' => round($items->sum('other_payment_amount'), 2),
                'total_difference' => round($items->sum('difference_amount'), 2),
                'total_registers' => $items->count(),
            ],
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $items->count(),
                'last_page' => max(1, (int) ceil($items->count() / max($perPage, 1))),
            ],
        ]);
    }

    public function show($id): JsonResponse
    {
        $register = CashRegister::with(['outlet', 'cashier'])->findOrFail($id);

        $salesQuery = Sale::where('cash_register_id', $register->id)->whereIn('status', ['completed', 'refunded']);

        // Gross Sales
        $grossSales = SaleItem::whereHas('sale', function ($q) use ($register) {
            $q->where('cash_register_id', $register->id)->whereIn('status', ['completed', 'refunded']);
        })->sum(DB::raw('amount + COALESCE(discount_amount, 0)'));

        // Total Discounts
        $totalDiscounts = SaleItem::whereHas('sale', function ($q) use ($register) {
            $q->where('cash_register_id', $register->id)->whereIn('status', ['completed', 'refunded']);
        })->sum('discount_amount');
        
        $netSales = $salesQuery->sum('total_amount');

        $totalTaxes = (float) DB::table('orders')
            ->join('sales', 'orders.id', '=', 'sales.order_id')
            ->where('sales.cash_register_id', $register->id)
            ->whereIn('sales.status', ['completed', 'refunded'])
            ->sum('orders.tax_amount');
        $totalRefunds = DB::table('refunds')->whereIn('sale_id', function($q) use ($register) {
            $q->select('id')->from('sales')->where('cash_register_id', $register->id);
        })->sum('refund_amount') ?? 0.0;

        $totalCollected = $netSales - $totalRefunds;

        // Tender Breakdown
        $tenderBreakdown = DB::table('sale_payments')
            ->join('sales', 'sale_payments.sale_id', '=', 'sales.id')
            ->where('sales.cash_register_id', $register->id)
            ->whereIn('sales.status', ['completed', 'refunded'])
            ->select('sale_payments.payment_method_name_snapshot as method', DB::raw('SUM(sale_payments.amount) as total'))
            ->groupBy('sale_payments.payment_method_name_snapshot')
            ->get();

        // Category Breakdown
        $categoryBreakdown = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sales.cash_register_id', $register->id)
            ->whereIn('sales.status', ['completed', 'refunded'])
            ->select('sale_items.item_type as category', DB::raw('SUM(sale_items.amount) as total'))
            ->groupBy('sale_items.item_type')
            ->get();

        return response()->json([
            'register' => $register,
            'metrics' => [
                'gross_sales' => round((float)$grossSales, 2),
                'total_discounts' => round((float)$totalDiscounts, 2),
                'net_sales' => round((float)$netSales, 2),
                'total_taxes' => round((float)$totalTaxes, 2),
                'total_refunds' => round((float)$totalRefunds, 2),
                'total_collected' => round((float)$totalCollected, 2),
            ],
            'tender_breakdown' => $tenderBreakdown,
            'category_breakdown' => $categoryBreakdown,
        ]);
    }

    public function exportPdf(Request $request)
    {
        $payload = $this->validatedFilters($request);
        $items = $this->filteredRegisters($payload);
        $summary = [
            'total_cash_sales' => round((float) $items->sum('cash_sale_amount'), 2),
            'total_other_payments' => round((float) $items->sum('other_payment_amount'), 2),
            'total_difference' => round((float) $items->sum('difference_amount'), 2),
            'total_registers' => $items->count(),
        ];

        $headers = [
            ['label' => 'Outlet', 'align' => 'left'],
            ['label' => 'Shift #', 'align' => 'left'],
            ['label' => 'Cashier', 'align' => 'left'],
            ['label' => 'Opened', 'align' => 'left'],
            ['label' => 'Closed', 'align' => 'left'],
            ['label' => 'Opening (MMK)', 'align' => 'right'],
            ['label' => 'Cash Sales', 'align' => 'right'],
            ['label' => 'Other Pay', 'align' => 'right'],
            ['label' => 'Status', 'align' => 'center'],
        ];

        $rows = [];
        foreach ($items as $d) {
            $rows[] = [
                ['val' => $d->outlet?->name ?? '—', 'align' => 'left'],
                ['val' => '#' . $d->id, 'align' => 'left'],
                ['val' => $d->cashier_name_snapshot ?: ($d->cashier?->name ?? '—'), 'align' => 'left'],
                ['val' => optional($d->opened_at)->format('Y-m-d H:i'), 'align' => 'left'],
                ['val' => $d->closed_at ? optional($d->closed_at)->format('Y-m-d H:i') : 'Open Shift', 'align' => 'left'],
                ['val' => number_format((float) $d->opening_balance, 2), 'align' => 'right'],
                ['val' => number_format((float) $d->cash_sale_amount, 2), 'align' => 'right'],
                ['val' => number_format((float) $d->other_payment_amount, 2), 'align' => 'right'],
                ['val' => strtoupper((string) $d->status), 'align' => 'center'],
            ];
        }

        $summaryData = [
            'Total Registers' => $summary['total_registers'] ?? 0,
            'Total Cash Sales' => number_format($summary['total_cash_sales'] ?? 0) . ' MMK',
            'Total Non-Cash Sales' => number_format($summary['total_other_payments'] ?? 0) . ' MMK',
            'Total Cash Difference' => number_format($summary['total_difference'] ?? 0) . ' MMK',
        ];

        $dateRange = ($payload['date_from'] ?? 'Start') . ' to ' . ($payload['date_to'] ?? 'Today');
        $html = \App\Services\PdfReportService::renderReportHtml(
            'Z/X Shift Audit Report',
            $headers,
            $rows,
            $summaryData,
            $payload['location_id'] ?? null,
            $dateRange
        );

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public function exportExcel(Request $request)
    {
        $payload = $this->validatedFilters($request);
        $items = $this->filteredRegisters($payload);
        $escape = static fn ($value): string => '"' . str_replace('"', '""', (string) ($value ?? '')) . '"';
        $output = "\xEF\xBB\xBF" . implode(',', [
            'Outlet', 'Shift #', 'Cashier', 'Opened At', 'Closed At', 'Opening Balance',
            'Cash Sales', 'Other Payments', 'Expected Closing', 'Actual Closing', 'Difference', 'Status',
        ]) . "\n";

        foreach ($items as $item) {
            $output .= implode(',', [
                $escape($item->outlet?->name),
                $escape('#' . $item->id),
                $escape($item->cashier_name_snapshot ?: ($item->cashier?->name ?? '')),
                $escape(optional($item->opened_at)->format('Y-m-d H:i')),
                $escape($item->closed_at ? optional($item->closed_at)->format('Y-m-d H:i') : 'Open Shift'),
                number_format((float) $item->opening_balance, 2, '.', ''),
                number_format((float) $item->cash_sale_amount, 2, '.', ''),
                number_format((float) $item->other_payment_amount, 2, '.', ''),
                number_format((float) $item->expected_closing_balance, 2, '.', ''),
                number_format((float) $item->actual_closing_balance, 2, '.', ''),
                number_format((float) $item->difference_amount, 2, '.', ''),
                $escape(strtoupper((string) $item->status)),
            ]) . "\n";
        }

        return response($output, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="zx_report_' . date('Y-m-d') . '.csv"',
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
            'cashier_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', 'string', 'in:all,open,closed'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
    }

    private function filteredRegisters(array $payload)
    {
        $query = CashRegister::query()->with(['outlet', 'cashier']);

        if (isset($payload['location_id'])) {
            $query->where('outlet_id', $payload['location_id']);
        }
        if (isset($payload['cashier_id'])) {
            $query->where('cashier_id', $payload['cashier_id']);
        }
        if (! empty($payload['status']) && $payload['status'] !== 'all') {
            $query->where('status', $payload['status']);
        }
        if (! empty($payload['date_from'])) {
            $query->whereDate('opened_at', '>=', $payload['date_from']);
        }
        if (! empty($payload['date_to'])) {
            $query->whereDate('opened_at', '<=', $payload['date_to']);
        }

        $search = trim((string) ($payload['search'] ?? ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('cashier_name_snapshot', 'like', "%{$search}%")
                    ->orWhere('opening_note', 'like', "%{$search}%")
                    ->orWhere('closing_note', 'like', "%{$search}%")
                    ->orWhereHas('outlet', fn ($oq) => $oq->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('cashier', fn ($cq) => $cq->where('name', 'like', "%{$search}%"));
            });
        }

        return $query->orderByDesc('id')->get();
    }
}
