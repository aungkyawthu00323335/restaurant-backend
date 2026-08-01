<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Ingredient;
use App\Models\IngredientStockMovement;
use App\Models\Location;
use App\Models\Order;
use App\Models\Purchase;
use App\Models\Refund;
use App\Models\Reservation;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Supplier;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class AdminDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userKey = $request->user()?->id ?? 'guest';
        $scopeKey = sha1(implode('|', [
            (string) $request->getQueryString(),
            (string) $request->header('X-Outlet-Id', ''),
        ]));
        $cacheKey = "pos.dashboard.{$userKey}.{$scopeKey}";

        // Dashboard metrics are live database data. A short scoped cache keeps
        // repeated mobile refreshes from rerunning every aggregate query.
        $payload = Cache::remember(
            $cacheKey,
            now()->addSeconds((int) config('pos.dashboard_cache_seconds', 10)),
            fn (): array => $this->buildDashboardData($request),
        );

        return response()->json($payload);
    }

    private function buildDashboardData(Request $request): array
    {
        $dateFrom = $request->date_from ? Carbon::parse($request->date_from)->startOfDay() : null;
        $dateTo   = $request->date_to   ? Carbon::parse($request->date_to)->endOfDay()     : null;
        $outletId = $request->outlet_id ? (int) $request->outlet_id : null;

        // ─── Sales (net of refunds, matching sale reports) ─────────────────────
        $salesQuery = Sale::whereIn('status', ['completed', 'refunded']);
        if ($dateFrom)  $salesQuery->where('sale_at', '>=', $dateFrom);
        if ($dateTo)    $salesQuery->where('sale_at', '<=', $dateTo);
        if ($outletId)  $salesQuery->where('outlet_id', $outletId);

        $grossSale = (float) (clone $salesQuery)->sum('total_amount');
        $totalRefunds = (float) Refund::query()
            ->whereHas('sale', function ($q) use ($dateFrom, $dateTo, $outletId): void {
                $q->whereIn('status', ['completed', 'refunded']);
                if ($dateFrom) $q->where('sale_at', '>=', $dateFrom);
                if ($dateTo)   $q->where('sale_at', '<=', $dateTo);
                if ($outletId) $q->where('outlet_id', $outletId);
            })
            ->sum('refund_amount');
        $totalSale = max(0, $grossSale - $totalRefunds);

        // ─── Purchases ─────────────────────────────────────────────────────────
        $purchasesQuery = Purchase::whereIn('status', ['pending', 'received']);
        if ($dateFrom)  $purchasesQuery->where('purchase_date', '>=', $dateFrom);
        if ($dateTo)    $purchasesQuery->where('purchase_date', '<=', $dateTo);
        if ($outletId)  $purchasesQuery->where('location_id', $outletId);

        $totalPurchase = (float) (clone $purchasesQuery)->sum('grand_total');
        $totalPayable  = (float) (clone $purchasesQuery)
            ->where('status', 'pending')
            ->sum('grand_total');

        // ─── Expenses ──────────────────────────────────────────────────────────
        $expensesQuery = DB::table('expenses')->whereNull('deleted_at');
        if ($dateFrom && $dateTo) {
            $expensesQuery
                ->where('date', '>=', $dateFrom->toDateString())
                ->where('date', '<', $dateTo->copy()->addDay()->toDateString());
        } elseif ($dateFrom) {
            $expensesQuery->where('date', '>=', $dateFrom->toDateString());
        } elseif ($dateTo) {
            $expensesQuery->where('date', '<', $dateTo->copy()->addDay()->toDateString());
        }
        if ($outletId)  $expensesQuery->where('outlet_id', $outletId);

        $totalExpense = (float) (clone $expensesQuery)->sum('amount');

        // ─── COGS & Profit ────────────────────────────────────────────────────────
        $cogsQuery = SaleItem::whereHas('sale', function ($q) use ($dateFrom, $dateTo, $outletId) {
            $q->whereIn('status', ['completed', 'refunded']);
            if ($dateFrom) $q->where('sale_at', '>=', $dateFrom);
            if ($dateTo)   $q->where('sale_at', '<=', $dateTo);
            if ($outletId) $q->where('outlet_id', $outletId);
        });
        $cogs        = (float) $cogsQuery->selectRaw('SUM(qty * cost_snapshot) as total_cogs')->value('total_cogs');
        $totalProfit = $totalSale - $cogs;

        $todayStart = Carbon::today()->startOfDay();
        $todayEnd = Carbon::today()->endOfDay();

        $todaySalesQuery = Sale::whereIn('status', ['completed', 'refunded'])
            ->whereBetween('sale_at', [$todayStart, $todayEnd])
            ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId));
        $todayGrossSale = (float) (clone $todaySalesQuery)->sum('total_amount');
        $todayRefunds = (float) Refund::query()
            ->whereHas('sale', function ($q) use ($todayStart, $todayEnd, $outletId): void {
                $q->whereIn('status', ['completed', 'refunded'])
                    ->whereBetween('sale_at', [$todayStart, $todayEnd]);
                if ($outletId) $q->where('outlet_id', $outletId);
            })
            ->sum('refund_amount');
        $todayTotalSale = max(0, $todayGrossSale - $todayRefunds);
        $todayTotalPurchase = (float) Purchase::whereIn('status', ['pending', 'received'])
            ->whereDate('purchase_date', Carbon::today()->toDateString())
            ->when($outletId, fn ($q) => $q->where('location_id', $outletId))
            ->sum('grand_total');
        $todayTotalExpense = (float) Expense::query()
            ->withoutGlobalScopes()
            ->where('date', '>=', Carbon::today()->toDateString())
            ->where('date', '<', Carbon::tomorrow()->toDateString())
            ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
            ->sum('amount');

        // ─── Cash vs Bank Sale ────────────────────────────────────────────────────
        $salePaymentsBase = SalePayment::whereHas('sale', function ($q) use ($dateFrom, $dateTo, $outletId) {
            $q->whereIn('status', ['completed', 'refunded']);
            if ($dateFrom) $q->where('sale_at', '>=', $dateFrom);
            if ($dateTo)   $q->where('sale_at', '<=', $dateTo);
            if ($outletId) $q->where('outlet_id', $outletId);
        });

        $cashSale = (float) (clone $salePaymentsBase)
            ->where(function ($q) {
                $q->whereHas('paymentMethod', fn($m) => $m->where('name', 'like', '%cash%'))
                  ->orWhere('payment_method_name_snapshot', 'like', '%cash%');
            })
            ->sum('amount');

        $bankSale = (float) (clone $salePaymentsBase)
            ->where(function ($q) {
                $q->whereHas('paymentMethod', fn($m) => $m->where('name', 'not like', '%cash%'))
                  ->orWhere('payment_method_name_snapshot', 'not like', '%cash%');
            })
            ->sum('amount');

        $totalReceived  = (float) (clone $salePaymentsBase)->sum('amount');
        $totalReceivable = max(0, $totalSale - $totalReceived);

        // ─── Counts ───────────────────────────────────────────────────────────────
        $totalOutlet    = Location::count();
        $totalCustomers = Customer::count();
        $totalSuppliers = Supplier::count();

        $summary = [
            'total_sale'       => round($totalSale, 2),
            'total_purchase'   => round($totalPurchase, 2),
            'total_expense'    => round($totalExpense, 2),
            'total_profit'     => round($totalProfit, 2),
            'today_total_sale' => round($todayTotalSale, 2),
            'today_total_purchase' => round($todayTotalPurchase, 2),
            'today_total_expense' => round($todayTotalExpense, 2),
            'total_outlet'     => $totalOutlet,
            'cash_sale'        => round($cashSale, 2),
            'bank_sale'        => round($bankSale, 2),
            'total_received'   => round($totalReceived, 2),
            'total_customers'  => $totalCustomers,
            'total_supplier'   => $totalSuppliers,
            'total_receivable' => round($totalReceivable, 2),
            'total_payable'    => round($totalPayable, 2),
        ];

        // ─── Monthly Chart Data ───────────────────────────────────────────────────
        $yearStart = $dateFrom ?? Carbon::now()->startOfYear();
        $yearEnd   = $dateTo   ?? Carbon::now()->endOfYear();
        $monthExpr = DB::getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', %s)"
            : "DATE_FORMAT(%s, '%Y-%m')";
        $saleMonth = str_replace('%s', 'sale_at', $monthExpr);
        $salesMonth = str_replace('%s', 'sales.sale_at', $monthExpr);
        $purchaseMonth = str_replace('%s', 'purchase_date', $monthExpr);

        $monthlySales = Sale::whereIn('status', ['completed', 'refunded'])
            ->whereBetween('sale_at', [$yearStart, $yearEnd])
            ->when($outletId, fn($q) => $q->where('outlet_id', $outletId))
            ->selectRaw("{$saleMonth} as month, SUM(total_amount) as total")
            ->groupBy('month')
            ->pluck('total', 'month');

        $monthlyRefunds = Refund::query()
            ->join('sales', 'refunds.sale_id', '=', 'sales.id')
            ->whereIn('sales.status', ['completed', 'refunded'])
            ->whereBetween('sales.sale_at', [$yearStart, $yearEnd])
            ->when($outletId, fn ($q) => $q->where('sales.outlet_id', $outletId))
            ->selectRaw("{$salesMonth} as month, SUM(refunds.refund_amount) as total")
            ->groupBy('month')
            ->pluck('total', 'month');

        $monthlyPurchases = Purchase::whereIn('status', ['pending', 'received'])
            ->whereBetween('purchase_date', [$yearStart, $yearEnd])
            ->when($outletId, fn($q) => $q->where('location_id', $outletId))
            ->selectRaw("{$purchaseMonth} as month, SUM(grand_total) as total")
            ->groupBy('month')
            ->pluck('total', 'month');

        $monthlyCogs = SaleItem::join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->whereIn('sales.status', ['completed', 'refunded'])
            ->whereBetween('sales.sale_at', [$yearStart, $yearEnd])
            ->when($outletId, fn($q) => $q->where('sales.outlet_id', $outletId))
            ->selectRaw("{$salesMonth} as month, SUM(sale_items.qty * sale_items.cost_snapshot) as total_cogs")
            ->groupBy('month')
            ->pluck('total_cogs', 'month');

        $chartData    = [];
        $currentMonth = $yearStart->copy()->startOfMonth();
        $endMonth     = $yearEnd->copy()->startOfMonth();
        while ($currentMonth <= $endMonth) {
            $key         = $currentMonth->format('Y-m');
            $monthSales  = max(0, (float) ($monthlySales[$key] ?? 0) - (float) ($monthlyRefunds[$key] ?? 0));
            $monthCogs   = (float) ($monthlyCogs[$key] ?? 0);
            $monthExpense = (float) Expense::query()
                ->whereBetween('date', [
                    $currentMonth->copy()->startOfMonth()->toDateString(),
                    $currentMonth->copy()->endOfMonth()->toDateString(),
                ])
                ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
                ->sum('amount');
            $chartData[] = [
                'month'      => $currentMonth->format('M'),
                'year_month' => $key,
                'sales'      => $monthSales,
                'purchases'  => (float) ($monthlyPurchases[$key] ?? 0),
                'profit'     => round($monthSales - $monthCogs, 2),
                'expense'    => $monthExpense,
            ];
            $currentMonth->addMonth();
        }

        $topSellingProducts = SaleItem::query()
            ->whereHas('sale', function ($q) use ($dateFrom, $dateTo, $outletId): void {
                $q->whereIn('status', ['completed', 'refunded']);
                if ($dateFrom) $q->where('sale_at', '>=', $dateFrom);
                if ($dateTo)   $q->where('sale_at', '<=', $dateTo);
                if ($outletId) $q->where('outlet_id', $outletId);
            })
            ->selectRaw('item_name_snapshot as name, SUM(qty) as quantity, SUM(amount) as amount')
            ->groupBy('item_name_snapshot')
            ->orderByDesc('quantity')
            ->orderByDesc('amount')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name,
                'quantity' => round((float) $row->quantity, 4),
                'amount' => round((float) $row->amount, 2),
            ]);

        // ─── Recent Orders (from Order table which has order_status & grand_total) ─
        $recentOrders = Order::query()
            ->withCount('items')
            ->orderBy('created_at', 'desc')
            ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
            ->limit(5)
            ->get()
            ->map(function ($order) {
                return [
                    'order_id' => '#ORD-' . str_pad($order->id, 3, '0', STR_PAD_LEFT),
                    'customer' => $order->customer_name ?: 'Walk-in',
                    'items'    => (int) $order->items_count,
                    'total'    => (float) $order->grand_total,
                    'status'   => ucfirst($order->order_status ?? 'pending'),
                    'time'     => $order->created_at->format('h:i A'),
                ];
            });

        // ─── Inventory Status ─────────────────────────────────────────────────────
        $ingredients = Ingredient::with('consumptionUnit')->where('is_active', true)->get();
        $movements   = IngredientStockMovement::select(
            'ingredient_id',
            DB::raw("SUM(CASE WHEN LOWER(direction) = 'in' THEN quantity_consumption ELSE -quantity_consumption END) as net_movement")
        )
            ->when($outletId, fn ($q) => $q->where('location_id', $outletId))
            ->groupBy('ingredient_id')
            ->pluck('net_movement', 'ingredient_id');

        $inventoryStatus = [];
        foreach ($ingredients as $ing) {
            $initialStock = 0;
            if ($ing->initial_stock_data) {
                $data = is_array($ing->initial_stock_data) ? $ing->initial_stock_data : json_decode($ing->initial_stock_data, true);
                if (is_array($data)) {
                    foreach ($data as $entry) {
                        if ($outletId && (int) ($entry['location_id'] ?? $entry['outlet_id'] ?? 0) !== $outletId) {
                            continue;
                        }
                        $initialStock += (float) ($entry['quantity'] ?? 0);
                    }
                }
            }
            $stock  = $initialStock + (float) ($movements[$ing->id] ?? 0);
            $status = 'normal';
            if ($stock <= 0)  $status = 'critical';
            elseif ($stock < 20) $status = 'low';

            if ($status !== 'normal') {
                $inventoryStatus[] = [
                    'item'        => $ing->name,
                    'quantity'    => round($stock, 2) . ' ' . ($ing->consumptionUnit->name ?? ''),
                    'status'      => $status,
                    'stock_value' => $stock,
                ];
            }
        }
        usort($inventoryStatus, fn($a, $b) => $a['stock_value'] <=> $b['stock_value']);
        $inventoryStatus = array_slice($inventoryStatus, 0, 5);

        // ─── Reservations ─────────────────────────────────────────────────────────
        $today    = Carbon::today();
        $tomorrow = Carbon::tomorrow();
        $endOfWeek = Carbon::today()->endOfWeek();

        $reservationScope = fn () => Reservation::query()->when($outletId, fn ($q) => $q->where('outlet_id', $outletId));
        $resToday = $reservationScope()
            ->where('reservation_date', '>=', $today->toDateString())
            ->where('reservation_date', '<', $tomorrow->toDateString())
            ->where('status', '!=', 'cancelled')
            ->count();
        $resTomorrow = $reservationScope()
            ->where('reservation_date', '>=', $tomorrow->toDateString())
            ->where('reservation_date', '<', $tomorrow->copy()->addDay()->toDateString())
            ->where('status', '!=', 'cancelled')
            ->count();
        $resWeek = $reservationScope()
            ->where('reservation_date', '>=', $today->toDateString())
            ->where('reservation_date', '<', $endOfWeek->copy()->addDay()->toDateString())
            ->where('status', '!=', 'cancelled')
            ->count();

        $upcomingList = Reservation::query()
            ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
            ->where('reservation_date', '>=', $today->toDateString())
            ->where('status', '!=', 'cancelled')
            ->orderBy('reservation_date', 'asc')
            ->orderBy('checkin_time', 'asc')
            ->limit(4)
            ->get()
            ->map(function ($res) use ($today, $tomorrow) {
                $resDate = Carbon::parse($res->reservation_date);
                if ($resDate->isToday())        $label = 'Today , ' . ($res->checkin_time ?? '');
                elseif ($resDate->isTomorrow()) $label = 'Tomorrow , ' . ($res->checkin_time ?? '');
                else $label = $resDate->format('M d') . ', ' . ($res->checkin_time ?? '');

                return [
                    'guest'     => $res->customer_name ?? 'Guest',
                    'ref'       => 'RES-' . str_pad($res->id, 3, '0', STR_PAD_LEFT),
                    'date_time' => $label,
                    'guests'    => $res->guest_count ?? 1,
                ];
            });

        return [
            'summary'          => $summary,
            'charts'           => $chartData,
            'recent_orders'    => $recentOrders,
            'inventory_status' => $inventoryStatus,
            'top_selling_products' => $topSellingProducts,
            'reservations'     => [
                'today'     => $resToday,
                'tomorrow'  => $resTomorrow,
                'this_week' => $resWeek,
                'list'      => $upcomingList,
            ],
        ];
    }
}
