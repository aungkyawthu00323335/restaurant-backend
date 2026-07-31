<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ConsumptionUnit;
use App\Models\FoodMenu;
use App\Models\FoodMenuIngredient;
use App\Models\FoodMenuUnit;
use App\Models\Ingredient;
use App\Models\IngredientBatch;
use App\Models\IngredientCategory;
use App\Models\IngredientStockMovement;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Printer;
use App\Models\PurchaseUnit;
use App\Services\OrderStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FoodMenuIngredientStockDeductionTest extends TestCase
{
    use RefreshDatabase;

    public function test_food_menu_ingredient_stock_deduction_uses_consumption_unit_qty(): void
    {
        $outlet = Location::create(['name' => 'Main Outlet', 'is_active' => true]);
        
        $pUnit = PurchaseUnit::create(['name' => 'Bag', 'code' => 'BAG', 'is_active' => true]);
        $cUnit = ConsumptionUnit::create(['name' => 'Gram', 'code' => 'G', 'is_active' => true]);
        $fmUnit = FoodMenuUnit::create(['name' => 'Cup', 'is_active' => true]);

        $ingredientCat = IngredientCategory::create(['name' => 'Dry Goods', 'is_active' => true]);
        $foodCat = Category::create(['name' => 'Beverages', 'slug' => 'beverages', 'is_active' => true]);
        $printer = Printer::create([
            'name' => 'Kitchen Printer',
            'ip_address' => '192.168.1.100',
            'port' => 9100,
            'paper_size' => 80,
            'is_active' => true,
        ]);

        $ingredient = Ingredient::create([
            'name' => 'Sugar',
            'sku_code' => 'ING-SUGAR',
            'ingredient_category_id' => $ingredientCat->id,
            'purchase_unit_id' => $pUnit->id,
            'consumption_unit_id' => $cUnit->id,
            'conversion_rate' => 1000.0, // 1 Bag = 1000 Grams
            'purchase_price' => 50.0,
            'is_active' => true,
        ]);

        // Seed 5000 Grams stock
        $batch = IngredientBatch::create([
            'ingredient_id' => $ingredient->id,
            'location_id' => $outlet->id,
            'original_qty' => 5000.0,
            'usable_qty' => 5000.0,
            'unit_cost' => 0.05,
            'received_at' => now(),
        ]);

        IngredientStockMovement::create([
            'ingredient_id' => $ingredient->id,
            'location_id' => $outlet->id,
            'ingredient_batch_id' => $batch->id,
            'direction' => 'IN',
            'reason_code' => 'opening',
            'unit_type' => 'consumption',
            'quantity_input' => 5000.0,
            'quantity_consumption' => 5000.0,
            'batch_unit_cost' => 0.05,
            'reference' => 'OPENING',
            'occurred_at' => now(),
        ]);

        // Food Menu: "Sweet Tea" with 10 Grams of Sugar per serving
        $foodMenu = FoodMenu::create([
            'name' => 'Sweet Tea',
            'code' => 'FM-TEA',
            'category_id' => $foodCat->id,
            'printer_id' => $printer->id,
            'unit_id' => $fmUnit->id,
            'stock_deduction_method' => 'deduct_ingredient_on_sale',
            'dine_in_price' => 5.0,
            'take_away_price' => 5.0,
            'delivery_price' => 5.0,
            'is_active' => true,
        ]);

        FoodMenuIngredient::create([
            'food_menu_id' => $foodMenu->id,
            'ingredient_id' => $ingredient->id,
            'unit_id' => $cUnit->id,
            'required_qty' => 10.0, // 10 Grams
            'unit_cost_snapshot' => 0.05,
            'amount' => 0.50,
        ]);

        // Order 2 Sweet Teas (requires 20 Grams of Sugar total)
        $order = Order::create([
            'order_no' => 'ORD-TEST-DEDUCT-001',
            'outlet_id' => $outlet->id,
            'order_type' => 'dine_in',
            'order_status' => 'pending',
            'confirmation_status' => 'draft',
            'payment_state' => 'unpaid',
            'stock_deduction_status' => 'none',
            'subtotal' => 10.0,
            'grand_total' => 10.0,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'item_type' => 'food_menu',
            'item_id' => $foodMenu->id,
            'item_name_snapshot' => $foodMenu->name,
            'unit_name_snapshot' => 'Cup',
            'qty' => 2,
            'original_qty' => 2,
            'active_qty' => 2,
            'cancelled_qty' => 0,
            'base_unit_price_snapshot' => 5.0,
            'final_unit_price' => 5.0,
            'discount_amount' => 0,
            'amount' => 10.0,
            'cost_snapshot' => 0.50,
        ]);

        $service = app(OrderStockService::class);
        $service->deductOrderStock($order, $outlet->id);

        // Batch usable_qty should now be 5000 - 20 = 4980 (NOT 5000 - 20000 = -15000!)
        $batch->refresh();
        $this->assertEquals(4980.0, (float) $batch->usable_qty);

        // Check net movement
        $netStock = IngredientStockMovement::query()
            ->where('ingredient_id', $ingredient->id)
            ->where('location_id', $outlet->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN LOWER(direction) = 'in' THEN quantity_consumption ELSE -quantity_consumption END), 0) as net")
            ->value('net');

        $this->assertEquals(4980.0, (float) $netStock);
    }
}
