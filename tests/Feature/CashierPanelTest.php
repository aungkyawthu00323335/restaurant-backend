<?php

namespace Tests\Feature;

use App\Models\CashRegister;
use App\Models\Floor;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\RestaurantTable;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashierPanelTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;
    protected Location $location;
    protected PaymentMethod $cashMethod;
    protected PaymentMethod $kpayMethod;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::query()->firstOrCreate(
            ['role_name' => 'Super Admin'],
            ['description' => 'Full access', 'status' => 'active']
        );

        $this->cashier = User::query()->create([
            'name' => 'Cashier User',
            'email' => 'cashier@example.com',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        $this->location = Location::query()->create([
            'name' => 'Main Outlet',
            'is_active' => true,
        ]);

        $this->cashMethod = PaymentMethod::query()->create([
            'name' => 'Cash',
            'is_active' => true,
        ]);

        $this->kpayMethod = PaymentMethod::query()->create([
            'name' => 'KPay',
            'is_active' => true,
        ]);
    }

    public function test_can_open_and_close_register_with_difference_calculation()
    {
        $this->actingAs($this->cashier);

        // Open Register
        $openResponse = $this->postJson('/api/v1/cashier-panel/register/open', [
            'outlet_id' => $this->location->id,
            'opening_balance' => 50000,
            'cashier_name' => 'Cashier User',
            'note' => 'Morning Shift',
        ]);

        $openResponse->assertStatus(201)
            ->assertJsonPath('register.status', 'open')
            ->assertJsonPath('register.opening_balance', '50000.00');

        $registerId = $openResponse->json('register.id');

        // Update cash sale manually for testing summary
        $register = CashRegister::find($registerId);
        $register->update(['cash_sale_amount' => 20000, 'other_payment_amount' => 15000]);

        // Close Register
        $closeResponse = $this->postJson("/api/v1/cashier-panel/register/{$registerId}/close", [
            'actual_closing_balance' => 70000,
            'note' => 'Shift closed OK',
        ]);

        // Expected = 50,000 + 20,000 = 70,000. Actual = 70,000. Difference = 0.
        $closeResponse->assertStatus(200)
            ->assertJsonPath('register.status', 'closed')
            ->assertJsonPath('register.expected_closing_balance', '70000.00')
            ->assertJsonPath('register.difference_amount', '0.00');
    }

    public function test_cashier_create_data_scopes_floors_and_tables_to_selected_outlet()
    {
        $otherLocation = Location::query()->create([
            'name' => 'Branch Outlet',
            'is_active' => true,
        ]);

        $mainFloor = Floor::query()->create([
            'name' => 'Main Floor',
            'code' => 'MF',
            'location_id' => $this->location->id,
            'is_active' => true,
        ]);
        $branchFloor = Floor::query()->create([
            'name' => 'Branch Floor',
            'code' => 'BF',
            'location_id' => $otherLocation->id,
            'is_active' => true,
        ]);

        RestaurantTable::query()->create([
            'outlet_id' => $this->location->id,
            'floor_id' => $mainFloor->id,
            'table_no' => 'M-1',
            'code' => 'M-1',
            'capacity' => 4,
            'status' => 'available',
            'is_active' => true,
        ]);
        $branchTable = RestaurantTable::query()->create([
            'outlet_id' => $otherLocation->id,
            'floor_id' => $branchFloor->id,
            'table_no' => 'B-1',
            'code' => 'B-1',
            'capacity' => 4,
            'status' => 'available',
            'is_active' => true,
        ]);
        $order = Order::query()->create([
            'order_no' => 'ORD-BRANCH-001',
            'outlet_id' => $otherLocation->id,
            'floor_id' => $branchFloor->id,
            'table_id' => $branchTable->id,
            'order_type' => 'dine_in',
            'order_status' => 'pending',
            'payment_state' => 'unpaid',
            'subtotal' => 10000,
            'grand_total' => 10000,
        ]);

        $response = $this->getJson('/api/v1/cashier-panel/create-data?location_id='.$otherLocation->id)
            ->assertOk()
            ->json();

        $this->assertSame([$branchFloor->id], collect($response['floors'])->pluck('id')->all());
        $this->assertSame(
            [$branchTable->id],
            collect($response['floors'][0]['tables'])->pluck('id')->all()
        );
        $this->assertSame(
            $order->id,
            $response['floors'][0]['tables'][0]['active_order']['id'] ?? null
        );
    }

    public function test_cashier_takeaway_tab_returns_takeaway_orders()
    {
        $order = Order::query()->create([
            'order_no' => 'ORD-TA-001',
            'outlet_id' => $this->location->id,
            'order_type' => 'takeaway',
            'order_status' => 'pending',
            'payment_state' => 'unpaid',
            'subtotal' => 10000,
            'grand_total' => 10000,
        ]);

        $response = $this->getJson('/api/v1/cashier-panel/orders?outlet_id='.$this->location->id.'&order_type=take_away')
            ->assertOk()
            ->json();

        $this->assertSame([$order->id], collect($response['data'])->pluck('id')->all());
    }

    public function test_payment_fails_if_register_is_not_open()
    {
        $this->actingAs($this->cashier);

        $order = Order::query()->create([
            'order_no' => 'ORD-1001',
            'outlet_id' => $this->location->id,
            'order_type' => 'dine_in',
            'order_status' => 'pending',
            'payment_state' => 'unpaid',
            'subtotal' => 10000,
            'grand_total' => 10000,
        ]);

        $response = $this->postJson("/api/v1/cashier-panel/orders/{$order->id}/complete-payment", [
            'payments' => [
                ['payment_method_id' => $this->cashMethod->id, 'amount' => 10000],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Please open register before payment.']);
    }

    public function test_payment_submit_updates_cash_and_non_cash_register_totals_and_releases_table()
    {
        $this->actingAs($this->cashier);

        // Open Register
        $register = CashRegister::create([
            'outlet_id' => $this->location->id,
            'cashier_id' => $this->cashier->id,
            'cashier_name_snapshot' => $this->cashier->name,
            'opened_at' => now(),
            'opening_balance' => 10000,
            'status' => 'open',
        ]);

        $table = RestaurantTable::query()->create([
            'outlet_id' => $this->location->id,
            'table_no' => 'T-01',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        $order = Order::query()->create([
            'order_no' => 'ORD-1002',
            'outlet_id' => $this->location->id,
            'table_id' => $table->id,
            'order_type' => 'dine_in',
            'order_status' => 'pending',
            'payment_state' => 'unpaid',
            'subtotal' => 15000,
            'grand_total' => 15000,
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'item_type' => 'food_menu',
            'item_id' => 1,
            'item_name_snapshot' => 'Fried Rice',
            'unit_name_snapshot' => 'Portion',
            'base_unit_price_snapshot' => 15000,
            'final_unit_price' => 15000,
            'qty' => 1,
            'amount' => 15000,
        ]);

        // Submit Split Payment: 5,000 Cash, 10,000 KPay
        $response = $this->postJson("/api/v1/cashier-panel/orders/{$order->id}/complete-payment", [
            'payments' => [
                ['payment_method_id' => $this->cashMethod->id, 'amount' => 5000],
                ['payment_method_id' => $this->kpayMethod->id, 'amount' => 10000],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('order.order_status', 'completed')
            ->assertJsonPath('order.payment_state', 'paid');

        // Check Register totals
        $register->refresh();
        $this->assertEquals('5000.00', $register->cash_sale_amount);
        $this->assertEquals('10000.00', $register->other_payment_amount);

        // Check Table status updated to available
        $table->refresh();
        $this->assertEquals('available', $table->status);
    }

    public function test_partial_payment_does_not_create_sale_or_deduct_stock(): void
    {
        $this->actingAs($this->cashier);

        $register = CashRegister::query()->create([
            'outlet_id' => $this->location->id,
            'cashier_id' => $this->cashier->id,
            'cashier_name_snapshot' => $this->cashier->name,
            'opened_at' => now(),
            'opening_balance' => 0,
            'status' => 'open',
        ]);

        $order = Order::query()->create([
            'order_no' => 'ORD-PARTIAL-001',
            'outlet_id' => $this->location->id,
            'order_type' => 'takeaway',
            'order_status' => 'pending',
            'payment_state' => 'unpaid',
            'stock_deduction_status' => 'none',
            'subtotal' => 10000,
            'grand_total' => 10000,
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'item_type' => 'food_menu',
            'item_id' => 1,
            'item_name_snapshot' => 'Fried Rice',
            'unit_name_snapshot' => 'Portion',
            'base_unit_price_snapshot' => 10000,
            'final_unit_price' => 10000,
            'qty' => 1,
            'amount' => 10000,
        ]);

        $response = $this->postJson("/api/v1/cashier-panel/orders/{$order->id}/complete-payment", [
            'payments' => [
                ['payment_method_id' => $this->cashMethod->id, 'amount' => 4000],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('order.order_status', 'confirmed')
            ->assertJsonPath('order.payment_state', 'partially_paid')
            ->assertJsonPath('order.paid_amount', '4000.00')
            ->assertJsonPath('order.balance_amount', '6000.00')
            ->assertJsonPath('order.stock_deduction_status', 'none');

        $register->refresh();
        $this->assertSame('4000.00', $register->cash_sale_amount);
        $this->assertDatabaseMissing('sales', ['order_id' => $order->id]);
    }

    public function test_delivery_tab_returns_active_delivery_orders()
    {
        $order = Order::query()->create([
            'order_no' => 'ORD-DV-001',
            'outlet_id' => $this->location->id,
            'order_type' => 'delivery',
            'order_status' => 'pending',
            'payment_state' => 'unpaid',
            'subtotal' => 15000,
            'grand_total' => 15100,
        ]);

        $response = $this->getJson('/api/v1/cashier-panel/orders?outlet_id='.$this->location->id.'&order_type=delivery')
            ->assertOk()
            ->json();

        $this->assertSame([$order->id], collect($response['data'])->pluck('id')->all());
    }
}
