<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductStockMovement;
use App\Models\ProductUnit;
use App\Services\ApiImageStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

class ProductController extends Controller
{
    public function __construct(
        private readonly ApiImageStorage $images,
    ) {}
    public function index(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'product_category_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            'product_unit_id' => ['nullable', 'integer', 'exists:product_units,id'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'stock_status' => ['nullable', 'string', Rule::in(['all', 'in_stock', 'low_stock', 'out_of_stock'])],
            'active_status' => ['nullable', 'string', Rule::in(['all', 'active', 'inactive'])],
            'sort_col' => ['nullable', 'string', Rule::in(['id', 'name', 'code', 'purchase_price_per_unit', 'sell_price_per_unit', 'created_at', 'is_active'])],
            'sort_dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer'],
            'include_outlet_stocks' => ['nullable', 'boolean'],
        ]);

        $sortCol = $payload['sort_col'] ?? 'created_at';
        $sortDir = $payload['sort_dir'] ?? 'desc';

        $query = Product::query()
            ->with(['productCategory', 'productUnit', 'printer']);
        $this->applyFilters($query, $payload);
        $query->orderBy("products.{$sortCol}", $sortDir)->orderBy('products.id', 'desc');

        $perPage = (int) ($payload['per_page'] ?? 20);
        $perPage = ($perPage > 0 && $perPage <= 1000) ? $perPage : 20;

        $locationId = isset($payload['location_id']) ? (int) $payload['location_id'] : null;
        $outletStockLocations = $request->boolean('include_outlet_stocks')
            ? Location::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
            : null;

        $records = $query->paginate($perPage)->through(
            fn (Product $product): array => $this->listResource($product, $locationId, $outletStockLocations)
        );

        $filteredQuery = Product::query();
        $this->applyFilters($filteredQuery, $payload);
        $filteredIds = $filteredQuery->pluck('products.id');

        return response()->json([
            'data' => [
                'summary' => [
                    'total_products' => $filteredIds->count(),
                    'total_active' => (clone $filteredQuery)->where('products.is_active', true)->count(),
                    'total_stock_qty' => round((float) (clone $filteredQuery)->sum('products.purchase_price_per_unit'), 4),
                    'low_stock_count' => (clone $filteredQuery)->where('products.is_active', true)->where('products.low_stock_qty', '>', 0)->count(),
                ],
                'records' => $records,
            ],
        ]);
    }

    public function createData(): JsonResponse
    {
        $categories = ProductCategory::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $units = ProductUnit::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'allow_decimal_qty']);

        $locations = Location::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $printers = \App\Models\Printer::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'paper_size', 'ip_address', 'port']);

        return response()->json([
            'data' => [
                'categories' => $categories,
                'units' => $units,
                'locations' => $locations,
                'printers' => $printers,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'code' => ['required', 'string', 'max:80', Rule::unique('products', 'code')->whereNull('deleted_at')],
            'barcode' => ['nullable', 'string', 'max:80', Rule::unique('products', 'barcode')->whereNull('deleted_at')],
            'product_category_id' => ['required', 'integer', 'exists:product_categories,id'],
            'printer_id' => ['nullable', 'integer', 'exists:printers,id'],
            'product_unit_id' => ['required', 'integer', 'exists:product_units,id'],
            'purchase_price_per_unit' => ['required', 'numeric', 'min:0'],
            'sell_price_per_unit' => ['required', 'numeric', 'min:0'],
            'opening_stock_qty' => ['nullable', 'numeric', 'min:0'],
            'opening_stock_location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'low_stock_qty' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
            'image_base64' => ['nullable', 'string'],
            'image_name' => ['nullable', 'string', 'max:255'],
            'image_url' => ['nullable', 'string', 'max:2048'],
        ]);

        $openingQty = (float) ($payload['opening_stock_qty'] ?? 0);
        $openingLocationId = isset($payload['opening_stock_location_id']) ? (int) $payload['opening_stock_location_id'] : null;

        if ($openingQty > 0 && $openingLocationId === null) {
            abort(422, 'Opening stock outlet is required when opening stock qty is greater than 0.');
        }

        $category = ProductCategory::query()->findOrFail((int) $payload['product_category_id']);
        if (! $category->is_active) {
            abort(422, 'Selected category is inactive.');
        }

        $unit = ProductUnit::query()->findOrFail((int) $payload['product_unit_id']);
        if (! $unit->is_active) {
            abort(422, 'Selected unit is inactive.');
        }

        $this->validateDecimalQty((float) ($payload['low_stock_qty'] ?? 0), $unit);
        $this->validateDecimalQty($openingQty, $unit);

        $newImage = $this->images->storeBase64($payload['image_base64'] ?? null, 'products');
        $actorName = 'System';

        try {
            $product = DB::transaction(function () use ($payload, $openingQty, $openingLocationId, $newImage, $actorName): Product {
                $product = Product::query()->create([
                    'name' => $payload['name'],
                    'code' => $payload['code'],
                    'barcode' => $payload['barcode'] ?? null,
                    'product_category_id' => (int) $payload['product_category_id'],
                    'product_unit_id' => (int) $payload['product_unit_id'],
                    'purchase_price_per_unit' => (float) ($payload['purchase_price_per_unit'] ?? 0),
                    'sell_price_per_unit' => (float) ($payload['sell_price_per_unit'] ?? 0),
                    'low_stock_qty' => (float) ($payload['low_stock_qty'] ?? 0),
                    'image_url' => $newImage,
                    'description' => $payload['description'] ?? null,
                    'note' => $payload['note'] ?? null,
                    'is_active' => $payload['is_active'] ?? true,
                    'created_by_name' => $actorName,
                    'updated_by_name' => $actorName,
                ]);

            if ($openingQty > 0 && $openingLocationId !== null) {
                $occurredAt = Carbon::now();
                $amount = round($openingQty * (float) $product->purchase_price_per_unit, 2);

                ProductStockMovement::query()->create([
                    'product_id' => $product->id,
                    'location_id' => $openingLocationId,
                    'direction' => 'in',
                    'reason_code' => 'opening_stock_in',
                    'quantity' => $openingQty,
                    'unit_cost' => $product->purchase_price_per_unit,
                    'amount' => $amount,
                    'reference' => 'Product Opening Stock',
                    'note' => 'Opening stock for ' . $product->name,
                    'occurred_at' => $occurredAt,
                    'created_by_name' => $actorName,
                ]);
            }

            return $product;
        });

        return response()->json(['data' => $this->detailResource($product)], 201);
    } catch (Throwable $e) {
        if ($newImage !== null) {
            $this->images->delete($newImage, 'products');
        }
        throw $e;
    }
}

    public function show(Product $product): JsonResponse
    {
        $locations = Location::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $outletStocks = $locations->map(fn (Location $loc): array => [
            'location_id' => $loc->id,
            'location_name' => $loc->name,
            'current_stock_qty' => $product->currentStockForLocation($loc->id),
        ]);

        $resource = $this->detailResource($product);
        $resource['outlet_stocks'] = $outletStocks->all();

        return response()->json(['data' => $resource]);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'code' => ['required', 'string', 'max:80', Rule::unique('products', 'code')->whereNull('deleted_at')->ignore($product->id)],
            'barcode' => ['nullable', 'string', 'max:80', Rule::unique('products', 'barcode')->whereNull('deleted_at')->ignore($product->id)],
            'product_category_id' => ['required', 'integer', 'exists:product_categories,id'],
            'printer_id' => ['nullable', 'integer', 'exists:printers,id'],
            'product_unit_id' => ['required', 'integer', 'exists:product_units,id'],
            'purchase_price_per_unit' => ['required', 'numeric', 'min:0'],
            'sell_price_per_unit' => ['required', 'numeric', 'min:0'],
            'low_stock_qty' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
            'image_base64' => ['nullable', 'string'],
            'image_name' => ['nullable', 'string', 'max:255'],
            'image_url' => ['nullable', 'string', 'max:2048'],
        ]);

        $unit = ProductUnit::query()->findOrFail((int) $payload['product_unit_id']);
        if (! $unit->is_active) {
            abort(422, 'Selected unit is inactive.');
        }

        $this->validateDecimalQty((float) ($payload['low_stock_qty'] ?? 0), $unit);

        if ((int) $payload['product_unit_id'] !== $product->product_unit_id) {
            $hasStockOrMovements = $product->stockMovements()->exists()
                || ProductStockMovement::query()->where('product_id', $product->id)->exists();

            if ($hasStockOrMovements) {
                abort(422, 'This Product Unit cannot be changed because the Product already has stock or transaction history.');
            }
        }

        $oldImage = $product->image_url;
        $newImage = $this->images->storeBase64($payload['image_base64'] ?? null, 'products');
        $requestedImage = array_key_exists('image_url', $payload)
            ? $this->nullableString($payload['image_url'])
            : $oldImage;
        $finalImage = $newImage ?? $requestedImage;

        try {
            DB::transaction(function () use ($payload, $product, $finalImage): void {
                $product->update([
                    'name' => $payload['name'],
                    'code' => $payload['code'],
                    'barcode' => $payload['barcode'] ?? null,
                    'product_category_id' => (int) $payload['product_category_id'],
                    'product_unit_id' => (int) $payload['product_unit_id'],
                    'purchase_price_per_unit' => (float) ($payload['purchase_price_per_unit'] ?? 0),
                    'sell_price_per_unit' => (float) ($payload['sell_price_per_unit'] ?? 0),
                    'low_stock_qty' => (float) ($payload['low_stock_qty'] ?? 0),
                    'image_url' => $finalImage,
                    'description' => $payload['description'] ?? null,
                    'note' => $payload['note'] ?? null,
                    'is_active' => $payload['is_active'] ?? true,
                ]);
            });
        } catch (Throwable $e) {
            if ($newImage !== null) {
                $this->images->delete($newImage, 'products');
            }
            throw $e;
        }

        if ($oldImage !== $finalImage && $oldImage !== null) {
            $this->images->delete($oldImage, 'products');
        }

        return response()->json(['data' => $this->detailResource($product->fresh())]);
    }

    public function destroy(Product $product): JsonResponse
    {
        $product->delete();
        return response()->json(['message' => 'Product deleted.']);
    }

    public function trash(Request $request): JsonResponse
    {
        $perPage = (int) ($request->input('per_page', 20));
        $perPage = in_array($perPage, [10, 20, 30, 50, 100], true) ? $perPage : 20;

        $products = Product::query()
            ->onlyTrashed()
            ->with(['productCategory', 'productUnit'])
            ->orderBy('deleted_at', 'desc')
            ->paginate($perPage)
            ->through(fn (Product $product): array => $this->listResource($product));

        return response()->json(['data' => $products]);
    }

    public function restore(int $id): JsonResponse
    {
        $product = Product::onlyTrashed()->findOrFail($id);
        $product->restore();

        return response()->json(['data' => $this->detailResource($product->fresh(['productCategory', 'productUnit']))]);
    }

    public function forceDelete(int $id): JsonResponse
    {
        $product = Product::onlyTrashed()->findOrFail($id);

        $hasTransactions = ProductStockMovement::query()
            ->where('product_id', $product->id)
            ->exists();

        if ($hasTransactions) {
            abort(422, 'This product cannot be permanently deleted because it has transaction history.');
        }

        $product->forceDelete();

        return response()->json(['message' => 'Product permanently deleted.']);
    }

    public function toggleStatus(Product $product): JsonResponse
    {
        $product->update(['is_active' => !$product->is_active]);

        return response()->json(['data' => $this->detailResource($product->fresh(['productCategory', 'productUnit']))]);
    }

    public function stockMovements(Request $request, Product $product): JsonResponse
    {
        $payload = $request->validate([
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'per_page' => ['nullable', 'integer'],
        ]);

        $perPage = (int) ($payload['per_page'] ?? 50);
        $perPage = in_array($perPage, [10, 20, 30, 50, 100], true) ? $perPage : 50;

        $query = $product->stockMovements()
            ->with('location')
            ->when(isset($payload['location_id']), fn ($q) => $q->where('location_id', (int) $payload['location_id']))
            ->orderBy('occurred_at', 'desc')
            ->orderBy('id', 'desc');

        return response()->json(
            $query->paginate($perPage)->through(fn (ProductStockMovement $m): array => [
                'id' => $m->id,
                'product_id' => $m->product_id,
                'location_id' => $m->location_id,
                'location_name' => $m->location?->name,
                'direction' => $m->direction,
                'reason_code' => $m->reason_code,
                'quantity' => round((float) $m->quantity, 4),
                'unit_cost' => round((float) $m->unit_cost, 2),
                'amount' => round((float) $m->amount, 2),
                'reference' => $m->reference,
                'note' => $m->note,
                'occurred_at' => $m->occurred_at?->toIso8601String(),
                'created_by_name' => $m->created_by_name,
            ])
        );
    }

    public function downloadImportTemplate()
    {
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="products_import_template.csv"',
        ];

        $columns = [
            'name',
            'code',
            'barcode',
            'category_name',
            'unit_name',
            'purchase_price',
            'sell_price',
            'low_stock_qty',
            'location_name',
            'opening_stock',
            'description'
        ];

        $output = "\xEF\xBB\xBF" . implode(',', $columns) . "\n";
        $output .= "Fresh Milk 1L,PRD-001,8850001,Dairy,Bottle,1500,2000,5,Main Warehouse,50,Fresh dairy milk\n";
        $output .= "Potato Chips 150g,PRD-002,8850002,Snacks,Piece,800,1200,10,Downtown Outlet,30,Crispy potato chips\n";
        $output .= "ရေသန့် 1L,PRD-003,8850003,Beverages,Bottle,800,1000,10,Main Warehouse,100,Fresh drinking water\n";
        $output .= "ကြက်ဥ,PRD-004,8850004,Grocery,Tray,4500,6000,15,Main Warehouse,20,Fresh chicken eggs\n";

        return response($output, 200, $headers);
    }

    public function importProducts(Request $request): JsonResponse
    {
        $rows = $request->input('rows');
        if (empty($rows) && $request->hasFile('file')) {
            $rows = $this->parseCsvFile($request->file('file'));
        }

        if (empty($rows) || !is_array($rows)) {
            return response()->json(['message' => 'No valid product rows or CSV file provided.'], 422);
        }

        $selectedLocationId = $request->filled('location_id') ? (int) $request->input('location_id') : null;
        $targetLocation = null;
        if ($selectedLocationId !== null) {
            $targetLocation = Location::query()->whereKey($selectedLocationId)->whereNull('deleted_at')->first();
        }

        $actorName = auth()->user()?->name ?? 'System';
        $validationErrors = [];
        $seenNames = [];
        $seenSkus = [];
        $seenBarcodes = [];

        // Pre-validation pass
        foreach ($rows as $index => $row) {
            $rowNum = $index + 1;
            
            $getValue = function (array $aliases) use ($row): string {
                foreach ($aliases as $alias) {
                    if (!empty($row[$alias])) return trim((string) $row[$alias]);
                    $lowerAlias = strtolower($alias);
                    if (!empty($row[$lowerAlias])) return trim((string) $row[$lowerAlias]);
                }
                return '';
            };

            $name = $this->sanitizeUtf8($getValue(['name', 'Name', 'product_name']));
            if ($name === '') {
                $validationErrors[] = "Row #{$rowNum}: Product name is required.";
                continue;
            }

            $catName = $this->sanitizeUtf8($getValue(['category_name', 'category', 'Category']));
            $unitName = $this->sanitizeUtf8($getValue(['unit_name', 'unit', 'Unit']));
            $purPriceStr = $getValue(['purchase_price', 'purchase_cost', 'cost']);
            $sellPriceStr = $getValue(['sell_price', 'price', 'selling_price']);

            $missingFields = [];
            if ($catName === '') $missingFields[] = 'Category';
            if ($unitName === '') $missingFields[] = 'Unit';

            if (!empty($missingFields)) {
                $fieldStr = implode(', ', $missingFields);
                $validationErrors[] = "Row #{$rowNum} ('{$name}'): Missing required field(s): {$fieldStr}.";
                continue;
            }

            $sku = $this->sanitizeUtf8($getValue(['code', 'Code', 'sku', 'SKU'])) ?: null;
            $barcode = $this->sanitizeUtf8($getValue(['barcode', 'Barcode'])) ?: null;

            // Duplicate checks in file
            $lowerName = mb_strtolower($name);
            if (isset($seenNames[$lowerName])) {
                $validationErrors[] = "Row #{$rowNum} ('{$name}'): Product name '{$name}' is duplicated in the import file (already seen in Row #{$seenNames[$lowerName]}).";
            } else {
                $seenNames[$lowerName] = $rowNum;
            }

            if ($sku !== null) {
                $lowerSku = mb_strtolower($sku);
                if (isset($seenSkus[$lowerSku])) {
                    $validationErrors[] = "Row #{$rowNum} ('{$name}'): SKU code '{$sku}' is duplicated in the import file (already seen in Row #{$seenSkus[$lowerSku]}).";
                } else {
                    $seenSkus[$lowerSku] = $rowNum;
                }
            }

            if ($barcode !== null) {
                $lowerBarcode = mb_strtolower($barcode);
                if (isset($seenBarcodes[$lowerBarcode])) {
                    $validationErrors[] = "Row #{$rowNum} ('{$name}'): Barcode '{$barcode}' is duplicated in the import file (already seen in Row #{$seenBarcodes[$lowerBarcode]}).";
                } else {
                    $seenBarcodes[$lowerBarcode] = $rowNum;
                }
            }
        }

        if (!empty($validationErrors)) {
            return response()->json([
                'message' => 'CSV file contains validation errors.',
                'errors' => $validationErrors,
            ], 422);
        }

        $importedCount = 0;
        $dbErrors = [];

        DB::transaction(function () use ($rows, $targetLocation, $actorName, &$importedCount, &$dbErrors) {
            foreach ($rows as $index => $row) {
                $rowNum = $index + 1;

                $getValue = function (array $aliases) use ($row): string {
                    foreach ($aliases as $alias) {
                        if (!empty($row[$alias])) return trim((string) $row[$alias]);
                        $lowerAlias = strtolower($alias);
                        if (!empty($row[$lowerAlias])) return trim((string) $row[$lowerAlias]);
                    }
                    return '';
                };

                $name = $this->sanitizeUtf8($getValue(['name', 'Name', 'product_name']));
                $code = $this->sanitizeUtf8($getValue(['code', 'Code', 'sku', 'SKU']));

                if ($code === '') {
                    $code = 'PRD-' . date('ymd') . '-' . str_pad((string) rand(100, 999), 3, '0', STR_PAD_LEFT);
                }

                $exists = Product::query()->where('code', $code)->whereNull('deleted_at')->exists();
                if ($exists) {
                    $code = $code . '-' . rand(10, 99);
                }

                $catName = $this->sanitizeUtf8($getValue(['category_name', 'category', 'Category']));
                $categoryId = null;
                if ($catName !== '') {
                    $catCode = 'CAT-' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $catName));
                    if (strlen($catCode) < 5) $catCode .= rand(100, 999);
                    $cat = ProductCategory::firstOrCreate(
                        ['name' => $catName],
                        [
                            'code' => substr($catCode, 0, 20),
                            'is_active' => true,
                        ]
                    );
                    $categoryId = $cat->id;
                }

                $unitName = $this->sanitizeUtf8($getValue(['unit_name', 'unit', 'Unit']));
                $unitId = null;
                if ($unitName !== '') {
                    $unitCode = 'UNT-' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $unitName));
                    if (strlen($unitCode) < 5) $unitCode .= rand(100, 999);
                    $u = ProductUnit::firstOrCreate(
                        ['name' => $unitName],
                        [
                            'code' => substr($unitCode, 0, 20),
                            'allow_decimal_qty' => false,
                            'is_active' => true,
                        ]
                    );
                    $unitId = $u->id;
                }

                $purchasePrice = (float) preg_replace('/[^0-9.]/', '', $getValue(['purchase_price', 'purchase_cost', 'cost']));
                $sellPrice = (float) preg_replace('/[^0-9.]/', '', $getValue(['sell_price', 'price', 'selling_price']));
                $lowStock = (float) preg_replace('/[^0-9.]/', '', $getValue(['low_stock_qty', 'low_stock']));
                $barcode = $this->sanitizeUtf8($getValue(['barcode', 'Barcode'])) ?: null;
                $desc = $this->sanitizeUtf8($getValue(['description', 'Description'])) ?: null;

                $product = Product::create([
                    'name' => $name,
                    'code' => $code,
                    'barcode' => $barcode,
                    'product_category_id' => $categoryId,
                    'product_unit_id' => $unitId,
                    'purchase_price_per_unit' => $purchasePrice,
                    'sell_price_per_unit' => $sellPrice,
                    'low_stock_qty' => $lowStock,
                    'description' => $desc,
                    'is_active' => true,
                    'created_by_name' => $actorName,
                    'updated_by_name' => $actorName,
                ]);

                // Handle opening stock for location if present
                $openingStockStr = $getValue(['opening_stock', 'opening_stock_qty', 'qty', 'stock']);
                $openingStock = (float) preg_replace('/[^0-9.]/', '', $openingStockStr);

                $locName = $this->sanitizeUtf8($getValue(['location_name', 'location', 'warehouse', 'warehouse_name']));
                $loc = null;
                if ($locName !== '') {
                    $loc = Location::query()->where('name', $locName)->whereNull('deleted_at')->first();
                }
                if ($loc === null) {
                    $loc = $targetLocation ?? Location::query()->whereNull('deleted_at')->where('is_active', true)->orderBy('id')->first();
                }

                if ($openingStock > 0 && $loc !== null) {
                    ProductStockMovement::query()->create([
                        'product_id' => $product->id,
                        'location_id' => $loc->id,
                        'direction' => 'in',
                        'reason_code' => 'opening_stock_in',
                        'quantity' => $openingStock,
                        'unit_cost' => $purchasePrice,
                        'amount' => round($openingStock * $purchasePrice, 2),
                        'reference' => 'Product Import Opening Stock',
                        'note' => 'Import opening stock for ' . $product->name,
                        'occurred_at' => Carbon::now(),
                        'created_by_name' => $actorName,
                    ]);
                }

                $importedCount++;
            }
        });

        return response()->json([
            'message' => "Successfully imported {$importedCount} product(s).",
            'imported_count' => $importedCount,
            'errors' => $dbErrors,
        ]);
    }

    public function exportExcel(Request $request)
    {
        $query = Product::query()->with(['productCategory', 'productUnit', 'stockMovements']);
        $this->applyFilters($query, $request->all());
        $products = $query->orderBy('id', 'desc')->get();

        $selectedLocationId = $request->filled('location_id') ? (int) $request->input('location_id') : null;
        $locations = Location::query()->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']);

        $currency = $this->mainCurrency();
        $currencySymbol = $currency['symbol'];
        $decimalPlaces = $currency['decimal_places'];

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="products_export_' . date('Y-m-d') . '.csv"',
        ];

        $escape = static fn ($value): string => '"' . str_replace('"', '""', (string) ($value ?? '')) . '"';
        $columns = [
            '#',
            'Warehouse',
            'Name',
            'SKU Code',
            'Barcode',
            'Category',
            'Unit',
            "Purchase Price ({$currencySymbol})",
            "Sell Price ({$currencySymbol})",
            'Stock Qty',
            'Low Stock Alert',
            'Status'
        ];
        $output = "\xEF\xBB\xBF" . implode(',', $columns) . "\n";

        $totalStockSum = 0;
        $totalPurchasePriceSum = 0;
        $totalSellPriceSum = 0;
        $rowCounter = 0;

        foreach ($products as $p) {
            $totalPurchasePriceSum += (float) $p->purchase_price_per_unit;
            $totalSellPriceSum += (float) $p->sell_price_per_unit;

            foreach ($this->stockRowsForExport($p, $selectedLocationId, $locations) as $stockRow) {
                $rowCounter++;
                $stockQty = (float) $stockRow['quantity'];
                $totalStockSum += $stockQty;

                $output .= implode(',', [
                    $rowCounter,
                    $escape($stockRow['location_name']),
                    $escape($p->name),
                    $escape($p->code),
                    $escape($p->barcode),
                    $escape($p->productCategory?->name ?? 'Uncategorized'),
                    $escape($p->productUnit?->name ?? 'Unit'),
                    number_format((float) $p->purchase_price_per_unit, 2, '.', ''),
                    number_format((float) $p->sell_price_per_unit, 2, '.', ''),
                    number_format($stockQty, 2, '.', ''),
                    number_format((float) $p->low_stock_qty, 2, '.', ''),
                    $p->is_active ? 'Active' : 'Inactive',
                ]) . "\n";
            }
        }

        $count = count($products);
        $avgPurchasePrice = $count > 0 ? number_format($totalPurchasePriceSum / $count, 2, '.', '') : '0.00';
        $avgSellPrice = $count > 0 ? number_format($totalSellPriceSum / $count, 2, '.', '') : '0.00';
        
        $output .= "\n" . implode(',', [
            'SUMMARY',
            $escape("Total Items: {$rowCounter}"),
            $escape("Total Products: {$count}"),
            '',
            '',
            '',
            '',
            $escape("Avg Purchase: {$currencySymbol} {$avgPurchasePrice}"),
            $escape("Avg Sell: {$currencySymbol} {$avgSellPrice}"),
            $escape("Total Stock: " . number_format($totalStockSum, 2, '.', '')),
            '',
            ''
        ]) . "\n";

        return response($output, 200, $headers);
    }

    public function exportPdf(Request $request)
    {
        $query = Product::query()->with(['productCategory', 'productUnit', 'stockMovements']);
        $this->applyFilters($query, $request->all());
        $products = $query->orderBy('id', 'desc')->get();

        $selectedLocationId = $request->filled('location_id') ? (int) $request->input('location_id') : null;
        $locations = Location::query()->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']);
        $selectedLocationName = $selectedLocationId !== null
            ? ($locations->firstWhere('id', $selectedLocationId)?->name ?? 'Selected Outlet')
            : 'All Outlets';

        $currency = $this->mainCurrency();
        $currencySymbol = $currency['symbol'];
        $decimalPlaces = $currency['decimal_places'];

        $totalCount = count($products);
        $activeCount = $products->where('is_active', true)->count();
        $inactiveCount = $totalCount - $activeCount;

        $totalStockQty = 0;
        $totalValuation = 0;
        $lowStockCount = 0;
        $exportRows = [];

        foreach ($products as $p) {
            foreach ($this->stockRowsForExport($p, $selectedLocationId, $locations) as $stockRow) {
                $stockQty = (float) $stockRow['quantity'];
                $totalStockQty += $stockQty;
                $totalValuation += ($stockQty * (float) $p->purchase_price_per_unit);

                if ($stockQty <= (float) $p->low_stock_qty && (float) $p->low_stock_qty > 0) {
                    $lowStockCount++;
                }

                $exportRows[] = [
                    'product' => $p,
                    'location_name' => $stockRow['location_name'],
                    'stock_qty' => $stockQty,
                ];
            }
        }

        $searchQuery = $request->string('search')->toString();

        $headers = [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="products_report_' . date('Y-m-d') . '.html"',
        ];

        $html = '<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Products Master Report</title>
    <style>
        @import url("https://fonts.googleapis.com/css2?family=Pyidaungsu:wght@400;700&family=Inter:wght@400;600;700&display=swap");
        @page { size: A4 landscape; margin: 12mm; }
        @media print {
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .no-print { display: none; }
        }
        * { box-sizing: border-box; font-family: "Pyidaungsu", "Inter", "Segoe UI", Roboto, sans-serif; }
        body { background: #f8fafc; color: #0f172a; margin: 0; padding: 20px; font-size: 11.5px; line-height: 1.4; }
        
        .header-card {
            background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
            color: #ffffff;
            padding: 20px 24px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.15);
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .header-card h1 { margin: 0; font-size: 22px; font-weight: 800; letter-spacing: -0.5px; }
        .header-card p { margin: 4px 0 0; font-size: 12px; opacity: 0.85; }
        .header-meta { text-align: right; font-size: 11.5px; opacity: 0.9; }

        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin-bottom: 20px;
        }
        .metric-box {
            background: #ffffff;
            padding: 14px 16px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }
        .metric-title { font-size: 10.5px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }
        .metric-val { font-size: 20px; font-weight: 800; color: #0f172a; margin-top: 2px; }
        .metric-sub { font-size: 10.5px; color: #94a3b8; margin-top: 1px; }

        .table-container {
            background: #ffffff;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th {
            background: #f1f5f9;
            color: #334155;
            font-size: 10.5px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 10px 12px;
            border-bottom: 2px solid #cbd5e1;
        }
        td { padding: 9px 12px; border-bottom: 1px solid #e2e8f0; font-size: 11.5px; vertical-align: middle; }
        tr:nth-child(even) { background-color: #f8fafc; }

        .badge {
            display: inline-block;
            padding: 2px 7px;
            border-radius: 9999px;
            font-size: 10px;
            font-weight: 800;
            text-align: center;
        }
        .badge-active { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
        .badge-disabled { background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; }
        .badge-low { background: #fff7ed; color: #c2410c; border: 1px solid #fdba74; }
        .badge-out { background: #fef2f2; color: #b91c1c; border: 1px solid #fca5a5; }

        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .font-mono { font-family: "Courier New", Courier, monospace; font-size: 11px; }

        .footer {
            margin-top: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 10.5px;
            color: #64748b;
        }
        .btn-print {
            background: #2563eb;
            color: #ffffff;
            border: none;
            padding: 9px 18px;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 2px 4px rgba(37,99,235,0.2);
        }
    </style>
</head>
<body>

    <div class="no-print" style="margin-bottom: 14px; text-align: right;">
        <button class="btn-print" onclick="window.print()">Print / Save PDF</button>
    </div>

    <div class="header-card">
        <div>
            <h1>Products Inventory Report</h1>
            <p>Master finished product catalog, selling prices, costs and stock positions</p>
        </div>
        <div class="header-meta">
            <div>Outlet: <strong>' . e($selectedLocationName) . '</strong></div>
            <div>Generated: <strong>' . date('d M Y, h:i A') . '</strong></div>
            <div>' . ($searchQuery ? 'Filter: "' . e($searchQuery) . '"' : 'All Catalog Products') . '</div>
        </div>
    </div>

    <div class="metrics-grid">
        <div class="metric-box">
            <div class="metric-title">Total Products</div>
            <div class="metric-val">' . number_format($totalCount) . '</div>
            <div class="metric-sub">' . count($exportRows) . ' Stock Position Rows</div>
        </div>
        <div class="metric-box">
            <div class="metric-title">Active Items</div>
            <div class="metric-val">' . number_format($activeCount) . '</div>
            <div class="metric-sub">' . $inactiveCount . ' Disabled Items</div>
        </div>
        <div class="metric-box">
            <div class="metric-title">Stock Valuation</div>
            <div class="metric-val">' . e($currencySymbol) . ' ' . number_format($totalValuation, 2) . '</div>
            <div class="metric-sub">Est. Cost Value</div>
        </div>
        <div class="metric-box">
            <div class="metric-title">Low Stock Alerts</div>
            <div class="metric-val" style="color: ' . ($lowStockCount > 0 ? '#d97706' : '#10b981') . '">' . number_format($lowStockCount) . '</div>
            <div class="metric-sub">Below Reorder Threshold</div>
        </div>
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th style="width: 35px;">#</th>
                    <th>Warehouse / Outlet</th>
                    <th>Product Name</th>
                    <th>SKU Code</th>
                    <th>Category</th>
                    <th>Unit</th>
                    <th class="text-right">Purchase Price</th>
                    <th class="text-right">Sell Price</th>
                    <th class="text-right">Stock Qty</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>';

        foreach ($exportRows as $index => $row) {
            $p = $row['product'];
            $locName = e($row['location_name']);
            $catName = e($p->productCategory?->name ?? 'General');
            $unitName = e($p->productUnit?->name ?? 'Unit');
            $currentStock = (float) $row['stock_qty'];
            $lowQty = (float) $p->low_stock_qty;

            $activeBadge = $p->is_active ? '<span class="badge badge-active">Active</span>' : '<span class="badge badge-disabled">Disabled</span>';
            $stockBadge = '';
            if ($currentStock <= 0) {
                $stockBadge = '<span class="badge badge-out">Out of Stock</span>';
            } elseif ($lowQty > 0 && $currentStock <= $lowQty) {
                $stockBadge = '<span class="badge badge-low">Low Stock</span>';
            }

            $html .= '<tr>
                <td class="text-center font-mono" style="color: #64748b;">' . ($index + 1) . '</td>
                <td><strong style="color: #2563eb;">' . $locName . '</strong></td>
                <td>
                    <strong style="color: #0f172a;">' . e($p->name) . '</strong>
                    ' . ($p->barcode ? '<br><span style="font-size: 9.5px; color: #94a3b8;">Barcode: ' . e($p->barcode) . '</span>' : '') . '
                </td>
                <td><span class="font-mono">' . e($p->code) . '</span></td>
                <td>' . $catName . '</td>
                <td>' . $unitName . '</td>
                <td class="text-right">' . e($currencySymbol) . ' ' . number_format((float) $p->purchase_price_per_unit, 2) . '</td>
                <td class="text-right"><strong style="color: #2563eb;">' . e($currencySymbol) . ' ' . number_format((float) $p->sell_price_per_unit, 2) . '</strong></td>
                <td class="text-right font-mono"><strong>' . number_format($currentStock, 2) . '</strong> ' . $stockBadge . '</td>
                <td>' . $activeBadge . '</td>
            </tr>';
        }

        $html .= '</tbody>
        </table>
    </div>

    <div class="footer">
        <div>POS System · Products Inventory Master Report (' . e($selectedLocationName) . ')</div>
        <div>Total Rows: ' . count($exportRows) . '</div>
    </div>

</body>
</html>';

        return response($html, 200, $headers);
    }

    private function stockRowsForExport(Product $product, ?int $selectedLocationId, $locations): array
    {
        $movements = $product->stockMovements;
        $stockByLocation = [];
        foreach ($movements as $m) {
            $locId = (int) $m->location_id;
            if (!isset($stockByLocation[$locId])) {
                $stockByLocation[$locId] = 0;
            }
            if ($m->direction === 'in') {
                $stockByLocation[$locId] += (float) $m->quantity;
            } else {
                $stockByLocation[$locId] -= (float) $m->quantity;
            }
        }

        $targets = $selectedLocationId !== null
            ? $locations->where('id', $selectedLocationId)
            : $locations;

        if ($targets->isEmpty()) {
            $targets = collect([ (object) ['id' => $selectedLocationId ?? 0, 'name' => 'Main Warehouse'] ]);
        }

        return $targets->map(fn ($location): array => [
            'location_id' => (int) $location->id,
            'location_name' => $location->name ?: 'Warehouse '.$location->id,
            'quantity' => max(0, (float) ($stockByLocation[(int) $location->id] ?? 0)),
        ])->all();
    }

    private function parseCsvFile($file): array
    {
        $rows = [];
        if (($handle = fopen($file->getRealPath(), 'r')) !== false) {
            $header = fgetcsv($handle, 1000, ',');
            if ($header) {
                $header = array_map('trim', $header);
                while (($data = fgetcsv($handle, 1000, ',')) !== false) {
                    if (count($data) == count($header)) {
                        $rows[] = array_combine($header, array_map('trim', $data));
                    }
                }
            }
            fclose($handle);
        }
        return $rows;
    }

    private function applyFilters(Builder $query, array $payload): void
    {
        $query
            ->when(isset($payload['search']) && trim((string) $payload['search']) !== '', function (Builder $q) use ($payload): void {
                $search = trim((string) $payload['search']);
                $q->where(function (Builder $qq) use ($search): void {
                    $qq->where('products.name', 'like', "%{$search}%")
                        ->orWhere('products.code', 'like', "%{$search}%")
                        ->orWhere('products.barcode', 'like', "%{$search}%");
                });
            })
            ->when(isset($payload['product_category_id']), function (Builder $q) use ($payload): void {
                $q->where('products.product_category_id', (int) $payload['product_category_id']);
            })
            ->when(isset($payload['product_unit_id']), function (Builder $q) use ($payload): void {
                $q->where('products.product_unit_id', (int) $payload['product_unit_id']);
            })
            ->when(isset($payload['active_status']) && $payload['active_status'] !== 'all', function (Builder $q) use ($payload): void {
                $q->where('products.is_active', $payload['active_status'] === 'active');
            });
    }

    private function mainCurrency(): array
    {
        $currency = Currency::query()
            ->where('is_major', true)
            ->where('is_active', true)
            ->first()
            ?? Currency::query()->where('is_active', true)->orderBy('id')->first();

        return [
            'symbol' => trim((string) ($currency?->symbol ?: $currency?->code ?: 'Ks')),
            'decimal_places' => max(0, min(4, (int) ($currency?->decimal_places ?? 2))),
        ];
    }

    private function formatMoney(float $amount, string $symbol, int $decimalPlaces): string
    {
        return $symbol . ' ' . number_format($amount, $decimalPlaces, '.', ',');
    }

    private function validateDecimalQty(float $qty, ProductUnit $unit): void
    {
        if (!$unit->allow_decimal_qty && $qty != floor($qty)) {
            abort(422, "{$unit->name} unit does not allow decimal quantity.");
        }
    }

    private function listResource(Product $product, ?int $locationId = null, mixed $outletStockLocations = null): array
    {
        $currentStock = 0;
        $selectedLocationName = 'All Outlets';

        if ($locationId !== null) {
            $currentStock = $product->currentStockForLocation($locationId);
            $selectedLocationName = Location::query()->where('id', $locationId)->value('name') ?: 'Selected Outlet';
        } else {
            $currentStock = round(
                (float) ProductStockMovement::query()
                    ->where('product_id', $product->id)
                    ->selectRaw("COALESCE(SUM(CASE WHEN LOWER(direction) = 'in' THEN quantity ELSE -quantity END), 0) AS net")
                    ->value('net'),
                4
            );
        }

        $purchasePrice = (float) $product->purchase_price_per_unit;
        $sellPrice = (float) $product->sell_price_per_unit;
        $profitPerUnit = round($sellPrice - $purchasePrice, 2);
        $stockValue = round($currentStock * $purchasePrice, 2);

        $resource = [
            'id' => $product->id,
            'name' => $product->name,
            'code' => $product->code,
            'barcode' => $product->barcode,
            'product_category_id' => $product->product_category_id,
            'product_category_name' => $product->productCategory?->name,
            'printer_id' => $product->printer_id,
            'printer_name' => $product->printer?->name,
            'product_unit_id' => $product->product_unit_id,
            'product_unit_name' => $product->productUnit?->name,
            'product_unit_code' => $product->productUnit?->code,
            'allow_decimal_qty' => $product->productUnit?->allow_decimal_qty ?? false,
            'purchase_price_per_unit' => round($purchasePrice, 2),
            'sell_price_per_unit' => round($sellPrice, 2),
            'profit_per_unit' => $profitPerUnit,
            'current_stock_qty' => round($currentStock, 4),
            'selected_location_name' => $selectedLocationName,
            'low_stock_qty' => round((float) $product->low_stock_qty, 4),
            'stock_value' => $stockValue,
            'stock_status' => $this->stockStatus($currentStock, (float) $product->low_stock_qty),
            'is_active' => $product->is_active,
            'image_url' => $product->image_url,
            'description' => $product->description,
            'note' => $product->note,
            'created_by_name' => $product->created_by_name,
            'created_at' => $product->created_at?->toIso8601String(),
            'updated_at' => $product->updated_at?->toIso8601String(),
        ];

        if ($outletStockLocations !== null) {
            $resource['outlet_stocks'] = collect($outletStockLocations)
                ->map(fn (Location $loc): array => [
                    'location_id' => $loc->id,
                    'location_name' => $loc->name,
                    'current_stock_qty' => $product->currentStockForLocation((int) $loc->id),
                ])
                ->values()
                ->all();
        }

        return $resource;
    }

    private function detailResource(Product $product): array
    {
        $resource = $this->listResource($product);
        $purchasePrice = (float) $product->purchase_price_per_unit;
        $sellPrice = (float) $product->sell_price_per_unit;
        $profitMargin = $sellPrice > 0 ? round((($sellPrice - $purchasePrice) / $sellPrice) * 100, 2) : 0;

        $resource['profit_margin_percentage'] = $profitMargin;
        $resource['updated_by_name'] = $product->updated_by_name;
        $resource['deleted_at'] = $product->deleted_at?->toIso8601String();

        return $resource;
    }

    private function stockStatus(float $currentStock, float $lowStockQty): string
    {
        if ($currentStock <= 0) {
            return 'out_of_stock';
        }

        return $lowStockQty > 0 && $currentStock <= $lowStockQty ? 'low_stock' : 'in_stock';
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return is_string($value) ? $value : null;
    }

    private function sanitizeUtf8(mixed $value): string
    {
        if ($value === null) return '';
        $text = trim((string) $value);
        if ($text === '') return '';

        // Ensure valid UTF-8
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8, Windows-1252, ISO-8859-1, Windows-874, CP936, ASCII');
        }

        // Strip non-printable ASCII control characters (0x00-0x1F, 0x7F) except \n and \r
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        // Strip Unicode replacement character \uFFFD
        $text = preg_replace('/\x{FFFD}/u', '', $text);

        return trim($text);
    }
}
