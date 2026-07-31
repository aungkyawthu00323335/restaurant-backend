<?php

namespace Tests\Feature;

use App\Models\ConsumptionUnit;
use App\Models\Ingredient;
use App\Models\IngredientCategory;
use App\Models\Location;
use App\Models\PurchaseUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IngredientApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_ingredient_update_changes_selected_outlet_alert_without_mutating_stock(): void
    {
        $category = IngredientCategory::create(['name' => 'Dry Goods', 'is_active' => true]);
        $purchaseUnit = PurchaseUnit::create(['name' => 'Bag', 'is_active' => true]);
        $consumptionUnit = ConsumptionUnit::create(['name' => 'Gram', 'is_active' => true]);
        $mainOutlet = Location::create(['name' => 'Main Outlet', 'is_active' => true]);
        $branchOutlet = Location::create(['name' => 'Branch Outlet', 'is_active' => true]);

        $ingredient = Ingredient::create([
            'name' => 'Sugar',
            'type' => 'single',
            'has_ingredient_mapping' => false,
            'ingredient_category_id' => $category->id,
            'purchase_unit_id' => $purchaseUnit->id,
            'consumption_unit_id' => $consumptionUnit->id,
            'conversion_rate' => 1000,
            'purchase_price' => 2000,
            'sku_code' => 'ING-SUGAR',
            'initial_stock_data' => [
                [
                    'location_id' => $mainOutlet->id,
                    'location_name' => $mainOutlet->name,
                    'quantity' => 25,
                    'cost' => 2,
                    'alert_quantity' => 5,
                ],
                [
                    'location_id' => $branchOutlet->id,
                    'location_name' => $branchOutlet->name,
                    'quantity' => 10,
                    'cost' => 2,
                    'alert_quantity' => 3,
                ],
            ],
            'is_active' => true,
        ]);

        $this->putJson('/api/v1/ingredients/'.$ingredient->id, [
            'location_id' => $branchOutlet->id,
            'name' => 'Sugar',
            'type' => 'single',
            'has_ingredient_mapping' => false,
            'ingredient_category_id' => $category->id,
            'purchase_unit_id' => $purchaseUnit->id,
            'consumption_unit_id' => $consumptionUnit->id,
            'conversion_rate' => 1000,
            'purchase_price' => 2500,
            'sku_code' => 'ING-SUGAR',
            'barcode' => null,
            'description' => null,
            'is_active' => true,
            'stock_settings' => [[
                'location_id' => $branchOutlet->id,
                'alert_quantity' => 7,
                'quantity' => 999,
                'cost' => 999,
            ]],
        ])->assertOk();

        $stockByOutlet = collect($ingredient->refresh()->initial_stock_data)
            ->keyBy(fn (array $item): int => (int) $item['location_id']);

        $this->assertSame(25.0, (float) $stockByOutlet[$mainOutlet->id]['quantity']);
        $this->assertSame(5.0, (float) $stockByOutlet[$mainOutlet->id]['alert_quantity']);
        $this->assertSame(10.0, (float) $stockByOutlet[$branchOutlet->id]['quantity']);
        $this->assertSame(7.0, (float) $stockByOutlet[$branchOutlet->id]['alert_quantity']);
    }
}
