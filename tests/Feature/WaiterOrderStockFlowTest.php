<?php

namespace Tests\Feature;

use App\Models\IngredientBatch;
use App\Models\IngredientStockMovement;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductStockMovement;
use App\Models\ProductUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WaiterOrderStockFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_waiter_confirm_add_item_cancel_item_and_cancel_order_keep_product_stock_per_outlet(): void
    {
        User::create([
            'name' => 'Waiter',
            'username' => 'waiter1',
            'email' => 'waiter1@example.com',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);

        $main = Location::create(['name' => 'Outlet 1', 'is_active' => true]);
        $branch = Location::create(['name' => 'Outlet 2', 'is_active' => true]);
        $product = $this->product();

        $this->seedProductStock($product, $main, 5);
        $this->seedProductStock($product, $branch, 7);

        $order = Order::create([
            'order_no' => 'ORD-STOCK-001',
            'outlet_id' => $main->id,
            'order_type' => 'dine_in',
            'order_status' => 'pending',
            'confirmation_status' => 'draft',
            'payment_state' => 'unpaid',
            'stock_deduction_status' => 'none',
            'subtotal' => 20,
            'grand_total' => 20,
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'item_type' => 'product',
            'item_id' => $product->id,
            'item_name_snapshot' => $product->name,
            'unit_name_snapshot' => 'Piece',
            'qty' => 2,
            'original_qty' => 2,
            'active_qty' => 2,
            'cancelled_qty' => 0,
            'base_unit_price_snapshot' => 10,
            'final_unit_price' => 10,
            'discount_amount' => 0,
            'amount' => 20,
            'cost_snapshot' => 4,
        ]);

        $this->postJson("/api/v1/waiter-panel/orders/{$order->id}/confirm", [
            'location_id' => $main->id,
        ])->assertOk();

        $this->assertSame(3.0, $this->productStock($product->id, $main->id));
        $this->assertSame(7.0, $this->productStock($product->id, $branch->id));
        $this->assertSame(3.0, $this->fifoProductStock($product->id, $main->id));

        $this->postJson("/api/v1/waiter-panel/orders/{$order->id}/add-items", [
            'location_id' => $main->id,
            'items' => [
                [
                    'item_type' => 'product',
                    'item_id' => $product->id,
                    'qty' => 1,
                ],
            ],
        ])->assertOk();

        $this->assertSame(2.0, $this->productStock($product->id, $main->id));

        $this->postJson("/api/v1/waiter-panel/orders/{$order->id}/items/{$item->id}/cancel", [
            'location_id' => $main->id,
            'cancel_qty' => 1,
            'cancellation_reason' => 'Guest changed order',
        ])->assertOk();

        $this->assertSame(3.0, $this->productStock($product->id, $main->id));

        $this->postJson("/api/v1/waiter-panel/orders/{$order->id}/cancel", [
            'location_id' => $main->id,
            'cancellation_reason' => 'Guest left',
        ])->assertOk();

        $this->assertSame(5.0, $this->productStock($product->id, $main->id));
        $this->assertSame(7.0, $this->productStock($product->id, $branch->id));
        $this->assertSame(5.0, $this->fifoProductStock($product->id, $main->id));
    }

    public function test_waiter_confirmation_cannot_deduct_more_product_than_the_outlet_has(): void
    {
        User::create([
            'name' => 'Waiter',
            'username' => 'waiter1',
            'email' => 'waiter1@example.com',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);

        $main = Location::create(['name' => 'Outlet 1', 'is_active' => true]);
        $product = $this->product();
        $this->seedProductStock($product, $main, 1);

        $order = Order::create([
            'order_no' => 'ORD-STOCK-002',
            'outlet_id' => $main->id,
            'order_type' => 'dine_in',
            'order_status' => 'pending',
            'confirmation_status' => 'draft',
            'payment_state' => 'unpaid',
            'stock_deduction_status' => 'none',
            'subtotal' => 20,
            'grand_total' => 20,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'item_type' => 'product',
            'item_id' => $product->id,
            'item_name_snapshot' => $product->name,
            'unit_name_snapshot' => 'Piece',
            'qty' => 2,
            'original_qty' => 2,
            'active_qty' => 2,
            'cancelled_qty' => 0,
            'base_unit_price_snapshot' => 10,
            'final_unit_price' => 10,
            'discount_amount' => 0,
            'amount' => 20,
            'cost_snapshot' => 4,
        ]);

        $this->postJson("/api/v1/waiter-panel/orders/{$order->id}/confirm", [
            'location_id' => $main->id,
        ])->assertUnprocessable();

        $this->assertSame(1.0, $this->productStock($product->id, $main->id));
    }

    private function product(): Product
    {
        $category = ProductCategory::create(['name' => 'Retail', 'code' => 'RTL', 'is_active' => true]);
        $unit = ProductUnit::create(['name' => 'Piece', 'code' => 'PCS', 'is_active' => true]);

        return Product::create([
            'name' => 'Bottle Water',
            'code' => 'P-WATER',
            'product_category_id' => $category->id,
            'product_unit_id' => $unit->id,
            'purchase_price_per_unit' => 4,
            'sell_price_per_unit' => 10,
            'low_stock_qty' => 0,
            'is_active' => true,
        ]);
    }

    private function seedProductStock(Product $product, Location $location, float $qty): void
    {
        $batch = IngredientBatch::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'original_qty' => $qty,
            'usable_qty' => $qty,
            'unit_cost' => 4,
            'received_at' => now(),
        ]);

        IngredientStockMovement::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'ingredient_batch_id' => $batch->id,
            'direction' => 'IN',
            'reason_code' => 'opening',
            'unit_type' => 'consumption',
            'quantity_input' => $qty,
            'quantity_consumption' => $qty,
            'batch_unit_cost' => 4,
            'reference' => 'TEST-STOCK',
            'occurred_at' => now(),
        ]);

        ProductStockMovement::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'direction' => 'in',
            'reason_code' => 'opening',
            'quantity' => $qty,
            'unit_cost' => 4,
            'amount' => $qty * 4,
            'reference' => 'TEST-STOCK',
            'occurred_at' => now(),
        ]);
    }

    private function productStock(int $productId, int $locationId): float
    {
        return round((float) ProductStockMovement::query()
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->selectRaw("COALESCE(SUM(CASE WHEN LOWER(direction) = 'in' THEN quantity ELSE -quantity END), 0) AS net")
            ->value('net'), 4);
    }

    private function fifoProductStock(int $productId, int $locationId): float
    {
        return round((float) IngredientStockMovement::query()
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->selectRaw("COALESCE(SUM(CASE WHEN LOWER(direction) = 'in' THEN quantity_consumption ELSE -quantity_consumption END), 0) AS net")
            ->value('net'), 4);
    }
}
