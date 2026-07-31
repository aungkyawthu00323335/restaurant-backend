<?php

namespace App\Http\Controllers\Api;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\ApiImageStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;

class ProductCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'active_status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
            'sort_col' => ['nullable', 'string', Rule::in(['id', 'name', 'code', 'sort_order', 'is_active', 'created_at'])],
            'sort_dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer'],
        ]);

        $sortCol = $payload['sort_col'] ?? 'created_at';
        $sortDir = $payload['sort_dir'] ?? 'desc';

        $query = ProductCategory::query();
        $this->applyFilters($query, $payload);
        $query->orderBy($sortCol, $sortDir)->orderBy('id', 'desc');

        $perPage = (int) ($payload['per_page'] ?? 20);
        $perPage = in_array($perPage, [10, 20, 30, 50, 100], true) ? $perPage : 20;

        $records = $query->paginate($perPage)->through(
            fn (ProductCategory $cat): array => $this->resource($cat)
        );

        return response()->json(['data' => $records]);
    }

    public function createData(): JsonResponse
    {
        return response()->json(['data' => []]);
    }

    public function store(Request $request, ApiImageStorage $images): JsonResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:80', Rule::unique('product_categories', 'code')->whereNull('deleted_at')],
            'description' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
            'image_base64' => ['nullable', 'string'],
        ]);

        $name = trim($payload['name']);
        $this->checkDuplicateName($name);

        $code = !empty($payload['code']) 
            ? strtoupper(trim($payload['code'])) 
            : strtoupper(Str::random(6));

        $newImage = $images->storeBase64($payload['image_base64'] ?? null, 'product_categories');

        try {
            $category = ProductCategory::query()->create([
                'name' => $name,
                'code' => $code,
                'description' => $payload['description'] ?? null,
                'image_url' => $newImage,
                'sort_order' => (int) ($payload['sort_order'] ?? 0),
                'is_active' => $payload['is_active'] ?? true,
                'note' => $payload['note'] ?? null,
                'created_by_name' => 'System',
                'updated_by_name' => 'System',
            ]);
            return response()->json(['data' => $this->resource($category)], 201);
        } catch (\Exception $e) {
            if ($newImage) {
                $images->delete($newImage, 'product_categories');
            }
            throw $e;
        }
    }

    public function show(ProductCategory $productCategory): JsonResponse
    {
        $productCategory->loadCount([
            'products as total_product_count' => fn ($q) => $q->withTrashed(),
            'products as active_product_count' => fn ($q) => $q->where('is_active', true),
            'products as inactive_product_count' => fn ($q) => $q->where('is_active', false),
        ]);

        $products = Product::query()
            ->where('product_category_id', $productCategory->id)
            ->with('productUnit:id,name,code')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'product_unit_id', 'is_active']);

        $resource = $this->resource($productCategory);
        $resource['total_product_count'] = (int) $productCategory->total_product_count;
        $resource['active_product_count'] = (int) $productCategory->active_product_count;
        $resource['inactive_product_count'] = (int) $productCategory->inactive_product_count;
        $resource['updated_by_name'] = $productCategory->updated_by_name;
        $resource['deleted_at'] = $productCategory->deleted_at?->toIso8601String();
        $resource['products'] = $products->map(fn (Product $p): array => [
            'id' => $p->id,
            'name' => $p->name,
            'code' => $p->code,
            'unit_name' => $p->productUnit?->name ?? '',
            'is_active' => $p->is_active,
        ])->all();

        return response()->json(['data' => $resource]);
    }

    public function update(Request $request, ProductCategory $productCategory, ApiImageStorage $images): JsonResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:80', Rule::unique('product_categories', 'code')->whereNull('deleted_at')->ignore($productCategory->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
            'image_base64' => ['nullable', 'string'],
        ]);

        $name = trim($payload['name']);
        $this->checkDuplicateName($name, $productCategory->id);

        $newImage = $images->storeBase64($payload['image_base64'] ?? null, 'product_categories');

        try {
            $productCategory->update([
                'name' => $name,
                'code' => !empty($payload['code']) ? strtoupper(trim($payload['code'])) : $productCategory->code,
                'description' => $payload['description'] ?? null,
                'image_url' => $newImage ?? $productCategory->image_url,
                'sort_order' => (int) ($payload['sort_order'] ?? 0),
                'is_active' => $payload['is_active'] ?? true,
                'note' => $payload['note'] ?? null,
                'updated_by_name' => 'System',
            ]);
            return response()->json(['data' => $this->resource($productCategory->fresh())]);
        } catch (\Exception $e) {
            if ($newImage) {
                $images->delete($newImage, 'product_categories');
            }
            throw $e;
        }
    }

    public function destroy(ProductCategory $productCategory): JsonResponse
    {
        $productCount = Product::query()->where('product_category_id', $productCategory->id)->withTrashed()->count();

        if ($productCount > 0) {
            abort(422, 'This Product Category cannot be deleted because it is used by one or more Products. Please deactivate it instead.');
        }

        $productCategory->delete();

        return response()->json(['message' => 'Product Category deleted.']);
    }

    public function toggleStatus(ProductCategory $productCategory): JsonResponse
    {
        $productCategory->update([
            'is_active' => !$productCategory->is_active,
            'updated_by_name' => 'System',
        ]);

        return response()->json(['data' => $this->resource($productCategory->fresh())]);
    }

    private function applyFilters(Builder $query, array $payload): void
    {
        $query
            ->when(isset($payload['search']) && trim((string) $payload['search']) !== '', function (Builder $q) use ($payload): void {
                $search = trim((string) $payload['search']);
                $q->where(function (Builder $qq) use ($search): void {
                    $qq->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                });
            })
            ->when(isset($payload['active_status']), function (Builder $q) use ($payload): void {
                $q->where('is_active', $payload['active_status'] === 'active');
            });
    }

    private function checkDuplicateName(string $name, ?int $ignoreId = null): void
    {
        $exists = ProductCategory::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)])
            ->when($ignoreId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->exists();

        if ($exists) {
            abort(422, 'Product Category Name already exists.');
        }
    }

    private function resource(ProductCategory $cat): array
    {
        return [
            'id' => $cat->id,
            'name' => $cat->name,
            'code' => $cat->code,
            'description' => $cat->description,
            'image_url' => $cat->image_url,
            'sort_order' => (int) $cat->sort_order,
            'is_active' => $cat->is_active,
            'note' => $cat->note,
            'product_count' => (int) Product::query()->where('product_category_id', $cat->id)->withTrashed()->count(),
            'created_by_name' => $cat->created_by_name,
            'created_at' => $cat->created_at?->toIso8601String(),
        ];
    }
}
