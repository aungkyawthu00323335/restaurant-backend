<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashRegister;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RegisterReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'cashier_id' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', 'string', 'in:all,open,closed'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer'],
        ]);

        $query = CashRegister::query()->with(['outlet', 'cashier']);

        if (!empty($payload['location_id'])) {
            $query->where('outlet_id', $payload['location_id']);
        }

        if (!empty($payload['cashier_id'])) {
            $query->where('cashier_id', $payload['cashier_id']);
        }

        if (!empty($payload['status']) && $payload['status'] !== 'all') {
            $query->where('status', $payload['status']);
        }

        if (!empty($payload['date_from'])) {
            $query->whereDate('opened_at', '>=', $payload['date_from']);
        }

        if (!empty($payload['date_to'])) {
            $query->whereDate('opened_at', '<=', $payload['date_to']);
        }

        if (!empty($payload['search']) && trim($payload['search']) !== '') {
            $search = trim($payload['search']);
            $query->where(function ($q) use ($search) {
                $q->where('cashier_name_snapshot', 'like', "%{$search}%")
                  ->orWhere('opening_note', 'like', "%{$search}%")
                  ->orWhere('closing_note', 'like', "%{$search}%");
            });
        }

        $items = $query->orderBy('id', 'desc')->get();

        $totalCashSales = 0.0;
        $totalOtherPayments = 0.0;
        $totalDifference = 0.0;

        foreach ($items as $item) {
            $totalCashSales += (float)$item->cash_sale_amount;
            $totalOtherPayments += (float)$item->other_payment_amount;
            $totalDifference += (float)$item->difference_amount;
        }

        $page = (int)($request->page ?? 1);
        $perPage = $this->reportPageSize($request);
        $offset = ($page - 1) * $perPage;

        $formatted = $items->slice($offset, $perPage)->values();

        return response()->json([
            'data' => $formatted,
            'summary' => [
                'total_cash_sales' => round($totalCashSales, 2),
                'total_other_payments' => round($totalOtherPayments, 2),
                'total_difference' => round($totalDifference, 2),
                'total_registers' => $items->count(),
            ],
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $items->count(),
                'last_page' => max(1, ceil($items->count() / $perPage)),
            ],
        ]);
    }

    public function exportPdf(Request $request)
    {
        $this->prepareReportExport($request);
        $response = $this->index($request)->getData(true);
        $data = $response['data'] ?? [];
        $summary = $response['summary'] ?? [];

        $headers = [
            ['label' => 'ID', 'align' => 'left'],
            ['label' => 'Cashier', 'align' => 'left'],
            ['label' => 'Opened', 'align' => 'left'],
            ['label' => 'Opening (MMK)', 'align' => 'right'],
            ['label' => 'Cash Sales', 'align' => 'right'],
            ['label' => 'Other Pay', 'align' => 'right'],
            ['label' => 'Expected', 'align' => 'right'],
            ['label' => 'Actual', 'align' => 'right'],
            ['label' => 'Diff', 'align' => 'right'],
            ['label' => 'Status', 'align' => 'center'],
        ];

        $rows = [];
        foreach ($data as $d) {
            $rows[] = [
                ['val' => '#' . $d['id'], 'align' => 'left'],
                ['val' => $d['cashier_name_snapshot'] ?? '—', 'align' => 'left'],
                ['val' => $d['opened_at'] ?? '—', 'align' => 'left'],
                ['val' => number_format((float)($d['opening_balance'] ?? 0)), 'align' => 'right'],
                ['val' => number_format((float)($d['cash_sale_amount'] ?? 0)), 'align' => 'right'],
                ['val' => number_format((float)($d['other_payment_amount'] ?? 0)), 'align' => 'right'],
                ['val' => number_format((float)($d['expected_closing_balance'] ?? 0)), 'align' => 'right'],
                ['val' => number_format((float)($d['actual_closing_balance'] ?? 0)), 'align' => 'right'],
                ['val' => number_format((float)($d['difference_amount'] ?? 0)), 'align' => 'right', 'bold' => true],
                ['val' => strtoupper($d['status'] ?? 'open'), 'align' => 'center'],
            ];
        }

        $summaryData = [
            'Total Registers' => $summary['total_registers'] ?? 0,
            'Total Cash Sales' => number_format($summary['total_cash_sales'] ?? 0) . ' MMK',
            'Total Other Payments' => number_format($summary['total_other_payments'] ?? 0) . ' MMK',
            'Total Cash Difference' => number_format($summary['total_difference'] ?? 0) . ' MMK',
        ];

        $dateRange = ($request->date_from ?? 'Start') . ' to ' . ($request->date_to ?? 'Today');
        $html = \App\Services\PdfReportService::renderReportHtml(
            'Cash Register Shift Report',
            $headers,
            $rows,
            $summaryData,
            $request->location_id ? (int)$request->location_id : null,
            $dateRange
        );

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public function exportExcel(Request $request)
    {
        $this->prepareReportExport($request);
        $response = $this->index($request)->getData(true);
        $rows = $response['data'] ?? [];
        $summary = $response['summary'] ?? [];

        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF"); // UTF-8 BOM for Myanmar support
        fputcsv($stream, [
            'ID', 'Cashier', 'Opened', 'Opening (MMK)', 'Cash Sales', 'Other Pay',
            'Expected', 'Actual', 'Diff', 'Status'
        ]);

        foreach ($rows as $row) {
            fputcsv($stream, [
                '#' . $row['id'],
                $row['cashier_name_snapshot'] ?? '—',
                $row['opened_at'] ?? '—',
                $row['opening_balance'] ?? 0,
                $row['cash_sale_amount'] ?? 0,
                $row['other_payment_amount'] ?? 0,
                $row['expected_closing_balance'] ?? 0,
                $row['actual_closing_balance'] ?? 0,
                $row['difference_amount'] ?? 0,
                strtoupper($row['status'] ?? 'open'),
            ]);
        }

        fputcsv($stream, []); // Empty row
        fputcsv($stream, [
            'Summary',
            $summary['total_registers'] ?? 0,
            $summary['total_cash_sales'] ?? 0,
            $summary['total_other_payments'] ?? 0,
            $summary['total_difference'] ?? 0,
        ]);
        
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return response($csv)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="register_report.csv"');
    }
}
