<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaxReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'period' => ['nullable', 'string', 'in:Daily,Weekly,Monthly,Yearly'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer'],
        ]);

        $period = $payload['period'] ?? 'Daily';
        
        $dateFormat = match (strtolower($period)) {
            'yearly' => '%Y',
            'monthly' => '%M %Y',
            'weekly' => '%X-W%V',
            default => '%M %d, %Y',
        };
        $salePeriodExpr = $this->datePeriodExpression('s.sale_at', $dateFormat);
        $refundPeriodExpr = $this->datePeriodExpression('r.created_at', $dateFormat);
        $leastTaxExpr = DB::getDriverName() === 'sqlite'
            ? 'min(COALESCE(o.tax_amount, 0), COALESCE(r.refund_amount, 0) * (COALESCE(o.tax_amount, 0) / o.grand_total))'
            : 'LEAST(COALESCE(o.tax_amount, 0), COALESCE(r.refund_amount, 0) * (COALESCE(o.tax_amount, 0) / o.grand_total))';

        $query = DB::table('sales as s')
            ->join('orders as o', 's.order_id', '=', 'o.id')
            ->selectRaw("
                {$salePeriodExpr} as period,
                COUNT(s.id) as orders_count,
                SUM(CASE WHEN o.tax_amount > 0 THEN 1 ELSE 0 END) as taxed_orders_count,
                SUM(CASE WHEN o.tax_amount > 0 THEN (o.subtotal - (o.item_discount_amount + o.order_discount_amount)) ELSE 0 END) as taxable_amount,
                SUM(o.tax_amount) as tax_collected
            ");

        if (!empty($payload['location_id'])) {
            $query->where('s.outlet_id', $payload['location_id']);
        }

        if (!empty($payload['date_from'])) {
            $query->whereDate('s.sale_at', '>=', $payload['date_from']);
        }

        if (!empty($payload['date_to'])) {
            $query->whereDate('s.sale_at', '<=', $payload['date_to']);
        }

        $query->groupByRaw($salePeriodExpr);
        $query->orderByRaw("MAX(s.sale_at) DESC");

        $items = $query->get();
        $refundQuery = DB::table('refunds as r')
            ->join('sales as s', 'r.sale_id', '=', 's.id')
            ->join('orders as o', 's.order_id', '=', 'o.id')
            ->selectRaw("
                {$refundPeriodExpr} as period,
                SUM(CASE
                    WHEN COALESCE(o.grand_total, 0) > 0
                    THEN {$leastTaxExpr}
                    ELSE 0
                END) as tax_refunded
            ");

        if (!empty($payload['location_id'])) {
            $refundQuery->where('s.outlet_id', $payload['location_id']);
        }

        if (!empty($payload['date_from'])) {
            $refundQuery->whereDate('r.created_at', '>=', $payload['date_from']);
        }

        if (!empty($payload['date_to'])) {
            $refundQuery->whereDate('r.created_at', '<=', $payload['date_to']);
        }

        $taxRefundedByPeriod = $refundQuery
            ->groupByRaw($refundPeriodExpr)
            ->pluck('tax_refunded', 'period');

        $formatted = [];
        $totalOrders = 0;
        $totalTaxedOrders = 0;
        $totalTaxableAmount = 0;
        $totalTaxCollected = 0;
        $totalTaxRefunded = 0;
        $totalNetTax = 0;

        foreach ($items as $item) {
            $periodString = $item->period;
            
            if (!empty($payload['search']) && stripos($periodString, $payload['search']) === false) {
                continue;
            }

            $orders = (int)$item->orders_count;
            $taxedOrders = (int)$item->taxed_orders_count;
            $taxableAmount = (float)$item->taxable_amount;
            $taxCollected = (float)$item->tax_collected;
            
            $taxRefunded = (float) ($taxRefundedByPeriod[$periodString] ?? 0);
            $netTax = $taxCollected - $taxRefunded;

            $formatted[] = [
                'period' => $periodString,
                'orders' => $orders,
                'taxed_orders' => $taxedOrders,
                'taxable_amount' => $taxableAmount,
                'tax_collected' => $taxCollected,
                'tax_refunded' => $taxRefunded,
                'net_tax' => $netTax,
            ];

            $totalOrders += $orders;
            $totalTaxedOrders += $taxedOrders;
            $totalTaxableAmount += $taxableAmount;
            $totalTaxCollected += $taxCollected;
            $totalTaxRefunded += $taxRefunded;
            $totalNetTax += $netTax;
        }

        $page = (int)($request->page ?? 1);
        $perPage = $this->reportPageSize($request);
        $offset = ($page - 1) * $perPage;
        
        $paginated = array_slice($formatted, $offset, $perPage);

        return response()->json([
            'data' => $paginated,
            'summary' => [
                'total_orders' => $totalOrders,
                'total_taxed_orders' => $totalTaxedOrders,
                'total_taxable_amount' => round($totalTaxableAmount, 2),
                'total_tax_collected' => round($totalTaxCollected, 2),
                'total_tax_refunded' => round($totalTaxRefunded, 2),
                'total_net_tax' => round($totalNetTax, 2),
            ],
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => count($formatted),
                'last_page' => max(1, ceil(count($formatted) / $perPage)),
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
            ['label' => 'Period', 'align' => 'left'],
            ['label' => 'Orders', 'align' => 'right'],
            ['label' => 'Taxed Orders', 'align' => 'right'],
            ['label' => 'Taxable Amount', 'align' => 'right'],
            ['label' => 'Tax Collected', 'align' => 'right'],
            ['label' => 'Tax Refunded', 'align' => 'right'],
            ['label' => 'Net Tax', 'align' => 'right'],
        ];

        $rows = [];
        foreach ($data as $d) {
            $rows[] = [
                ['val' => $d['period'], 'align' => 'left'],
                ['val' => number_format($d['orders']), 'align' => 'right'],
                ['val' => number_format($d['taxed_orders']), 'align' => 'right'],
                ['val' => number_format($d['taxable_amount'], 2), 'align' => 'right'],
                ['val' => number_format($d['tax_collected'], 2), 'align' => 'right'],
                ['val' => number_format($d['tax_refunded'], 2), 'align' => 'right'],
                ['val' => number_format($d['net_tax'], 2), 'align' => 'right'],
            ];
        }

        $summaryData = [
            'Total Orders' => number_format($summary['total_orders'] ?? 0),
            'Total Taxed Orders' => number_format($summary['total_taxed_orders'] ?? 0),
            'Total Taxable Amount' => number_format($summary['total_taxable_amount'] ?? 0, 2),
            'Total Tax Collected' => number_format($summary['total_tax_collected'] ?? 0, 2),
            'Total Tax Refunded' => number_format($summary['total_tax_refunded'] ?? 0, 2),
            'Total Net Tax' => number_format($summary['total_net_tax'] ?? 0, 2),
        ];

        $dateRange = ($request->date_from ?? 'Start') . ' to ' . ($request->date_to ?? 'Today');
        $html = \App\Services\PdfReportService::renderReportHtml(
            'Tax Report',
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
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['Period', 'Orders', 'Taxed Orders', 'Taxable Amount', 'Tax Collected', 'Tax Refunded', 'Net Tax']);
        foreach ($rows as $row) {
            fputcsv($stream, [
                $row['period'],
                $row['orders'],
                $row['taxed_orders'],
                $row['taxable_amount'],
                $row['tax_collected'],
                $row['tax_refunded'],
                $row['net_tax']
            ]);
        }
        fputcsv($stream, []);
        fputcsv($stream, ['Summary']);
        fputcsv($stream, ['Total Orders', $summary['total_orders'] ?? 0]);
        fputcsv($stream, ['Total Taxed Orders', $summary['total_taxed_orders'] ?? 0]);
        fputcsv($stream, ['Total Taxable Amount', $summary['total_taxable_amount'] ?? 0]);
        fputcsv($stream, ['Total Tax Collected', $summary['total_tax_collected'] ?? 0]);
        fputcsv($stream, ['Total Tax Refunded', $summary['total_tax_refunded'] ?? 0]);
        fputcsv($stream, ['Total Net Tax', $summary['total_net_tax'] ?? 0]);
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return response($csv)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="tax_report.csv"');
    }

    private function datePeriodExpression(string $column, string $mysqlFormat): string
    {
        if (DB::getDriverName() !== 'sqlite') {
            return "DATE_FORMAT({$column}, '{$mysqlFormat}')";
        }

        return match ($mysqlFormat) {
            '%Y' => "strftime('%Y', {$column})",
            '%M %Y' => "strftime('%Y-%m', {$column})",
            '%X-W%V' => "strftime('%Y-W%W', {$column})",
            default => "strftime('%Y-%m-%d', {$column})",
        };
    }
}
