<?php

namespace Tests\Feature;

use App\Models\ConsumptionUnit;
use App\Models\Ingredient;
use App\Models\IngredientBatch;
use App\Models\IngredientCategory;
use App\Models\IngredientStockMovement;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductStockMovement;
use App\Models\ProductUnit;
use App\Models\PurchaseUnit;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_received_purchase_adds_ingredient_and_product_stock_to_selected_outlet_and_reports_it(): void
    {
        [$main, $branch, $supplier, $ingredient, $purchaseUnit, $product] = $this->purchaseFixtures();

        $purchase = $this->postJson('/api/v1/purchases', [
            'ref_no' => 'PUR-UNIT-001',
            'supplier_id' => $supplier->id,
            'location_id' => $main->id,
            'purchase_date' => '2026-07-29',
            'status' => 'received',
            'items' => [
                [
                    'ingredient_id' => $ingredient->id,
                    'purchase_unit_id' => $purchaseUnit->id,
                    'unit_type' => 'purchase',
                    'quantity' => 1.5,
                    'unit_price' => 2000,
                ],
                [
                    'product_id' => $product->id,
                    'unit_type' => 'consumption',
                    'quantity' => 4,
                    'unit_price' => 3,
                ],
            ],
        ])->assertOk()->json();

        $this->assertSame(1500.0, $this->ingredientStock($ingredient->id, $main->id));
        $this->assertSame(0.0, $this->ingredientStock($ingredient->id, $branch->id));
        $this->assertSame(4.0, $this->productStock($product->id, $main->id));
        $this->assertSame(0.0, $this->productStock($product->id, $branch->id));

        $report = $this->getJson('/api/v1/purchase-report?location_id='.$main->id)
            ->assertOk()
            ->json();

        $this->assertSame(2, $report['total']);
        $ingredientRow = collect($report['data'])->firstWhere('item_type', 'Ingredient');
        $productRow = collect($report['data'])->firstWhere('item_type', 'Product');
        $this->assertSame(1.5, (float) $ingredientRow['quantity']);
        $this->assertSame(1500.0, (float) $ingredientRow['stock_quantity']);
        $this->assertSame(4.0, (float) $productRow['stock_quantity']);

        $this->getJson('/api/v1/purchases?location_id='.$branch->id)
            ->assertOk()
            ->assertJsonPath('total', 0);

        $purchaseId = $purchase['id'] ?? $purchase['data']['id'];
        $this->deleteJson('/api/v1/purchases/'.$purchaseId)->assertOk();

        $this->assertSame(0.0, $this->ingredientStock($ingredient->id, $main->id));
        $this->assertSame(0.0, $this->productStock($product->id, $main->id));
    }

    public function test_received_purchase_cannot_be_reversed_after_received_stock_is_used(): void
    {
        [$main, , $supplier, $ingredient, $purchaseUnit, $product] = $this->purchaseFixtures();

        $purchase = $this->postJson('/api/v1/purchases', [
            'ref_no' => 'PUR-LOCK-001',
            'supplier_id' => $supplier->id,
            'location_id' => $main->id,
            'purchase_date' => '2026-07-29',
            'status' => 'received',
            'items' => [
                [
                    'ingredient_id' => $ingredient->id,
                    'purchase_unit_id' => $purchaseUnit->id,
                    'unit_type' => 'purchase',
                    'quantity' => 1,
                    'unit_price' => 2000,
                ],
                [
                    'product_id' => $product->id,
                    'unit_type' => 'consumption',
                    'quantity' => 4,
                    'unit_price' => 3,
                ],
            ],
        ])->assertOk()->json();

        IngredientBatch::withoutGlobalScopes()
            ->where('ingredient_id', $ingredient->id)
            ->where('location_id', $main->id)
            ->firstOrFail()
            ->update(['usable_qty' => 900]);

        ProductStockMovement::query()->create([
            'product_id' => $product->id,
            'location_id' => $main->id,
            'direction' => 'out',
            'reason_code' => 'sale',
            'quantity' => 1,
            'unit_cost' => 3,
            'amount' => 3,
            'reference' => 'Test Sale',
            'occurred_at' => now(),
        ]);

        $purchaseId = $purchase['id'] ?? $purchase['data']['id'];
        $this->deleteJson('/api/v1/purchases/'.$purchaseId)->assertUnprocessable();

        $this->assertSame(1000.0, $this->ingredientStock($ingredient->id, $main->id));
        $this->assertSame(3.0, $this->productStock($product->id, $main->id));
    }

    private function purchaseFixtures(): array
    {
        $main = Location::create(['name' => 'Main Outlet', 'is_active' => true]);
        $branch = Location::create(['name' => 'Branch Outlet', 'is_active' => true]);
        $supplier = Supplier::create(['name' => 'Vendor A', 'phone' => '091234567']);

        $ingredientCategory = IngredientCategory::create(['name' => 'Dry Goods', 'is_active' => true]);
        $purchaseUnit = PurchaseUnit::create(['name' => 'Bag', 'is_active' => true]);
        $consumptionUnit = ConsumptionUnit::create(['name' => 'Gram', 'is_active' => true]);

        $ingredient = Ingredient::create([
            'name' => 'Rice',
            'type' => 'single',
            'has_ingredient_mapping' => false,
            'ingredient_category_id' => $ingredientCategory->id,
            'purchase_unit_id' => $purchaseUnit->id,
            'consumption_unit_id' => $consumptionUnit->id,
            'conversion_rate' => 1000,
            'purchase_price' => 2000,
            'sku_code' => 'ING-RICE',
            'is_active' => true,
        ]);

        $productCategory = ProductCategory::create(['name' => 'Retail', 'code' => 'RTL', 'is_active' => true]);
        $productUnit = ProductUnit::create(['name' => 'Piece', 'code' => 'PCS', 'is_active' => true]);
        $product = Product::create([
            'name' => 'Bottle Water',
            'code' => 'P-WATER',
            'product_category_id' => $productCategory->id,
            'product_unit_id' => $productUnit->id,
            'purchase_price_per_unit' => 3,
            'sell_price_per_unit' => 5,
            'low_stock_qty' => 0,
            'is_active' => true,
        ]);

        return [$main, $branch, $supplier, $ingredient, $purchaseUnit, $product];
    }

    private function ingredientStock(int $ingredientId, int $locationId): float
    {
        return round((float) IngredientStockMovement::query()
            ->where('ingredient_id', $ingredientId)
            ->where('location_id', $locationId)
            ->selectRaw("COALESCE(SUM(CASE WHEN LOWER(direction) = 'in' THEN quantity_consumption ELSE -quantity_consumption END), 0) AS net")
            ->value('net'), 4);
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
