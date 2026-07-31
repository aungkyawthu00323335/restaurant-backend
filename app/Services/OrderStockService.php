<?php

namespace App\Services;

use App\Models\FoodMenu;
use App\Models\FoodMenuIngredient;
use App\Models\Ingredient;
use App\Models\IngredientBatch;
use App\Models\IngredientStockMovement;
use App\Models\Order;
use App\Models\OrderComboComponent;
use App\Models\Product;
use App\Models\ProductStockMovement;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class OrderStockService
{
    protected FifoInventoryService $fifoService;

    public function __construct(FifoInventoryService $fifoService)
    {
        $this->fifoService = $fifoService;
    }

    public function deductOrderStock(Order $order, int $outletId): void
    {
        if ($order->stock_deduction_status === 'deducted') {
            Log::warning("Stock already deducted for order {$order->order_no}, skipping.");
            return;
        }

        $requirements = $this->requirementsFromOrder($order);
        $this->assertSufficientStock($requirements, $outletId);
        $this->consumeRequirements($requirements, $order, $outletId, "Order {$order->order_no}");

        $order->update([
            'stock_deduction_status' => 'deducted',
            'stock_deducted_at' => Carbon::now(),
        ]);
    }

    public function reverseOrderStock(Order $order): void
    {
        if ($order->stock_deduction_status !== 'deducted') {
            return;
        }

        $requirements = $this->requirementsFromOrder($order);
        $this->restoreRequirements(
            $requirements,
            $order,
            $order->order_no,
            'order_refund',
            'Refunded from Order '.$order->order_no
        );

        $order->update(['stock_deduction_status' => 'reversed']);
    }

    public function deductItemsStock(Order $order, int $outletId, array $itemsData): void
    {
        if ($itemsData === []) {
            return;
        }

        $requirements = $this->requirementsFromItems($itemsData);
        $this->assertSufficientStock($requirements, $outletId);
        $this->consumeRequirements($requirements, $order, $outletId, "Added to Order {$order->order_no}");
    }

    public function reverseItemsStock(Order $order, array $itemsData): void
    {
        if ($order->stock_deduction_status !== 'deducted' || $itemsData === []) {
            return;
        }

        $requirements = $this->requirementsFromItems($itemsData);
        $this->restoreRequirements(
            $requirements,
            $order,
            'CANCEL-'.$order->order_no,
            'item_cancellation',
            "Item cancellation for Order {$order->order_no}"
        );
    }

    private function emptyRequirements(): array
    {
        return [
            'products' => [],
            'food_menus' => [],
            'ingredients' => [],
        ];
    }

    private function requirementsFromOrder(Order $order): array
    {
        $requirements = $this->emptyRequirements();
        $items = $order->items()->get();

        foreach ($items as $item) {
            $qty = (float) $item->qty;

            if ($item->item_type === 'product') {
                $this->addRequirement($requirements['products'], (int) $item->item_id, $qty);
                continue;
            }

            if ($item->item_type === 'food_menu') {
                $this->addFoodMenuRequirement($requirements, (int) $item->item_id, $qty);
                continue;
            }

            if ($item->item_type === 'combo') {
                $components = OrderComboComponent::query()
                    ->where('order_item_id', $item->id)
                    ->get();

                foreach ($components as $component) {
                    $componentQty = (float) $component->total_qty;
                    if ($component->item_type === 'product') {
                        $this->addRequirement($requirements['products'], (int) $component->item_id, $componentQty);
                    } elseif ($component->item_type === 'food_menu') {
                        $this->addFoodMenuRequirement($requirements, (int) $component->item_id, $componentQty);
                    }
                }
            }
        }

        return $requirements;
    }

    private function requirementsFromItems(array $itemsData): array
    {
        $requirements = $this->emptyRequirements();

        foreach ($itemsData as $itemData) {
            $qty = (float) ($itemData['qty'] ?? 0);
            if ($qty <= 0) {
                continue;
            }

            if (($itemData['item_type'] ?? null) === 'product') {
                $this->addRequirement($requirements['products'], (int) $itemData['item_id'], $qty);
                continue;
            }

            if (($itemData['item_type'] ?? null) === 'food_menu') {
                $this->addFoodMenuRequirement($requirements, (int) $itemData['item_id'], $qty);
                continue;
            }

            if (($itemData['item_type'] ?? null) === 'combo') {
                $orderItemId = (int) ($itemData['order_item_id'] ?? 0);
                if ($orderItemId <= 0) {
                    continue;
                }

                $components = OrderComboComponent::query()
                    ->where('order_item_id', $orderItemId)
                    ->get();

                foreach ($components as $component) {
                    $componentQty = $qty * (float) $component->qty_per_combo;
                    if ($component->item_type === 'product') {
                        $this->addRequirement($requirements['products'], (int) $component->item_id, $componentQty);
                    } elseif ($component->item_type === 'food_menu') {
                        $this->addFoodMenuRequirement($requirements, (int) $component->item_id, $componentQty);
                    }
                }
            }
        }

        return $requirements;
    }

    private function addFoodMenuRequirement(array &$requirements, int $foodMenuId, float $qty): void
    {
        $menu = FoodMenu::withoutGlobalScopes()->find($foodMenuId);
        if (! $menu || $menu->stock_deduction_method === 'no_stock') {
            return;
        }

        if ($menu->stock_deduction_method === 'production_stock') {
            $this->addRequirement($requirements['food_menus'], $menu->id, $qty);
            return;
        }

        if ($menu->stock_deduction_method === 'deduct_ingredient_on_sale') {
            $mappings = FoodMenuIngredient::query()
                ->with('ingredient')
                ->where('food_menu_id', $menu->id)
                ->get();

            foreach ($mappings as $map) {
                $this->addRequirement(
                    $requirements['ingredients'],
                    (int) $map->ingredient_id,
                    $this->getConsumptionQty($map, $qty)
                );
            }
        }
    }

    private function addRequirement(array &$bucket, int $id, float $qty): void
    {
        if ($id <= 0 || $qty <= 0) {
            return;
        }

        $bucket[$id] = ($bucket[$id] ?? 0) + $qty;
    }

    private function assertSufficientStock(array $requirements, int $outletId): void
    {
        $messages = [];

        foreach ($requirements['products'] as $productId => $requiredQty) {
            $product = Product::withoutGlobalScopes()->find($productId);
            $available = $this->currentProductStockForLocation((int) $productId, $outletId);

            if ($available + 0.000001 < $requiredQty) {
                $name = $product?->name ?? "Product #{$productId}";
                $unit = $product?->productUnit?->name ?? 'units';
                $messages[] = "Insufficient stock for {$name}. Required: {$this->formatQty($requiredQty)} {$unit}, Available: {$this->formatQty($available)} {$unit}.";
            }
        }

        foreach ($requirements['food_menus'] as $foodMenuId => $requiredQty) {
            $menu = FoodMenu::withoutGlobalScopes()->with('unit')->find($foodMenuId);
            $available = $this->currentFoodMenuStockForLocation((int) $foodMenuId, $outletId);

            if ($available + 0.000001 < $requiredQty) {
                $name = $menu?->name ?? "Food Menu #{$foodMenuId}";
                $unit = $menu?->unit?->name ?? 'units';
                $messages[] = "Insufficient stock for {$name}. Required: {$this->formatQty($requiredQty)} {$unit}, Available: {$this->formatQty($available)} {$unit}.";
            }
        }

        foreach ($requirements['ingredients'] as $ingredientId => $requiredQty) {
            $ingredient = Ingredient::withoutGlobalScopes()->with('consumptionUnit')->find($ingredientId);
            $available = $ingredient
                ? $this->currentIngredientStockForLocation($ingredient, $outletId)
                : 0.0;

            if ($available + 0.000001 < $requiredQty) {
                $name = $ingredient?->name ?? "Ingredient #{$ingredientId}";
                $unit = $ingredient?->consumptionUnit?->name ?? 'units';
                $messages[] = "Insufficient ingredient stock for {$name}. Required: {$this->formatQty($requiredQty)} {$unit}, Available: {$this->formatQty($available)} {$unit}.";
            }
        }

        if ($messages !== []) {
            throw ValidationException::withMessages(['stock' => $messages]);
        }
    }

    private function consumeRequirements(array $requirements, Order $order, int $outletId, string $note): void
    {
        foreach ($requirements['products'] as $productId => $requiredQty) {
            $this->fifoService->consumeStock(
                ingredientId: null,
                locationId: $outletId,
                quantity: $requiredQty,
                direction: 'OUT',
                reasonCode: 'order_sale',
                reference: $order->order_no,
                note: $note,
                productId: $productId,
                foodMenuId: null
            );
        }

        foreach ($requirements['food_menus'] as $foodMenuId => $requiredQty) {
            $this->fifoService->consumeStock(
                ingredientId: null,
                locationId: $outletId,
                quantity: $requiredQty,
                direction: 'OUT',
                reasonCode: 'order_sale',
                reference: $order->order_no,
                note: $note,
                productId: null,
                foodMenuId: $foodMenuId
            );
        }

        foreach ($requirements['ingredients'] as $ingredientId => $requiredQty) {
            $this->fifoService->consumeStock(
                ingredientId: $ingredientId,
                locationId: $outletId,
                quantity: $requiredQty,
                direction: 'OUT',
                reasonCode: 'order_sale_ingredient',
                reference: $order->order_no,
                note: $note,
                productId: null,
                foodMenuId: null
            );
        }
    }

    private function restoreRequirements(array $requirements, Order $order, string $reference, string $reasonCode, string $note): void
    {
        foreach ($requirements['products'] as $productId => $qty) {
            $product = Product::withoutGlobalScopes()->find($productId);
            $unitCost = (float) ($product?->purchase_price_per_unit ?? 0);
            $batch = $this->createReturnedBatch(null, (int) $productId, null, (int) $order->outlet_id, $qty, $unitCost);

            IngredientStockMovement::query()->create([
                'ingredient_id' => null,
                'product_id' => $productId,
                'food_menu_id' => null,
                'location_id' => $order->outlet_id,
                'ingredient_batch_id' => $batch->id,
                'direction' => 'IN',
                'reason_code' => $reasonCode,
                'unit_type' => 'consumption',
                'quantity_input' => $qty,
                'quantity_consumption' => $qty,
                'batch_unit_cost' => $unitCost,
                'reference' => $reference,
                'note' => $note,
                'occurred_at' => Carbon::now(),
            ]);

            ProductStockMovement::query()->create([
                'product_id' => $productId,
                'location_id' => $order->outlet_id,
                'direction' => 'in',
                'reason_code' => $reasonCode,
                'quantity' => $qty,
                'unit_cost' => $unitCost,
                'amount' => round($qty * $unitCost, 2),
                'reference' => $reference,
                'note' => $note,
                'occurred_at' => Carbon::now(),
            ]);
        }

        foreach ($requirements['food_menus'] as $foodMenuId => $qty) {
            $menu = FoodMenu::withoutGlobalScopes()->find($foodMenuId);
            $unitCost = (float) ($menu?->cost_per_unit ?? 0);
            $batch = $this->createReturnedBatch(null, null, (int) $foodMenuId, (int) $order->outlet_id, $qty, $unitCost);

            IngredientStockMovement::query()->create([
                'ingredient_id' => null,
                'product_id' => null,
                'food_menu_id' => $foodMenuId,
                'location_id' => $order->outlet_id,
                'ingredient_batch_id' => $batch->id,
                'direction' => 'IN',
                'reason_code' => $reasonCode,
                'unit_type' => 'consumption',
                'quantity_input' => $qty,
                'quantity_consumption' => $qty,
                'batch_unit_cost' => $unitCost,
                'reference' => $reference,
                'note' => $note,
                'occurred_at' => Carbon::now(),
            ]);
        }

        foreach ($requirements['ingredients'] as $ingredientId => $qty) {
            $ingredient = Ingredient::withoutGlobalScopes()->find($ingredientId);
            $unitCost = (float) ($ingredient?->cost_per_consumption_unit ?? 0);
            $batch = $this->createReturnedBatch((int) $ingredientId, null, null, (int) $order->outlet_id, $qty, $unitCost);

            IngredientStockMovement::query()->create([
                'ingredient_id' => $ingredientId,
                'product_id' => null,
                'food_menu_id' => null,
                'location_id' => $order->outlet_id,
                'ingredient_batch_id' => $batch->id,
                'direction' => 'IN',
                'reason_code' => $reasonCode,
                'unit_type' => 'consumption',
                'quantity_input' => $qty,
                'quantity_consumption' => $qty,
                'batch_unit_cost' => $unitCost,
                'reference' => $reference,
                'note' => $note,
                'occurred_at' => Carbon::now(),
            ]);
        }
    }

    private function createReturnedBatch(?int $ingredientId, ?int $productId, ?int $foodMenuId, int $locationId, float $qty, float $unitCost): IngredientBatch
    {
        return IngredientBatch::query()->create([
            'ingredient_id' => $ingredientId,
            'product_id' => $productId,
            'food_menu_id' => $foodMenuId,
            'location_id' => $locationId,
            'original_qty' => $qty,
            'usable_qty' => $qty,
            'unit_cost' => $unitCost,
            'received_at' => Carbon::now(),
        ]);
    }

    private function currentProductStockForLocation(int $productId, int $locationId): float
    {
        return round(
            (float) ProductStockMovement::query()
                ->where('product_id', $productId)
                ->where('location_id', $locationId)
                ->selectRaw("COALESCE(SUM(CASE WHEN LOWER(direction) = 'in' THEN quantity ELSE -quantity END), 0) AS net")
                ->value('net'),
            4
        );
    }

    private function currentFoodMenuStockForLocation(int $foodMenuId, int $locationId): float
    {
        return round(
            (float) IngredientStockMovement::withoutGlobalScopes()
                ->where('food_menu_id', $foodMenuId)
                ->where('location_id', $locationId)
                ->selectRaw("COALESCE(SUM(CASE WHEN LOWER(direction) = 'in' THEN quantity_consumption ELSE -quantity_consumption END), 0) AS net")
                ->value('net'),
            4
        );
    }

    private function currentIngredientStockForLocation(Ingredient $ingredient, int $locationId): float
    {
        $movementNet = (float) IngredientStockMovement::withoutGlobalScopes()
            ->where('ingredient_id', $ingredient->id)
            ->where('location_id', $locationId)
            ->selectRaw("COALESCE(SUM(CASE WHEN LOWER(direction) = 'in' THEN quantity_consumption ELSE -quantity_consumption END), 0) AS net")
            ->value('net');

        return round($this->initialStockForLocation($ingredient, $locationId) + $movementNet, 4);
    }

    private function initialStockForLocation(Ingredient $ingredient, int $locationId): float
    {
        $data = is_array($ingredient->initial_stock_data) ? $ingredient->initial_stock_data : [];

        foreach ($data as $entry) {
            if (is_array($entry) && (int) ($entry['location_id'] ?? 0) === $locationId) {
                return (float) ($entry['quantity'] ?? 0);
            }
        }

        return 0.0;
    }

    private function getConsumptionQty(FoodMenuIngredient $map, float $multiplier): float
    {
        return (float) $map->required_qty * $multiplier;
    }

    private function formatQty(float $value): string
    {
        $formatted = number_format($value, 4, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }
}
