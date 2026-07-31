<?php

namespace Tests\Feature;

use App\Models\ConsumptionUnit;
use App\Models\Ingredient;
use App\Models\IngredientCategory;
use App\Models\IngredientStockMovement;
use App\Models\Location;
use App\Models\PurchaseUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IngredientProcessingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_processing_deducts_inputs_adds_output_and_blocks_over_processing(): void
    {
        $location = Location::create(['name' => 'Kitchen Outlet', 'is_active' => true]);
        $category = IngredientCategory::create(['name' => 'Prep', 'is_active' => true]);
        $purchaseUnit = PurchaseUnit::create(['name' => 'Bag', 'is_active' => true]);
        $consumptionUnit = ConsumptionUnit::create(['name' => 'Gram', 'is_active' => true]);

        $input = Ingredient::create([
            'name' => 'Raw Chicken',
            'type' => 'single',
            'has_ingredient_mapping' => false,
            'ingredient_category_id' => $category->id,
            'purchase_unit_id' => $purchaseUnit->id,
            'consumption_unit_id' => $consumptionUnit->id,
            'conversion_rate' => 1000,
            'purchase_price' => 5000,
            'sku_code' => 'RAW-CHICKEN',
            'initial_stock_data' => [[
                'location_id' => $location->id,
                'location_name' => $location->name,
                'quantity' => 10,
                'cost' => 5,
                'alert_quantity' => 0,
            ]],
            'is_active' => true,
        ]);

        $output = Ingredient::create([
            'name' => 'Prepared Chicken',
            'type' => 'composite',
            'has_ingredient_mapping' => true,
            'ingredient_category_id' => $category->id,
            'purchase_unit_id' => $purchaseUnit->id,
            'consumption_unit_id' => $consumptionUnit->id,
            'conversion_rate' => 1000,
            'purchase_price' => 0,
            'sku_code' => 'PREP-CHICKEN',
            'initial_stock_data' => [],
            'is_active' => true,
        ]);

        $output->compositions()->create([
            'child_ingredient_id' => $input->id,
            'quantity' => 2,
            'unit_type' => 'consumption',
        ]);

        $this->postJson('/api/v1/ingredient-processings', [
            'location_id' => $location->id,
            'processing_date' => now()->toDateString(),
            'output_ingredient_id' => $output->id,
            'processing_qty' => 3,
            'processing_unit_type' => 'consumption',
        ])->assertCreated();

        $this->assertSame(4.0, $this->ingredientStock($input, $location->id));
        $this->assertSame(3.0, $this->ingredientStock($output, $location->id));

        $this->postJson('/api/v1/ingredient-processings', [
            'location_id' => $location->id,
            'processing_date' => now()->toDateString(),
            'output_ingredient_id' => $output->id,
            'processing_qty' => 3,
            'processing_unit_type' => 'consumption',
        ])->assertUnprocessable();
    }

    private function ingredientStock(Ingredient $ingredient, int $locationId): float
    {
        $initial = 0.0;
        foreach ($ingredient->initial_stock_data ?? [] as $entry) {
            if (is_array($entry) && (int) ($entry['location_id'] ?? 0) === $locationId) {
                $initial = (float) ($entry['quantity'] ?? 0);
                break;
            }
        }

        $net = (float) IngredientStockMovement::withoutGlobalScopes()
            ->where('ingredient_id', $ingredient->id)
            ->where('location_id', $locationId)
            ->selectRaw("COALESCE(SUM(CASE WHEN LOWER(direction) = 'in' THEN quantity_consumption ELSE -quantity_consumption END), 0) AS net")
            ->value('net');

        return round($initial + $net, 4);
    }
}
