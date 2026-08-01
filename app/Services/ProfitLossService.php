<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Refund;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ProfitLossService
{
    /**
     * Build the base query for sales with optional filters.
     */
    protected static function salesQuery(array $filters)
    {
        $query = Sale::query()->whereIn('status', ['completed', 'refunded']);
        if (!empty($filters['date_from'])) {
            $query->where('sale_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('sale_at', '<=', $filters['date_to']);
        }
        if (!empty($filters['outlet_id'])) {
            $query->where('outlet_id', $filters['outlet_id']);
        }
        return $query;
    }

    protected static function refundsQuery(array $filters)
    {
        $query = Refund::query()->whereHas('sale', fn($q) => $q->whereIn('status', ['completed', 'refunded']));
        if (!empty($filters['date_from'])) {
            $query->whereHas('sale', fn($q) => $q->where('sale_at', '>=', $filters['date_from']));
        }
        if (!empty($filters['date_to'])) {
            $query->whereHas('sale', fn($q) => $q->where('sale_at', '<=', $filters['date_to']));
        }
        if (!empty($filters['outlet_id'])) {
            $query->whereHas('sale', fn($q) => $q->where('outlet_id', $filters['outlet_id']));
        }
        return $query;
    }

    protected static function cogsQuery(array $filters)
    {
        $query = SaleItem::query()->whereHas('sale', fn($q) => $q->whereIn('status', ['completed', 'refunded']));
        if (!empty($filters['date_from'])) {
            $query->whereHas('sale', fn($q) => $q->where('sale_at', '>=', $filters['date_from']));
        }
        if (!empty($filters['date_to'])) {
            $query->whereHas('sale', fn($q) => $q->where('sale_at', '<=', $filters['date_to']));
        }
        if (!empty($filters['outlet_id'])) {
            $query->whereHas('sale', fn($q) => $q->where('outlet_id', $filters['outlet_id']));
        }
        return $query;
    }

    protected static function expensesQuery(array $filters)
    {
        $query = Expense::query();
        if (!empty($filters['date_from'])) {
            $query->where('date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('date', '<=', $filters['date_to']);
        }
        if (!empty($filters['outlet_id'])) {
            $query->where('outlet_id', $filters['outlet_id']);
        }
        return $query;
    }

    public static function calculate(array $filters = [])
    {
        $salesQuery = self::salesQuery($filters);
        $refundsQuery = self::refundsQuery($filters);
        $cogsQuery = self::cogsQuery($filters);
        $expensesQuery = self::expensesQuery($filters);

        $grossRevenue = (float) $salesQuery->sum('total_amount');
        $totalRefunds = (float) $refundsQuery->sum('refund_amount');
        $netRevenue = max(0, $grossRevenue - $totalRefunds);
        $cogs = (float) $cogsQuery->selectRaw('SUM(qty * cost_snapshot) as total_cogs')->value('total_cogs');
        $cogsPercent = $netRevenue > 0 ? round(($cogs / $netRevenue) * 100, 2) : 0;
        $grossProfit = $netRevenue - $cogs;
        $grossMarginPercent = $netRevenue > 0 ? round(($grossProfit / $netRevenue) * 100, 2) : 0;
        $totalExpenses = (float) $expensesQuery->sum('amount');
        $expensePercent = $netRevenue > 0 ? round(($totalExpenses / $netRevenue) * 100, 2) : 0;
        $netProfit = $grossProfit - $totalExpenses;
        $netMarginPercent = $netRevenue > 0 ? round(($netProfit / $netRevenue) * 100, 2) : 0;

        $expenseCategories = DB::table('expenses')
            ->join('expense_categories', 'expenses.expense_category_id', '=', 'expense_categories.id')
            ->whereNull('expenses.deleted_at')
            ->when(!empty($filters['date_from']), fn($q) => $q->where('expenses.date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']), fn($q) => $q->where('expenses.date', '<=', $filters['date_to']))
            ->when(!empty($filters['outlet_id']), fn($q) => $q->where('expenses.outlet_id', $filters['outlet_id']))
            ->selectRaw('expense_categories.name as name, SUM(expenses.amount) as total')
            ->groupBy('expense_categories.id', 'expense_categories.name')
            ->orderByDesc('total')
            ->get()
            ->map(fn($row) => ['name' => $row->name, 'amount' => round((float) $row->total, 2)])
            ->values()
            ->toArray();

        return [
            'grossRevenue' => $grossRevenue,
            'totalRefunds' => $totalRefunds,
            'netRevenue' => $netRevenue,
            'cogs' => $cogs,
            'cogsPercent' => $cogsPercent,
            'grossProfit' => $grossProfit,
            'grossMarginPercent' => $grossMarginPercent,
            'totalExpenses' => $totalExpenses,
            'expensePercent' => $expensePercent,
            'netProfit' => $netProfit,
            'netMarginPercent' => $netMarginPercent,
            'expenseCategories' => $expenseCategories,
        ];
    }

    public static function breakdown(array $filters = [])
    {
        $salesQuery = self::salesQuery($filters);
        $refundsQuery = self::refundsQuery($filters);
        $cogsQuery = self::cogsQuery($filters);
        $expensesQuery = self::expensesQuery($filters);

        $breakdown = [];
        if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
            $diffDays = $filters['date_from']->diffInDays($filters['date_to']);
            $format = $diffDays <= 60 ? '%Y-%m-%d' : '%Y-%m';
            $salesPeriodExpr = self::datePeriodExpression('sale_at', $format);
            $refundPeriodExpr = self::datePeriodExpression('refunds.created_at', $format);
            $cogsPeriodExpr = self::datePeriodExpression('sales.sale_at', $format);
            $expensePeriodExpr = self::datePeriodExpression('date', $format);

            $salesBreakdown = (clone $salesQuery)
                ->selectRaw("{$salesPeriodExpr} as date, SUM(total_amount) as amount")
                ->groupBy('date')
                ->get()
                ->keyBy('date');

            $refundsBreakdown = (clone $refundsQuery)
                ->selectRaw("{$refundPeriodExpr} as date, SUM(refund_amount) as amount")
                ->groupBy('date')
                ->get()
                ->keyBy('date');

            $cogsBreakdown = DB::table('sale_items')
                ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
                ->whereIn('sales.status', ['completed', 'refunded'])
                ->when(!empty($filters['date_from']), fn($q) => $q->where('sales.sale_at', '>=', $filters['date_from']))
                ->when(!empty($filters['date_to']), fn($q) => $q->where('sales.sale_at', '<=', $filters['date_to']))
                ->when(!empty($filters['outlet_id']), fn($q) => $q->where('sales.outlet_id', $filters['outlet_id']))
                ->selectRaw("{$cogsPeriodExpr} as date, SUM(sale_items.qty * sale_items.cost_snapshot) as total_cogs")
                ->groupBy('date')
                ->get()
                ->keyBy('date');

            $expensesBreakdown = DB::table('expenses')
                ->whereNull('deleted_at')
                ->when(!empty($filters['date_from']), fn($q) => $q->where('date', '>=', $filters['date_from']))
                ->when(!empty($filters['date_to']), fn($q) => $q->where('date', '<=', $filters['date_to']))
                ->when(!empty($filters['outlet_id']), fn($q) => $q->where('outlet_id', $filters['outlet_id']))
                ->selectRaw("{$expensePeriodExpr} as date, SUM(amount) as total_expenses")
                ->groupBy('date')
                ->get()
                ->keyBy('date');

            $allDates = collect(array_merge(
                $salesBreakdown->keys()->toArray(),
                $cogsBreakdown->keys()->toArray(),
                $refundsBreakdown->keys()->toArray(),
                $expensesBreakdown->keys()->toArray()
            ))->unique()->sort()->values();

            foreach ($allDates as $date) {
                $rev = (float) ($salesBreakdown->get($date)->amount ?? 0);
                $ref = (float) ($refundsBreakdown->get($date)->amount ?? 0);
                $c = (float) ($cogsBreakdown->get($date)->total_cogs ?? 0);
                $exp = (float) ($expensesBreakdown->get($date)->total_expenses ?? 0);
                $net = max(0, $rev - $ref);
                $grossProf = $net - $c;
                $netProf = $grossProf - $exp;

                $breakdown[] = [
                    'date' => $date,
                    'gross_revenue' => round($rev, 2),
                    'refunds' => round($ref, 2),
                    'net_revenue' => round($net, 2),
                    'cogs' => round($c, 2),
                    'gross_profit' => round($grossProf, 2),
                    'gross_margin_percent' => $net > 0 ? round(($grossProf / $net) * 100, 2) : 0,
                    'total_expenses' => round($exp, 2),
                    'net_profit' => round($netProf, 2),
                    'net_margin_percent' => $net > 0 ? round(($netProf / $net) * 100, 2) : 0,
                ];
            }
        }
        return $breakdown;
    }

    private static function datePeriodExpression(string $column, string $mysqlFormat): string
    {
        if (DB::getDriverName() !== 'sqlite') {
            return "DATE_FORMAT({$column}, '{$mysqlFormat}')";
        }

        return "strftime('".($mysqlFormat === '%Y-%m-%d' ? '%Y-%m-%d' : '%Y-%m')."', {$column})";
    }
}
?>
