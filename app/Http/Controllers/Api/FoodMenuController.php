<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FoodMenuRequest;
use App\Models\Category;
use App\Models\FoodMenu;
use App\Models\Ingredient;
use App\Models\IngredientStockMovement;
use App\Services\ApiImageStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class FoodMenuController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $allowedSorts = [
            'id',
            'name',
            'code',
            'category',
            'printer',
            'stock_deduction_method',
            'cost_per_unit',
            'current_stock_qty',
            'is_active',
            'created_at',
        ];
        $sortColumn = in_array($request->string('sort_col')->toString(), $allowedSorts, true)
            ? $request->string('sort_col')->toString()
            : 'id';
        $sortDirection = $request->string('sort_dir')->toString() === 'asc' ? 'asc' : 'desc';
        $search = mb_substr(trim($request->string('search')->toString()), 0, 100);
        $locationId = $request->filled('location_id') ? $request->integer('location_id') : null;

        $query = FoodMenu::query()
            ->with($this->summaryRelations())
            ->when($request->filled('category_id'), fn ($query) => $query->where('food_menus.category_id', $request->integer('category_id')))
            ->when($request->filled('printer_id'), fn ($query) => $query->where('food_menus.printer_id', $request->integer('printer_id')))
            ->when(in_array($request->string('stock_deduction_method')->toString(), FoodMenu::STOCK_DEDUCTION_METHODS, true), function ($query) use ($request): void {
                $query->where('food_menus.stock_deduction_method', $request->string('stock_deduction_method')->toString());
            })
            ->when($request->has('active'), fn ($query) => $query->where('food_menus.is_active', $request->boolean('active')))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('food_menus.name', 'like', "%{$search}%")
                        ->orWhere('food_menus.code', 'like', "%{$search}%")
                        ->orWhere('food_menus.description', 'like', "%{$search}%")
                        ->orWhere('food_menus.note', 'like', "%{$search}%");
                });
            });

        if ($sortColumn === 'category') {
            $query->orderBy(
                Category::query()->select('name')->whereColumn('categories.id', 'food_menus.category_id')->limit(1),
                $sortDirection
            );
        } elseif ($sortColumn === 'printer') {
            $query->orderBy(
                DB::table('printers')->select('name')->whereColumn('printers.id', 'food_menus.printer_id')->limit(1),
                $sortDirection
            );
        } else {
            $query->orderBy("food_menus.{$sortColumn}", $sortDirection);
        }

        $perPage = (int) $request->integer('per_page', 10);
        $perPage = in_array($perPage, [10, 20, 30, 50, 100], true) ? $perPage : 10;

        return response()->json(
            $query->paginate($perPage)->through(fn (FoodMenu $foodMenu): array => $this->resource($foodMenu, $locationId))
        );
    }

    public function store(FoodMenuRequest $request, ApiImageStorage $images): JsonResponse
    {
        $validated = $request->validated();
        $newImage = $images->storeBase64($validated['image_base64'] ?? null, 'food-menus');

        try {
            $foodMenu = DB::transaction(function () use ($validated, $newImage): FoodMenu {
                $payload = $this->foodMenuPayload($validated);
                $payload['image_url'] = $newImage ?? ($payload['image_url'] ?? null);
                $payload['current_stock_qty'] = 0;

                $foodMenu = FoodMenu::create($payload);
                $this->syncIngredients($foodMenu, $validated);
                $this->syncModifierGroups($foodMenu, $validated);
                $this->syncLocations($foodMenu);

                return $foodMenu;
            });
        } catch (Throwable $exception) {
            if ($newImage !== null) {
                $images->delete($newImage, 'food-menus');
            }
            throw $exception;
        }

        return response()->json($this->resource($foodMenu->load($this->detailRelations())), 201);
    }

    public function show(FoodMenu $foodMenu): JsonResponse
    {
        return response()->json($this->resource($foodMenu->load($this->detailRelations())));
    }

    public function update(FoodMenuRequest $request, FoodMenu $foodMenu, ApiImageStorage $images): JsonResponse
    {
        $validated = $request->validated();
        $oldImage = $foodMenu->image_url;
        $newImage = $images->storeBase64($validated['image_base64'] ?? null, 'food-menus');
        $requestedImage = array_key_exists('image_url', $validated)
            ? $this->nullableString($validated['image_url'])
            : $oldImage;
        $finalImage = $newImage ?? $requestedImage;

        try {
            DB::transaction(function () use ($validated, $foodMenu, $finalImage): void {
                $payload = $this->foodMenuPayload($validated);
                $payload['image_url'] = $finalImage;
                if ($payload['stock_deduction_method'] !== 'production_stock') {
                    $payload['current_stock_qty'] = 0;
                }

                $foodMenu->update($payload);
                $this->syncIngredients($foodMenu, $validated);
                $this->syncModifierGroups($foodMenu, $validated);
                $this->syncLocations($foodMenu);
            });
        } catch (Throwable $exception) {
            if ($newImage !== null) {
                $images->delete($newImage, 'food-menus');
            }
            throw $exception;
        }

        if ($oldImage !== $finalImage) {
            $images->delete($oldImage, 'food-menus');
        }

        return response()->json($this->resource($foodMenu->refresh()->load($this->detailRelations())));
    }

    public function destroy(FoodMenu $foodMenu, ApiImageStorage $images): JsonResponse
    {
        $image = $foodMenu->image_url;
        $foodMenu->delete();
        $images->delete($image, 'food-menus');

        return response()->json(['message' => 'Food menu deleted.']);
    }

    private function foodMenuPayload(array $validated): array
    {
        $payload = Arr::except($validated, [
            'image_base64',
            'image_name',
            'ingredients',
            'modifier_groups',
        ]);

        foreach (['name', 'code', 'description', 'note', 'image_url'] as $field) {
            if (array_key_exists($field, $payload)) {
                $payload[$field] = $this->nullableString($payload[$field]);
            }
        }

        $payload['name'] = (string) $payload['name'];
        $payload['code'] = Str::upper((string) $payload['code']);
        $payload['low_stock_qty'] = $payload['stock_deduction_method'] === 'production_stock'
            ? ($payload['low_stock_qty'] ?? 0)
            : null;

        return $payload;
    }

    private function syncIngredients(FoodMenu $foodMenu, array $validated): void
    {
        $rows = $validated['ingredients'] ?? [];
        if ($foodMenu->stock_deduction_method === 'no_stock') {
            $rows = [];
        }

        $ingredientIds = collect($rows)->pluck('ingredient_id')->map(fn ($id): int => (int) $id)->all();
        $ingredients = Ingredient::query()
            ->whereIn('id', $ingredientIds)
            ->with(['consumptionUnit:id,name', 'compositions.child.compositions.child'])
            ->get()
            ->keyBy('id');

        $mappedRows = [];
        $totalCost = 0.0;
        foreach ($rows as $row) {
            $ingredient = $ingredients->get((int) $row['ingredient_id']);
            if ($ingredient === null || $ingredient->consumption_unit_id === null) {
                continue;
            }

            $quantity = round((float) $row['required_qty'], 4);
            $unitCost = round((float) $ingredient->cost_per_consumption_unit, 4);
            $amount = round($quantity * $unitCost, 4);
            $totalCost += $amount;
            $mappedRows[] = [
                'ingredient_id' => $ingredient->id,
                'unit_id' => $ingredient->consumption_unit_id,
                'required_qty' => $quantity,
                'unit_cost_snapshot' => $unitCost,
                'amount' => $amount,
            ];
        }

        $foodMenu->ingredientMappings()->delete();
        if ($mappedRows !== []) {
            $foodMenu->ingredientMappings()->createMany($mappedRows);
        }
        $foodMenu->forceFill(['cost_per_unit' => round($totalCost, 4)])->saveQuietly();
    }

    private function syncModifierGroups(FoodMenu $foodMenu, array $validated): void
    {
        $sync = [];
        foreach ($validated['modifier_groups'] ?? [] as $index => $row) {
            $sync[(int) $row['modifier_group_id']] = [
                'is_required' => (bool) $row['is_required'],
                'min_selection' => (int) $row['min_selection'],
                'max_selection' => (int) $row['max_selection'],
                'sort_order' => (int) ($row['sort_order'] ?? $index),
            ];
        }

        $foodMenu->modifierGroups()->sync($sync);
    }

    private function syncLocations(FoodMenu $foodMenu): void
    {
        $locations = \App\Models\Location::query()->where('is_active', true)->get();
        foreach ($locations as $location) {
            DB::table('location_food_menu')->updateOrInsert(
                [
                    'location_id' => $location->id,
                    'food_menu_id' => $foodMenu->id,
                ],
                [
                    'dine_in_price' => $foodMenu->dine_in_price,
                    'take_away_price' => $foodMenu->take_away_price,
                    'delivery_price' => $foodMenu->delivery_price,
                    'is_active' => $foodMenu->is_active,
                    'updated_at' => now(),
                ]
            );
        }
    }

    private function resource(FoodMenu $foodMenu, ?int $locationId = null): array
    {
        $data = $foodMenu->attributesToArray();

        if ($locationId !== null) {
            $pivot = DB::table('location_food_menu')
                ->where('location_id', $locationId)
                ->where('food_menu_id', $foodMenu->id)
                ->first(['dine_in_price', 'take_away_price', 'delivery_price']);

            if ($pivot !== null) {
                $data['dine_in_price'] = $pivot->dine_in_price ?? $data['dine_in_price'];
                $data['take_away_price'] = $pivot->take_away_price ?? $data['take_away_price'];
                $data['delivery_price'] = $pivot->delivery_price ?? $data['delivery_price'];
            }
        }

        $data['category_name'] = $foodMenu->category?->name;
        $data['printer_name'] = $foodMenu->printer?->name;
        $data['unit_name'] = $foodMenu->unit?->name;
        $data['stock_status'] = $foodMenu->stock_status;

        if ($foodMenu->stock_deduction_method === 'production_stock') {
            $data['current_stock_qty'] = $this->foodMenuStockForLocation($foodMenu->id, $locationId);
            $data['stock_status'] = $this->stockStatusForQuantity($data['current_stock_qty'], $foodMenu->low_stock_qty);
        }

        if ($foodMenu->relationLoaded('ingredientMappings')) {
            $data['ingredients'] = $foodMenu->ingredientMappings->map(fn ($mapping): array => [
                'id' => $mapping->id,
                'ingredient_id' => $mapping->ingredient_id,
                'ingredient_name' => $mapping->ingredient?->name,
                'required_qty' => (float) $mapping->required_qty,
                'unit_id' => $mapping->unit_id,
                'unit_name' => $mapping->unit?->name,
                'unit_cost_snapshot' => (float) $mapping->unit_cost_snapshot,
                'amount' => (float) $mapping->amount,
            ])->values()->all();
        }

        if ($foodMenu->relationLoaded('modifierGroups')) {
            $data['modifier_groups'] = $foodMenu->modifierGroups
                ->sortBy(fn ($modifier) => $modifier->pivot->sort_order)
                ->map(fn ($modifier): array => [
                    'modifier_group_id' => $modifier->id,
                    'name' => $modifier->name,
                    'selection_type' => $modifier->selection_type,
                    'is_required' => (bool) $modifier->pivot->is_required,
                    'min_selection' => (int) $modifier->pivot->min_selection,
                    'max_selection' => (int) $modifier->pivot->max_selection,
                    'sort_order' => (int) $modifier->pivot->sort_order,
                    'items' => $modifier->options,
                ])->values()->all();
        }

        return $data;
    }

    private function foodMenuStockForLocation(int $foodMenuId, ?int $locationId): float
    {
        $query = IngredientStockMovement::query()
            ->where('food_menu_id', $foodMenuId)
            ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId));

        return round((float) $query
            ->selectRaw("COALESCE(SUM(CASE WHEN LOWER(direction) = 'in' THEN quantity_consumption ELSE -quantity_consumption END), 0) AS net")
            ->value('net'), 4);
    }

    private function stockStatusForQuantity(float $quantity, mixed $lowStock): string
    {
        if ($quantity <= 0) return 'out_of_stock';
        return ((float) ($lowStock ?? 0) > 0 && $quantity <= (float) $lowStock)
            ? 'low_stock'
            : 'in_stock';
    }

    private function summaryRelations(): array
    {
        return [
            'category:id,name',
            'printer:id,name',
            'unit:id,name',
        ];
    }

    private function detailRelations(): array
    {
        return [
            ...$this->summaryRelations(),
            'ingredientMappings.ingredient:id,name,consumption_unit_id',
            'ingredientMappings.unit:id,name',
            'modifierGroups:id,name,selection_type,options',
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
