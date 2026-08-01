<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StaffReportController extends Controller
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
        $periodExpr = $this->datePeriodExpression('s.sale_at', $dateFormat);

        $refundTotals = DB::table('refunds')
            ->select('sale_id', DB::raw('SUM(refund_amount) as refund_amount'))
            ->groupBy('sale_id');

        $query = DB::table('sales as s')
            ->join('users as u', 's.created_by', '=', 'u.id')
            ->join('orders as o', 's.order_id', '=', 'o.id')
            ->leftJoinSub($refundTotals, 'refund_totals', function ($join): void {
                $join->on('s.id', '=', 'refund_totals.sale_id');
            })
            ->selectRaw("
                {$periodExpr} as period,
                u.id as user_id,
                u.name as staff_member,
                COUNT(s.id) as orders_count,
                SUM(o.subtotal) as gross_sales,
                SUM(COALESCE(refund_totals.refund_amount, 0)) as returns_amount,
                SUM(o.grand_total) - SUM(COALESCE(refund_totals.refund_amount, 0)) as net_sales
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

        $query->groupByRaw("{$periodExpr}, u.id, u.name");
        $query->orderByRaw("MAX(s.sale_at) DESC");

        $items = $query->get();

        $formatted = [];
        $totalOrders = 0;
        $totalGrossSales = 0;
        $totalReturns = 0;
        $totalNetSales = 0;

        foreach ($items as $item) {
            $periodString = $item->period;
            $staffMember = $item->staff_member ?? 'Unknown';
            $userId = (int)$item->user_id;
            
            if (!empty($payload['search']) && stripos($staffMember, $payload['search']) === false) {
                continue;
            }

            $orders = (int)$item->orders_count;
            $grossSales = (float)$item->gross_sales;
            $netSales = (float)$item->net_sales;
            
            $returns = (float)$item->returns_amount;
            
            $avgOrder = $orders > 0 ? $netSales / $orders : 0;

            $formatted[] = [
                'period' => $periodString,
                'user_id' => $userId,
                'staff_member' => $staffMember,
                'orders' => $orders,
                'gross_sales' => $grossSales,
                'returns' => $returns,
                'net_sales' => $netSales,
                'avg_order' => $avgOrder,
            ];

            $totalOrders += $orders;
            $totalGrossSales += $grossSales;
            $totalReturns += $returns;
            $totalNetSales += $netSales;
        }

        $page = (int)($request->page ?? 1);
        $perPage = $this->reportPageSize($request);
        $offset = ($page - 1) * $perPage;
        
        $paginated = array_slice($formatted, $offset, $perPage);

        return response()->json([
            'data' => $paginated,
            'summary' => [
                'total_orders' => $totalOrders,
                'total_gross_sales' => round($totalGrossSales, 2),
                'total_returns' => round($totalReturns, 2),
                'total_net_sales' => round($totalNetSales, 2),
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
            ['label' => 'Staff Member', 'align' => 'left'],
            ['label' => 'Orders', 'align' => 'right'],
            ['label' => 'Gross Sales', 'align' => 'right'],
            ['label' => 'Returns', 'align' => 'right'],
            ['label' => 'Net Sales', 'align' => 'right'],
            ['label' => 'Avg Order', 'align' => 'right'],
        ];

        $rows = [];
        foreach ($data as $d) {
            $rows[] = [
                ['val' => $d['period'], 'align' => 'left'],
                ['val' => $d['staff_member'], 'align' => 'left'],
                ['val' => number_format($d['orders']), 'align' => 'right'],
                ['val' => number_format($d['gross_sales'], 2), 'align' => 'right'],
                ['val' => number_format($d['returns'], 2), 'align' => 'right'],
                ['val' => number_format($d['net_sales'], 2), 'align' => 'right'],
                ['val' => number_format($d['avg_order'], 2), 'align' => 'right'],
            ];
        }

        $summaryData = [
            'Total Orders' => number_format($summary['total_orders'] ?? 0),
            'Total Gross Sales' => number_format($summary['total_gross_sales'] ?? 0, 2),
            'Total Returns' => number_format($summary['total_returns'] ?? 0, 2),
            'Total Net Sales' => number_format($summary['total_net_sales'] ?? 0, 2),
        ];

        $dateRange = ($request->date_from ?? 'Start') . ' to ' . ($request->date_to ?? 'Today');
        $html = \App\Services\PdfReportService::renderReportHtml(
            'Staff Performance Report',
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
        fputcsv($stream, ['Period', 'Staff Member', 'Orders', 'Gross Sales', 'Returns', 'Net Sales', 'Avg Order']);
        foreach ($rows as $row) {
            fputcsv($stream, [
                $row['period'],
                $row['staff_member'],
                $row['orders'],
                $row['gross_sales'],
                $row['returns'],
                $row['net_sales'],
                $row['avg_order']
            ]);
        }
        fputcsv($stream, []);
        fputcsv($stream, ['Summary']);
        fputcsv($stream, ['Total Orders', $summary['total_orders'] ?? 0]);
        fputcsv($stream, ['Total Gross Sales', $summary['total_gross_sales'] ?? 0]);
        fputcsv($stream, ['Total Returns', $summary['total_returns'] ?? 0]);
        fputcsv($stream, ['Total Net Sales', $summary['total_net_sales'] ?? 0]);
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return response($csv)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="staff_report.csv"');
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
