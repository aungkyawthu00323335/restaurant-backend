<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LocationRequest;
use App\Models\Location;
use App\Services\ApiImageStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class LocationController extends Controller
{
    public function __construct(
        private readonly ApiImageStorage $images,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $sortColumn = in_array($request->string('sort_col')->toString(), ['id', 'name', 'number', 'city', 'country', 'is_head_office', 'is_active', 'created_at'], true)
            ? $request->string('sort_col')->toString()
            : 'created_at';
        $sortDirection = $request->string('sort_dir')->toString() === 'asc' ? 'asc' : 'desc';
        $search = mb_substr(trim($request->string('search')->toString()), 0, 100);

        $query = Location::query()
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('number', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('address', 'like', "%{$search}%")
                        ->orWhere('city', 'like', "%{$search}%")
                        ->orWhere('country', 'like', "%{$search}%");
                });
            })
            ->orderBy($sortColumn, $sortDirection);

        $user = $request->user();
        if ($user && ! $user->isSuperAdmin()) {
            $query->whereIn('id', $user->allowedOutletIds());
        }

        $perPage = (int) $request->integer('per_page', 10);
        $perPage = in_array($perPage, [10, 20, 30, 50, 100], true) ? $perPage : 10;

        return response()->json($query->paginate($perPage));
    }

    public function createData(): JsonResponse
    {
        return response()->json([
            'food_menus' => \App\Models\FoodMenu::query()->where('is_active', true)->whereNull('deleted_at')->get(['id', 'name', 'category_id', 'dine_in_price', 'take_away_price', 'delivery_price', 'image_url']),
            'combo_menus' => \App\Models\ComboMenu::query()->where('is_active', true)->whereNull('deleted_at')->get(['id', 'name', 'category_id', 'dine_in_price', 'take_away_price', 'delivery_price', 'image_url']),
            'products' => \App\Models\Product::query()->where('is_active', true)->whereNull('deleted_at')->get(['id', 'name', 'product_category_id', 'sell_price_per_unit', 'image_url']),
        ]);
    }

    public function store(LocationRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $newImage = $this->images->storeBase64($validated['image_base64'] ?? null, 'locations');

        try {
            $location = DB::transaction(function () use ($validated, $newImage): Location {
                $payload = array_merge($validated, ['number' => $this->nextNumber()]);
                if ($newImage !== null) {
                    $payload['image_url'] = $newImage;
                }
                unset($payload['image_base64'], $payload['image_name']);

                if (($payload['is_head_office'] ?? false) || ! Location::query()->where('is_head_office', true)->exists()) {
                    $payload['is_head_office'] = true;
                    $this->clearHeadOffice();
                }

                $location = Location::create($payload);
                
                if (isset($validated['food_menus'])) {
                    $location->foodMenus()->sync(collect($validated['food_menus'])->keyBy('id')->map(fn($item) => collect($item)->except('id')->toArray())->toArray());
                }
                if (isset($validated['combo_menus'])) {
                    $location->comboMenus()->sync(collect($validated['combo_menus'])->keyBy('id')->map(fn($item) => collect($item)->except('id')->toArray())->toArray());
                }
                if (isset($validated['products'])) {
                    $location->products()->sync(collect($validated['products'])->keyBy('id')->map(fn($item) => collect($item)->except('id')->toArray())->toArray());
                }

                return $location;
            });
        } catch (Throwable $e) {
            if ($newImage !== null) {
                $this->images->delete($newImage, 'locations');
            }
            throw $e;
        }

        return response()->json($location, Response::HTTP_CREATED);
    }

    public function show(Location $location): JsonResponse
    {
        $location->load([
            'foodMenus:id,name,category_id,dine_in_price,take_away_price,delivery_price,image_url',
            'comboMenus:id,name,category_id,dine_in_price,take_away_price,delivery_price,image_url',
            'products:id,name,product_category_id,sell_price_per_unit,image_url'
        ]);
        return response()->json($location);
    }

    public function update(LocationRequest $request, Location $location): JsonResponse
    {
        $validated = $request->validated();
        $oldImage = $location->image_url;
        $newImage = $this->images->storeBase64($validated['image_base64'] ?? null, 'locations');
        $requestedImage = array_key_exists('image_url', $validated)
            ? $this->nullableString($validated['image_url'])
            : $oldImage;
        $finalImage = $newImage ?? $requestedImage;

        try {
            DB::transaction(function () use ($validated, $location, $finalImage): void {
                $payload = array_merge($validated, ['image_url' => $finalImage]);
                unset($payload['image_base64'], $payload['image_name']);

                if (($payload['is_head_office'] ?? false) === true) {
                    $this->clearHeadOffice($location);
                }

                $location->update($payload);

                if (isset($validated['food_menus'])) {
                    $location->foodMenus()->sync(collect($validated['food_menus'])->keyBy('id')->map(fn($item) => collect($item)->except('id')->toArray())->toArray());
                }
                if (isset($validated['combo_menus'])) {
                    $location->comboMenus()->sync(collect($validated['combo_menus'])->keyBy('id')->map(fn($item) => collect($item)->except('id')->toArray())->toArray());
                }
                if (isset($validated['products'])) {
                    $location->products()->sync(collect($validated['products'])->keyBy('id')->map(fn($item) => collect($item)->except('id')->toArray())->toArray());
                }

                if ($location->wasChanged('is_head_office') && ! $location->is_head_office) {
                    $this->ensureHeadOffice($location);
                }
            });
        } catch (Throwable $e) {
            if ($newImage !== null) {
                $this->images->delete($newImage, 'locations');
            }
            throw $e;
        }

        if ($oldImage !== $finalImage && $oldImage !== null) {
            $this->images->delete($oldImage, 'locations');
        }

        return response()->json($location->refresh());
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return is_string($value) ? $value : null;
    }

    public function destroy(Location $location): JsonResponse
    {
        DB::transaction(function () use ($location): void {
            $wasHeadOffice = $location->is_head_office;
            $location->delete();

            if ($wasHeadOffice) {
                $replacement = Location::query()->where('is_active', true)->oldest('id')->first();
                $replacement?->update(['is_head_office' => true]);
            }
        });

        return response()->json(['message' => 'Location deleted.']);
    }

    private function clearHeadOffice(?Location $except = null): void
    {
        $ids = Location::query()
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->id))
            ->where('is_head_office', true)
            ->lockForUpdate()
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            Location::query()->whereKey($ids)->update(['is_head_office' => false]);
        }
    }

    private function ensureHeadOffice(Location $except): void
    {
        if (Location::query()->where('is_head_office', true)->exists()) {
            return;
        }

        $replacement = Location::query()
            ->whereKeyNot($except->id)
            ->where('is_active', true)
            ->oldest('id')
            ->first();

        ($replacement ?? $except)->update(['is_head_office' => true]);
    }

    private function nextNumber(): string
    {
        $next = (Location::withTrashed()->max('id') ?? 0) + 1;

        return str_pad((string) $next, max(3, strlen((string) $next)), '0', STR_PAD_LEFT);
    }
}
