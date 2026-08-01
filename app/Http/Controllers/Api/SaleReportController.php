<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaleReportController extends Controller
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
        $periodExpr = $this->datePeriodExpression('sales.sale_at', $dateFormat);
        $tipsExpr = DB::getDriverName() === 'sqlite'
            ? 'max(COALESCE(orders.paid_amount, 0) - COALESCE(orders.grand_total, 0) - COALESCE(orders.change_amount, 0), 0)'
            : 'GREATEST(COALESCE(orders.paid_amount, 0) - COALESCE(orders.grand_total, 0) - COALESCE(orders.change_amount, 0), 0)';

        $refundTotals = DB::table('refunds')
            ->select('sale_id', DB::raw('SUM(refund_amount) as refund_amount'))
            ->groupBy('sale_id');

        $query = Sale::query()
            ->join('orders', 'sales.order_id', '=', 'orders.id')
            ->leftJoinSub($refundTotals, 'refund_totals', function ($join): void {
                $join->on('sales.id', '=', 'refund_totals.sale_id');
            })
            ->selectRaw("
                {$periodExpr} as period,
                COUNT(sales.id) as orders_count,
                SUM(orders.subtotal) as sub_total,
                SUM(COALESCE(orders.item_discount_amount, 0) + COALESCE(orders.order_discount_amount, 0)) as discounts,
                SUM(COALESCE(orders.tax_amount, 0)) as tax,
                SUM(COALESCE(orders.service_charge_amount, 0)) as charges,
                SUM({$tipsExpr}) as tips,
                SUM(COALESCE(orders.delivery_fee, 0)) as fees,
                SUM(COALESCE(refund_totals.refund_amount, 0)) as returns_amount,
                SUM(sales.total_cost) as cost
            ")
            ->where('sales.status', '!=', 'voided');

        if (!empty($payload['location_id'])) {
            $query->where('sales.outlet_id', $payload['location_id']);
        }

        if (!empty($payload['date_from'])) {
            $query->whereDate('sales.sale_at', '>=', $payload['date_from']);
        }

        if (!empty($payload['date_to'])) {
            $query->whereDate('sales.sale_at', '<=', $payload['date_to']);
        }

        // We can't search aggregate results easily before group by, but we could filter by period string having.
        // For simplicity we will search after getting results.

        $query->groupByRaw($periodExpr);
        // Order by date descending by default (we can't just order by the formatted string, we order by MAX sale_at)
        $query->orderByRaw("MAX(sales.sale_at) DESC");

        $items = $query->get();

        $formatted = [];
        $totalOrders = 0;
        $totalSubTotal = 0;
        $totalDiscounts = 0;
        $totalTax = 0;
        $totalCharges = 0;
        $totalTips = 0;
        $totalFees = 0;
        $totalGrossSales = 0;
        $totalReturns = 0;
        $totalNetSales = 0;
        $totalCost = 0;
        $totalGrossProfit = 0;

        foreach ($items as $item) {
            $grossSales = $item->sub_total - $item->discounts + $item->tax + $item->charges + $item->tips + $item->fees;
            $returns = (float)$item->returns_amount;
            $netSales = $grossSales - $returns;
            $grossProfit = $netSales - $item->cost;
            $avgOrder = $item->orders_count > 0 ? $netSales / $item->orders_count : 0;

            if (!empty($payload['search']) && stripos($item->period, $payload['search']) === false) {
                continue;
            }

            $formatted[] = [
                'period' => $item->period,
                'orders' => (int)$item->orders_count,
                'sub_total' => (float)$item->sub_total,
                'discounts' => (float)$item->discounts,
                'tax' => (float)$item->tax,
                'charges' => (float)$item->charges,
                'tips' => (float)$item->tips,
                'fees' => (float)$item->fees,
                'gross_sales' => (float)$grossSales,
                'returns' => (float)$returns,
                'net_sales' => (float)$netSales,
                'cost' => (float)$item->cost,
                'gross_profit' => (float)$grossProfit,
                'avg_order' => (float)$avgOrder,
            ];

            $totalOrders += $item->orders_count;
            $totalSubTotal += $item->sub_total;
            $totalDiscounts += $item->discounts;
            $totalTax += $item->tax;
            $totalCharges += $item->charges;
            $totalTips += $item->tips;
            $totalFees += $item->fees;
            $totalGrossSales += $grossSales;
            $totalReturns += $returns;
            $totalNetSales += $netSales;
            $totalCost += $item->cost;
            $totalGrossProfit += $grossProfit;
        }

        $page = (int)($request->page ?? 1);
        $perPage = $this->reportPageSize($request);
        $offset = ($page - 1) * $perPage;
        
        $paginated = array_slice($formatted, $offset, $perPage);

        return response()->json([
            'data' => $paginated,
            'summary' => [
                'total_orders' => $totalOrders,
                'total_sub_total' => round($totalSubTotal, 2),
                'total_discounts' => round($totalDiscounts, 2),
                'total_tax' => round($totalTax, 2),
                'total_charges' => round($totalCharges, 2),
                'total_tips' => round($totalTips, 2),
                'total_fees' => round($totalFees, 2),
                'total_gross_sales' => round($totalGrossSales, 2),
                'total_returns' => round($totalReturns, 2),
                'total_net_sales' => round($totalNetSales, 2),
                'total_cost' => round($totalCost, 2),
                'total_gross_profit' => round($totalGrossProfit, 2),
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
            ['label' => 'Subtotal', 'align' => 'right'],
            ['label' => 'Discount', 'align' => 'right'],
            ['label' => 'Net Sales', 'align' => 'right'],
            ['label' => 'COGS', 'align' => 'right'],
            ['label' => 'Gross Profit', 'align' => 'right'],
        ];

        $rows = [];
        foreach ($data as $d) {
            $rows[] = [
                ['val' => $d['period'], 'align' => 'left'],
                ['val' => $d['orders'], 'align' => 'right'],
                ['val' => number_format($d['sub_total']), 'align' => 'right'],
                ['val' => number_format($d['discounts']), 'align' => 'right'],
                ['val' => number_format($d['net_sales']), 'align' => 'right', 'bold' => true],
                ['val' => number_format($d['cost']), 'align' => 'right'],
                ['val' => number_format($d['gross_profit']), 'align' => 'right', 'bold' => true],
            ];
        }

        $summaryData = [
            'Total Orders' => $summary['total_orders'] ?? 0,
            'Total Net Sales' => number_format($summary['total_net_sales'] ?? 0) . ' MMK',
            'Total Cost (COGS)' => number_format($summary['total_cost'] ?? 0) . ' MMK',
            'Total Gross Profit' => number_format($summary['total_gross_profit'] ?? 0) . ' MMK',
        ];

        $dateRange = ($request->date_from ?? 'Start') . ' to ' . ($request->date_to ?? 'Today');
        $html = \App\Services\PdfReportService::renderReportHtml(
            'Sales Summary Report',
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
        fputcsv($stream, ['Period', 'Orders', 'Subtotal', 'Discounts', 'Tax', 'Charges', 'Fees', 'Gross Sales', 'Returns', 'Net Sales', 'COGS', 'Gross Profit', 'Average Order']);
        foreach ($rows as $row) {
            fputcsv($stream, [
                $row['period'], $row['orders'], $row['sub_total'], $row['discounts'], $row['tax'],
                $row['charges'], $row['fees'], $row['gross_sales'], $row['returns'], $row['net_sales'],
                $row['cost'], $row['gross_profit'], $row['avg_order'],
            ]);
        }
        fputcsv($stream, []);
        fputcsv($stream, ['Summary', $summary['total_orders'] ?? 0, '', $summary['total_discounts'] ?? 0, $summary['total_tax'] ?? 0, '', $summary['total_fees'] ?? 0, $summary['total_gross_sales'] ?? 0, $summary['total_returns'] ?? 0, $summary['total_net_sales'] ?? 0, $summary['total_cost'] ?? 0, $summary['total_gross_profit'] ?? 0]);
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return response($csv)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="sales_report.csv"');
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
