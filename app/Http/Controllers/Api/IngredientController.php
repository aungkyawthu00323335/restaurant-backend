<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompositeIngredient;
use App\Models\Ingredient;
use App\Models\Location;
use App\Services\ApiImageStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class IngredientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sortCol = in_array($request->string('sort_col')->toString(), ['name', 'sku_code', 'purchase_price'], true)
            ? $request->string('sort_col')->toString()
            : 'created_at';
        $sortDir = $request->string('sort_dir')->toString() === 'asc' ? 'asc' : 'desc';

        $query = Ingredient::query()
            ->with(['category', 'purchaseUnit', 'consumptionUnit', 'compositions.child'])
            ->when($request->has('active'), fn ($query) => $query->where('is_active', $request->boolean('active')))
            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request): void {
                $search = $request->string('search')->toString();
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('sku_code', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->orderBy($sortCol, $sortDir);

        $perPage = (int) $request->integer('per_page', 10);
        $perPage = ($perPage > 0 && $perPage <= 5000) ? $perPage : 10;

        $paginator = $query->paginate($perPage);
        $this->appendCurrentStockData($paginator->items());

        return response()->json($paginator);
    }

    public function store(Request $request, ApiImageStorage $images): JsonResponse
    {
        $maxEncodedLength = $this->maxEncodedImageLength();
        $payload = $request->validate([
            'type' => ['nullable', 'string', 'in:single,composite'],
            'has_ingredient_mapping' => ['nullable', 'boolean'],
            'name' => ['required', 'string', 'max:120', Rule::unique('ingredients', 'name')],
            'ingredient_category_id' => ['required', 'integer', Rule::exists('ingredient_categories', 'id')->whereNull('deleted_at')],
            'purchase_unit_id' => ['required', 'integer', Rule::exists('purchase_units', 'id')->whereNull('deleted_at')],
            'consumption_unit_id' => ['required', 'integer', Rule::exists('consumption_units', 'id')->whereNull('deleted_at')],
            'conversion_rate' => ['nullable', 'numeric', 'gt:0', 'max:9999999999.9999'],
            'purchase_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'sku_code' => ['required', 'string', 'max:80', Rule::unique('ingredients', 'sku_code')],
            'barcode' => ['nullable', 'string', 'max:80', Rule::unique('ingredients', 'barcode')],
            'description' => ['nullable', 'string', 'max:500'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'image_base64' => ['nullable', 'string', 'max:'.$maxEncodedLength],
            'image_name' => ['nullable', 'string', 'max:255'],
            'initial_stock_data' => ['nullable', 'array', 'max:200'],
            'initial_stock_data.*.location_id' => ['required', 'integer', 'distinct', Rule::exists('locations', 'id')->whereNull('deleted_at')],
            'initial_stock_data.*.location_name' => ['nullable', 'string', 'max:160'],
            'initial_stock_data.*.quantity' => ['required', 'numeric', 'min:0', 'max:9999999999.9999'],
            'initial_stock_data.*.cost' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'initial_stock_data.*.alert_quantity' => ['required', 'numeric', 'min:0', 'max:9999999999.9999'],
            'initial_stock_data.*.unit_type' => ['nullable', 'string', 'in:purchase,consumption'],
            'is_active' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            'compositions' => ['nullable', 'array', 'max:100'],
            'compositions.*.child_ingredient_id' => ['required', 'integer', 'distinct', Rule::exists('ingredients', 'id')->whereNull('deleted_at')],
            'compositions.*.quantity' => ['required', 'numeric', 'gt:0', 'max:9999999999.9999'],
            'compositions.*.unit_type' => ['nullable', 'string', 'in:purchase,consumption'],
        ]);

        if ($request->has('active') && ! $request->has('is_active')) {
            $payload['is_active'] = $request->boolean('active');
        }
        unset($payload['active']);

        if ($request->has('has_ingredient_mapping')) {
            $payload['has_ingredient_mapping'] = $request->boolean('has_ingredient_mapping');
        } elseif ($request->has('type')) {
            $payload['has_ingredient_mapping'] = ($request->input('type') === 'composite');
        } else {
            $payload['has_ingredient_mapping'] = false;
        }
        $payload['type'] = $payload['has_ingredient_mapping'] ? 'composite' : 'single';
        if ($payload['has_ingredient_mapping']) {
            // Mapping definitions do not create stock. Stock is posted later
            // through the ingredient processing transaction.
            $payload['initial_stock_data'] = [];
        }
        $compositions = $payload['compositions'] ?? [];
        unset($payload['compositions']);

        if ($payload['has_ingredient_mapping'] && empty($compositions)) {
            abort(422, 'Ingredient mapping cannot be empty.');
        }
        $this->validateCompositionRules(null, $compositions);

        $newImage = $images->storeBase64($payload['image_base64'] ?? null, 'ingredients');
        $payload['image_url'] = $newImage ?? ($payload['image_url'] ?? null);
        unset($payload['image_base64'], $payload['image_name']);

        try {
            $ingredient = DB::transaction(function () use ($payload, $compositions): Ingredient {
                $ingredient = Ingredient::create($payload);
                $this->syncCompositions($ingredient, $compositions);

                return $ingredient;
            });
        } catch (Throwable $exception) {
            if ($newImage !== null) {
                $images->delete($newImage, 'ingredients');
            }
            throw $exception;
        }

        $ingredient->load(['category', 'purchaseUnit', 'consumptionUnit', 'compositions.child']);
        $this->appendCurrentStockData([$ingredient]);

        return response()->json($ingredient, 201);
    }

    public function show(Ingredient $ingredient): JsonResponse
    {
        $ingredient->load(['category', 'purchaseUnit', 'consumptionUnit', 'compositions.child']);
        $this->appendCurrentStockData([$ingredient]);
        return response()->json($ingredient);
    }

    public function update(Request $request, Ingredient $ingredient, ApiImageStorage $images): JsonResponse
    {
        $maxEncodedLength = $this->maxEncodedImageLength();
        $payload = $request->validate([
            'type' => ['nullable', 'string', 'in:single,composite'],
            'has_ingredient_mapping' => ['nullable', 'boolean'],
            'name' => ['required', 'string', 'max:120', Rule::unique('ingredients', 'name')->ignore($ingredient->id)],
            'ingredient_category_id' => ['nullable', 'integer', Rule::exists('ingredient_categories', 'id')->whereNull('deleted_at')],
            'purchase_unit_id' => ['nullable', 'integer', Rule::exists('purchase_units', 'id')->whereNull('deleted_at')],
            'consumption_unit_id' => ['nullable', 'integer', Rule::exists('consumption_units', 'id')->whereNull('deleted_at')],
            'conversion_rate' => ['nullable', 'numeric', 'gt:0', 'max:9999999999.9999'],
            'purchase_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'sku_code' => ['nullable', 'string', 'max:80', Rule::unique('ingredients', 'sku_code')->ignore($ingredient->id)],
            'barcode' => ['nullable', 'string', 'max:80', Rule::unique('ingredients', 'barcode')->ignore($ingredient->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'image_base64' => ['nullable', 'string', 'max:'.$maxEncodedLength],
            'image_name' => ['nullable', 'string', 'max:255'],
            'initial_stock_data' => ['nullable', 'array', 'max:200'],
            'initial_stock_data.*.location_id' => ['required', 'integer', 'distinct', Rule::exists('locations', 'id')->whereNull('deleted_at')],
            'initial_stock_data.*.location_name' => ['nullable', 'string', 'max:160'],
            'initial_stock_data.*.quantity' => ['nullable', 'numeric', 'min:0', 'max:9999999999.9999'],
            'initial_stock_data.*.cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'initial_stock_data.*.alert_quantity' => ['nullable', 'numeric', 'min:0', 'max:9999999999.9999'],
            'initial_stock_data.*.unit_type' => ['nullable', 'string', 'in:purchase,consumption'],
            'stock_settings' => ['nullable', 'array', 'max:200'],
            'stock_settings.*.location_id' => ['required', 'integer', 'distinct', Rule::exists('locations', 'id')->whereNull('deleted_at')],
            'stock_settings.*.location_name' => ['nullable', 'string', 'max:160'],
            'stock_settings.*.cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'stock_settings.*.alert_quantity' => ['nullable', 'numeric', 'min:0', 'max:9999999999.9999'],
            'stock_settings.*.unit_type' => ['nullable', 'string', 'in:purchase,consumption'],
            'is_active' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            'compositions' => ['nullable', 'array', 'max:100'],
            'compositions.*.child_ingredient_id' => ['required', 'integer', 'distinct', Rule::exists('ingredients', 'id')->whereNull('deleted_at')],
            'compositions.*.quantity' => ['required', 'numeric', 'gt:0', 'max:9999999999.9999'],
            'compositions.*.unit_type' => ['nullable', 'string', 'in:purchase,consumption'],
        ]);

        if ($request->has('active') && ! $request->has('is_active')) {
            $payload['is_active'] = $request->boolean('active');
        }
        unset($payload['active']);

        if ($request->has('has_ingredient_mapping')) {
            $payload['has_ingredient_mapping'] = $request->boolean('has_ingredient_mapping');
        } elseif ($request->has('type')) {
            $payload['has_ingredient_mapping'] = ($request->input('type') === 'composite');
        } else {
            $payload['has_ingredient_mapping'] = (bool) $ingredient->has_ingredient_mapping;
        }
        $payload['type'] = $payload['has_ingredient_mapping'] ? 'composite' : 'single';

        $stockSettings = $request->input('stock_settings');
        if ($stockSettings === null && $request->has('initial_stock_data')) {
            $stockSettings = $request->input('initial_stock_data');
        }
        unset($payload['stock_settings']);

        if ($payload['has_ingredient_mapping']) {
            $payload['initial_stock_data'] = [];
        } elseif (is_array($stockSettings) && ! empty($stockSettings)) {
            $payload['initial_stock_data'] = $this->mergeOutletStockSettings($ingredient, $stockSettings);
        } else {
            unset($payload['initial_stock_data']);
        }

        $compositions = $payload['compositions'] ?? [];
        unset($payload['compositions']);

        if ($payload['has_ingredient_mapping'] && empty($compositions)) {
            abort(422, 'Ingredient mapping cannot be empty.');
        }
        $this->validateCompositionRules($ingredient, $compositions);

        $oldImage = $ingredient->image_url;
        $newImage = $images->storeBase64($payload['image_base64'] ?? null, 'ingredients');
        $payload['image_url'] = $newImage ?? (array_key_exists('image_url', $payload) ? $payload['image_url'] : $oldImage);
        unset($payload['image_base64'], $payload['image_name']);

        try {
            DB::transaction(function () use ($ingredient, $payload, $compositions): void {
                $ingredient->update($payload);
                $this->syncCompositions($ingredient, $compositions);
            });
        } catch (Throwable $exception) {
            if ($newImage !== null) {
                $images->delete($newImage, 'ingredients');
            }
            throw $exception;
        }

        if ($oldImage !== $payload['image_url']) {
            $images->delete($oldImage, 'ingredients');
        }

        $ingredient->fresh()->load(['category', 'purchaseUnit', 'consumptionUnit', 'compositions.child']);
        $this->appendCurrentStockData([$ingredient]);

        return response()->json($ingredient);
    }

    public function destroy(Ingredient $ingredient, ApiImageStorage $images): JsonResponse
    {
        if ($ingredient->foodMenuMappings()->exists()) {
            return response()->json([
                'message' => 'This ingredient is mapped to a food menu and cannot be deleted.',
            ], Response::HTTP_CONFLICT);
        }

        if (CompositeIngredient::query()->where('child_ingredient_id', $ingredient->id)->exists()) {
            return response()->json([
                'message' => 'This ingredient is used by a composite ingredient and cannot be deleted.',
            ], Response::HTTP_CONFLICT);
        }

        $image = $ingredient->image_url;
        DB::transaction(function () use ($ingredient): void {
            $ingredient->compositions()->delete();
            $ingredient->delete();
        });
        $images->delete($image, 'ingredients');

        return response()->json(['message' => 'Ingredient deleted.']);
    }

    private function syncCompositions(Ingredient $ingredient, array $compositions): void
    {
        $ingredient->compositions()->delete();
        if (empty($compositions)) {
            return;
        }
        foreach ($compositions as $comp) {
            $ingredient->compositions()->create([
                'child_ingredient_id' => $comp['child_ingredient_id'],
                'quantity' => $comp['quantity'],
                'unit_type' => $comp['unit_type'] ?? 'consumption',
            ]);
        }
    }

    private function validateCompositionRules(?Ingredient $ingredient, array $compositions): void
    {
        if (empty($compositions)) {
            return;
        }

        $seenChildIds = [];

        foreach ($compositions as $comp) {
            $childIngredientId = (int) ($comp['child_ingredient_id'] ?? 0);

            if (in_array($childIngredientId, $seenChildIds, true)) {
                abort(422, 'Duplicate input ingredient rows are not allowed.');
            }
            $seenChildIds[] = $childIngredientId;

            if ($ingredient !== null && $childIngredientId === (int) $ingredient->id) {
                abort(422, 'Output ingredient cannot be the same as input ingredient.');
            }

            if ($ingredient !== null && $this->ingredientDependsOn($childIngredientId, (int) $ingredient->id)) {
                abort(422, 'Circular mapping is not allowed.');
            }
        }
    }

    private function mergeOutletStockSettings(Ingredient $ingredient, array $stockSettings): array
    {
        $existingStock = is_array($ingredient->initial_stock_data) ? $ingredient->initial_stock_data : [];
        $updatedStockMap = [];

        foreach ($existingStock as $item) {
            if (is_array($item) && isset($item['location_id'])) {
                $updatedStockMap[(int) $item['location_id']] = $item;
            }
        }

        foreach ($stockSettings as $newItem) {
            if (! is_array($newItem) || ! isset($newItem['location_id'])) {
                continue;
            }

            $locId = (int) $newItem['location_id'];
            if ($locId <= 0) {
                continue;
            }

            $alertQty = (float) ($newItem['alert_quantity'] ?? $newItem['alert_qty'] ?? $newItem['min_stock'] ?? ($updatedStockMap[$locId]['alert_quantity'] ?? 0));

            if (isset($updatedStockMap[$locId])) {
                $updatedStockMap[$locId]['alert_quantity'] = $alertQty;
                continue;
            }

            $locationName = $newItem['location_name']
                ?? Location::query()->whereKey($locId)->whereNull('deleted_at')->value('name')
                ?? 'Warehouse '.$locId;

            $updatedStockMap[$locId] = [
                'location_id' => $locId,
                'location_name' => $locationName,
                'quantity' => 0.0,
                'cost' => (float) ($newItem['cost'] ?? $newItem['unit_cost'] ?? $newItem['purchase_price'] ?? 0),
                'alert_quantity' => $alertQty,
                'unit_type' => $newItem['unit_type'] ?? 'consumption',
            ];
        }

        return array_values($updatedStockMap);
    }

    private function ingredientDependsOn(int $ingredientId, int $targetIngredientId, array $visited = []): bool
    {
        if (in_array($ingredientId, $visited, true)) {
            return false;
        }

        $visited[] = $ingredientId;
        $childIds = CompositeIngredient::query()
            ->where('ingredient_id', $ingredientId)
            ->pluck('child_ingredient_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        foreach ($childIds as $childId) {
            if ($childId === $targetIngredientId) {
                return true;
            }

            if ($this->ingredientDependsOn($childId, $targetIngredientId, $visited)) {
                return true;
            }
        }

        return false;
    }

    private function maxEncodedImageLength(): int
    {
        return (int) ceil(max(1, (int) config('pos.max_image_bytes', 5 * 1024 * 1024)) * 4 / 3) + 128;
    }

    private function appendCurrentStockData(array $ingredients): void
    {
        if (empty($ingredients)) {
            return;
        }

        $ingredientIds = collect($ingredients)->pluck('id')->all();

        $movements = DB::table('ingredient_stock_movements')
            ->whereIn('ingredient_id', $ingredientIds)
            ->groupBy('ingredient_id', 'location_id')
            ->selectRaw("
                ingredient_id,
                location_id,
                COALESCE(SUM(CASE WHEN LOWER(direction) = 'in' THEN quantity_consumption ELSE -quantity_consumption END), 0) AS net
            ")
            ->get()
            ->groupBy('ingredient_id');

        foreach ($ingredients as $ingredient) {
            $ingMovements = $movements->get($ingredient->id, collect())->pluck('net', 'location_id')->all();
            $initialData = is_array($ingredient->initial_stock_data) ? $ingredient->initial_stock_data : [];

            $currentStockData = [];
            $processedLocationIds = [];

            foreach ($initialData as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $locId = (int) ($entry['location_id'] ?? 0);
                if ($locId <= 0) {
                    continue;
                }
                $net = (float) ($ingMovements[$locId] ?? 0);
                $initialQty = (float) ($entry['quantity'] ?? 0);

                $currentStockData[] = array_merge($entry, [
                    'quantity' => round($initialQty + $net, 4),
                ]);
                $processedLocationIds[] = $locId;
            }

            foreach ($ingMovements as $locId => $net) {
                if (in_array($locId, $processedLocationIds, true)) {
                    continue;
                }

                $locationName = DB::table('locations')->where('id', $locId)->whereNull('deleted_at')->value('name') ?? 'Unknown';

                $currentStockData[] = [
                    'location_id' => $locId,
                    'location_name' => $locationName,
                    'quantity' => round((float) $net, 4),
                    'cost' => 0.0,
                    'alert_quantity' => 0.0,
                    'unit_type' => 'consumption',
                ];
            }

            $ingredient->setAttribute('current_stock_data', $currentStockData);
        }
    }

    public function downloadImportTemplate()
    {
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="single_ingredients_import_template.csv"',
        ];

        $columns = [
            'name',
            'category_name',
            'outlet_name',
            'purchase_unit_name',
            'consumption_unit_name',
            'conversion_rate',
            'purchase_price',
            'sku_code',
            'barcode',
            'description',
            'alert_quantity',
            'opening_stock'
        ];
        
        $output = "\xEF\xBB\xBF" . implode(',', $columns) . "\n";
        $output .= "Chicken Breast,Meat & Poultry,Main Warehouse,Kg,Gram,1000,8500,ING-001,8850001,Fresh boneless chicken breast,50,100\n";
        $output .= "Cooking Oil,Oil & Sauce,Downtown Outlet,Liter,Milliliter,1000,12000,ING-002,8850002,Palm cooking oil grade A,20,50\n";
        $output .= "White Sugar,Dry Goods,Main Warehouse,Kg,Gram,1000,3500,ING-003,8850003,Refined white sugar,10,25\n";
        $output .= "Whole Milk,Dairy,Downtown Outlet,Liter,Milliliter,1000,4500,ING-004,8850004,Pasteurized fresh milk,15,30\n";

        return response($output, 200, $headers);
    }

    public function exportExcel(Request $request)
    {
        $query = Ingredient::query()->with(['category', 'purchaseUnit', 'consumptionUnit']);
        if ($request->string('search')->isNotEmpty()) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku_code', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            });
        }
        $ingredients = $query->orderByDesc('id')->get();
        $selectedLocationId = $request->filled('location_id') ? (int) $request->input('location_id') : null;
        $locations = Location::query()->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']);
        $this->appendCurrentStockData($ingredients->all());

        $escape = static fn ($value): string => '"'.str_replace('"', '""', (string) ($value ?? '')).'"';
        $output = "\xEF\xBB\xBF".implode(',', [
            'ID', 'Warehouse', 'Name', 'Type', 'SKU Code', 'Barcode', 'Category',
            'Purchase Unit', 'Consumption Unit', 'Conversion Rate', 
            'Purchase Price ($)', 'Consumption Cost ($)', 'Qty', 'Alert Qty', 'Status'
        ])."\n";

        $totalStockSum = 0;
        $totalPriceSum = 0;

        foreach ($ingredients as $ingredient) {
            $cUnits = $ingredient->consumptionUnit?->name ?? 'Unit';
            $pUnits = $ingredient->purchaseUnit?->name ?? 'Unit';
            
            $totalPriceSum += (float) $ingredient->purchase_price;

            foreach ($this->stockRowsForExport($ingredient, $selectedLocationId, $locations) as $stockRow) {
                $stockQty = (float) $stockRow['quantity'];
                $totalStockSum += $stockQty;
                $output .= implode(',', [
                    $ingredient->id,
                    $escape($stockRow['location_name']),
                    $escape($ingredient->name),
                    $escape($ingredient->type === 'composite' ? 'Mapping' : 'Single'),
                    $escape($ingredient->sku_code),
                    $escape($ingredient->barcode),
                    $escape($ingredient->category?->name ?? 'Uncategorized'),
                    $escape($pUnits),
                    $escape($cUnits),
                    $ingredient->conversion_rate,
                    number_format((float) $ingredient->purchase_price, 2, '.', ''),
                    number_format((float) $ingredient->cost_per_consumption_unit, 4, '.', ''),
                    number_format($stockQty, 2, '.', ''),
                    number_format((float) $ingredient->alert_quantity, 2, '.', ''),
                    $ingredient->is_active ? 'Active' : 'Inactive',
                ])."\n";
            }
        }

        $count = count($ingredients);
        $avgPrice = $count > 0 ? number_format($totalPriceSum / $count, 2, '.', '') : '0.00';
        $output .= "\n" . implode(',', [
            'SUMMARY',
            $escape("Total Ingredients: {$count}"),
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            $escape("Avg Price: \${$avgPrice}"),
            '',
            $escape("Total Qty: " . number_format($totalStockSum, 2, '.', '')),
            '',
            ''
        ]) . "\n";

        return response($output, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="ingredients_export_'.date('Y-m-d').'.csv"',
        ]);
    }

    public function exportPdf(Request $request)
    {
        $query = Ingredient::query()->with(['category', 'purchaseUnit', 'consumptionUnit']);
        if ($request->string('search')->isNotEmpty()) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku_code', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            });
        }
        $ingredients = $query->orderByDesc('id')->get();
        $this->appendCurrentStockData($ingredients->all());

        $totalCount = count($ingredients);
        $activeCount = $ingredients->where('is_active', true)->count();
        $inactiveCount = $totalCount - $activeCount;
        $mappingCount = $ingredients->where('type', 'composite')->count();
        $singleCount = $totalCount - $mappingCount;

        $totalValuation = 0;
        $totalStockQty = 0;
        $lowStockCount = 0;

        foreach ($ingredients as $ing) {
            $stockData = is_array($ing->current_stock_data) ? $ing->current_stock_data : [];
            $stockQty = array_reduce($stockData, fn($sum, $item) => $sum + (float)($item['quantity'] ?? 0), 0);
            $totalStockQty += $stockQty;
            $totalValuation += ($stockQty * (float)$ing->cost_per_consumption_unit);
            
            if ($stockQty <= (float)$ing->alert_quantity && (float)$ing->alert_quantity > 0) {
                $lowStockCount++;
            }
        }

        $searchQuery = $request->string('search')->toString();

        $html = '<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Ingredients Master Report</title>
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
        .badge-single { background: #dbeafe; color: #1d4ed8; border: 1px solid #93c5fd; }
        .badge-mapping { background: #fef3c7; color: #b45309; border: 1px solid #fde047; }
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
            <h1>Ingredients Inventory Report</h1>
            <p>Master raw material list, recipes, costs and warehouse stock positions</p>
        </div>
        <div class="header-meta">
            <div>Generated: <strong>' . date('d M Y, h:i A') . '</strong></div>
            <div>' . ($searchQuery ? 'Filter: "' . e($searchQuery) . '"' : 'All Items') . '</div>
        </div>
    </div>

    <div class="metrics-grid">
        <div class="metric-box">
            <div class="metric-title">Total Ingredients</div>
            <div class="metric-val">' . number_format($totalCount) . '</div>
            <div class="metric-sub">' . $singleCount . ' Single · ' . $mappingCount . ' Composite</div>
        </div>
        <div class="metric-box">
            <div class="metric-title">Active Items</div>
            <div class="metric-val">' . number_format($activeCount) . '</div>
            <div class="metric-sub">' . $inactiveCount . ' Disabled Ingredients</div>
        </div>
        <div class="metric-box">
            <div class="metric-title">Stock Valuation</div>
            <div class="metric-val">$' . number_format($totalValuation, 2) . '</div>
            <div class="metric-sub">Est. Consumption Cost</div>
        </div>
        <div class="metric-box">
            <div class="metric-title">Low Stock Alerts</div>
            <div class="metric-val" style="color: ' . ($lowStockCount > 0 ? '#d97706' : '#10b981') . '">' . number_format($lowStockCount) . '</div>
            <div class="metric-sub">Below Threshold</div>
        </div>
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th style="width: 35px;">#</th>
                    <th>Ingredient Name</th>
                    <th>Type</th>
                    <th>SKU Code</th>
                    <th>Category</th>
                    <th class="text-right">Purchase Price</th>
                    <th class="text-right">Consumption Cost</th>
                    <th>Conversion</th>
                    <th class="text-right">Total Stock</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>';

        foreach ($ingredients as $index => $ing) {
            $cUnits = e($ing->consumptionUnit?->name ?? 'Unit');
            $pUnits = e($ing->purchaseUnit?->name ?? 'Unit');
            $catName = e($ing->category?->name ?? 'General');

            $stockData = is_array($ing->current_stock_data) ? $ing->current_stock_data : [];
            $stockQty = array_reduce($stockData, fn($sum, $item) => $sum + (float)($item['quantity'] ?? 0), 0);
            $alertQty = (float)$ing->alert_quantity;

            $isMapping = $ing->type === 'composite' || $ing->has_ingredient_mapping;
            $typeBadge = $isMapping ? '<span class="badge badge-mapping">Mapping</span>' : '<span class="badge badge-single">Single</span>';
            $activeBadge = $ing->is_active ? '<span class="badge badge-active">Active</span>' : '<span class="badge badge-disabled">Disabled</span>';

            $stockBadge = '';
            if ($stockQty <= 0) {
                $stockBadge = '<span class="badge badge-out">Out of Stock</span>';
            } elseif ($alertQty > 0 && $stockQty <= $alertQty) {
                $stockBadge = '<span class="badge badge-low">Low Stock</span>';
            }

            $html .= '<tr>
                <td class="text-center font-mono" style="color: #64748b;">' . ($index + 1) . '</td>
                <td>
                    <strong style="color: #0f172a;">' . e($ing->name) . '</strong>
                    ' . ($ing->barcode ? '<br><span style="font-size: 9.5px; color: #94a3b8;">Barcode: ' . e($ing->barcode) . '</span>' : '') . '
                </td>
                <td>' . $typeBadge . '</td>
                <td class="font-mono" style="color: #475569;">' . e($ing->sku_code ?? '-') . '</td>
                <td>' . $catName . '</td>
                <td class="text-right font-mono">$' . number_format((float)$ing->purchase_price, 2) . ' / ' . $pUnits . '</td>
                <td class="text-right font-mono" style="color: #d97706; font-weight: 700;">$' . number_format((float)$ing->cost_per_consumption_unit, 4) . ' / ' . $cUnits . '</td>
                <td style="font-size: 10.5px; color: #475569;">1 ' . $pUnits . ' = ' . number_format((float)$ing->conversion_rate, 1) . ' ' . $cUnits . '</td>
                <td class="text-right font-mono">
                    <strong>' . number_format($stockQty, 2) . ' ' . $cUnits . '</strong>
                    ' . ($stockBadge ? '<br>' . $stockBadge : '') . '
                </td>
                <td>' . $activeBadge . '</td>
            </tr>';
        }

        $html .= '</tbody>
        </table>
    </div>

    <div class="footer">
        <div>POS System · Ingredients Inventory Report</div>
        <div>Total Records: ' . $totalCount . '</div>
    </div>

</body>
</html>';

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="ingredients_report_'.date('Y-m-d').'.html"',
        ]);
    }

    public function importIngredients(Request $request): JsonResponse
    {
        $rows = $request->input('rows');
        if (empty($rows) && $request->hasFile('file')) {
            $rows = $this->parseCsvFile($request->file('file'));
        }

        if (empty($rows) || !is_array($rows)) {
            return response()->json(['message' => 'No valid ingredient rows or CSV file provided.'], 422);
        }

        $selectedLocationId = $request->filled('location_id') ? (int) $request->input('location_id') : null;
        $targetLocations = $selectedLocationId !== null
            ? Location::query()->whereKey($selectedLocationId)->whereNull('deleted_at')->get(['id', 'name'])
            : Location::query()->whereNull('deleted_at')->where('is_active', true)->orderBy('id')->get(['id', 'name']);
        if ($targetLocations->isEmpty()) {
            return response()->json(['message' => 'No active warehouse/outlet is available for this import.'], 422);
        }

        $validationErrors = [];
        $seenNames = [];
        $seenSkus = [];
        $seenBarcodes = [];

        foreach ($rows as $index => $row) {
            $rowNum = $index + 1;
            $name = trim($row['name'] ?? '');

            if ($name === '') {
                $validationErrors[] = "Row #{$rowNum}: Ingredient name is required.";
                continue;
            }

            $catName = trim($row['category_name'] ?? $row['category'] ?? '');
            $puName = trim($row['purchase_unit_name'] ?? $row['purchase_unit'] ?? $row['pur_unit'] ?? '');
            $cuName = trim($row['consumption_unit_name'] ?? $row['consumption_unit'] ?? $row['con_unit'] ?? '');

            $missingFields = [];
            if ($catName === '') {
                $missingFields[] = 'Category';
            }
            if ($puName === '') {
                $missingFields[] = 'Purchase Unit';
            }
            if ($cuName === '') {
                $missingFields[] = 'Consumption Unit';
            }

            if (!empty($missingFields)) {
                $fieldStr = implode(', ', $missingFields);
                $validationErrors[] = "Row #{$rowNum} ('{$name}'): Missing required field(s): {$fieldStr}.";
                continue;
            }

            $sku = trim($row['sku_code'] ?? $row['sku'] ?? $row['code'] ?? '') ?: null;
            $barcode = trim($row['barcode'] ?? '') ?: null;

            // 1. Check duplicate within the imported file itself
            $lowerName = mb_strtolower($name);
            if (isset($seenNames[$lowerName])) {
                $validationErrors[] = "Row #{$rowNum} ('{$name}'): Ingredient name '{$name}' is duplicated in the import file (already seen in Row #{$seenNames[$lowerName]}).";
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
                    $validationErrors[] = "Row #{$rowNum} ('{$name}'): Product code/Barcode '{$barcode}' is duplicated in the import file (already seen in Row #{$seenBarcodes[$lowerBarcode]}).";
                } else {
                    $seenBarcodes[$lowerBarcode] = $rowNum;
                }
            }

            // 2. Check duplicate against existing ingredients in database
            // (a) Duplicate Name
            $dbNameExists = Ingredient::whereRaw('LOWER(name) = ?', [$lowerName])->exists();
            if ($dbNameExists) {
                $validationErrors[] = "Row #{$rowNum} ('{$name}'): An ingredient with the name '{$name}' already exists in the system.";
            }

            // (b) Duplicate SKU
            if ($sku !== null) {
                $dbSkuExists = Ingredient::whereRaw('LOWER(sku_code) = ?', [$lowerSku])->exists();
                if ($dbSkuExists) {
                    $validationErrors[] = "Row #{$rowNum} ('{$name}'): SKU code '{$sku}' already exists in the system.";
                }
            }

            // (c) Duplicate Barcode
            if ($barcode !== null) {
                $dbBarcodeExists = Ingredient::whereRaw('LOWER(barcode) = ?', [$lowerBarcode])->exists();
                if ($dbBarcodeExists) {
                    $validationErrors[] = "Row #{$rowNum} ('{$name}'): Product code/Barcode '{$barcode}' already exists in the system.";
                }
            }
        }

        if (!empty($validationErrors)) {
            $mapErrors = [];
            foreach ($validationErrors as $i => $err) {
                $mapErrors["error_{$i}"] = [$err];
            }
            return response()->json([
                'message' => 'Import validation failed. No data was imported.',
                'imported_count' => 0,
                'updated_count' => 0,
                'errors' => $mapErrors,
            ], 422);
        }

        $importedCount = 0;

        DB::transaction(function () use ($rows, $targetLocations, &$importedCount) {
            foreach ($rows as $row) {
                $name = trim($row['name'] ?? '');
                $catName = trim($row['category_name'] ?? $row['category'] ?? '');
                $puName = trim($row['purchase_unit_name'] ?? $row['purchase_unit'] ?? $row['pur_unit'] ?? '');
                $cuName = trim($row['consumption_unit_name'] ?? $row['consumption_unit'] ?? $row['con_unit'] ?? '');

                // Match or auto-create category
                $cat = \App\Models\IngredientCategory::whereRaw('LOWER(name) = ?', [mb_strtolower($catName)])->first();
                if (!$cat) {
                    $cat = \App\Models\IngredientCategory::create([
                        'name' => $catName,
                        'is_active' => true,
                    ]);
                }
                $categoryId = $cat->id;

                // Match or auto-create purchase unit
                $pu = \App\Models\PurchaseUnit::whereRaw('LOWER(name) = ?', [mb_strtolower($puName)])->first();
                if (!$pu) {
                    $pu = \App\Models\PurchaseUnit::create([
                        'name' => $puName,
                        'is_active' => true,
                    ]);
                }
                $purchaseUnitId = $pu->id;

                // Match or auto-create consumption unit
                $cu = \App\Models\ConsumptionUnit::whereRaw('LOWER(name) = ?', [mb_strtolower($cuName)])->first();
                if (!$cu) {
                    $cu = \App\Models\ConsumptionUnit::create([
                        'name' => $cuName,
                        'is_active' => true,
                    ]);
                }
                $consumptionUnitId = $cu->id;

                $openingStock = (float)($row['opening_stock'] ?? $row['quantity'] ?? $row['initial_stock'] ?? 0);
                $alertQty = (float)($row['alert_quantity'] ?? $row['alert_qty'] ?? 0);
                $purchasePrice = (float)($row['purchase_price'] ?? 0);
                $sku = trim($row['sku_code'] ?? $row['sku'] ?? $row['code'] ?? '') ?: null;
                $barcode = trim($row['barcode'] ?? '') ?: null;

                $initialStockData = [];
                foreach ($targetLocations as $targetLocation) {
                    $initialStockData[] = [
                        'location_id' => $targetLocation->id,
                        'location_name' => $targetLocation->name,
                        'quantity' => $openingStock,
                        'alert_quantity' => $alertQty,
                        'cost' => $purchasePrice,
                        'unit_type' => 'consumption',
                    ];
                }

                Ingredient::create([
                    'name' => $name,
                    'type' => 'single',
                    'has_ingredient_mapping' => false,
                    'ingredient_category_id' => $categoryId,
                    'purchase_unit_id' => $purchaseUnitId,
                    'consumption_unit_id' => $consumptionUnitId,
                    'conversion_rate' => (float)($row['conversion_rate'] ?? $row['rate'] ?? 1),
                    'purchase_price' => $purchasePrice,
                    'sku_code' => $sku,
                    'barcode' => $barcode,
                    'description' => $row['description'] ?? null,
                    'alert_quantity' => $alertQty,
                    'initial_stock_data' => $initialStockData,
                    'is_active' => true,
                ]);

                $importedCount++;
            }
        });

        return response()->json([
            'message' => "Import completed: {$importedCount} ingredients imported.",
            'imported_count' => $importedCount,
            'updated_count' => 0,
            'errors' => [],
        ]);
    }

    private function stockRowsForExport(Ingredient $ingredient, ?int $selectedLocationId, $locations): array
    {
        $stockByLocation = collect(is_array($ingredient->current_stock_data) ? $ingredient->current_stock_data : [])
            ->mapWithKeys(fn ($entry) => [(int) ($entry['location_id'] ?? 0) => (float) ($entry['quantity'] ?? 0)]);

        $targets = $selectedLocationId !== null
            ? $locations->where('id', $selectedLocationId)
            : $locations;
        if ($targets->isEmpty()) {
            $targets = collect([ (object) ['id' => $selectedLocationId ?? 0, 'name' => 'All Warehouses'] ]);
        }

        return $targets->map(fn ($location): array => [
            'location_id' => (int) $location->id,
            'location_name' => $location->name ?: 'Warehouse '.$location->id,
            'quantity' => (float) ($stockByLocation->get((int) $location->id, 0)),
        ])->all();
    }

    private function parseCsvFile($file): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        if (!$handle) return [];

        $headers = [];
        $rows = [];
        $rowNum = 0;

        while (($data = fgetcsv($handle, 1000, ',')) !== false) {
            $rowNum++;
            if ($rowNum === 1) {
                $headers = array_map(
                    fn($h) => strtolower(trim($h, "\xEF\xBB\xBF \t\n\r\0\x0B")),
                    $data
                );
                continue;
            }

            $row = [];
            foreach ($headers as $i => $header) {
                if (isset($data[$i])) {
                    $row[$header] = trim($data[$i]);
                }
            }
            if (!empty($row['name'])) {
                $rows[] = $row;
            }
        }

        fclose($handle);
        return $rows;
    }
}
