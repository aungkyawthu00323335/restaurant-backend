<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ConsumptionUnit;
use App\Models\FoodMenu;
use App\Models\FoodMenuUnit;
use App\Models\Ingredient;
use App\Models\IngredientBatch;
use App\Models\IngredientCategory;
use App\Models\IngredientStockMovement;
use App\Models\Location;
use App\Models\Printer;
use App\Models\PurchaseUnit;
use App\Services\FifoInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FoodMenuProductionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_food_menu_production_moves_stock_per_outlet_and_reports_actual_fifo_cost(): void
    {
        [$main, $branch, $foodMenu, $ingredient] = $this->productionFixtures();

        $this->getJson('/api/v1/food-menu-productions/create-data?location_id='.$main->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.food_menus');

        $this->getJson('/api/v1/food-menu-productions/create-data?location_id='.$branch->id)
            ->assertOk()
            ->assertJsonCount(0, 'data.food_menus');

        $production = $this->postJson('/api/v1/food-menu-productions', [
            'location_id' => $main->id,
            'production_date' => '2026-07-29',
            'food_menu_id' => $foodMenu->id,
            'production_qty' => 3,
            'note' => 'Lunch prep',
        ])->assertCreated()
            ->assertJsonPath('data.total_ingredient_cost', 30)
            ->assertJsonPath('data.production_cost_per_unit', 10)
            ->assertJsonPath('data.details.0.amount', 30)
            ->json('data');

        $this->assertSame(4.0, $this->ingredientStock($ingredient->id, $main->id));
        $this->assertSame(0.0, $this->ingredientStock($ingredient->id, $branch->id));
        $this->assertSame(3.0, $this->foodMenuStock($foodMenu->id, $main->id));
        $this->assertSame(0.0, $this->foodMenuStock($foodMenu->id, $branch->id));

        $report = $this->getJson('/api/v1/food-menu-productions?location_id='.$main->id.'&search=Chicken Rice')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $report['summary']['total_production_count']);
        $this->assertSame(3.0, (float) $report['summary']['total_produced_qty']);
        $this->assertSame(30.0, (float) $report['summary']['filtered_production_amount']);
        $this->assertSame($production['id'], $report['records']['data'][0]['id']);

        $this->getJson('/api/v1/food-menu-productions?location_id='.$branch->id)
            ->assertOk()
            ->assertJsonPath('data.summary.total_production_count', 0);

        $this->postJson('/api/v1/food-menu-productions', [
            'location_id' => $main->id,
            'production_date' => '2026-07-29',
            'food_menu_id' => $foodMenu->id,
            'production_qty' => 3,
        ])->assertUnprocessable();

        $this->assertSame(4.0, $this->ingredientStock($ingredient->id, $main->id));
        $this->assertSame(3.0, $this->foodMenuStock($foodMenu->id, $main->id));
    }

    public function test_food_menu_production_reverse_restores_ingredients_and_removes_produced_stock(): void
    {
        [$main, , $foodMenu, $ingredient] = $this->productionFixtures();

        $production = $this->postJson('/api/v1/food-menu-productions', [
            'location_id' => $main->id,
            'production_date' => '2026-07-29',
            'food_menu_id' => $foodMenu->id,
            'production_qty' => 3,
        ])->assertCreated()->json('data');

        $this->postJson('/api/v1/food-menu-productions/'.$production['id'].'/reverse', [
            'location_id' => $main->id,
            'note' => 'Mistake',
        ])->assertOk()
            ->assertJsonPath('data.status', 'reversed');

        $this->assertSame(10.0, $this->ingredientStock($ingredient->id, $main->id));
        $this->assertSame(0.0, $this->foodMenuStock($foodMenu->id, $main->id));
    }

    public function test_food_menu_production_reverse_is_blocked_after_produced_batch_is_used(): void
    {
        [$main, , $foodMenu, $ingredient] = $this->productionFixtures();

        $production = $this->postJson('/api/v1/food-menu-productions', [
            'location_id' => $main->id,
            'production_date' => '2026-07-29',
            'food_menu_id' => $foodMenu->id,
            'production_qty' => 3,
        ])->assertCreated()->json('data');

        app(FifoInventoryService::class)->consumeStock(
            ingredientId: null,
            locationId: $main->id,
            quantity: 1,
            direction: 'OUT',
            reasonCode: 'order_sale',
            reference: 'ORDER-TEST-001',
            note: 'Sold one prepared item',
            productId: null,
            foodMenuId: $foodMenu->id
        );

        $this->postJson('/api/v1/food-menu-productions/'.$production['id'].'/reverse', [
            'location_id' => $main->id,
        ])->assertUnprocessable();

        $this->assertSame(4.0, $this->ingredientStock($ingredient->id, $main->id));
        $this->assertSame(2.0, $this->foodMenuStock($foodMenu->id, $main->id));
    }

    private function productionFixtures(): array
    {
        $main = Location::create(['name' => 'Main Outlet', 'is_active' => true]);
        $branch = Location::create(['name' => 'Branch Outlet', 'is_active' => true]);
        $category = Category::create(['name' => 'Mains', 'slug' => 'mains', 'is_active' => true]);
        $foodMenuUnit = FoodMenuUnit::create(['name' => 'Portion', 'is_active' => true]);
        $printer = Printer::create([
            'name' => 'Kitchen',
            'ip_address' => '127.0.0.20',
            'port' => 9100,
            'paper_size' => '80mm',
            'copies' => 1,
            'is_active' => true,
        ]);

        $ingredientCategory = IngredientCategory::create(['name' => 'Raw', 'is_active' => true]);
        $purchaseUnit = PurchaseUnit::create(['name' => 'Bag', 'is_active' => true]);
        $consumptionUnit = ConsumptionUnit::create(['name' => 'Gram', 'is_active' => true]);
        $ingredient = Ingredient::create([
            'name' => 'Chicken',
            'type' => 'single',
            'has_ingredient_mapping' => false,
            'ingredient_category_id' => $ingredientCategory->id,
            'purchase_unit_id' => $purchaseUnit->id,
            'consumption_unit_id' => $consumptionUnit->id,
            'conversion_rate' => 1000,
            'purchase_price' => 5000,
            'sku_code' => 'ING-CHICKEN',
            'is_active' => true,
        ]);

        $this->addIngredientStock($ingredient, $main, 10, 5);

        $foodMenu = FoodMenu::create([
            'name' => 'Chicken Rice',
            'code' => 'FM-CHICKEN-RICE',
            'category_id' => $category->id,
            'printer_id' => $printer->id,
            'unit_id' => $foodMenuUnit->id,
            'stock_deduction_method' => 'production_stock',
            'dine_in_price' => 8000,
            'take_away_price' => 8500,
            'delivery_price' => 9000,
            'cost_per_unit' => 10,
            'current_stock_qty' => 0,
            'low_stock_qty' => 1,
            'is_active' => true,
        ]);
        $foodMenu->ingredientMappings()->create([
            'ingredient_id' => $ingredient->id,
            'unit_id' => $consumptionUnit->id,
            'required_qty' => 2,
            'unit_cost_snapshot' => 5,
            'amount' => 10,
        ]);
        $main->foodMenus()->attach($foodMenu->id, [
            'dine_in_price' => 8000,
            'take_away_price' => 8500,
            'delivery_price' => 9000,
            'is_active' => true,
        ]);

        return [$main, $branch, $foodMenu, $ingredient];
    }

    private function addIngredientStock(Ingredient $ingredient, Location $location, float $qty, float $unitCost): void
    {
        $batch = IngredientBatch::create([
            'ingredient_id' => $ingredient->id,
            'location_id' => $location->id,
            'original_qty' => $qty,
            'usable_qty' => $qty,
            'unit_cost' => $unitCost,
            'received_at' => '2026-07-29 08:00:00',
        ]);

        IngredientStockMovement::create([
            'ingredient_id' => $ingredient->id,
            'location_id' => $location->id,
            'ingredient_batch_id' => $batch->id,
            'direction' => 'IN',
            'reason_code' => 'opening_stock_in',
            'unit_type' => 'consumption',
            'quantity_input' => $qty,
            'quantity_consumption' => $qty,
            'batch_unit_cost' => $unitCost,
            'reference' => 'Test Opening Stock',
            'occurred_at' => '2026-07-29 08:00:00',
        ]);
    }

    private function ingredientStock(int $ingredientId, int $locationId): float
    {
        return round((float) IngredientStockMovement::withoutGlobalScopes()
            ->where('ingredient_id', $ingredientId)
            ->where('location_id', $locationId)
            ->selectRaw("COALESCE(SUM(CASE WHEN LOWER(direction) = 'in' THEN quantity_consumption ELSE -quantity_consumption END), 0) AS net")
            ->value('net'), 4);
    }

    private function foodMenuStock(int $foodMenuId, int $locationId): float
    {
        return round((float) IngredientStockMovement::withoutGlobalScopes()
            ->where('food_menu_id', $foodMenuId)
            ->where('location_id', $locationId)
            ->selectRaw("COALESCE(SUM(CASE WHEN LOWER(direction) = 'in' THEN quantity_consumption ELSE -quantity_consumption END), 0) AS net")
            ->value('net'), 4);
    }
}
