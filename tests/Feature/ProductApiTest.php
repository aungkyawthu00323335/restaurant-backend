<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ConsumptionUnit;
use App\Models\Ingredient;
use App\Models\Printer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_requires_the_configured_token(): void
    {
        config(['pos.api_token' => 'test-secret']);

        $this->getJson('/api/v1/categories')->assertUnauthorized();
        $this->withHeader('X-POS-API-TOKEN', 'wrong')
            ->getJson('/api/v1/categories')
            ->assertUnauthorized();
        $this->withToken('test-secret')
            ->getJson('/api/v1/categories')
            ->assertOk();
    }

    public function test_category_crud_search_pagination_and_image_storage(): void
    {
        Storage::fake('public');

        $created = $this->postJson('/api/v1/categories', [
            'name' => 'Soups',
            'description' => 'Hot and cold soups',
            'is_active' => true,
            'image_base64' => $this->pngDataUri(),
        ])->assertCreated()
            ->assertJsonPath('name', 'Soups')
            ->json();

        $storedPath = str_replace('/storage/', '', $created['image_url']);
        Storage::disk('public')->assertExists($storedPath);

        $this->getJson('/api/v1/categories?active=1&search=soup&per_page=20')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->deleteJson('/api/v1/categories/'.$created['id'])->assertOk();
        Storage::disk('public')->assertMissing($storedPath);
    }

    public function test_location_crud_keeps_one_head_office(): void
    {
        $first = $this->postJson('/api/v1/locations', [
            'name' => 'Main Restaurant',
            'opening_time' => '05:00 AM',
            'closing_time' => '07:00 PM',
            'is_active' => true,
        ])->assertCreated()->json();

        $second = $this->postJson('/api/v1/locations', [
            'name' => 'Downtown Branch',
            'is_head_office' => true,
            'is_active' => true,
        ])->assertCreated()->json();

        $this->assertDatabaseHas('locations', [
            'id' => $first['id'],
            'is_head_office' => false,
        ]);

        $this->deleteJson('/api/v1/locations/'.$second['id'])->assertOk();
        $this->assertDatabaseHas('locations', [
            'id' => $first['id'],
            'is_head_office' => true,
        ]);
    }

    public function test_food_menu_create_uses_direct_printer_and_cost_snapshots(): void
    {
        $category = Category::create([
            'name' => 'Mains',
            'slug' => 'mains',
            'is_active' => true,
        ]);
        $unit = \App\Models\FoodMenuUnit::create([
            'name' => 'Portion',
            'is_active' => true,
        ]);
        $ingredientUnit = ConsumptionUnit::create([
            'name' => 'Gram',
            'is_active' => true,
        ]);
        $printer = Printer::create([
            'name' => 'Hot Line',
            'ip_address' => '127.0.0.10',
            'port' => 9100,
            'paper_size' => '80mm',
            'copies' => 1,
            'is_active' => true,
        ]);
        $ingredient = Ingredient::create([
            'name' => 'Chicken',
            'consumption_unit_id' => $ingredientUnit->id,
            'conversion_rate' => 1000,
            'purchase_price' => 5000,
            'barcode' => 'ING-001',
            'is_active' => true,
        ]);

        $modifier = $this->postJson('/api/v1/modifiers', [
            'name' => 'Spice Level',
            'selection_type' => 'single',
            'min_selection' => 1,
            'max_selection' => 1,
            'is_required' => true,
            'is_active' => true,
            'options' => [
                ['name' => 'Normal', 'price' => 0],
                ['name' => 'Spicy', 'price' => 500],
            ],
        ])->assertCreated()->json();

        $menu = $this->postJson('/api/v1/food-menus', [
            'name' => 'Chicken Rice',
            'code' => 'fm-001',
            'category_id' => $category->id,
            'printer_id' => $printer->id,
            'unit_id' => $unit->id,
            'stock_deduction_method' => 'deduct_ingredient_on_sale',
            'dine_in_price' => 6000,
            'take_away_price' => 6500,
            'delivery_price' => 7000,
            'is_active' => true,
            'ingredients' => [[
                'ingredient_id' => $ingredient->id,
                'required_qty' => 200,
                'unit_cost_snapshot' => 999999,
            ]],
            'modifier_groups' => [[
                'modifier_group_id' => $modifier['id'],
                'is_required' => true,
                'min_selection' => 1,
                'max_selection' => 1,
                'sort_order' => 0,
            ]],
        ])->assertCreated()
            ->assertJsonPath('code', 'FM-001')
            ->assertJsonPath('printer_name', 'Hot Line')
            ->assertJsonPath('unit_name', 'Portion')
            ->assertJsonPath('ingredients.0.ingredient_name', 'Chicken')
            ->assertJsonPath('ingredients.0.unit_name', 'Gram')
            ->assertJsonPath('ingredients.0.unit_cost_snapshot', 5)
            ->assertJsonPath('ingredients.0.amount', 1000)
            ->assertJsonPath('cost_per_unit', '1000.0000')
            ->assertJsonPath('stock_status', 'not_tracked')
            ->json();

        $this->assertDatabaseHas('food_menus', [
            'id' => $menu['id'],
            'printer_id' => $printer->id,
        ]);
        $this->assertDatabaseHas('food_menu_ingredients', [
            'food_menu_id' => $menu['id'],
            'ingredient_id' => $ingredient->id,
            'unit_id' => $ingredientUnit->id,
            'unit_cost_snapshot' => 5,
            'amount' => 1000,
        ]);
        $this->assertDatabaseHas('food_menu_modifier_groups', [
            'food_menu_id' => $menu['id'],
            'modifier_group_id' => $modifier['id'],
            'is_required' => true,
        ]);

        $this->getJson('/api/v1/food-menus?search=chicken&stock_deduction_method=deduct_ingredient_on_sale')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->deleteJson('/api/v1/categories/'.$category->id)
            ->assertStatus(409);
        $this->deleteJson('/api/v1/modifiers/'.$modifier['id'])
            ->assertStatus(409);
    }

    public function test_food_menu_stock_method_validation_is_enforced(): void
    {
        $category = Category::create(['name' => 'Drinks', 'slug' => 'drinks', 'is_active' => true]);
        $unit = ConsumptionUnit::create(['name' => 'Glass', 'is_active' => true]);
        $ingredientUnit = ConsumptionUnit::create(['name' => 'Millilitre', 'is_active' => true]);
        $printer = Printer::create([
            'name' => 'Bar',
            'ip_address' => '127.0.0.11',
            'port' => 9100,
            'paper_size' => '80mm',
            'copies' => 1,
            'is_active' => false,
        ]);
        $ingredient = Ingredient::create([
            'name' => 'Tea',
            'consumption_unit_id' => $ingredientUnit->id,
            'conversion_rate' => 1000,
            'purchase_price' => 1000,
            'barcode' => 'ING-002',
            'is_active' => true,
        ]);

        $payload = [
            'name' => 'Iced Tea',
            'code' => 'FM-002',
            'category_id' => $category->id,
            'printer_id' => $printer->id,
            'unit_id' => $unit->id,
            'stock_deduction_method' => 'no_stock',
            'dine_in_price' => 1000,
            'take_away_price' => 1000,
            'delivery_price' => 1000,
            'is_active' => true,
            'ingredients' => [[
                'ingredient_id' => $ingredient->id,
                'required_qty' => 1,
            ]],
        ];

        $this->postJson('/api/v1/food-menus', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['printer_id', 'ingredients']);

        $printer->update(['is_active' => true]);
        $payload['stock_deduction_method'] = 'production_stock';
        $payload['ingredients'] = [];

        $this->postJson('/api/v1/food-menus', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ingredients');
    }

    private function pngDataUri(): string
    {
        return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
    }
}
