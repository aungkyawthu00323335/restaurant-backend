<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CategoryRequest;
use App\Models\Category;
use App\Services\ApiImageStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class CategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sortColumn = in_array($request->string('sort_col')->toString(), ['id', 'name', 'number', 'sort_order', 'is_active', 'created_at'], true)
            ? $request->string('sort_col')->toString()
            : 'created_at';
        $sortDirection = $request->string('sort_dir')->toString() === 'asc' ? 'asc' : 'desc';
        $search = mb_substr(trim($request->string('search')->toString()), 0, 100);

        $query = Category::query()
            ->when($request->has('active'), fn ($query) => $query->where('is_active', $request->boolean('active')))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('number', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->orderBy($sortColumn, $sortDirection);

        $perPage = (int) $request->integer('per_page', 10);
        $perPage = in_array($perPage, [10, 20, 30, 50, 100], true) ? $perPage : 10;

        return response()->json($query->paginate($perPage));
    }

    public function store(CategoryRequest $request, ApiImageStorage $images): JsonResponse
    {
        $payload = $request->validated();
        $newImage = $images->storeBase64($payload['image_base64'] ?? null, 'categories');
        unset($payload['image_base64'], $payload['image_name']);
        $payload['slug'] = $this->uniqueSlug($payload['name']);
        $payload['number'] = $this->nextNumber();
        $payload['image_url'] = $newImage ?? ($payload['image_url'] ?? null);

        try {
            $category = Category::create($payload);
        } catch (Throwable $exception) {
            if ($newImage !== null) {
                $images->delete($newImage, 'categories');
            }
            throw $exception;
        }

        return response()->json($category, Response::HTTP_CREATED);
    }

    public function show(Category $category): JsonResponse
    {
        return response()->json($category);
    }

    public function update(CategoryRequest $request, Category $category, ApiImageStorage $images): JsonResponse
    {
        $payload = $request->validated();
        $oldImage = $category->image_url;
        $newImage = $images->storeBase64($payload['image_base64'] ?? null, 'categories');
        $requestedImage = array_key_exists('image_url', $payload) ? $payload['image_url'] : $oldImage;
        unset($payload['image_base64'], $payload['image_name']);
        $payload['slug'] = $this->uniqueSlug($payload['name'], $category->id);
        $payload['image_url'] = $newImage ?? $requestedImage;

        try {
            $category->update($payload);
        } catch (Throwable $exception) {
            if ($newImage !== null) {
                $images->delete($newImage, 'categories');
            }
            throw $exception;
        }

        if ($oldImage !== $payload['image_url']) {
            $images->delete($oldImage, 'categories');
        }

        return response()->json($category->refresh());
    }

    public function destroy(Category $category, ApiImageStorage $images): JsonResponse
    {
        if ($category->foodMenus()->exists()) {
            return response()->json([
                'message' => 'This category is used by one or more food menus. Move those food menus before deleting it.',
            ], Response::HTTP_CONFLICT);
        }

        $image = $category->image_url;
        $category->delete();
        $images->delete($image, 'categories');

        return response()->json(['message' => 'Category deleted.']);
    }

    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;
        $index = 2;

        while (Category::withTrashed()->where('slug', $slug)->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))->exists()) {
            $slug = "{$base}-{$index}";
            $index++;
        }

        return $slug;
    }

    private function nextNumber(): string
    {
        $next = (Category::withTrashed()->max('id') ?? 0) + 1;

        return str_pad((string) $next, max(3, strlen((string) $next)), '0', STR_PAD_LEFT);
    }
}
