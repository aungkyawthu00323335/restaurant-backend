<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesByOrderTypeReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer'],
        ]);

        $refundTotals = DB::table('refunds')
            ->select('sale_id', DB::raw('SUM(refund_amount) as refund_amount'))
            ->groupBy('sale_id');

        $query = DB::table('sales as s')
            ->join('orders as o', 's.order_id', '=', 'o.id')
            ->leftJoinSub($refundTotals, 'refund_totals', function ($join): void {
                $join->on('s.id', '=', 'refund_totals.sale_id');
            })
            ->selectRaw("
                COALESCE(o.order_type, 'dine_in') as order_type,
                COUNT(s.id) as orders_count,
                SUM(s.total_amount) as gross_sales,
                SUM(COALESCE(o.item_discount_amount, 0) + COALESCE(o.order_discount_amount, 0)) as discounts,
                SUM(COALESCE(o.tax_amount, 0)) as taxes,
                SUM(GREATEST(COALESCE(o.paid_amount, 0) - COALESCE(o.grand_total, 0) - COALESCE(o.change_amount, 0), 0)) as tips,
                SUM(COALESCE(refund_totals.refund_amount, 0)) as returns_amount,
                SUM(s.profit_amount) as profit
            ")
            ->where('s.status', '!=', 'voided');

        if (!empty($payload['location_id'])) {
            $query->where('s.outlet_id', $payload['location_id']);
        }

        if (!empty($payload['date_from'])) {
            $query->whereDate('s.sale_at', '>=', $payload['date_from']);
        }

        if (!empty($payload['date_to'])) {
            $query->whereDate('s.sale_at', '<=', $payload['date_to']);
        }

        $query->groupByRaw("COALESCE(o.order_type, 'dine_in')");
        
        $items = $query->get();

        $formatted = [];
        $totalOrdersCount = 0;
        $totalGrossSales = 0;
        $totalDiscounts = 0;
        $totalTaxes = 0;
        $totalTips = 0;
        $totalNetSales = 0;
        $totalProfit = 0;

        foreach ($items as $item) {
            $returns = (float)$item->returns_amount;
            $netSales = (float)$item->gross_sales - $returns;
            $profit = (float)$item->profit - $returns;
            $avgOrder = $item->orders_count > 0 ? $netSales / $item->orders_count : 0;
            
            // Format the order type string for display
            $displayType = match($item->order_type) {
                'dine_in' => 'DineIn',
                'takeaway' => 'Takeaway',
                'delivery' => 'Delivery',
                default => 'DineIn',
            };

            if (!empty($payload['search']) && stripos($displayType, $payload['search']) === false) {
                continue;
            }

            $formatted[] = [
                'order_type' => $displayType,
                'orders' => (int)$item->orders_count,
                'gross_sales' => (float)$item->gross_sales,
                'discounts' => (float)$item->discounts,
                'taxes' => (float)$item->taxes,
                'tips' => (float)$item->tips,
                'net_sales' => (float)$netSales,
                'avg_order' => (float)$avgOrder,
                'profit' => (float)$profit,
            ];

            $totalOrdersCount += $item->orders_count;
            $totalGrossSales += $item->gross_sales;
            $totalDiscounts += $item->discounts;
            $totalTaxes += $item->taxes;
            $totalTips += $item->tips;
            $totalNetSales += $netSales;
            $totalProfit += $profit;
        }

        $page = (int)($request->page ?? 1);
        $perPage = (int)($payload['per_page'] ?? 15);
        $offset = ($page - 1) * $perPage;
        
        $paginated = array_slice($formatted, $offset, $perPage);

        return response()->json([
            'data' => $paginated,
            'summary' => [
                'total_orders' => $totalOrdersCount,
                'total_gross_sales' => round($totalGrossSales, 2),
                'total_discounts' => round($totalDiscounts, 2),
                'total_taxes' => round($totalTaxes, 2),
                'total_tips' => round($totalTips, 2),
                'total_net_sales' => round($totalNetSales, 2),
                'total_profit' => round($totalProfit, 2),
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
        $request->merge(['page' => 1, 'per_page' => 100000]);
        $response = $this->index($request)->getData(true);
        $data = $response['data'] ?? [];
        $summary = $response['summary'] ?? [];

        $headers = [
            ['label' => 'Order Type', 'align' => 'left'],
            ['label' => 'Orders', 'align' => 'right'],
            ['label' => 'Gross Sales', 'align' => 'right'],
            ['label' => 'Discounts', 'align' => 'right'],
            ['label' => 'Taxes', 'align' => 'right'],
            ['label' => 'Net Sales', 'align' => 'right'],
            ['label' => 'Avg Order', 'align' => 'right'],
            ['label' => 'Profit', 'align' => 'right'],
        ];

        $rows = [];
        foreach ($data as $d) {
            $rows[] = [
                ['val' => $d['order_type'], 'align' => 'left'],
                ['val' => number_format($d['orders']), 'align' => 'right'],
                ['val' => number_format($d['gross_sales']), 'align' => 'right'],
                ['val' => number_format($d['discounts']), 'align' => 'right'],
                ['val' => number_format($d['taxes']), 'align' => 'right'],
                ['val' => number_format($d['net_sales']), 'align' => 'right', 'bold' => true],
                ['val' => number_format($d['avg_order']), 'align' => 'right'],
                ['val' => number_format($d['profit']), 'align' => 'right', 'bold' => true],
            ];
        }

        $summaryData = [
            'Total Orders' => number_format($summary['total_orders'] ?? 0),
            'Total Net Sales' => number_format($summary['total_net_sales'] ?? 0) . ' MMK',
            'Total Profit' => number_format($summary['total_profit'] ?? 0) . ' MMK',
        ];

        $dateRange = ($request->date_from ?? 'Start') . ' to ' . ($request->date_to ?? 'Today');
        $html = \App\Services\PdfReportService::renderReportHtml(
            'Sales by Order Type Report',
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
        $request->merge(['page' => 1, 'per_page' => 100000]);
        $response = $this->index($request)->getData(true);
        $rows = $response['data'] ?? [];
        $summary = $response['summary'] ?? [];
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");
        
        fputcsv($stream, ['Order Type', 'Orders', 'Gross Sales', 'Discounts', 'Taxes', 'Tips', 'Net Sales', 'Avg Order', 'Profit']);
        
        foreach ($rows as $row) {
            fputcsv($stream, [
                $row['order_type'],
                $row['orders'],
                $row['gross_sales'],
                $row['discounts'],
                $row['taxes'],
                $row['tips'],
                $row['net_sales'],
                $row['avg_order'],
                $row['profit']
            ]);
        }
        
        fputcsv($stream, []);
        fputcsv($stream, [
            'Summary',
            $summary['total_orders'] ?? 0,
            $summary['total_gross_sales'] ?? 0,
            $summary['total_discounts'] ?? 0,
            $summary['total_taxes'] ?? 0,
            $summary['total_tips'] ?? 0,
            $summary['total_net_sales'] ?? 0,
            '',
            $summary['total_profit'] ?? 0
        ]);
        
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return response($csv)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="sales_by_order_type_report.csv"');
    }
}
