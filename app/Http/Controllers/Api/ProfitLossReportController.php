<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Refund;
use App\Models\Sale;
use App\Models\SaleItem;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\ProfitLossService;
class ProfitLossReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'outlet_id' => ['nullable', 'integer', 'exists:locations,id'],
        ]);

        $dateFrom = !empty($payload['date_from']) ? Carbon::parse($payload['date_from'])->startOfDay() : null;
        $dateTo = !empty($payload['date_to']) ? Carbon::parse($payload['date_to'])->endOfDay() : null;
        $outletId = $payload['outlet_id'] ?? null;

        $filters = [];
        if ($dateFrom) { $filters['date_from'] = $dateFrom; }
        if ($dateTo) { $filters['date_to'] = $dateTo; }
        if ($outletId) { $filters['outlet_id'] = $outletId; }

        $calc = \App\Services\ProfitLossService::calculate($filters);
        $breakdown = \App\Services\ProfitLossService::breakdown($filters);

        return response()->json([
            'summary' => [
                'gross_revenue' => round($calc['grossRevenue'], 2),
                'refunds' => round($calc['totalRefunds'], 2),
                'net_revenue' => round($calc['netRevenue'], 2),
                'cogs' => round($calc['cogs'], 2),
                'cogs_percent' => $calc['cogsPercent'],
                'gross_profit' => round($calc['grossProfit'], 2),
                'gross_margin_percent' => $calc['grossMarginPercent'],
                'total_expenses' => round($calc['totalExpenses'], 2),
                'expense_percent' => $calc['expensePercent'],
                'net_profit' => round($calc['netProfit'], 2),
                'net_margin_percent' => $calc['netMarginPercent'],
                'expense_categories' => $calc['expenseCategories'],
            ],
            'breakdown' => $breakdown,
        ]);
    }

    public function exportPdf(Request $request)
    {
        $this->prepareReportExport($request);
        $response = $this->index($request)->getData(true);
        $data = $response['breakdown'] ?? [];
        $summary = $response['summary'] ?? [];

        $headers = [
            ['label' => 'Date', 'align' => 'left'],
            ['label' => 'Gross Revenue', 'align' => 'right'],
            ['label' => 'Refunds', 'align' => 'right'],
            ['label' => 'Net Revenue', 'align' => 'right'],
            ['label' => 'COGS', 'align' => 'right'],
            ['label' => 'Gross Profit', 'align' => 'right'],
            ['label' => 'Margin %', 'align' => 'right'],
            ['label' => 'Expenses', 'align' => 'right'],
            ['label' => 'Net Profit', 'align' => 'right'],
            ['label' => 'Net Margin %', 'align' => 'right'],
        ];

        $rows = [];
        foreach ($data as $d) {
            $rows[] = [
                ['val' => $d['date'] ?? '', 'align' => 'left'],
                ['val' => number_format($d['gross_revenue'] ?? 0), 'align' => 'right'],
                ['val' => number_format($d['refunds'] ?? 0), 'align' => 'right'],
                ['val' => number_format($d['net_revenue'] ?? 0), 'align' => 'right'],
                ['val' => number_format($d['cogs'] ?? 0), 'align' => 'right'],
                ['val' => number_format($d['gross_profit'] ?? 0), 'align' => 'right'],
                ['val' => ($d['gross_margin_percent'] ?? 0) . '%', 'align' => 'right'],
                ['val' => number_format($d['total_expenses'] ?? 0), 'align' => 'right'],
                ['val' => number_format($d['net_profit'] ?? 0), 'align' => 'right'],
                ['val' => ($d['net_margin_percent'] ?? 0) . '%', 'align' => 'right'],
            ];
        }

        $summaryData = [
            'Gross Revenue' => number_format($summary['gross_revenue'] ?? 0),
            'Refunds' => number_format($summary['refunds'] ?? 0),
            'Net Revenue' => number_format($summary['net_revenue'] ?? 0),
            'COGS' => number_format($summary['cogs'] ?? 0),
            'COGS %' => ($summary['cogs_percent'] ?? 0) . '%',
            'Gross Profit' => number_format($summary['gross_profit'] ?? 0),
            'Gross Margin %' => ($summary['gross_margin_percent'] ?? 0) . '%',
            'Total Expenses' => number_format($summary['total_expenses'] ?? 0),
            'Expense %' => ($summary['expense_percent'] ?? 0) . '%',
            'Net Profit' => number_format($summary['net_profit'] ?? 0),
            'Net Margin %' => ($summary['net_margin_percent'] ?? 0) . '%',
        ];

        $dateRange = ($request->date_from ?? 'Start') . ' to ' . ($request->date_to ?? 'Today');
        $html = \App\Services\PdfReportService::renderReportHtml(
            'Profit & Loss Report',
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
        $rows = $response['breakdown'] ?? [];
        $summary = $response['summary'] ?? [];

        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF"); // UTF-8 BOM for Myanmar support
        
        fputcsv($stream, [
            'Date', 'Gross Revenue', 'Refunds', 'Net Revenue', 'COGS', 
            'Gross Profit', 'Gross Margin %', 'Expenses', 'Net Profit', 'Net Margin %'
        ]);

        foreach ($rows as $row) {
            fputcsv($stream, [
                $row['date'] ?? '',
                $row['gross_revenue'] ?? 0,
                $row['refunds'] ?? 0,
                $row['net_revenue'] ?? 0,
                $row['cogs'] ?? 0,
                $row['gross_profit'] ?? 0,
                ($row['gross_margin_percent'] ?? 0) . '%',
                $row['total_expenses'] ?? 0,
                $row['net_profit'] ?? 0,
                ($row['net_margin_percent'] ?? 0) . '%',
            ]);
        }
        
        fputcsv($stream, []); // Empty row
        fputcsv($stream, [
            'Summary',
            $summary['gross_revenue'] ?? 0,
            $summary['refunds'] ?? 0,
            $summary['net_revenue'] ?? 0,
            $summary['cogs'] ?? 0,
            $summary['gross_profit'] ?? 0,
            ($summary['gross_margin_percent'] ?? 0) . '%',
            $summary['total_expenses'] ?? 0,
            $summary['net_profit'] ?? 0,
            ($summary['net_margin_percent'] ?? 0) . '%',
        ]);
        
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return response($csv)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="profit_loss_report.csv"');
    }
}
