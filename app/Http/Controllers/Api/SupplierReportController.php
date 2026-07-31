<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SupplierReportController extends Controller
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

        $query = DB::table('purchases as p')
            ->join('suppliers as s', 'p.supplier_id', '=', 's.id')
            ->selectRaw("
                s.name as supplier,
                COUNT(p.id) as purchases_count,
                SUM(p.grand_total) as total_purchase,
                SUM(CASE WHEN p.status = 'received' THEN p.grand_total ELSE 0 END) as paid_amount,
                SUM(CASE WHEN p.status = 'received' THEN 0 ELSE p.grand_total END) as due_amount,
                MAX(p.purchase_date) as last_purchase_date
            ")
            ->whereNull('p.deleted_at')
            ->where('p.status', '!=', 'canceled');

        if (!empty($payload['location_id'])) {
            $query->where('p.location_id', $payload['location_id']);
        }

        if (!empty($payload['date_from'])) {
            $query->whereDate('p.purchase_date', '>=', $payload['date_from']);
        }

        if (!empty($payload['date_to'])) {
            $query->whereDate('p.purchase_date', '<=', $payload['date_to']);
        }

        $query->groupBy('s.id', 's.name');
        
        $items = $query->get();

        $formatted = [];
        $totalPurchases = 0;
        $totalPurchaseAmount = 0;
        $totalPaid = 0;
        $totalDue = 0;
        $totalNetPurchase = 0;

        foreach ($items as $item) {
            $supplierName = $item->supplier;
            
            if (!empty($payload['search']) && stripos($supplierName, $payload['search']) === false) {
                continue;
            }

            $purchases = (int)$item->purchases_count;
            $totalPurchase = (float)$item->total_purchase;
            
            $paid = (float)$item->paid_amount;
            $due = (float)$item->due_amount;
            $netPurchase = $totalPurchase;
            
            $avgPurchase = $purchases > 0 ? $netPurchase / $purchases : 0;
            
            $lastPurchase = null;
            if ($item->last_purchase_date) {
                $lastPurchase = Carbon::parse($item->last_purchase_date)->format('m/d/Y');
            }

            $formatted[] = [
                'supplier' => $supplierName,
                'purchases' => $purchases,
                'total_purchase' => $totalPurchase,
                'paid' => $paid,
                'due' => $due,
                'net_purchase' => $netPurchase,
                'avg_purchase' => $avgPurchase,
                'last_purchase' => $lastPurchase,
            ];

            $totalPurchases += $purchases;
            $totalPurchaseAmount += $totalPurchase;
            $totalPaid += $paid;
            $totalDue += $due;
            $totalNetPurchase += $netPurchase;
        }

        $page = (int)($request->page ?? 1);
        $perPage = (int)($payload['per_page'] ?? 15);
        $offset = ($page - 1) * $perPage;
        
        $paginated = array_slice($formatted, $offset, $perPage);

        return response()->json([
            'data' => $paginated,
            'summary' => [
                'total_purchases' => $totalPurchases,
                'total_purchase_amount' => round($totalPurchaseAmount, 2),
                'total_paid' => round($totalPaid, 2),
                'total_due' => round($totalDue, 2),
                'total_net_purchase' => round($totalNetPurchase, 2),
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
            ['label' => 'Supplier', 'align' => 'left'],
            ['label' => 'Purchases', 'align' => 'right'],
            ['label' => 'Total Purchase', 'align' => 'right'],
            ['label' => 'Paid', 'align' => 'right'],
            ['label' => 'Due', 'align' => 'right'],
            ['label' => 'Net Purchase', 'align' => 'right'],
            ['label' => 'Avg Purchase', 'align' => 'right'],
            ['label' => 'Last Purchase', 'align' => 'right'],
        ];

        $rows = [];
        foreach ($data as $d) {
            $rows[] = [
                ['val' => $d['supplier'], 'align' => 'left'],
                ['val' => number_format($d['purchases']), 'align' => 'right'],
                ['val' => number_format($d['total_purchase'], 2), 'align' => 'right'],
                ['val' => number_format($d['paid'], 2), 'align' => 'right'],
                ['val' => number_format($d['due'], 2), 'align' => 'right'],
                ['val' => number_format($d['net_purchase'], 2), 'align' => 'right'],
                ['val' => number_format($d['avg_purchase'], 2), 'align' => 'right'],
                ['val' => $d['last_purchase'] ?? '-', 'align' => 'right'],
            ];
        }

        $summaryData = [
            'Total Purchases' => number_format($summary['total_purchases'] ?? 0),
            'Total Purchase Amount' => number_format($summary['total_purchase_amount'] ?? 0, 2),
            'Total Paid' => number_format($summary['total_paid'] ?? 0, 2),
            'Total Due' => number_format($summary['total_due'] ?? 0, 2),
            'Total Net Purchase' => number_format($summary['total_net_purchase'] ?? 0, 2),
        ];

        $dateRange = ($request->date_from ?? 'Start') . ' to ' . ($request->date_to ?? 'Today');
        $html = \App\Services\PdfReportService::renderReportHtml(
            'Supplier Report',
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
        fputcsv($stream, ['Supplier', 'Purchases', 'Total Purchase', 'Paid', 'Due', 'Net Purchase', 'Avg Purchase', 'Last Purchase']);
        foreach ($rows as $row) {
            fputcsv($stream, [
                $row['supplier'],
                $row['purchases'],
                $row['total_purchase'],
                $row['paid'],
                $row['due'],
                $row['net_purchase'],
                $row['avg_purchase'],
                $row['last_purchase'] ?? '-'
            ]);
        }
        fputcsv($stream, []);
        fputcsv($stream, ['Summary']);
        fputcsv($stream, ['Total Purchases', $summary['total_purchases'] ?? 0]);
        fputcsv($stream, ['Total Purchase Amount', $summary['total_purchase_amount'] ?? 0]);
        fputcsv($stream, ['Total Paid', $summary['total_paid'] ?? 0]);
        fputcsv($stream, ['Total Due', $summary['total_due'] ?? 0]);
        fputcsv($stream, ['Total Net Purchase', $summary['total_net_purchase'] ?? 0]);
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return response($csv)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="supplier_report.csv"');
    }
}
