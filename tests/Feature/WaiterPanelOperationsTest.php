<?php

namespace Tests\Feature;

use App\Models\Charge;
use App\Models\Discount;
use App\Models\Floor;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\RestaurantTable;
use App\Models\Role;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WaiterPanelOperationsTest extends TestCase
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

    public function test_waiter_create_data_scopes_floors_and_tables_to_selected_outlet(): void
    {
        $main = Location::query()->create(['name' => 'Outlet 1', 'is_active' => true]);
        $branch = Location::query()->create(['name' => 'Outlet 2', 'is_active' => true]);

        $mainFloor = Floor::query()->create([
            'name' => 'Main Floor',
            'code' => 'MF',
            'location_id' => $main->id,
            'is_active' => true,
        ]);
        $branchFloor = Floor::query()->create([
            'name' => 'Branch Floor',
            'code' => 'BF',
            'location_id' => $branch->id,
            'is_active' => true,
        ]);

        RestaurantTable::query()->create([
            'outlet_id' => $main->id,
            'floor_id' => $mainFloor->id,
            'table_no' => 'M-1',
            'code' => 'M-1',
            'capacity' => 4,
            'status' => 'available',
            'is_active' => true,
        ]);
        $branchTable = RestaurantTable::query()->create([
            'outlet_id' => $branch->id,
            'floor_id' => $branchFloor->id,
            'table_no' => 'B-1',
            'code' => 'B-1',
            'capacity' => 4,
            'status' => 'available',
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/waiter-panel/create-data?location_id='.$branch->id)
            ->assertOk()
            ->json();

        $this->assertSame([$branchFloor->id], collect($response['floors'])->pluck('id')->all());
        $this->assertSame(
            [$branchTable->id],
            collect($response['floors'][0]['tables'])->pluck('id')->all()
        );
    }

    public function test_kitchen_confirmation_is_idempotent_and_tracks_printed_quantity(): void
    {
        $order = $this->createOrder();
        $item = $this->createItem($order, 1, 'Pizza');

        $this->postJson("/api/v1/waiter-panel/orders/{$order->id}/confirm")
            ->assertOk()
            ->assertJsonPath('order.confirmation_status', 'confirmed');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'confirmation_status' => 'confirmed',
        ]);
        $this->assertDatabaseHas('order_items', [
            'id' => $item->id,
            'printed_qty' => 1,
        ]);

        $this->postJson("/api/v1/waiter-panel/orders/{$order->id}/confirm")
            ->assertOk()
            ->assertJsonPath('message', 'Order is already confirmed.');

        $this->assertDatabaseHas('order_items', [
            'id' => $item->id,
            'printed_qty' => 1,
        ]);
    }

    public function test_transport_idempotency_key_replays_the_original_kitchen_response(): void
    {
        $order = $this->createOrder();
        $this->createItem($order, 1, 'Pizza');
        $headers = ['Idempotency-Key' => 'kot-'.$order->id.'-send'];

        $this->withHeaders($headers)
            ->postJson("/api/v1/waiter-panel/orders/{$order->id}/confirm")
            ->assertOk();

        $this->withHeaders($headers)
            ->postJson("/api/v1/waiter-panel/orders/{$order->id}/confirm")
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertDatabaseCount('api_idempotency_keys', 1);
    }

    public function test_order_split_rejects_moving_every_item_and_creates_a_valid_child_order(): void
    {
        $order = $this->createOrder(subtotal: 20);
        $firstItem = $this->createItem($order, 1, 'Pizza');
        $secondItem = $this->createItem($order, 2, 'Salad');

        $this->postJson("/api/v1/waiter-panel/orders/{$order->id}/split", [
            'items' => [
                ['order_item_id' => $firstItem->id, 'move_qty' => 1],
                ['order_item_id' => $secondItem->id, 'move_qty' => 1],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        $response = $this->postJson("/api/v1/waiter-panel/orders/{$order->id}/split", [
            'items' => [
                ['order_item_id' => $firstItem->id, 'move_qty' => 1],
            ],
        ])->assertOk()
            ->assertJsonPath('source_order.id', $order->id)
            ->json();

        $targetOrderId = $response['target_order']['id'];
        $this->assertDatabaseHas('orders', [
            'id' => $targetOrderId,
            'parent_order_id' => $order->id,
            'split_from_order_id' => $order->id,
        ]);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $targetOrderId,
            'item_name_snapshot' => 'Pizza',
            'active_qty' => 1,
        ]);
        $this->assertDatabaseHas('order_items', [
            'id' => $secondItem->id,
            'order_id' => $order->id,
            'active_qty' => 1,
        ]);
    }

    public function test_unpaid_dine_in_order_can_move_to_an_available_table_and_records_activity(): void
    {
        $order = $this->createOrder(subtotal: 20);
        $floor = Floor::query()->create([
            'name' => 'Ground Floor',
            'code' => 'GF',
            'location_id' => $order->outlet_id,
            'is_active' => true,
        ]);
        $source = RestaurantTable::query()->create([
            'outlet_id' => $order->outlet_id,
            'floor_id' => $floor->id,
            'table_no' => 'T1',
            'code' => 'T1',
            'status' => 'occupied',
            'is_active' => true,
        ]);
        $target = RestaurantTable::query()->create([
            'outlet_id' => $order->outlet_id,
            'floor_id' => $floor->id,
            'table_no' => 'T2',
            'code' => 'T2',
            'status' => 'available',
            'is_active' => true,
        ]);
        $order->update([
            'order_type' => 'dine_in',
            'floor_id' => $floor->id,
            'table_id' => $source->id,
        ]);

        $this->postJson("/api/v1/waiter-panel/tables/{$source->id}/swap", [
            'target_table_id' => $target->id,
        ])->assertOk()
            ->assertJsonPath('source_order.table_id', $target->id);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'table_id' => $target->id,
        ]);
        $this->assertDatabaseHas('tables', ['id' => $source->id, 'status' => 'available']);
        $this->assertDatabaseHas('tables', ['id' => $target->id, 'status' => 'occupied']);

        $this->getJson("/api/v1/waiter-panel/tables/{$target->id}/activity")
            ->assertOk()
            ->assertJsonPath('active_order.id', $order->id)
            ->assertJsonFragment(['type' => 'table_swapped']);
    }

    public function test_waiter_adjustments_share_cashier_calculation_and_are_audited(): void
    {
        $order = $this->createOrder(subtotal: 100);
        $discount = Discount::query()->create([
            'name' => 'Staff 10%',
            'value' => 10,
            'type' => 'percentage',
            'is_active' => true,
        ]);
        $tax = TaxRate::query()->create([
            'name' => 'Tax 5%',
            'value' => 5,
            'type' => 'percentage',
            'is_active' => true,
        ]);
        $charge = Charge::query()->create([
            'name' => 'Service 10%',
            'value' => 10,
            'type' => 'percentage',
            'apply_to' => 'all',
            'is_active' => true,
        ]);

        $this->postJson("/api/v1/waiter-panel/orders/{$order->id}/adjustments", [
            'discount_id' => $discount->id,
            'tax_rate_id' => $tax->id,
            'charge_id' => $charge->id,
        ])->assertOk()
            ->assertJsonPath('order.order_discount_amount', '10.00')
            ->assertJsonPath('order.tax_amount', '4.50')
            ->assertJsonPath('order.service_charge_amount', '9.00')
            ->assertJsonPath('order.grand_total', '103.50')
            ->assertJsonPath('order.balance_amount', '103.50');

        $this->assertDatabaseHas('order_change_histories', [
            'order_id' => $order->id,
            'action_type' => 'adjustments_updated',
        ]);
    }

    public function test_item_cancel_supports_partial_quantity_and_releases_table_when_last_qty_is_cancelled(): void
    {
        $order = $this->createOrder(subtotal: 30);
        $floor = Floor::query()->create([
            'name' => 'Ground Floor',
            'code' => 'GF',
            'location_id' => $order->outlet_id,
            'is_active' => true,
        ]);
        $table = RestaurantTable::query()->create([
            'outlet_id' => $order->outlet_id,
            'floor_id' => $floor->id,
            'table_no' => 'T1',
            'code' => 'T1',
            'status' => 'occupied',
            'is_active' => true,
        ]);
        $order->update([
            'order_type' => 'dine_in',
            'floor_id' => $floor->id,
            'table_id' => $table->id,
            'confirmation_status' => 'confirmed',
        ]);
        $item = $this->createItem($order, 1, 'Pizza', qty: 3);

        $this->postJson("/api/v1/waiter-panel/orders/{$order->id}/items/{$item->id}/cancel", [
            'cancel_qty' => 1,
            'cancellation_reason' => 'Customer changed quantity',
        ])->assertOk();

        $this->assertDatabaseHas('order_items', [
            'id' => $item->id,
            'active_qty' => 2,
            'cancelled_qty' => 1,
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'order_status' => 'pending',
        ]);
        $this->assertDatabaseHas('tables', [
            'id' => $table->id,
            'status' => 'occupied',
        ]);

        $this->postJson("/api/v1/waiter-panel/orders/{$order->id}/items/{$item->id}/cancel", [
            'cancel_qty' => 2,
            'cancellation_reason' => 'Customer cancelled item',
        ])->assertOk()
            ->assertJsonPath('order.order_status', 'cancelled');

        $this->assertDatabaseHas('order_items', [
            'id' => $item->id,
            'active_qty' => 0,
            'cancelled_qty' => 3,
        ]);
        $this->assertDatabaseHas('tables', [
            'id' => $table->id,
            'status' => 'available',
        ]);
    }

    public function test_delivery_order_creation_calculates_correct_grand_total_without_forced_tax(): void
    {
        $user = User::query()->first();
        $outlet = Location::query()->create(['name' => 'Delivery Outlet', 'is_active' => true]);
        
        // Active tax rate exists in DB
        TaxRate::query()->create(['name' => 'Commercial Tax 10%', 'value' => 10, 'type' => 'percentage', 'is_active' => true]);
        
        // Open cash register for register requirement
        \App\Models\CashRegister::query()->create([
            'outlet_id' => $outlet->id,
            'cashier_id' => $user->id,
            'cashier_name_snapshot' => $user->name,
            'opening_balance' => 1000,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $cat = \App\Models\Category::query()->create(['name' => 'Curry', 'slug' => 'curry']);
        $unit = \App\Models\FoodMenuUnit::query()->create(['name' => 'Portion', 'code' => 'portion', 'is_active' => true]);
        $printer = \App\Models\Printer::query()->create(['name' => 'Kitchen Printer', 'type' => 'network', 'connection_type' => 'network', 'ip_address' => '127.0.0.1', 'port' => 9100, 'outlet_id' => $outlet->id, 'is_active' => true]);
        $pm = \App\Models\PaymentMethod::query()->create(['name' => 'KPay', 'code' => 'kpay', 'is_active' => true]);
        $food = \App\Models\FoodMenu::query()->create([
            'name' => 'Chicken Curry',
            'code' => 'CC1',
            'category_id' => $cat->id,
            'unit_id' => $unit->id,
            'printer_id' => $printer->id,
            'dine_in_price' => 15000,
            'take_away_price' => 15000,
            'delivery_price' => 15000,
            'outlet_id' => $outlet->id,
            'is_active' => true,
        ]);

        $this->actingAs($user);

        $payload = [
            'outlet_id' => $outlet->id,
            'order_type' => 'delivery',
            'delivery_partner' => 'FoodPanda',
            'customer_name' => 'John Doe',
            'customer_phone' => '091234567',
            'delivery_address' => 'No. 12 Pyay Road',
            'delivery_fee' => 100,
            'payments' => [
                [
                    'payment_method_id' => $pm->id,
                    'amount' => 15100,
                ],
            ],
            'items' => [
                [
                    'item_type' => 'food_menu',
                    'item_id' => $food->id,
                    'qty' => 1,
                    'unit_price' => 15000,
                ],
            ],
        ];

        $res = $this->postJson('/api/v1/waiter-panel/orders', $payload, ['X-Outlet-Id' => $outlet->id]);
        $res->assertCreated();

        $this->assertDatabaseHas('orders', [
            'order_type' => 'delivery',
            'subtotal' => 15000,
            'delivery_fee' => 100,
            'tax_amount' => 0,
            'grand_total' => 15100,
            'paid_amount' => 0,
        ]);
    }

    private function createOrder(float $subtotal = 10): Order
    {
        $outlet = Location::query()->create([
            'name' => 'Test Restaurant',
            'is_active' => true,
        ]);

        return Order::query()->create([
            'order_no' => 'TA'.now()->format('Ymd').'-0001',
            'outlet_id' => $outlet->id,
            'order_type' => 'takeaway',
            'subtotal' => $subtotal,
            'grand_total' => $subtotal,
            'balance_amount' => $subtotal,
            'order_status' => 'pending',
            'confirmation_status' => 'draft',
            'print_status' => 'not_printed',
            'payment_state' => 'unpaid',
        ]);
    }

    private function createItem(Order $order, int $itemId, string $name, float $qty = 1): OrderItem
    {
        $unitPrice = 10;

        return OrderItem::query()->create([
            'order_id' => $order->id,
            'item_type' => 'food_menu',
            'item_id' => $itemId,
            'item_name_snapshot' => $name,
            'qty' => $qty,
            'original_qty' => $qty,
            'active_qty' => $qty,
            'cancelled_qty' => 0,
            'printed_qty' => 0,
            'cancelled_printed_qty' => 0,
            'base_unit_price_snapshot' => $unitPrice,
            'modifier_price' => 0,
            'final_unit_price' => $unitPrice,
            'discount_amount' => 0,
            'amount' => $unitPrice * $qty,
        ]);
    }
}
