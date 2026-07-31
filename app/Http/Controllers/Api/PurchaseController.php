<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\IngredientBatch;
use App\Models\IngredientStockMovement;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PurchaseController extends Controller
{
    public function index(Request $request)
    {
        $payload = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'search' => ['nullable', 'string', 'max:120'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'status' => ['nullable', 'string', Rule::in(['pending', 'received', 'canceled', 'cancelled', 'ordered'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'sort_col' => ['nullable', 'string', Rule::in(['id', 'ref_no', 'purchase_date', 'status', 'grand_total'])],
            'sort_dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ]);

        $query = Purchase::query()->with(['supplier', 'location', 'createdBy'])->withCount('items');

        $search = trim((string) ($payload['search'] ?? ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('ref_no', 'like', "%{$search}%")
                    ->orWhere('status', 'like', "%{$search}%")
                    ->orWhereHas('supplier', fn ($sq) => $sq->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('location', fn ($lq) => $lq->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('items.ingredient', function ($iq) use ($search): void {
                        $iq->where('name', 'like', "%{$search}%")
                            ->orWhere('sku_code', 'like', "%{$search}%")
                            ->orWhere('barcode', 'like', "%{$search}%");
                    })
                    ->orWhereHas('items.product', function ($pq) use ($search): void {
                        $pq->where('name', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%")
                            ->orWhere('barcode', 'like', "%{$search}%");
                    });
            });
        }

        if (! empty($payload['supplier_id'])) {
            $query->where('supplier_id', (int) $payload['supplier_id']);
        }
        if (! empty($payload['location_id'])) {
            $query->where('location_id', (int) $payload['location_id']);
        }
        if (! empty($payload['status'])) {
            $status = (string) $payload['status'];
            $status === 'cancelled'
                ? $query->whereIn('status', ['cancelled', 'canceled'])
                : $query->where('status', $status);
        }
        if (! empty($payload['date_from'])) {
            $query->whereDate('purchase_date', '>=', $payload['date_from']);
        }
        if (! empty($payload['date_to'])) {
            $query->whereDate('purchase_date', '<=', $payload['date_to']);
        }

        $sortCol = $payload['sort_col'] ?? 'id';
        $sortDir = $payload['sort_dir'] ?? 'desc';
        $perPage = (int) ($payload['perPage'] ?? $payload['per_page'] ?? 15);
        $perPage = ($perPage > 0 && $perPage <= 1000) ? $perPage : 15;

        return $query->orderBy($sortCol, $sortDir)->paginate($perPage);
    }

    public function store(Request $request)
    {
        $validated = $this->validatedPayload($request);
        $validated['ref_no'] = $this->resolveRefNo($validated['ref_no'] ?? null);
        $this->validatePurchasableItems($validated['items']);

        return DB::transaction(function () use ($validated, $request) {
            [$subtotal, $discount, $shipping, $grandTotal] = $this->totals($validated);

            $purchase = Purchase::query()->create([
                'ref_no' => $validated['ref_no'],
                'supplier_id' => (int) $validated['supplier_id'],
                'location_id' => (int) $validated['location_id'],
                'purchase_date' => $validated['purchase_date'],
                'status' => $validated['status'],
                'subtotal' => $subtotal,
                'discount' => $discount,
                'shipping_charge' => $shipping,
                'grand_total' => $grandTotal,
                'note' => $validated['note'] ?? null,
                'created_by' => $request->user()->id ?? null,
            ]);

            $this->createItems($purchase, $validated['items']);
            $purchase->load('items.ingredient', 'items.product');
            $this->updateStockIfReceived($purchase);

            return response()->json($purchase->load(
                'items.ingredient',
                'items.product',
                'items.foodMenu',
                'items.purchaseUnit',
                'supplier',
                'location'
            ));
        });
    }

    public function show($id)
    {
        return Purchase::query()
            ->with(['items.ingredient', 'items.product', 'items.foodMenu', 'items.purchaseUnit', 'supplier', 'location', 'createdBy'])
            ->findOrFail($id);
    }

    public function update(Request $request, $id)
    {
        $purchase = Purchase::query()->findOrFail($id);

        $validated = $this->validatedPayload($request, $purchase);
        $validated['ref_no'] = $this->resolveRefNo($validated['ref_no'] ?? null, $purchase);
        $this->validatePurchasableItems($validated['items']);

        return DB::transaction(function () use ($validated, $purchase) {
            $this->revertStockIfReceived($purchase);
            [$subtotal, $discount, $shipping, $grandTotal] = $this->totals($validated);

            $purchase->update([
                'ref_no' => $validated['ref_no'],
                'supplier_id' => (int) $validated['supplier_id'],
                'location_id' => (int) $validated['location_id'],
                'purchase_date' => $validated['purchase_date'],
                'status' => $validated['status'],
                'subtotal' => $subtotal,
                'discount' => $discount,
                'shipping_charge' => $shipping,
                'grand_total' => $grandTotal,
                'note' => $validated['note'] ?? null,
            ]);

            $purchase->items()->delete();
            $purchase->unsetRelation('items');
            $this->createItems($purchase, $validated['items']);
            $purchase->load('items.ingredient', 'items.product');
            $this->updateStockIfReceived($purchase);

            return response()->json($purchase->load(
                'items.ingredient',
                'items.product',
                'items.foodMenu',
                'items.purchaseUnit',
                'supplier',
                'location'
            ));
        });
    }

    public function destroy($id)
    {
        $purchase = Purchase::query()->findOrFail($id);

        return DB::transaction(function () use ($purchase) {
            $this->revertStockIfReceived($purchase);
            $purchase->items()->delete();
            $purchase->delete();

            return response()->json(['message' => 'Purchase deleted']);
        });
    }

    private function validatedPayload(Request $request, ?Purchase $purchase = null): array
    {
        return $request->validate([
            'ref_no' => ['nullable', 'string', 'max:80', Rule::unique('purchases', 'ref_no')->ignore($purchase?->id)],
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'purchase_date' => ['required', 'date'],
            'status' => ['required', 'string', Rule::in(['pending', 'received', 'canceled', 'ordered'])],
            'items' => ['required', 'array', 'min:1'],
            'items.*.ingredient_id' => ['nullable', 'integer', 'exists:ingredients,id'],
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.food_menu_id' => ['nullable', 'integer', 'exists:food_menus,id'],
            'items.*.purchase_unit_id' => ['nullable', 'integer', 'exists:purchase_units,id'],
            'items.*.unit_type' => ['nullable', 'string', Rule::in(['purchase', 'consumption'])],
            'items.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'shipping_charge' => ['nullable', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    private function totals(array $payload): array
    {
        $subtotal = collect($payload['items'])
            ->sum(fn (array $item): float => round((float) $item['quantity'] * (float) $item['unit_price'], 4));
        $discount = (float) ($payload['discount'] ?? 0);
        $shipping = (float) ($payload['shipping_charge'] ?? 0);
        $grandTotal = max(0, $subtotal - $discount + $shipping);

        return [
            round($subtotal, 2),
            round($discount, 2),
            round($shipping, 2),
            round($grandTotal, 2),
        ];
    }

    private function createItems(Purchase $purchase, array $items): void
    {
        foreach ($items as $item) {
            $isProduct = ! empty($item['product_id']);
            $unitType = $isProduct ? 'consumption' : ($item['unit_type'] ?? 'purchase');
            $qty = (float) $item['quantity'];
            $unitPrice = (float) $item['unit_price'];

            $purchase->items()->create([
                'ingredient_id' => $isProduct ? null : ($item['ingredient_id'] ?? null),
                'product_id' => $isProduct ? (int) $item['product_id'] : null,
                'food_menu_id' => null,
                'purchase_unit_id' => $isProduct || $unitType !== 'purchase' ? null : ($item['purchase_unit_id'] ?? null),
                'unit_type' => $unitType,
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'subtotal' => round($qty * $unitPrice, 2),
            ]);
        }
    }

    private function updateStockIfReceived(Purchase $purchase): void
    {
        if ($purchase->status !== 'received') {
            return;
        }

        $purchase->loadMissing('items.ingredient', 'items.product');
        $reference = 'Purchase: ' . $purchase->ref_no;
        $occurredAt = Carbon::parse($purchase->purchase_date)->toDateTimeString();

        foreach ($purchase->items as $item) {
            if ($item->product_id) {
                $product = $item->product ?: Product::query()->find($item->product_id);
                if ($product === null) {
                    continue;
                }

                $stockQty = round((float) $item->quantity, 4);
                $unitCost = $stockQty > 0 ? round((float) $item->subtotal / $stockQty, 4) : 0.0;

                $batch = IngredientBatch::query()->create([
                    'product_id' => $product->id,
                    'location_id' => $purchase->location_id,
                    'purchase_item_id' => $item->id,
                    'original_qty' => $stockQty,
                    'usable_qty' => $stockQty,
                    'unit_cost' => $unitCost,
                    'received_at' => $occurredAt,
                    'expiry_date' => $item->expiry_date ?? null,
                ]);

                IngredientStockMovement::query()->create([
                    'product_id' => $product->id,
                    'location_id' => $purchase->location_id,
                    'ingredient_batch_id' => $batch->id,
                    'direction' => 'IN',
                    'reason_code' => 'purchase',
                    'unit_type' => 'consumption',
                    'quantity_input' => $stockQty,
                    'quantity_consumption' => $stockQty,
                    'batch_unit_cost' => $unitCost,
                    'reference' => $reference,
                    'occurred_at' => $occurredAt,
                ]);

                ProductStockMovement::query()->create([
                    'product_id' => $product->id,
                    'location_id' => $purchase->location_id,
                    'direction' => 'in',
                    'reason_code' => 'purchase_received',
                    'quantity' => $stockQty,
                    'unit_cost' => $unitCost,
                    'amount' => round((float) $item->subtotal, 2),
                    'reference' => $reference,
                    'occurred_at' => $occurredAt,
                ]);

                continue;
            }

            $ingredient = $item->ingredient ?: Ingredient::query()->find($item->ingredient_id);
            if ($ingredient === null) {
                continue;
            }

            $stockQty = $this->stockQuantityForIngredientItem($item, $ingredient);
            $unitCost = $stockQty > 0 ? round((float) $item->subtotal / $stockQty, 4) : 0.0;

            $batch = IngredientBatch::query()->create([
                'ingredient_id' => $ingredient->id,
                'location_id' => $purchase->location_id,
                'purchase_item_id' => $item->id,
                'original_qty' => $stockQty,
                'usable_qty' => $stockQty,
                'unit_cost' => $unitCost,
                'received_at' => $occurredAt,
                'expiry_date' => $item->expiry_date ?? null,
            ]);

            IngredientStockMovement::query()->create([
                'ingredient_id' => $ingredient->id,
                'location_id' => $purchase->location_id,
                'ingredient_batch_id' => $batch->id,
                'direction' => 'IN',
                'reason_code' => 'purchase',
                'unit_type' => $item->unit_type ?? 'purchase',
                'quantity_input' => (float) $item->quantity,
                'quantity_consumption' => $stockQty,
                'batch_unit_cost' => $unitCost,
                'reference' => $reference,
                'occurred_at' => $occurredAt,
            ]);
        }
    }

    private function validatePurchasableItems(array $items): void
    {
        foreach ($items as $index => $item) {
            $selected = collect(['ingredient_id', 'product_id', 'food_menu_id'])
                ->filter(fn (string $key): bool => ! empty($item[$key]))
                ->count();

            if ($selected !== 1 || ! empty($item['food_menu_id'])) {
                abort(422, sprintf('Purchase item %d must be exactly one single ingredient or product. Food menus cannot be purchased.', $index + 1));
            }

            if (! empty($item['ingredient_id'])) {
                $ingredient = Ingredient::query()->find((int) $item['ingredient_id']);
                if ($ingredient === null || $ingredient->type === 'composite' || $ingredient->has_ingredient_mapping) {
                    abort(422, sprintf('Purchase item %d must be a single ingredient. Mapped ingredients cannot be purchased.', $index + 1));
                }
            }
        }
    }

    private function revertStockIfReceived(Purchase $purchase): void
    {
        if ($purchase->status !== 'received') {
            return;
        }

        $reference = 'Purchase: ' . $purchase->ref_no;
        $items = $purchase->items()->get();
        $itemIds = $items->pluck('id')->filter()->values();

        $productMovements = ProductStockMovement::query()
            ->where('reference', $reference)
            ->lockForUpdate()
            ->get();
        $this->guardProductRollback($productMovements);

        $ingredientMovements = IngredientStockMovement::withoutGlobalScopes()
            ->where('reference', $reference)
            ->lockForUpdate()
            ->get();
        $this->guardIngredientRollback($ingredientMovements);

        $batches = IngredientBatch::withoutGlobalScopes()
            ->whereIn('purchase_item_id', $itemIds)
            ->lockForUpdate()
            ->get();

        foreach ($batches as $batch) {
            if ((float) $batch->usable_qty + 0.000001 < (float) $batch->original_qty) {
                abort(422, 'Cannot change this purchase because some received stock has already been used at this outlet.');
            }
        }

        IngredientStockMovement::withoutGlobalScopes()->where('reference', $reference)->delete();
        ProductStockMovement::query()->where('reference', $reference)->delete();

        if ($batches->isNotEmpty()) {
            IngredientBatch::withoutGlobalScopes()
                ->whereIn('id', $batches->pluck('id')->all())
                ->delete();
        }

        $purchase->unsetRelation('items');
    }

    private function guardProductRollback($movements): void
    {
        $inboundGroups = $movements
            ->filter(fn (ProductStockMovement $movement): bool => strtolower((string) $movement->direction) === 'in')
            ->groupBy(fn (ProductStockMovement $movement): string => $movement->product_id . ':' . $movement->location_id);

        foreach ($inboundGroups as $group) {
            $first = $group->first();
            if ($first === null) {
                continue;
            }

            $currentQty = (float) ProductStockMovement::query()
                ->where('product_id', $first->product_id)
                ->where('location_id', $first->location_id)
                ->selectRaw("COALESCE(SUM(CASE WHEN LOWER(direction) = 'in' THEN quantity ELSE -quantity END), 0) as qty")
                ->value('qty');
            $rollbackQty = (float) $group->sum(fn (ProductStockMovement $movement): float => (float) $movement->quantity);

            if ($currentQty + 0.000001 < $rollbackQty) {
                abort(422, 'Cannot change this purchase because some received product stock has already been used at this outlet.');
            }
        }
    }

    private function guardIngredientRollback($movements): void
    {
        $inboundGroups = $movements
            ->filter(fn (IngredientStockMovement $movement): bool => strtolower((string) $movement->direction) === 'in' && $movement->ingredient_id !== null)
            ->groupBy(fn (IngredientStockMovement $movement): string => $movement->ingredient_id . ':' . $movement->location_id);

        foreach ($inboundGroups as $group) {
            $first = $group->first();
            if ($first === null) {
                continue;
            }

            $currentQty = (float) IngredientStockMovement::withoutGlobalScopes()
                ->where('ingredient_id', $first->ingredient_id)
                ->where('location_id', $first->location_id)
                ->selectRaw("COALESCE(SUM(CASE WHEN LOWER(direction) = 'in' THEN quantity_consumption ELSE -quantity_consumption END), 0) as qty")
                ->value('qty');
            $rollbackQty = (float) $group->sum(fn (IngredientStockMovement $movement): float => (float) $movement->quantity_consumption);

            if ($currentQty + 0.000001 < $rollbackQty) {
                abort(422, 'Cannot change this purchase because some received ingredient stock has already been used at this outlet.');
            }
        }
    }

    private function stockQuantityForIngredientItem(PurchaseItem $item, Ingredient $ingredient): float
    {
        $qty = (float) $item->quantity;
        if (($item->unit_type ?? 'purchase') === 'consumption') {
            return round($qty, 4);
        }

        $conversionRate = (float) ($ingredient->conversion_rate ?: 1);
        return round($qty * ($conversionRate > 0 ? $conversionRate : 1), 4);
    }

    private function resolveRefNo(?string $refNo, ?Purchase $purchase = null): string
    {
        $refNo = trim((string) $refNo);
        if ($refNo !== '') {
            return $refNo;
        }

        if ($purchase !== null && $purchase->ref_no !== '') {
            return $purchase->ref_no;
        }

        do {
            $candidate = 'PUR-' . now()->format('YmdHis') . '-' . random_int(100, 999);
        } while (Purchase::withTrashed()->where('ref_no', $candidate)->exists());

        return $candidate;
    }
}
