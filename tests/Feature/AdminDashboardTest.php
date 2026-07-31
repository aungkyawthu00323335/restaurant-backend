<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Floor;
use App\Models\Location;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Models\Refund;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::query()->firstOrCreate(
            ['role_name' => 'Super Admin'],
            ['description' => 'Full access', 'status' => 'active']
        );

        User::query()->create([
            'name' => 'Super Admin',
            'username' => 'superadmin',
            'email' => 'superadmin@example.com',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'status' => 'active',
        ]);
    }

    public function test_admin_dashboard_returns_outlet_scoped_live_metrics_for_mobile_ui(): void
    {
        $main = Location::query()->create(['name' => 'Main Outlet', 'is_active' => true]);
        $branch = Location::query()->create(['name' => 'Branch Outlet', 'is_active' => true]);
        $cash = PaymentMethod::query()->create(['name' => 'Cash', 'is_active' => true]);
        $bank = PaymentMethod::query()->create(['name' => 'KPay', 'is_active' => true]);
        $supplier = Supplier::query()->create([
            'name' => 'Demo Supplier',
            'phone' => '091234567',
        ]);
        $expenseCategory = ExpenseCategory::query()->create(['name' => 'Utilities', 'status' => 'active']);
        $floor = Floor::query()->create([
            'name' => 'Main Floor',
            'code' => 'MF',
            'location_id' => $main->id,
            'is_active' => true,
        ]);
        $table = RestaurantTable::query()->create([
            'outlet_id' => $main->id,
            'floor_id' => $floor->id,
            'table_no' => 'T1',
            'code' => 'T1',
            'status' => 'available',
            'is_active' => true,
        ]);
        $today = Carbon::today();

        $mainOrder = Order::query()->create([
            'order_no' => 'ORD-DASH-001',
            'outlet_id' => $main->id,
            'order_type' => 'dine_in',
            'order_status' => 'completed',
            'payment_state' => 'paid',
            'customer_name' => 'Main Guest',
            'subtotal' => 100,
            'grand_total' => 100,
        ]);
        $mainSale = Sale::query()->create([
            'sale_no' => 'SALE-DASH-001',
            'order_id' => $mainOrder->id,
            'outlet_id' => $main->id,
            'total_amount' => 100,
            'total_cost' => 30,
            'profit_amount' => 70,
            'sale_at' => $today->copy()->setTime(10, 0),
            'status' => 'refunded',
        ]);
        SaleItem::query()->create([
            'sale_id' => $mainSale->id,
            'item_type' => 'food_menu',
            'item_id' => 1,
            'item_name_snapshot' => 'Fried Rice',
            'unit_name_snapshot' => 'Plate',
            'qty' => 2,
            'final_unit_price_snapshot' => 50,
            'amount' => 100,
            'cost_snapshot' => 15,
        ]);
        SalePayment::query()->create([
            'sale_id' => $mainSale->id,
            'payment_method_id' => $cash->id,
            'payment_method_name_snapshot' => 'Cash',
            'amount' => 60,
        ]);
        SalePayment::query()->create([
            'sale_id' => $mainSale->id,
            'payment_method_id' => $bank->id,
            'payment_method_name_snapshot' => 'KPay',
            'amount' => 40,
        ]);
        Refund::query()->create([
            'refund_no' => 'REF-DASH-001',
            'sale_id' => $mainSale->id,
            'refund_type' => 'partial',
            'refund_amount' => 20,
            'return_to_stock' => false,
            'reason' => 'Dashboard regression',
        ]);

        $branchOrder = Order::query()->create([
            'order_no' => 'ORD-DASH-BRANCH',
            'outlet_id' => $branch->id,
            'order_type' => 'dine_in',
            'order_status' => 'completed',
            'payment_state' => 'paid',
            'subtotal' => 999,
            'grand_total' => 999,
        ]);
        $branchSale = Sale::query()->create([
            'sale_no' => 'SALE-DASH-BRANCH',
            'order_id' => $branchOrder->id,
            'outlet_id' => $branch->id,
            'total_amount' => 999,
            'total_cost' => 100,
            'profit_amount' => 899,
            'sale_at' => $today->copy()->setTime(11, 0),
            'status' => 'completed',
        ]);
        SaleItem::query()->create([
            'sale_id' => $branchSale->id,
            'item_type' => 'food_menu',
            'item_id' => 2,
            'item_name_snapshot' => 'Branch Only Item',
            'qty' => 1,
            'amount' => 999,
            'cost_snapshot' => 100,
        ]);

        Purchase::query()->create([
            'ref_no' => 'PUR-DASH-001',
            'supplier_id' => $supplier->id,
            'location_id' => $main->id,
            'purchase_date' => $today->toDateString(),
            'status' => 'pending',
            'grand_total' => 30,
        ]);
        Purchase::query()->create([
            'ref_no' => 'PUR-DASH-002',
            'supplier_id' => $supplier->id,
            'location_id' => $main->id,
            'purchase_date' => $today->toDateString(),
            'status' => 'received',
            'grand_total' => 40,
        ]);
        Expense::query()->create([
            'expense_category_id' => $expenseCategory->id,
            'outlet_id' => $main->id,
            'date' => $today->toDateString(),
            'amount' => 10,
        ]);
        Reservation::query()->create([
            'reservation_no' => 'RES-DASH-001',
            'outlet_id' => $main->id,
            'floor_id' => $floor->id,
            'table_id' => $table->id,
            'customer_name' => 'Reserved Guest',
            'customer_phone' => '091234567',
            'guest_count' => 4,
            'reservation_date' => $today->toDateString(),
            'checkin_time' => '18:00',
            'status' => 'confirmed',
        ]);

        $response = $this->getJson('/api/v1/dashboard/admin?outlet_id='.$main->id.'&date_from='.$today->toDateString().'&date_to='.$today->toDateString())
            ->assertOk()
            ->json();

        $this->assertSame(80.0, (float) $response['summary']['total_sale']);
        $this->assertSame(80.0, (float) $response['summary']['today_total_sale']);
        $this->assertSame(70.0, (float) $response['summary']['total_purchase']);
        $this->assertSame(70.0, (float) $response['summary']['today_total_purchase']);
        $this->assertSame(10.0, (float) $response['summary']['total_expense']);
        $this->assertSame(10.0, (float) $response['summary']['today_total_expense']);
        $this->assertSame(50.0, (float) $response['summary']['total_profit']);
        $this->assertSame(30.0, (float) $response['summary']['total_payable']);

        $month = collect($response['charts'])->firstWhere('year_month', $today->format('Y-m'));
        $this->assertSame(80.0, (float) $month['sales']);
        $this->assertSame(70.0, (float) $month['purchases']);
        $this->assertSame(50.0, (float) $month['profit']);
        $this->assertSame(10.0, (float) $month['expense']);

        $this->assertSame('Fried Rice', $response['top_selling_products'][0]['name']);
        $this->assertSame(2.0, (float) $response['top_selling_products'][0]['quantity']);
        $this->assertSame('Main Guest', $response['recent_orders'][0]['customer']);
        $this->assertSame(1, $response['reservations']['today']);
        $this->assertSame('Reserved Guest', $response['reservations']['list'][0]['guest']);
        $this->assertNotContains('Branch Only Item', collect($response['top_selling_products'])->pluck('name')->all());
    }
}
