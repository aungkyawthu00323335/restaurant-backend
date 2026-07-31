<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KdsTicketFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_kds_tickets_are_scoped_to_outlet_and_use_current_order_schema(): void
    {
        User::create([
            'name' => 'Kitchen User',
            'username' => 'kitchen',
            'email' => 'kitchen@example.com',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);

        $main = Location::create(['name' => 'Outlet 1', 'is_active' => true]);
        $branch = Location::create(['name' => 'Outlet 2', 'is_active' => true]);

        $mainOrder = $this->order($main, 'ORD-KDS-001', 'pending');
        OrderItem::create([
            'order_id' => $mainOrder->id,
            'item_type' => 'food_menu',
            'item_id' => 10,
            'item_name_snapshot' => 'Chicken Rice',
            'unit_name_snapshot' => 'Plate',
            'qty' => 2,
            'original_qty' => 2,
            'active_qty' => 2,
            'cancelled_qty' => 0,
            'base_unit_price_snapshot' => 5000,
            'final_unit_price' => 5000,
            'discount_amount' => 0,
            'amount' => 10000,
            'cost_snapshot' => 2500,
            'item_note' => 'No chili',
        ]);

        $this->order($branch, 'ORD-KDS-002', 'pending');
        $draftOrder = $this->order($main, 'ORD-KDS-DRAFT', 'pending');
        $draftOrder->update([
            'confirmation_status' => 'draft',
            'stock_deduction_status' => 'none',
        ]);

        $response = $this->getJson('/api/v1/kds/tickets?location_id='.$main->id)
            ->assertOk()
            ->json();

        $this->assertCount(1, $response['data']);
        $this->assertSame('ORD-KDS-001', $response['data'][0]['order_number']);
        $this->assertSame('PENDING', $response['data'][0]['kitchen_status']);
        $this->assertSame('Chicken Rice', $response['data'][0]['items'][0]['name']);
        $this->assertSame('No chili', $response['data'][0]['items'][0]['note']);
        $this->assertNotContains('ORD-KDS-DRAFT', collect($response['data'])->pluck('order_number')->all());
    }

    public function test_kds_bump_progresses_order_status_and_keeps_outlet_scope(): void
    {
        User::create([
            'name' => 'Kitchen User',
            'username' => 'kitchen',
            'email' => 'kitchen@example.com',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);

        $main = Location::create(['name' => 'Outlet 1', 'is_active' => true]);
        $branch = Location::create(['name' => 'Outlet 2', 'is_active' => true]);
        $order = $this->order($main, 'ORD-KDS-003', 'pending');

        $this->postJson('/api/v1/kds/tickets/'.$order->id.'/bump', [
            'location_id' => $branch->id,
        ])->assertNotFound();

        $this->postJson('/api/v1/kds/tickets/'.$order->id.'/bump', [
            'location_id' => $main->id,
        ])->assertOk()
            ->assertJsonPath('data.kitchen_status', 'PREPARING');

        $this->assertSame('preparing', $order->fresh()->order_status);
    }

    public function test_kds_ready_bump_does_not_complete_unpaid_order(): void
    {
        User::create([
            'name' => 'Kitchen User',
            'username' => 'kitchen',
            'email' => 'kitchen@example.com',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);

        $main = Location::create(['name' => 'Outlet 1', 'is_active' => true]);
        $order = $this->order($main, 'ORD-KDS-READY', 'ready');

        $this->postJson('/api/v1/kds/tickets/'.$order->id.'/bump', [
            'location_id' => $main->id,
        ])->assertOk()
            ->assertJsonPath('data.kitchen_status', 'READY');

        $order->refresh();
        $this->assertSame('ready', $order->order_status);
        $this->assertNull($order->completed_at);
        $this->assertSame('unpaid', $order->payment_state);
    }

    private function order(Location $location, string $orderNo, string $status): Order
    {
        return Order::create([
            'order_no' => $orderNo,
            'outlet_id' => $location->id,
            'order_type' => 'dine_in',
            'order_status' => $status,
            'confirmation_status' => 'confirmed',
            'payment_state' => 'unpaid',
            'stock_deduction_status' => 'deducted',
            'subtotal' => 10000,
            'grand_total' => 10000,
        ]);
    }
}
