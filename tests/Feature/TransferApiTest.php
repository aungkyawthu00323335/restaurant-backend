<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductStockMovement;
use App\Models\ProductUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransferApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_product_transfer_moves_stock_without_negative_source(): void
    {
        [$from, $to, $product] = $this->productWithOpeningStock(10);

        $transfer = $this->postJson('/api/v1/inventory/transfers', [
            'from_location_id' => $from->id,
            'to_location_id' => $to->id,
            'status' => 'completed',
            'items' => [[
                'item_id' => $product->id,
                'type' => 'product',
                'unit_type' => 'consumption',
                'quantity' => 6,
                'unit_cost' => 3,
            ]],
        ])->assertOk()->json();

        $this->assertSame(4.0, $this->productStock($product->id, $from->id));
        $this->assertSame(6.0, $this->productStock($product->id, $to->id));

        $this->postJson('/api/v1/inventory/transfers', [
            'from_location_id' => $from->id,
            'to_location_id' => $to->id,
            'status' => 'completed',
            'items' => [[
                'item_id' => $product->id,
                'type' => 'product',
                'unit_type' => 'consumption',
                'quantity' => 5,
                'unit_cost' => 3,
            ]],
        ])->assertUnprocessable();

        $this->assertSame(4.0, $this->productStock($product->id, $from->id));
        $this->assertSame(6.0, $this->productStock($product->id, $to->id));

        $this->deleteJson('/api/v1/inventory/transfers/'.($transfer['id'] ?? $transfer['data']['id']))
            ->assertNoContent();

        $this->assertSame(10.0, $this->productStock($product->id, $from->id));
        $this->assertSame(0.0, $this->productStock($product->id, $to->id));
    }

    public function test_completed_product_transfer_cannot_be_reversed_after_destination_stock_is_used(): void
    {
        [$from, $to, $product] = $this->productWithOpeningStock(10);

        $transfer = $this->postJson('/api/v1/inventory/transfers', [
            'from_location_id' => $from->id,
            'to_location_id' => $to->id,
            'status' => 'completed',
            'items' => [[
                'item_id' => $product->id,
                'type' => 'product',
                'unit_type' => 'consumption',
                'quantity' => 6,
                'unit_cost' => 3,
            ]],
        ])->assertOk()->json();

        ProductStockMovement::create([
            'product_id' => $product->id,
            'location_id' => $to->id,
            'direction' => 'out',
            'reason_code' => 'sale',
            'quantity' => 2,
            'unit_cost' => 3,
            'amount' => 6,
            'reference' => 'Test Sale',
            'occurred_at' => now(),
        ]);

        $this->deleteJson('/api/v1/inventory/transfers/'.($transfer['id'] ?? $transfer['data']['id']))
            ->assertUnprocessable();

        $this->assertSame(4.0, $this->productStock($product->id, $from->id));
        $this->assertSame(4.0, $this->productStock($product->id, $to->id));
    }

    private function productWithOpeningStock(float $qty): array
    {
        $from = Location::create(['name' => 'Main Outlet', 'is_active' => true]);
        $to = Location::create(['name' => 'Branch Outlet', 'is_active' => true]);
        $category = ProductCategory::create(['name' => 'Retail', 'code' => 'RTL', 'is_active' => true]);
        $unit = ProductUnit::create(['name' => 'Piece', 'code' => 'PCS', 'is_active' => true]);

        $product = Product::create([
            'name' => 'Bottle Water',
            'code' => 'P-WATER',
            'product_category_id' => $category->id,
            'product_unit_id' => $unit->id,
            'purchase_price_per_unit' => 3,
            'sell_price_per_unit' => 5,
            'low_stock_qty' => 0,
            'is_active' => true,
        ]);

        ProductStockMovement::create([
            'product_id' => $product->id,
            'location_id' => $from->id,
            'direction' => 'in',
            'reason_code' => 'opening_stock_in',
            'quantity' => $qty,
            'unit_cost' => 3,
            'amount' => $qty * 3,
            'reference' => 'Test Opening Stock',
            'occurred_at' => now(),
        ]);

        return [$from, $to, $product];
    }

    private function productStock(int $productId, int $locationId): float
    {
        return round((float) ProductStockMovement::query()
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->selectRaw("COALESCE(SUM(CASE WHEN LOWER(direction) = 'in' THEN quantity ELSE -quantity END), 0) AS net")
            ->value('net'), 4);
    }
}
