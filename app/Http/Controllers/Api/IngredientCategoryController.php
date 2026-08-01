<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IngredientCategory;
use App\Services\ApiImageStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class IngredientCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sortCol = in_array($request->string('sort_col')->toString(), ['name'], true)
            ? $request->string('sort_col')->toString()
            : 'created_at';
        $sortDir = $request->string('sort_dir')->toString() === 'asc' ? 'asc' : 'desc';

        $query = IngredientCategory::query()
            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request): void {
                $search = $request->string('search')->toString();
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->orderBy($sortCol, $sortDir);

        $perPage = (int) $request->integer('per_page', 10);
        $perPage = ($perPage > 0 && $perPage <= (int) config('pos.max_page_size', 100)) ? $perPage : 10;

        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request, ApiImageStorage $images): JsonResponse
    {
        $maxEncodedLength = $this->maxEncodedImageLength();
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('ingredient_categories', 'name')],
            'description' => ['nullable', 'string', 'max:500'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'image_base64' => ['nullable', 'string', 'max:'.$maxEncodedLength],
            'image_name' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        $newImage = $images->storeBase64($payload['image_base64'] ?? null, 'ingredient-categories');
        $payload['image_url'] = $newImage ?? ($payload['image_url'] ?? null);
        unset($payload['image_base64'], $payload['image_name']);

        try {
            $category = IngredientCategory::create($payload);
        } catch (Throwable $exception) {
            if ($newImage !== null) {
                $images->delete($newImage, 'ingredient-categories');
            }
            throw $exception;
        }

        return response()->json($category, 201);
    }

    public function show(IngredientCategory $ingredientCategory): JsonResponse
    {
        return response()->json($ingredientCategory);
    }

    public function update(Request $request, IngredientCategory $ingredientCategory, ApiImageStorage $images): JsonResponse
    {
        $maxEncodedLength = $this->maxEncodedImageLength();
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('ingredient_categories', 'name')->ignore($ingredientCategory->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'image_base64' => ['nullable', 'string', 'max:'.$maxEncodedLength],
            'image_name' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        $oldImage = $ingredientCategory->image_url;
        $newImage = $images->storeBase64($payload['image_base64'] ?? null, 'ingredient-categories');
        $payload['image_url'] = $newImage ?? (array_key_exists('image_url', $payload) ? $payload['image_url'] : $oldImage);
        unset($payload['image_base64'], $payload['image_name']);

        try {
            $ingredientCategory->update($payload);
        } catch (Throwable $exception) {
            if ($newImage !== null) {
                $images->delete($newImage, 'ingredient-categories');
            }
            throw $exception;
        }

        if ($oldImage !== $payload['image_url']) {
            $images->delete($oldImage, 'ingredient-categories');
        }

        return response()->json($ingredientCategory->refresh());
    }

    public function destroy(IngredientCategory $ingredientCategory, ApiImageStorage $images): JsonResponse
    {
        $image = $ingredientCategory->image_url;
        $ingredientCategory->delete();
        $images->delete($image, 'ingredient-categories');

        return response()->json(['message' => 'Ingredient category deleted.']);
    }

    private function maxEncodedImageLength(): int
    {
        return (int) ceil(max(1, (int) config('pos.max_image_bytes', 5 * 1024 * 1024)) * 4 / 3) + 128;
    }
}
