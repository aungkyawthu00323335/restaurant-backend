<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ItemSalesReportController extends Controller
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

        $allocatedReturns = "SUM(CASE WHEN s.total_amount > 0 THEN (oi.amount / s.total_amount) * COALESCE(refund_totals.refund_amount, 0) ELSE 0 END)";

        $query = DB::table('order_items as oi')
            ->join('orders as o', 'oi.order_id', '=', 'o.id')
            ->join('sales as s', 'o.id', '=', 's.order_id')
            ->leftJoinSub($refundTotals, 'refund_totals', function ($join): void {
                $join->on('s.id', '=', 'refund_totals.sale_id');
            })
            ->leftJoin('food_menus as fm', function ($join) {
                $join->on('oi.item_id', '=', 'fm.id')
                     ->where('oi.item_type', '=', 'food_menu');
            })
            ->leftJoin('combo_menus as cm', function ($join) {
                $join->on('oi.item_id', '=', 'cm.id')
                     ->where('oi.item_type', '=', 'combo');
            })
            ->leftJoin('products as p', function ($join) {
                $join->on('oi.item_id', '=', 'p.id')
                     ->where('oi.item_type', '=', 'product');
            })
            ->leftJoin('categories as c', function ($join) {
                $join->on('c.id', '=', DB::raw('COALESCE(fm.category_id, cm.category_id)'));
            })
            ->leftJoin('product_categories as pc', function ($join) {
                $join->on('pc.id', '=', 'p.product_category_id');
            })
            ->selectRaw("
                oi.item_name_snapshot as item_name,
                oi.item_type,
                COALESCE(c.name, pc.name, 'Uncategorized') as category_name,
                SUM(oi.qty) as qty_sold,
                SUM(oi.amount) as gross_sales,
                SUM(oi.discount_amount) as discounts,
                {$allocatedReturns} as returns_amount,
                SUM(oi.amount) - {$allocatedReturns} as net_sales,
                SUM(COALESCE(oi.cost_snapshot, 0) * oi.qty) as total_cost
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

        $query->groupByRaw("oi.item_id, oi.item_type, oi.item_name_snapshot, COALESCE(c.name, pc.name, 'Uncategorized')");
        
        $items = $query->get();

        $formatted = [];
        $totalQtySold = 0;
        $totalGrossSales = 0;
        $totalDiscounts = 0;
        $totalReturns = 0;
        $totalNetSales = 0;
        $totalCost = 0;
        $totalProfit = 0;

        foreach ($items as $item) {
            $profit = $item->net_sales - $item->total_cost;
            $marginPercent = $item->net_sales > 0 ? ($profit / $item->net_sales) * 100 : 0;

            // Format type
            $displayType = match($item->item_type) {
                'food_menu' => 'Recipe',
                'combo' => 'Combo',
                'product' => 'Product',
                default => 'Service',
            };

            if (!empty($payload['search']) && 
                stripos($item->item_name, $payload['search']) === false &&
                stripos($displayType, $payload['search']) === false &&
                stripos($item->category_name, $payload['search']) === false) {
                continue;
            }

            $formatted[] = [
                'item_name' => $item->item_name,
                'type' => $displayType,
                'category' => $item->category_name,
                'qty_sold' => (float)$item->qty_sold,
                'gross_sales' => (float)$item->gross_sales,
                'discounts' => (float)$item->discounts,
                'returns' => (float)$item->returns_amount,
                'net_sales' => (float)$item->net_sales,
                'cost' => (float)$item->total_cost,
                'profit' => (float)$profit,
                'margin_percent' => (float)$marginPercent,
            ];

            $totalQtySold += $item->qty_sold;
            $totalGrossSales += $item->gross_sales;
            $totalDiscounts += $item->discounts;
            $totalReturns += $item->returns_amount;
            $totalNetSales += $item->net_sales;
            $totalCost += $item->total_cost;
            $totalProfit += $profit;
        }

        $page = (int)($request->page ?? 1);
        $perPage = (int)($payload['per_page'] ?? 15);
        $offset = ($page - 1) * $perPage;
        
        $paginated = array_slice($formatted, $offset, $perPage);

        return response()->json([
            'data' => $paginated,
            'summary' => [
                'total_qty_sold' => round($totalQtySold, 2),
                'total_gross_sales' => round($totalGrossSales, 2),
                'total_discounts' => round($totalDiscounts, 2),
                'total_returns' => round($totalReturns, 2),
                'total_net_sales' => round($totalNetSales, 2),
                'total_cost' => round($totalCost, 2),
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
            ['label' => 'Item Name', 'align' => 'left'],
            ['label' => 'Type', 'align' => 'left'],
            ['label' => 'Category', 'align' => 'left'],
            ['label' => 'Qty Sold', 'align' => 'right'],
            ['label' => 'Gross Sales', 'align' => 'right'],
            ['label' => 'Discounts', 'align' => 'right'],
            ['label' => 'Net Sales', 'align' => 'right'],
            ['label' => 'Cost', 'align' => 'right'],
            ['label' => 'Profit', 'align' => 'right'],
            ['label' => 'Margin %', 'align' => 'right'],
        ];

        $rows = [];
        foreach ($data as $d) {
            $rows[] = [
                ['val' => $d['item_name'], 'align' => 'left'],
                ['val' => $d['type'], 'align' => 'left'],
                ['val' => $d['category'], 'align' => 'left'],
                ['val' => number_format($d['qty_sold']), 'align' => 'right'],
                ['val' => number_format($d['gross_sales']), 'align' => 'right'],
                ['val' => number_format($d['discounts']), 'align' => 'right'],
                ['val' => number_format($d['net_sales']), 'align' => 'right', 'bold' => true],
                ['val' => number_format($d['cost']), 'align' => 'right'],
                ['val' => number_format($d['profit']), 'align' => 'right', 'bold' => true],
                ['val' => number_format($d['margin_percent'], 1) . '%', 'align' => 'right'],
            ];
        }

        $summaryData = [
            'Total Qty Sold' => number_format($summary['total_qty_sold'] ?? 0),
            'Total Net Sales' => number_format($summary['total_net_sales'] ?? 0) . ' MMK',
            'Total Profit' => number_format($summary['total_profit'] ?? 0) . ' MMK',
        ];

        $dateRange = ($request->date_from ?? 'Start') . ' to ' . ($request->date_to ?? 'Today');
        $html = \App\Services\PdfReportService::renderReportHtml(
            'Item Sales Report',
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
        
        fputcsv($stream, ['Item Name', 'Type', 'Category', 'Qty Sold', 'Gross Sales', 'Discounts', 'Returns', 'Net Sales', 'Cost', 'Profit', 'Margin %']);
        
        foreach ($rows as $row) {
            fputcsv($stream, [
                $row['item_name'], 
                $row['type'], 
                $row['category'], 
                $row['qty_sold'], 
                $row['gross_sales'], 
                $row['discounts'], 
                $row['returns'], 
                $row['net_sales'], 
                $row['cost'], 
                $row['profit'], 
                $row['margin_percent']
            ]);
        }
        
        fputcsv($stream, []);
        fputcsv($stream, [
            'Summary', 
            '', 
            '', 
            $summary['total_qty_sold'] ?? 0, 
            $summary['total_gross_sales'] ?? 0, 
            $summary['total_discounts'] ?? 0, 
            $summary['total_returns'] ?? 0, 
            $summary['total_net_sales'] ?? 0, 
            $summary['total_cost'] ?? 0, 
            $summary['total_profit'] ?? 0, 
            ''
        ]);
        
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return response($csv)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="item_sales_report.csv"');
    }
}
