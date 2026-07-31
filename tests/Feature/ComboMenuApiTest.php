<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ConsumptionUnit;
use App\Models\FoodMenu;
use App\Models\FoodMenuUnit;
use App\Models\Location;
use App\Models\Printer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComboMenuApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_combo_create_data_and_list_follow_selected_outlet(): void
    {
        [$main, $branch, $category, $foodMenu, $product] = $this->comboFixtures();

        $this->getJson('/api/v1/combo-menus/create-data?location_id='.$main->id)
            ->assertOk()
            ->assertJsonCount(1, 'food_menus')
            ->assertJsonCount(1, 'products');

        $this->getJson('/api/v1/combo-menus/create-data?location_id='.$branch->id)
            ->assertOk()
            ->assertJsonCount(0, 'food_menus')
            ->assertJsonCount(0, 'products');

        $combo = $this->postJson('/api/v1/combo-menus', [
            'name' => 'Lunch Combo',
            'code' => 'COMBO-LUNCH',
            'category_id' => $category->id,
            'dine_in_price' => 12000,
            'take_away_price' => 12500,
            'delivery_price' => 13000,
            'is_active' => true,
            'items' => [
                ['item_type' => 'food_menu', 'item_id' => $foodMenu->id, 'qty' => 1],
                ['item_type' => 'product', 'item_id' => $product->id, 'qty' => 2],
            ],
        ])->assertCreated()
            ->assertJsonPath('cost_per_unit', '11.0000')
            ->json();

        $main->comboMenus()->attach($combo['id'], [
            'dine_in_price' => 12000,
            'take_away_price' => 12500,
            'delivery_price' => 13000,
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/combo-menus?location_id='.$main->id)
            ->assertOk()
            ->assertJsonPath('summary.total_combos', 1);

        $this->getJson('/api/v1/combo-menus?location_id='.$branch->id)
            ->assertOk()
            ->assertJsonPath('summary.total_combos', 0);
    }

    public function test_combo_menu_rejects_duplicate_components(): void
    {
        [, , $category, $foodMenu] = $this->comboFixtures();

        $this->postJson('/api/v1/combo-menus', [
            'name' => 'Duplicate Combo',
            'code' => 'COMBO-DUP',
            'category_id' => $category->id,
            'dine_in_price' => 10000,
            'take_away_price' => 10000,
            'delivery_price' => 10000,
            'is_active' => true,
            'items' => [
                ['item_type' => 'food_menu', 'item_id' => $foodMenu->id, 'qty' => 1],
                ['item_type' => 'food_menu', 'item_id' => $foodMenu->id, 'qty' => 2],
            ],
        ])->assertUnprocessable();
    }

    private function comboFixtures(): array
    {
        $main = Location::create(['name' => 'Main Outlet', 'is_active' => true]);
        $branch = Location::create(['name' => 'Branch Outlet', 'is_active' => true]);
        $category = Category::create(['name' => 'Combos', 'slug' => 'combos', 'is_active' => true]);
        $foodMenuUnit = FoodMenuUnit::create(['name' => 'Portion', 'is_active' => true]);
        $consumptionUnit = ConsumptionUnit::create(['name' => 'Each', 'is_active' => true]);
        $printer = Printer::create([
            'name' => 'Kitchen',
            'ip_address' => '127.0.0.21',
            'port' => 9100,
            'paper_size' => '80mm',
            'copies' => 1,
            'is_active' => true,
        ]);

        $foodMenu = FoodMenu::create([
            'name' => 'Fried Rice',
            'code' => 'FM-FRIED-RICE',
            'category_id' => $category->id,
            'printer_id' => $printer->id,
            'unit_id' => $foodMenuUnit->id,
            'stock_deduction_method' => 'no_stock',
            'dine_in_price' => 7000,
            'take_away_price' => 7500,
            'delivery_price' => 8000,
            'cost_per_unit' => 5,
            'current_stock_qty' => 0,
            'is_active' => true,
        ]);
        $main->foodMenus()->attach($foodMenu->id, [
            'dine_in_price' => 7000,
            'take_away_price' => 7500,
            'delivery_price' => 8000,
            'is_active' => true,
        ]);

        $productCategory = ProductCategory::create(['name' => 'Drink', 'code' => 'DRK', 'is_active' => true]);
        $productUnit = ProductUnit::create(['name' => 'Bottle', 'code' => 'BTL', 'is_active' => true]);
        $product = Product::create([
            'name' => 'Water',
            'code' => 'P-WATER-COMBO',
            'product_category_id' => $productCategory->id,
            'product_unit_id' => $productUnit->id,
            'purchase_price_per_unit' => 3,
            'sell_price_per_unit' => 5,
            'low_stock_qty' => 0,
            'is_active' => true,
        ]);
        $main->products()->attach($product->id, [
            'sell_price_per_unit' => 5,
            'is_active' => true,
        ]);

        return [$main, $branch, $category, $foodMenu, $product, $consumptionUnit];
    }
}
