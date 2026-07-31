<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesByPaymentMethodReportController extends Controller
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

        $paymentTotals = DB::table('sale_payments')
            ->select('sale_id', DB::raw('SUM(amount) as total_paid'))
            ->groupBy('sale_id');

        // Aggregate Payments
        $paymentsQuery = DB::table('sale_payments as sp')
            ->join('sales as s', 'sp.sale_id', '=', 's.id')
            ->join('orders as o', 's.order_id', '=', 'o.id')
            ->leftJoin('payment_methods as pm', 'sp.payment_method_id', '=', 'pm.id')
            ->leftJoinSub($paymentTotals, 'payment_totals', function ($join): void {
                $join->on('sp.sale_id', '=', 'payment_totals.sale_id');
            })
            ->selectRaw("
                COALESCE(pm.name, sp.payment_method_name_snapshot, 'Unknown') as method_name,
                COUNT(sp.id) as transactions_count,
                SUM(CASE WHEN COALESCE(payment_totals.total_paid, 0) > 0 THEN (sp.amount / payment_totals.total_paid) * s.total_amount ELSE sp.amount END) as amount,
                SUM(CASE WHEN COALESCE(payment_totals.total_paid, 0) > 0 THEN (sp.amount / payment_totals.total_paid) * GREATEST(COALESCE(payment_totals.total_paid, 0) - COALESCE(s.total_amount, 0) - COALESCE(o.change_amount, 0), 0) ELSE 0 END) as tips
            ")
            ->where('s.status', '!=', 'voided');

        // Aggregate Refunds. Older refund records may not store a payment method, so
        // allocate them back to the original tender split proportionally.
        $refundsQuery = DB::table('refunds as r')
            ->join('sales as s', 'r.sale_id', '=', 's.id')
            ->join('sale_payments as sp', 'sp.sale_id', '=', 's.id')
            ->leftJoin('payment_methods as refund_pm', 'r.payment_method_id', '=', 'refund_pm.id')
            ->leftJoin('payment_methods as payment_pm', 'sp.payment_method_id', '=', 'payment_pm.id')
            ->leftJoinSub($paymentTotals, 'refund_payment_totals', function ($join): void {
                $join->on('sp.sale_id', '=', 'refund_payment_totals.sale_id');
            })
            ->selectRaw("
                COALESCE(refund_pm.name, payment_pm.name, sp.payment_method_name_snapshot, 'Unknown') as method_name,
                SUM(CASE
                    WHEN r.payment_method_id IS NOT NULL AND sp.payment_method_id = r.payment_method_id THEN r.refund_amount
                    WHEN r.payment_method_id IS NULL AND COALESCE(refund_payment_totals.total_paid, 0) > 0 THEN (sp.amount / refund_payment_totals.total_paid) * r.refund_amount
                    ELSE 0
                END) as amount
            ")
            ->where('s.status', '!=', 'voided')
            ->where(function ($query): void {
                $query->whereNull('r.payment_method_id')
                    ->orWhereColumn('sp.payment_method_id', 'r.payment_method_id');
            });

        if (!empty($payload['location_id'])) {
            $paymentsQuery->where('s.outlet_id', $payload['location_id']);
            $refundsQuery->where('s.outlet_id', $payload['location_id']);
        }

        if (!empty($payload['date_from'])) {
            $paymentsQuery->whereDate('s.sale_at', '>=', $payload['date_from']);
            $refundsQuery->whereDate('r.created_at', '>=', $payload['date_from']);
        }

        if (!empty($payload['date_to'])) {
            $paymentsQuery->whereDate('s.sale_at', '<=', $payload['date_to']);
            $refundsQuery->whereDate('r.created_at', '<=', $payload['date_to']);
        }

        $paymentsQuery->groupByRaw("COALESCE(pm.name, sp.payment_method_name_snapshot, 'Unknown')");
        $refundsQuery->groupByRaw("COALESCE(refund_pm.name, payment_pm.name, sp.payment_method_name_snapshot, 'Unknown')");

        $payments = $paymentsQuery->get();
        $refunds = $refundsQuery->get()->keyBy('method_name');

        $formatted = [];
        $totalTransactions = 0;
        $totalAmount = 0;
        $totalPaidAmount = 0;
        $totalRefunds = 0;
        $totalNetAmount = 0;
        $totalTips = 0;

        foreach ($payments as $payment) {
            $methodName = $payment->method_name;
            $refundRow = $refunds->get($methodName);
            $refundAmount = $refundRow ? (float)$refundRow->amount : 0;
            
            $transactions = (int)$payment->transactions_count;
            $amount = (float)$payment->amount;
            $netAmount = $amount - $refundAmount;
            $tips = (float)$payment->tips;
            
            $avgTransaction = $transactions > 0 ? $netAmount / $transactions : 0;

            if (!empty($payload['search']) && stripos($methodName, $payload['search']) === false) {
                continue;
            }

            $formatted[] = [
                'payment_method' => $methodName,
                'transactions' => $transactions,
                'total_amount' => $amount,
                'paid_amount' => $amount,
                'refunds' => $refundAmount,
                'net_amount' => $netAmount,
                'tips' => $tips,
                'avg_transaction' => $avgTransaction,
            ];

            $totalTransactions += $transactions;
            $totalAmount += $amount;
            $totalPaidAmount += $amount;
            $totalRefunds += $refundAmount;
            $totalNetAmount += $netAmount;
            $totalTips += $tips;
        }

        $page = (int)($request->page ?? 1);
        $perPage = (int)($payload['per_page'] ?? 15);
        $offset = ($page - 1) * $perPage;
        
        $paginated = array_slice($formatted, $offset, $perPage);

        return response()->json([
            'data' => $paginated,
            'summary' => [
                'total_transactions' => $totalTransactions,
                'total_amount' => round($totalAmount, 2),
                'total_paid_amount' => round($totalPaidAmount, 2),
                'total_refunds' => round($totalRefunds, 2),
                'total_net_amount' => round($totalNetAmount, 2),
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
            ['label' => 'Payment Method', 'align' => 'left'],
            ['label' => 'Transactions', 'align' => 'right'],
            ['label' => 'Total Amount', 'align' => 'right'],
            ['label' => 'Paid Amount', 'align' => 'right'],
            ['label' => 'Refunds', 'align' => 'right'],
            ['label' => 'Net Amount', 'align' => 'right'],
            ['label' => 'Avg Transaction', 'align' => 'right'],
        ];

        $rows = [];
        foreach ($data as $d) {
            $rows[] = [
                ['val' => $d['payment_method'], 'align' => 'left'],
                ['val' => number_format($d['transactions']), 'align' => 'right'],
                ['val' => number_format($d['total_amount']), 'align' => 'right'],
                ['val' => number_format($d['paid_amount']), 'align' => 'right'],
                ['val' => number_format($d['refunds']), 'align' => 'right'],
                ['val' => number_format($d['net_amount']), 'align' => 'right', 'bold' => true],
                ['val' => number_format($d['avg_transaction']), 'align' => 'right'],
            ];
        }

        $summaryData = [
            'Total Transactions' => number_format($summary['total_transactions'] ?? 0),
            'Total Paid Amount' => number_format($summary['total_paid_amount'] ?? 0) . ' MMK',
            'Total Net Amount' => number_format($summary['total_net_amount'] ?? 0) . ' MMK',
        ];

        $dateRange = ($request->date_from ?? 'Start') . ' to ' . ($request->date_to ?? 'Today');
        $html = \App\Services\PdfReportService::renderReportHtml(
            'Sales by Payment Method Report',
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
        
        fputcsv($stream, ['Payment Method', 'Transactions', 'Total Amount', 'Paid Amount', 'Refunds', 'Net Amount', 'Tips', 'Avg Transaction']);
        
        foreach ($rows as $row) {
            fputcsv($stream, [
                $row['payment_method'],
                $row['transactions'],
                $row['total_amount'],
                $row['paid_amount'],
                $row['refunds'],
                $row['net_amount'],
                $row['tips'],
                $row['avg_transaction']
            ]);
        }
        
        fputcsv($stream, []);
        fputcsv($stream, [
            'Summary',
            $summary['total_transactions'] ?? 0,
            $summary['total_amount'] ?? 0,
            $summary['total_paid_amount'] ?? 0,
            $summary['total_refunds'] ?? 0,
            $summary['total_net_amount'] ?? 0,
            $summary['total_tips'] ?? 0,
            ''
        ]);
        
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return response($csv)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="sales_by_payment_method_report.csv"');
    }
}
