<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerReportController extends Controller
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
                COALESCE(o.customer_name, 'Cash Customer') as customer,
                COUNT(s.id) as orders_count,
                SUM(o.subtotal) as gross_total,
                SUM(o.grand_total) - SUM(COALESCE(refund_totals.refund_amount, 0)) as net_total,
                SUM(o.paid_amount) as paid,
                SUM(o.balance_amount) as due,
                SUM(GREATEST(COALESCE(o.paid_amount, 0) - COALESCE(o.grand_total, 0) - COALESCE(o.change_amount, 0), 0)) as tips
            ")
            ->where('s.total_amount', '>', 0)
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

        $query->groupByRaw("COALESCE(o.customer_name, 'Cash Customer')");
        
        $items = $query->get();

        $formatted = [];
        $totalOrders = 0;
        $totalGrossTotal = 0;
        $totalNetTotal = 0;
        $totalPaid = 0;
        $totalDue = 0;
        $totalTips = 0;

        foreach ($items as $item) {
            $customerName = $item->customer;
            
            if (!empty($payload['search']) && stripos($customerName, $payload['search']) === false) {
                continue;
            }

            $orders = (int)$item->orders_count;
            $grossTotal = (float)$item->gross_total;
            $netTotal = (float)$item->net_total;
            $paid = (float)$item->paid;
            
            $due = (float)$item->due;
            $tips = (float)$item->tips;
            
            $avgOrder = $orders > 0 ? $netTotal / $orders : 0;

            $formatted[] = [
                'customer' => $customerName,
                'orders' => $orders,
                'gross_total' => $grossTotal,
                'net_total' => $netTotal,
                'paid' => $paid,
                'due' => $due,
                'tips' => $tips,
                'avg_order' => $avgOrder,
            ];

            $totalOrders += $orders;
            $totalGrossTotal += $grossTotal;
            $totalNetTotal += $netTotal;
            $totalPaid += $paid;
            $totalDue += $due;
            $totalTips += $tips;
        }

        $page = (int)($request->page ?? 1);
        $perPage = (int)($payload['per_page'] ?? 15);
        $offset = ($page - 1) * $perPage;
        
        $paginated = array_slice($formatted, $offset, $perPage);

        return response()->json([
            'data' => $paginated,
            'summary' => [
                'total_orders' => $totalOrders,
                'total_gross_total' => round($totalGrossTotal, 2),
                'total_net_total' => round($totalNetTotal, 2),
                'total_paid' => round($totalPaid, 2),
                'total_due' => round($totalDue, 2),
                'total_tips' => round($totalTips, 2),
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
            ['label' => 'Customer', 'align' => 'left'],
            ['label' => 'Orders', 'align' => 'right'],
            ['label' => 'Gross Total', 'align' => 'right'],
            ['label' => 'Net Total', 'align' => 'right'],
            ['label' => 'Paid', 'align' => 'right'],
            ['label' => 'Due', 'align' => 'right'],
            ['label' => 'Tips', 'align' => 'right'],
            ['label' => 'Avg Order', 'align' => 'right'],
        ];

        $rows = [];
        foreach ($data as $d) {
            $rows[] = [
                ['val' => $d['customer'], 'align' => 'left'],
                ['val' => number_format($d['orders']), 'align' => 'right'],
                ['val' => number_format($d['gross_total'], 2), 'align' => 'right'],
                ['val' => number_format($d['net_total'], 2), 'align' => 'right'],
                ['val' => number_format($d['paid'], 2), 'align' => 'right'],
                ['val' => number_format($d['due'], 2), 'align' => 'right'],
                ['val' => number_format($d['tips'], 2), 'align' => 'right'],
                ['val' => number_format($d['avg_order'], 2), 'align' => 'right'],
            ];
        }

        $summaryData = [
            'Total Orders' => number_format($summary['total_orders'] ?? 0),
            'Total Gross Total' => number_format($summary['total_gross_total'] ?? 0, 2),
            'Total Net Total' => number_format($summary['total_net_total'] ?? 0, 2),
            'Total Paid' => number_format($summary['total_paid'] ?? 0, 2),
            'Total Due' => number_format($summary['total_due'] ?? 0, 2),
            'Total Tips' => number_format($summary['total_tips'] ?? 0, 2),
        ];

        $dateRange = ($request->date_from ?? 'Start') . ' to ' . ($request->date_to ?? 'Today');
        $html = \App\Services\PdfReportService::renderReportHtml(
            'Customer Report',
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
        fputcsv($stream, ['Customer', 'Orders', 'Gross Total', 'Net Total', 'Paid', 'Due', 'Tips', 'Avg Order']);
        foreach ($rows as $row) {
            fputcsv($stream, [
                $row['customer'],
                $row['orders'],
                $row['gross_total'],
                $row['net_total'],
                $row['paid'],
                $row['due'],
                $row['tips'],
                $row['avg_order']
            ]);
        }
        fputcsv($stream, []);
        fputcsv($stream, ['Summary']);
        fputcsv($stream, ['Total Orders', $summary['total_orders'] ?? 0]);
        fputcsv($stream, ['Total Gross Total', $summary['total_gross_total'] ?? 0]);
        fputcsv($stream, ['Total Net Total', $summary['total_net_total'] ?? 0]);
        fputcsv($stream, ['Total Paid', $summary['total_paid'] ?? 0]);
        fputcsv($stream, ['Total Due', $summary['total_due'] ?? 0]);
        fputcsv($stream, ['Total Tips', $summary['total_tips'] ?? 0]);
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return response($csv)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="customer_report.csv"');
    }
}
