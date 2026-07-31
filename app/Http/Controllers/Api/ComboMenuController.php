<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\ComboMenu;
use App\Models\ComboMenuItem;
use App\Models\FoodMenu;
use App\Models\Product;
use App\Services\ApiImageStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class ComboMenuController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'active_status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
            'sort_col' => ['nullable', 'string', Rule::in(['id', 'name', 'code', 'dine_in_price', 'take_away_price', 'delivery_price', 'cost_per_unit', 'is_active', 'created_at'])],
            'sort_dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer'],
        ]);

        $sortCol = $payload['sort_col'] ?? 'created_at';
        $sortDir = $payload['sort_dir'] ?? 'desc';

        $query = ComboMenu::query()->with(['category:id,name', 'items']);
        $this->applyFilters($query, $payload);
        $query->orderBy($sortCol, $sortDir)->orderBy('id', 'desc');

        $perPage = (int) ($payload['per_page'] ?? 20);
        $perPage = in_array($perPage, [10, 20, 30, 50, 100], true) ? $perPage : 20;

        $records = $query->paginate($perPage)->through(
            fn (ComboMenu $combo): array => $this->listResource($combo)
        );

        $filteredQuery = ComboMenu::query();
        $this->applyFilters($filteredQuery, $payload);

        return response()->json([
            'data' => $records,
            'summary' => [
                'total_combos' => $filteredQuery->count(),
                'total_active' => (clone $filteredQuery)->where('combo_menus.is_active', true)->count(),
                'total_inactive' => (clone $filteredQuery)->where('combo_menus.is_active', false)->count(),
            ],
        ]);
    }

    public function createData(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
        ]);
        $locationId = isset($payload['location_id']) ? (int) $payload['location_id'] : null;

        return response()->json([
            'categories' => Category::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'food_menus' => FoodMenu::query()
                ->where('is_active', true)
                ->when($locationId !== null, function (Builder $query) use ($locationId): void {
                    $query->whereHas('locations', function (Builder $locationQuery) use ($locationId): void {
                        $locationQuery->where('locations.id', $locationId)
                            ->where('location_food_menu.is_active', true);
                    });
                })
                ->with('unit:id,name')
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'cost_per_unit', 'unit_id']),
            'products' => Product::query()
                ->where('is_active', true)
                ->when($locationId !== null, function (Builder $query) use ($locationId): void {
                    $query->whereHas('locations', function (Builder $locationQuery) use ($locationId): void {
                        $locationQuery->where('locations.id', $locationId)
                            ->where('location_product.is_active', true);
                    });
                })
                ->with('productUnit:id,name')
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'purchase_price_per_unit', 'product_unit_id']),
        ]);
    }

    public function store(Request $request, ApiImageStorage $images): JsonResponse
    {
        $payload = $this->validateStore($request);
        $newImage = $images->storeBase64($payload['image_base64'] ?? null, 'combo-menus');

        try {
            $combo = DB::transaction(function () use ($payload, $newImage): ComboMenu {
                $header = $this->headerPayload($payload);
                $header['image_url'] = $newImage;
                $header['cost_per_unit'] = 0;

                $combo = ComboMenu::create($header);
                $this->syncItems($combo, $payload['items'] ?? []);
                $combo->refresh();

                return $combo;
            });
        } catch (Throwable $exception) {
            if ($newImage !== null) {
                $images->delete($newImage, 'combo-menus');
            }
            throw $exception;
        }

        return response()->json($this->detailResource($combo->load(['category:id,name', 'items', 'createdBy:id,name'])), 201);
    }

    public function show(ComboMenu $comboMenu): JsonResponse
    {
        return response()->json(
            $this->detailResource($comboMenu->load(['category:id,name', 'items', 'createdBy:id,name', 'updatedBy:id,name']))
        );
    }

    public function update(Request $request, ComboMenu $comboMenu, ApiImageStorage $images): JsonResponse
    {
        $payload = $this->validateUpdate($request, $comboMenu);
        $oldImage = $comboMenu->image_url;
        $newImage = $images->storeBase64($payload['image_base64'] ?? null, 'combo-menus');
        $requestedImage = array_key_exists('image_url', $payload)
            ? $this->nullableString($payload['image_url'])
            : $oldImage;
        $finalImage = $newImage ?? $requestedImage;

        try {
            DB::transaction(function () use ($payload, $comboMenu, $finalImage): void {
                $header = $this->headerPayload($payload);
                $header['image_url'] = $finalImage;

                $comboMenu->update($header);
                $this->syncItems($comboMenu, $payload['items'] ?? []);
                $comboMenu->refresh();
            });
        } catch (Throwable $exception) {
            if ($newImage !== null) {
                $images->delete($newImage, 'combo-menus');
            }
            throw $exception;
        }

        if ($oldImage !== $finalImage) {
            $images->delete($oldImage, 'combo-menus');
        }

        return response()->json($this->detailResource($comboMenu->refresh()->load(['category:id,name', 'items', 'updatedBy:id,name'])));
    }

    public function toggleStatus(ComboMenu $comboMenu): JsonResponse
    {
        $comboMenu->update(['is_active' => ! $comboMenu->is_active]);

        return response()->json($this->detailResource($comboMenu->load(['category:id,name', 'items'])));
    }

    public function destroy(ComboMenu $comboMenu): JsonResponse
    {
        $hasTransactions = false;

        if ($hasTransactions) {
            return response()->json([
                'message' => 'This Combo Menu cannot be permanently deleted because it has transaction history. Please deactivate it instead.',
            ], 409);
        }

        $image = $comboMenu->image_url;
        $comboMenu->delete();

        if ($image !== null) {
            app(ApiImageStorage::class)->delete($image, 'combo-menus');
        }

        return response()->json(['message' => 'Combo menu deleted.']);
    }

    private function validateStore(Request $request): array
    {
        $payload = $request->validate($this->rules());
        $this->validateItems($payload['items'] ?? []);

        return $payload;
    }

    private function validateUpdate(Request $request, ComboMenu $comboMenu): array
    {
        $payload = $request->validate($this->rules($comboMenu->id));
        $this->validateItems($payload['items'] ?? []);

        return $payload;
    }

    private function rules(?int $comboId = null): array
    {
        $uniqueCode = Rule::unique('combo_menus', 'code')->whereNull('deleted_at');
        if ($comboId !== null) {
            $uniqueCode->ignore($comboId);
        }

        return [
            'name' => ['required', 'string', 'max:160'],
            'code' => ['required', 'string', 'max:80', $uniqueCode],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'dine_in_price' => ['required', 'numeric', 'min:0'],
            'take_away_price' => ['required', 'numeric', 'min:0'],
            'delivery_price' => ['required', 'numeric', 'min:0'],
            'is_active' => ['required', 'boolean'],
            'description' => ['nullable', 'string', 'max:5000'],
            'note' => ['nullable', 'string', 'max:500'],
            'image_base64' => ['nullable', 'string'],
            'image_name' => ['nullable', 'string', 'max:200'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_type' => ['required', 'string', Rule::in(ComboMenu::ITEM_TYPES)],
            'items.*.item_id' => ['required', 'integer', 'min:1'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
        ];
    }

    private function headerPayload(array $validated): array
    {
        $payload = [
            'name' => trim((string) $validated['name']),
            'code' => Str::upper(trim((string) $validated['code'])),
            'category_id' => (int) $validated['category_id'],
            'dine_in_price' => (float) $validated['dine_in_price'],
            'take_away_price' => (float) $validated['take_away_price'],
            'delivery_price' => (float) $validated['delivery_price'],
            'is_active' => (bool) $validated['is_active'],
        ];

        if (array_key_exists('description', $validated)) {
            $payload['description'] = $this->nullableString($validated['description']);
        }
        if (array_key_exists('note', $validated)) {
            $payload['note'] = $this->nullableString($validated['note']);
        }

        return $payload;
    }

    private function syncItems(ComboMenu $combo, array $items): void
    {
        $foodMenuIds = [];
        $productIds = [];

        foreach ($items as $item) {
            if ($item['item_type'] === 'food_menu') {
                $foodMenuIds[] = (int) $item['item_id'];
            } else {
                $productIds[] = (int) $item['item_id'];
            }
        }

        $foodMenus = FoodMenu::query()->whereIn('id', $foodMenuIds)->with('unit:id,name')->get()->keyBy('id');
        $products = Product::query()->whereIn('id', $productIds)->with('productUnit:id,name')->get()->keyBy('id');

        $rows = [];
        $totalCost = 0.0;

        foreach ($items as $i => $item) {
            $type = $item['item_type'];
            $itemId = (int) $item['item_id'];
            $qty = round((float) $item['qty'], 4);
            $costPerUnit = 0.0;
            $name = '';
            $unitId = null;
            $unitName = null;

            if ($type === 'food_menu') {
                $fm = $foodMenus->get($itemId);
                if ($fm === null) {
                    abort(422, sprintf('Combo component %d food menu was not found.', $i + 1));
                }
                $name = $fm->name;
                $costPerUnit = round((float) $fm->cost_per_unit, 4);
                $unitId = $fm->unit_id;
                $unitName = $fm->unit?->name;
            } else {
                $product = $products->get($itemId);
                if ($product === null) {
                    abort(422, sprintf('Combo component %d product was not found.', $i + 1));
                }
                $name = $product->name;
                $costPerUnit = round((float) $product->purchase_price_per_unit, 4);
                $unitId = $product->product_unit_id;
                $unitName = $product->productUnit?->name;
            }

            $amount = round($qty * $costPerUnit, 4);
            $totalCost += $amount;

            $rows[] = [
                'item_type' => $type,
                'item_id' => $itemId,
                'item_name_snapshot' => $name,
                'qty' => $qty,
                'unit_id' => $unitId,
                'unit_name_snapshot' => $unitName,
                'cost_per_unit_snapshot' => $costPerUnit,
                'amount' => $amount,
                'sort_order' => $i,
            ];
        }

        $combo->items()->delete();
        if ($rows !== []) {
            $combo->items()->createMany($rows);
        }

        $combo->forceFill(['cost_per_unit' => round($totalCost, 4)])->saveQuietly();
    }

    private function applyFilters(Builder $query, array $payload): void
    {
        $query
            ->when(
                ! empty($payload['search']),
                fn (Builder $q) => $q->where(function (Builder $q) use ($payload): void {
                    $search = mb_substr(trim($payload['search']), 0, 100);
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                })
            )
            ->when(
                ! empty($payload['category_id']),
                fn (Builder $q) => $q->where('category_id', (int) $payload['category_id'])
            )
            ->when(
                ! empty($payload['location_id']),
                fn (Builder $q) => $q->whereHas('locations', function (Builder $locationQuery) use ($payload): void {
                    $locationQuery->where('locations.id', (int) $payload['location_id'])
                        ->where('location_combo_menu.is_active', true);
                })
            )
            ->when(
                ! empty($payload['active_status']),
                fn (Builder $q) => $q->where('combo_menus.is_active', $payload['active_status'] === 'active')
            );
    }

    private function validateItems(array $items): void
    {
        $seen = [];
        foreach ($items as $index => $item) {
            $type = (string) ($item['item_type'] ?? '');
            $itemId = (int) ($item['item_id'] ?? 0);
            $key = $type.':'.$itemId;

            if (isset($seen[$key])) {
                abort(422, sprintf('Combo component %d is duplicated.', $index + 1));
            }
            $seen[$key] = true;

            if ($type === 'food_menu') {
                $exists = FoodMenu::query()
                    ->whereKey($itemId)
                    ->where('is_active', true)
                    ->exists();
            } else {
                $exists = Product::query()
                    ->whereKey($itemId)
                    ->where('is_active', true)
                    ->exists();
            }

            if (! $exists) {
                abort(422, sprintf('Combo component %d item was not found or is inactive.', $index + 1));
            }
        }
    }

    private function listResource(ComboMenu $combo): array
    {
        $componentCount = $combo->items->count();
        $itemData = $combo->items->map(fn (ComboMenuItem $item): array => [
            'item_type' => $item->item_type,
            'item_name_snapshot' => $item->item_name_snapshot,
            'qty' => (float) $item->qty,
            'unit_name_snapshot' => $item->unit_name_snapshot,
            'cost_per_unit_snapshot' => (float) $item->cost_per_unit_snapshot,
            'amount' => (float) $item->amount,
        ])->values()->all();

        $cost = (float) $combo->cost_per_unit;
        $dineInPrice = (float) $combo->dine_in_price;
        $takeAwayPrice = (float) $combo->take_away_price;
        $deliveryPrice = (float) $combo->delivery_price;

        return [
            'id' => $combo->id,
            'name' => $combo->name,
            'code' => $combo->code,
            'category_id' => $combo->category_id,
            'category_name' => $combo->category?->name,
            'component_count' => $componentCount,
            'cost_per_unit' => $cost,
            'dine_in_price' => $dineInPrice,
            'take_away_price' => $takeAwayPrice,
            'delivery_price' => $deliveryPrice,
            'dine_in_profit' => round($dineInPrice - $cost, 2),
            'take_away_profit' => round($takeAwayPrice - $cost, 2),
            'delivery_profit' => round($deliveryPrice - $cost, 2),
            'is_active' => $combo->is_active,
            'created_at' => $combo->created_at?->toIso8601String(),
            'items' => $itemData,
        ];
    }

    private function detailResource(ComboMenu $combo): array
    {
        $data = $combo->attributesToArray();
        $imageUrl = $data['image_url'] ?? null;
        if (is_string($imageUrl) && $imageUrl !== '' && ! Str::startsWith($imageUrl, ['http://', 'https://'])) {
            $data['image_url'] = rtrim((string) config('app.url'), '/') . '/' . ltrim($imageUrl, '/');
        }

        $data['category_name'] = $combo->category?->name;
        $data['created_by_name'] = $combo->createdBy?->name;
        $data['updated_by_name'] = $combo->updatedBy?->name;

        $cost = (float) $combo->cost_per_unit;
        $dineInPrice = (float) $combo->dine_in_price;
        $takeAwayPrice = (float) $combo->take_away_price;
        $deliveryPrice = (float) $combo->delivery_price;

        $data['dine_in_profit'] = round($dineInPrice - $cost, 2);
        $data['take_away_profit'] = round($takeAwayPrice - $cost, 2);
        $data['delivery_profit'] = round($deliveryPrice - $cost, 2);

        if ($combo->relationLoaded('items')) {
            $data['items'] = $combo->items->map(fn (ComboMenuItem $item): array => [
                'id' => $item->id,
                'item_type' => $item->item_type,
                'item_id' => $item->item_id,
                'item_name_snapshot' => $item->item_name_snapshot,
                'qty' => (float) $item->qty,
                'unit_id' => $item->unit_id,
                'unit_name_snapshot' => $item->unit_name_snapshot,
                'cost_per_unit_snapshot' => (float) $item->cost_per_unit_snapshot,
                'amount' => (float) $item->amount,
                'sort_order' => $item->sort_order,
            ])->values()->all();
        }

        return $data;
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
