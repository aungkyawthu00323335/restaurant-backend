<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\ProductUnit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductUnitController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'active_status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
            'allow_decimal' => ['nullable', 'string', Rule::in(['yes', 'no'])],
            'sort_col' => ['nullable', 'string', Rule::in(['id', 'name', 'code', 'created_at', 'allow_decimal_qty'])],
            'sort_dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer'],
        ]);

        $sortCol = $payload['sort_col'] ?? 'created_at';
        $sortDir = $payload['sort_dir'] ?? 'desc';

        $query = ProductUnit::query();
        $this->applyFilters($query, $payload);
        $query->orderBy($sortCol, $sortDir)->orderBy('id', 'desc');

        $perPage = (int) ($payload['per_page'] ?? 20);
        $perPage = in_array($perPage, [10, 20, 30, 50, 100], true) ? $perPage : 20;

        $records = $query->paginate($perPage)->through(
            fn (ProductUnit $unit): array => $this->resource($unit)
        );

        return response()->json(['data' => $records]);
    }

    public function createData(): JsonResponse
    {
        return response()->json(['data' => []]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:40', Rule::unique('product_units', 'code')->whereNull('deleted_at')],
            'allow_decimal_qty' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $name = trim($payload['name']);
        $this->checkDuplicateName($name);

        $code = !empty($payload['code']) 
            ? strtoupper(trim($payload['code'])) 
            : strtoupper(Str::random(4));

        $unit = ProductUnit::query()->create([
            'name' => $name,
            'code' => $code,
            'allow_decimal_qty' => $payload['allow_decimal_qty'] ?? false,
            'description' => $payload['description'] ?? null,
            'sort_order' => (int) ($payload['sort_order'] ?? 0),
            'is_active' => $payload['is_active'] ?? true,
            'note' => $payload['note'] ?? null,
            'created_by_name' => 'System',
            'updated_by_name' => 'System',
        ]);

        return response()->json(['data' => $this->resource($unit)], 201);
    }

    public function show(ProductUnit $productUnit): JsonResponse
    {
        $products = Product::query()
            ->where('product_unit_id', $productUnit->id)
            ->with('productCategory:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'product_category_id', 'is_active']);

        $resource = $this->resource($productUnit);
        $resource['updated_by_name'] = $productUnit->updated_by_name;
        $resource['deleted_at'] = $productUnit->deleted_at?->toIso8601String();
        $resource['products'] = $products->map(fn (Product $p): array => [
            'id' => $p->id,
            'name' => $p->name,
            'code' => $p->code,
            'category_name' => $p->productCategory?->name ?? '',
            'is_active' => $p->is_active,
        ])->all();

        return response()->json(['data' => $resource]);
    }

    public function update(Request $request, ProductUnit $productUnit): JsonResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:40', Rule::unique('product_units', 'code')->whereNull('deleted_at')->ignore($productUnit->id)],
            'allow_decimal_qty' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $name = trim($payload['name']);
        $this->checkDuplicateName($name, $productUnit->id);

        $newAllowDecimal = $payload['allow_decimal_qty'] ?? false;

        if ($productUnit->allow_decimal_qty && !$newAllowDecimal) {
            $hasDecimalQty = $this->unitHasDecimalQuantities($productUnit->id);
            if ($hasDecimalQty) {
                abort(422, 'This Unit cannot disable decimal quantity because decimal stock or transaction quantities already exist.');
            }
        }

        $productUnit->update([
            'name' => $name,
            'code' => !empty($payload['code']) ? strtoupper(trim($payload['code'])) : $productUnit->code,
            'allow_decimal_qty' => $newAllowDecimal,
            'description' => $payload['description'] ?? null,
            'sort_order' => (int) ($payload['sort_order'] ?? 0),
            'is_active' => $payload['is_active'] ?? true,
            'note' => $payload['note'] ?? null,
            'updated_by_name' => 'System',
        ]);

        return response()->json(['data' => $this->resource($productUnit->fresh())]);
    }

    public function destroy(ProductUnit $productUnit): JsonResponse
    {
        $productCount = Product::query()->where('product_unit_id', $productUnit->id)->withTrashed()->count();

        if ($productCount > 0) {
            abort(422, 'This Product Unit cannot be deleted because it has Product or transaction history. Please deactivate it instead.');
        }

        $hasMovements = ProductStockMovement::query()
            ->whereHas('product', fn ($q) => $q->where('product_unit_id', $productUnit->id))
            ->exists();

        if ($hasMovements) {
            abort(422, 'This Product Unit cannot be deleted because it has Product or transaction history. Please deactivate it instead.');
        }

        $productUnit->delete();

        return response()->json(['message' => 'Product Unit deleted.']);
    }

    public function toggleStatus(ProductUnit $productUnit): JsonResponse
    {
        $productUnit->update([
            'is_active' => !$productUnit->is_active,
            'updated_by_name' => 'System',
        ]);

        return response()->json(['data' => $this->resource($productUnit->fresh())]);
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
            })
            ->when(isset($payload['allow_decimal']), function (Builder $q) use ($payload): void {
                $q->where('allow_decimal_qty', $payload['allow_decimal'] === 'yes');
            });
    }

    private function checkDuplicateName(string $name, ?int $ignoreId = null): void
    {
        $exists = ProductUnit::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)])
            ->when($ignoreId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->exists();

        if ($exists) {
            abort(422, 'Product Unit Name already exists.');
        }
    }

    private function unitHasDecimalQuantities(int $unitId): bool
    {
        $productIds = Product::query()
            ->where('product_unit_id', $unitId)
            ->where(function ($q) {
                $q->whereRaw('low_stock_qty != FLOOR(low_stock_qty)');
            })
            ->pluck('id');

        if ($productIds->isNotEmpty()) {
            return true;
        }

        return ProductStockMovement::query()
            ->whereIn('product_id', function ($q) use ($unitId) {
                $q->select('id')
                    ->from('products')
                    ->where('product_unit_id', $unitId);
            })
            ->whereRaw('quantity != FLOOR(quantity)')
            ->exists();
    }

    private function resource(ProductUnit $unit): array
    {
        return [
            'id' => $unit->id,
            'name' => $unit->name,
            'code' => $unit->code,
            'allow_decimal_qty' => $unit->allow_decimal_qty,
            'description' => $unit->description,
            'sort_order' => (int) $unit->sort_order,
            'is_active' => $unit->is_active,
            'note' => $unit->note,
            'product_count' => (int) Product::query()->where('product_unit_id', $unit->id)->withTrashed()->count(),
            'created_by_name' => $unit->created_by_name,
            'created_at' => $unit->created_at?->toIso8601String(),
        ];
    }
}
