<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Charge;
use App\Models\CashRegister;
use App\Models\ComboMenu;
use App\Models\Discount;
use App\Models\Floor;
use App\Models\FoodMenu;
use App\Models\FoodMenuIngredient;
use App\Models\Ingredient;
use App\Models\IngredientStockMovement;
use App\Models\Location;
use App\Models\Modifier;
use App\Models\Order;
use App\Models\OrderChangeHistory;
use App\Models\OrderComboComponent;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\OrderSplitHistory;
use App\Models\OrderSplitItem;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Printer;
use App\Models\PrintLog;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\ProductStockMovement;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleModifier;
use App\Models\SalePayment;
use App\Models\Scopes\OutletScope;
use App\Models\TableMergeGroup;
use App\Models\TableMergeMember;
use App\Models\TaxRate;
use App\Models\UserSession;
use App\Services\OrderStockService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WaiterPanelController extends Controller
{
    public function createData(Request $request): JsonResponse
    {
        $user = auth()->user();
        $outletId = $request->integer('location_id') ?: null;

        $outlets = Location::query()
            ->where('is_active', true)
            ->when(
                $user && ! $user->isSuperAdmin(),
                fn ($q) => $q->whereIn('id', $user->allowedOutletIds())
            )
            ->orderBy('name')
            ->get(['id', 'name']);
        $allowedOutletIds = $outlets->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if ($outletId !== null && ! in_array($outletId, $allowedOutletIds, true)) {
            $outletId = null;
        }

        $floors = Floor::withoutGlobalScope(OutletScope::class)
            ->where('is_active', true)
            ->whereIn('location_id', $allowedOutletIds)
            ->when($outletId, fn ($q) => $q->where('location_id', $outletId))
            ->with(['tables' => function ($q) use ($outletId, $allowedOutletIds) {
                $q->withoutGlobalScope(OutletScope::class)
                    ->where('is_active', true)
                    ->where(function ($q2) use ($outletId, $allowedOutletIds) {
                        if ($outletId) {
                            $q2->where('outlet_id', $outletId)->orWhereNull('outlet_id');
                        } else {
                            $q2->whereIn('outlet_id', $allowedOutletIds)->orWhereNull('outlet_id');
                        }
                    })
                    ->orderBy('sort_order')
                    ->orderBy('table_no');
            }])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
        $tableIds = $floors->pluck('tables')->flatten()->pluck('id');
        $activeOrders = Order::withoutGlobalScope(OutletScope::class)
            ->whereIn('table_id', $tableIds)
            ->whereIn('outlet_id', $allowedOutletIds)
            ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
            ->whereNotIn('order_status', ['completed', 'cancelled'])
            ->select('table_id', DB::raw('MIN(id) as order_id'))
            ->groupBy('table_id')
            ->pluck('order_id', 'table_id');
        $mergeGroups = TableMergeGroup::withoutGlobalScope(OutletScope::class)
            ->where('status', 'active')
            ->whereIn('outlet_id', $allowedOutletIds)
            ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
            ->with(['members' => function ($q) {
                $q->with(['table' => function ($q) {
                    $q->withoutGlobalScope(OutletScope::class)->select('id', 'table_no', 'code');
                }]);
            }])
            ->get()
            ->keyBy('id');

        foreach ($floors as $floor) {
            foreach ($floor->tables as $table) {
                $table->active_order_id = $activeOrders->get($table->id);
                $table->merge_group_id = null;
                $table->merge_members = [];

                if ($table->status === 'merged' && $table->merged_with_table_id) {
                    foreach ($mergeGroups as $group) {
                        $member = $group->members->firstWhere('table_id', $table->id);
                        if ($member) {
                            $table->merge_group_id = $group->id;
                            break;
                        }
                    }
                } else {
                    foreach ($mergeGroups as $group) {
                        if ($group->primary_table_id === $table->id) {
                            $table->merge_group_id = $group->id;
                            $table->merge_members = $group->members
                                ->where('member_type', 'secondary')
                                ->values()
                                ->map(fn ($m) => [
                                    'id' => $m->table_id,
                                    'name' => $m->table?->table_no ?? "T-{$m->table_id}",
                                    'table_no' => $m->table?->table_no ?? "T-{$m->table_id}",
                                    'code' => $m->table?->code,
                                ]);
                            break;
                        }
                    }
                }
            }
        }
        $menuResponse = $this->menuData();
        $menuData = $menuResponse->getData(true);

        return response()->json(array_merge([
            'outlets' => $outlets,
            'floors' => $floors,
            'delivery_partners' => config('services.delivery.partners', []),
            'user' => $user?->only(['id', 'name', 'email']),
        ], is_array($menuData) ? $menuData : []));
    }

    public function menuData(): JsonResponse
    {
        $outletId = request()->integer('location_id') ?: null;
        $userKey = request()->user()?->id ?? 'guest';
        $cacheKey = 'pos.menu-data.'.((string) $userKey).'.'.($outletId ?? 'all');

        // Menu/catalog data is database-driven. A short scoped cache prevents
        // every waiter screen refresh from reserializing the full catalog.
        $payload = Cache::remember(
            $cacheKey,
            now()->addSeconds((int) config('pos.catalog_cache_seconds', 10)),
            fn (): array => $this->buildMenuData($outletId),
        );

        return response()->json($payload);
    }

    private function buildMenuData(?int $outletId): array
    {
        $foodMenuCategories = Category::query()->where('is_active', true)->get(['id', 'name']);

        $foodMenus = FoodMenu::query()
            ->where('food_menus.is_active', true)
            ->whereNull('food_menus.deleted_at')
            ->with(['category:id,name', 'printer:id,name', 'unit:id,name', 'modifierGroups' => function ($q) {
                $q->withPivot(['is_required', 'min_selection', 'max_selection', 'sort_order']);
            }])
            ->when($outletId, fn ($q) => $q->with(['locations' => fn ($q) => $q->whereKey($outletId)]))
            ->get();

        $productionMenuIds = $foodMenus
            ->where('stock_deduction_method', 'production_stock')
            ->pluck('id');
        $productionStock = collect();
        if ($outletId && $productionMenuIds->isNotEmpty()) {
            $productionStock = IngredientStockMovement::query()
                ->whereIn('food_menu_id', $productionMenuIds)
                ->where('location_id', (int) $outletId)
                ->select('food_menu_id')
                ->selectRaw("COALESCE(SUM(CASE WHEN LOWER(direction) = 'in' THEN quantity_consumption ELSE -quantity_consumption END), 0) AS net")
                ->groupBy('food_menu_id')
                ->pluck('net', 'food_menu_id');
        }

        foreach ($foodMenus as $menu) {
            foreach ($menu->modifierGroups as $modifier) {
                $this->attachModifierOptionIds($modifier);
            }
            if ($outletId && $menu->locations && $menu->locations->isNotEmpty()) {
                $pivot = $menu->locations->first()->pivot;
                if ($pivot->is_active) {
                    $menu->dine_in_price = $pivot->dine_in_price ?? $menu->dine_in_price;
                    $menu->take_away_price = $pivot->take_away_price ?? $menu->take_away_price;
                    $menu->delivery_price = $pivot->delivery_price ?? $menu->delivery_price;
                }
            }
            if ($outletId && $menu->stock_deduction_method === 'production_stock') {
                $menu->current_stock_qty = (float) ($productionStock[$menu->id] ?? 0);
            }
            unset($menu->locations);
        }
        $this->attachMenuFallbackImages($foodMenus);

        $products = Product::query()
            ->where('products.is_active', true)
            ->whereNull('products.deleted_at')
            ->with(['productCategory:id,name', 'productUnit:id,name'])
            ->when($outletId, fn ($q) => $q->with(['locations' => fn ($q) => $q->whereKey($outletId)]))
            ->get();
        foreach ($products as $product) {
            if ($outletId && $product->locations && $product->locations->isNotEmpty()) {
                $pivot = $product->locations->first()->pivot;
                if ($pivot->is_active) {
                    $product->sell_price_per_unit = $pivot->sell_price_per_unit ?? $product->sell_price_per_unit;
                }
            }
            unset($product->locations);
        }
        $this->attachMenuFallbackImages($products);

        $combos = ComboMenu::query()
            ->where('combo_menus.is_active', true)
            ->whereNull('combo_menus.deleted_at')
            ->with(['category:id,name', 'items'])
            ->when($outletId, fn ($q) => $q->with(['locations' => fn ($q) => $q->whereKey($outletId)]))
            ->get();
        foreach ($combos as $combo) {
            if ($outletId && $combo->locations && $combo->locations->isNotEmpty()) {
                $pivot = $combo->locations->first()->pivot;
                if ($pivot->is_active) {
                    $combo->dine_in_price = $pivot->dine_in_price ?? $combo->dine_in_price;
                    $combo->take_away_price = $pivot->take_away_price ?? $combo->take_away_price;
                    $combo->delivery_price = $pivot->delivery_price ?? $combo->delivery_price;
                }
            }
            unset($combo->locations);
        }
        $this->attachMenuFallbackImages($combos);

        $modifiers = Modifier::query()->where('is_active', true)->get();
        foreach ($modifiers as $modifier) {
            $this->attachModifierOptionIds($modifier);
        }

        $printers = Printer::query()->where('is_active', true)->get(['id', 'name', 'ip_address', 'port', 'paper_size', 'copies']);

        $taxRates = TaxRate::query()->where('is_active', true)->get(['id', 'name', 'value', 'type']);

        $charges = Charge::query()->where('is_active', true)->get(['id', 'name', 'value', 'type', 'apply_to']);

        $discounts = Discount::query()->where('is_active', true)->get(['id', 'name', 'value', 'type']);

        $productCategories = ProductCategory::query()->where('is_active', true)->get(['id', 'name']);

        return [
            'food_menu_categories' => $foodMenuCategories,
            'product_categories' => $productCategories,
            'food_menus' => $foodMenus,
            'products' => $products,
            'combos' => $combos,
            'modifiers' => $modifiers,
            'printers' => $printers,
            'tax_rates' => $taxRates,
            'charges' => $charges,
            'discounts' => $discounts,
            'payment_methods' => PaymentMethod::query()->where('is_active', true)->get(['id', 'name']),
        ];
    }

    private function attachMenuFallbackImages($items): void
    {
        foreach ($items as $item) {
            if (! empty($item->image_url)) {
                continue;
            }

            $fallback = $this->menuFallbackFor((string) $item->name);
            if (Storage::disk('public')->exists($fallback)) {
                $item->setAttribute('image_url', '/storage/'.$fallback);
            }
        }
    }

    private function attachModifierOptionIds(Modifier $modifier): void
    {
        $options = collect($modifier->options ?? [])->values()->map(
            fn (array $option, int $index) => ['id' => $option['id'] ?? ($index + 1)] + $option
        );

        $modifier->setAttribute('options', $options->all());
    }

    private function menuFallbackFor(string $name): string
    {
        $name = strtolower($name);

        return match (true) {
            str_contains($name, 'pizza') => 'menu-fallbacks/pizza.png',
            str_contains($name, 'coffee'), str_contains($name, 'latte') => 'menu-fallbacks/coffee.png',
            str_contains($name, 'sundae'), str_contains($name, 'dessert'), str_contains($name, 'chocolate') => 'menu-fallbacks/dessert.png',
            str_contains($name, 'tea'), str_contains($name, 'drink'), str_contains($name, 'juice'),
            str_contains($name, 'cola'), str_contains($name, 'water') => 'menu-fallbacks/drink.png',
            str_contains($name, 'salad') => 'menu-fallbacks/salad.png',
            str_contains($name, 'combo'), str_contains($name, 'set'), str_contains($name, 'family') => 'menu-fallbacks/combo.png',
            default => 'menu-fallbacks/combo.png',
        };
    }

    public function tables(Request $request): JsonResponse
    {
        $floorId = $request->get('floor_id');
        $outletId = $request->integer('location_id') ?: null;
        $query = RestaurantTable::withoutGlobalScope(OutletScope::class)
            ->with(['floor' => function ($q) {
                $q->withoutGlobalScope(OutletScope::class)->select('id', 'name', 'location_id');
            }]);

        if ($floorId) {
            $query->where('floor_id', $floorId);
        }
        if ($outletId) {
            $query->where('outlet_id', $outletId);
        }

        $tables = $query
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('table_no')
            ->get();

        return response()->json(['tables' => $tables]);
    }

    public function tableActivity(int $id): JsonResponse
    {
        $table = RestaurantTable::query()
            ->with(['floor:id,name', 'outlet:id,name'])
            ->findOrFail($id);

        $mergeGroupIds = TableMergeMember::query()
            ->where('table_id', $table->id)
            ->pluck('merge_group_id')
            ->merge(
                TableMergeGroup::query()
                    ->where('primary_table_id', $table->id)
                    ->pluck('id')
            )
            ->unique()
            ->values();

        $relatedTableIds = TableMergeMember::query()
            ->whereIn('merge_group_id', $mergeGroupIds)
            ->pluck('table_id')
            ->push($table->id)
            ->unique()
            ->values();

        $orders = Order::query()
            ->with([
                'items.modifiers',
                'items.comboComponents',
                'floor:id,name',
                'table:id,table_no',
                'outlet:id,name',
                'createdBy:id,name',
                'changeHistories.changedBy:id,name',
                'changeHistories.orderItem:id,item_name_snapshot',
                'printLogs.printer:id,name',
            ])
            ->where(function ($query) use ($relatedTableIds, $mergeGroupIds) {
                $query->whereIn('table_id', $relatedTableIds);
                if ($mergeGroupIds->isNotEmpty()) {
                    $query->orWhereIn('table_merge_group_id', $mergeGroupIds);
                }
            })
            ->latest('created_at')
            ->limit(30)
            ->get();

        $events = [];
        foreach ($orders as $order) {
            $events[] = [
                'id' => 'order-'.$order->id.'-created',
                'type' => 'order_created',
                'title' => 'Order opened',
                'description' => $order->order_no.' - '.$order->items->count().' line items',
                'order_id' => $order->id,
                'order_no' => $order->order_no,
                'actor' => $order->createdBy?->name ?? 'System',
                'occurred_at' => $order->created_at?->toIso8601String(),
            ];

            foreach ($order->changeHistories as $history) {
                $itemName = $history->orderItem?->item_name_snapshot;
                $events[] = [
                    'id' => 'change-'.$history->id,
                    'type' => $history->action_type,
                    'title' => $this->activityTitle($history->action_type),
                    'description' => $this->activityDescription($history, $itemName),
                    'order_id' => $order->id,
                    'order_no' => $order->order_no,
                    'actor' => $history->changedBy?->name ?? 'System',
                    'reason' => $history->reason,
                    'old_values' => $history->old_values,
                    'new_values' => $history->new_values,
                    'occurred_at' => $history->changed_at?->toIso8601String(),
                ];
            }

            foreach ($order->printLogs as $printLog) {
                $events[] = [
                    'id' => 'print-'.$printLog->id,
                    'type' => $printLog->is_reprint ? 'kot_reprinted' : 'kot_printed',
                    'title' => $printLog->is_reprint ? 'KOT reprinted' : 'KOT printed',
                    'description' => ($printLog->printer?->name ?? 'Kitchen printer').' - '.$printLog->print_status,
                    'order_id' => $order->id,
                    'order_no' => $order->order_no,
                    'actor' => is_numeric($printLog->printed_by) ? 'Staff #'.$printLog->printed_by : 'System',
                    'reason' => $printLog->error_message,
                    'occurred_at' => $printLog->printed_at?->toIso8601String(),
                ];
            }
        }

        $mergeGroups = TableMergeGroup::query()
            ->with(['primaryTable:id,table_no', 'members.table:id,table_no', 'mergedBy:id,name', 'unmergedBy:id,name'])
            ->whereIn('id', $mergeGroupIds)
            ->get();
        foreach ($mergeGroups as $group) {
            $memberLabels = $group->members
                ->where('member_type', 'secondary')
                ->pluck('table.table_no')
                ->filter()
                ->join(', ');
            $events[] = [
                'id' => 'merge-'.$group->id,
                'type' => 'tables_merged',
                'title' => 'Tables merged',
                'description' => trim(($group->primaryTable?->table_no ?? 'Table').' + '.$memberLabels, ' +'),
                'actor' => $group->mergedBy?->name ?? 'System',
                'occurred_at' => $group->merged_at?->toIso8601String(),
            ];
            if ($group->unmerged_at) {
                $events[] = [
                    'id' => 'unmerge-'.$group->id,
                    'type' => 'tables_split',
                    'title' => 'Tables split',
                    'description' => 'Merged table group closed',
                    'actor' => $group->unmergedBy?->name ?? 'System',
                    'occurred_at' => $group->unmerged_at?->toIso8601String(),
                ];
            }
        }

        usort($events, fn ($a, $b) => strcmp((string) ($b['occurred_at'] ?? ''), (string) ($a['occurred_at'] ?? '')));

        $activeOrder = $orders->first(
            fn ($order) => ! in_array($order->order_status, ['completed', 'cancelled'], true)
        );

        return response()->json([
            'table' => $table,
            'active_order' => $activeOrder,
            'orders' => $orders->map(fn ($order) => [
                'id' => $order->id,
                'order_no' => $order->order_no,
                'order_status' => $order->order_status,
                'payment_state' => $order->payment_state,
                'grand_total' => $order->grand_total,
                'created_at' => $order->created_at?->toIso8601String(),
                'cancelled_at' => $order->cancelled_at?->toIso8601String(),
            ])->values(),
            'activity' => $events,
        ]);
    }

    public function swapOptions(int $id): JsonResponse
    {
        $sourceTable = RestaurantTable::query()->with('floor:id,name')->findOrFail($id);
        $sourceOrder = Order::query()
            ->where('table_id', $sourceTable->id)
            ->whereNotIn('order_status', ['completed', 'cancelled'])
            ->first();

        if (! $sourceOrder) {
            return response()->json(['message' => 'This table has no active order to move.'], 422);
        }
        if ($sourceOrder->payment_state !== 'unpaid') {
            return response()->json(['message' => 'Paid orders cannot be moved to another table.'], 422);
        }
        if ($this->tableHasActiveMerge($sourceTable)) {
            return response()->json(['message' => 'Split merged tables before swapping them.'], 422);
        }

        $primaryIds = TableMergeGroup::query()
            ->where('status', 'active')
            ->pluck('primary_table_id');

        $targets = RestaurantTable::query()
            ->with('floor:id,name')
            ->where('outlet_id', $sourceTable->outlet_id)
            ->where('id', '!=', $sourceTable->id)
            ->where('is_active', true)
            ->whereNotIn('status', ['inactive', 'reserved', 'merged'])
            ->whereNotIn('id', $primaryIds)
            ->orderBy('floor_id')
            ->orderBy('sort_order')
            ->get();

        $activeOrders = Order::query()
            ->whereIn('table_id', $targets->pluck('id'))
            ->whereNotIn('order_status', ['completed', 'cancelled'])
            ->get()
            ->keyBy('table_id');

        $targets = $targets->filter(function ($table) use ($activeOrders) {
            $order = $activeOrders->get($table->id);
            if ($table->status === 'occupied' && ! $order) {
                return false;
            }
            if ($order && $order->payment_state !== 'unpaid') {
                return false;
            }
            $table->active_order = $order?->only(['id', 'order_no', 'grand_total']);

            return true;
        })->values();

        return response()->json([
            'source_table' => $sourceTable,
            'source_order' => $sourceOrder->only(['id', 'order_no', 'grand_total']),
            'target_tables' => $targets,
        ]);
    }

    public function swapTable(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'target_table_id' => 'required|integer|different:source_table_id|exists:tables,id',
        ]);
        $user = $request->user();

        return DB::transaction(function () use ($id, $validated, $user) {
            $tables = RestaurantTable::query()
                ->whereIn('id', [$id, $validated['target_table_id']])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $sourceTable = $tables->get($id);
            $targetTable = $tables->get((int) $validated['target_table_id']);

            if (! $sourceTable || ! $targetTable || $sourceTable->id === $targetTable->id) {
                throw ValidationException::withMessages(['target_table_id' => 'Choose a different valid table.']);
            }
            if ($sourceTable->outlet_id !== $targetTable->outlet_id) {
                throw ValidationException::withMessages(['target_table_id' => 'Orders cannot move between outlets.']);
            }
            if (! $targetTable->is_active || in_array($targetTable->status, ['inactive', 'reserved', 'merged'], true)) {
                throw ValidationException::withMessages(['target_table_id' => 'The target table is not available for a swap.']);
            }
            if ($this->tableHasActiveMerge($sourceTable) || $this->tableHasActiveMerge($targetTable)) {
                throw ValidationException::withMessages(['target_table_id' => 'Split merged tables before swapping them.']);
            }

            $sourceOrder = Order::query()
                ->where('table_id', $sourceTable->id)
                ->whereNotIn('order_status', ['completed', 'cancelled'])
                ->lockForUpdate()
                ->first();
            $targetOrder = Order::query()
                ->where('table_id', $targetTable->id)
                ->whereNotIn('order_status', ['completed', 'cancelled'])
                ->lockForUpdate()
                ->first();

            if (! $sourceOrder) {
                throw ValidationException::withMessages(['table' => 'The source table no longer has an active order.']);
            }
            if ($sourceOrder->payment_state !== 'unpaid' || ($targetOrder && $targetOrder->payment_state !== 'unpaid')) {
                throw ValidationException::withMessages(['table' => 'Only unpaid orders can be swapped.']);
            }
            if ($targetTable->status === 'occupied' && ! $targetOrder) {
                throw ValidationException::withMessages(['target_table_id' => 'The target table state is stale. Refresh and try again.']);
            }

            $sourceLabel = $sourceTable->table_no ?: $sourceTable->code;
            $targetLabel = $targetTable->table_no ?: $targetTable->code;

            $sourceOrder->update([
                'table_id' => $targetTable->id,
                'floor_id' => $targetTable->floor_id,
                'updated_by' => $user?->id,
                'version_number' => (int) $sourceOrder->version_number + 1,
            ]);
            if ($targetOrder) {
                $targetOrder->update([
                    'table_id' => $sourceTable->id,
                    'floor_id' => $sourceTable->floor_id,
                    'updated_by' => $user?->id,
                    'version_number' => (int) $targetOrder->version_number + 1,
                ]);
            }

            $sourceTable->update(['status' => $targetOrder ? 'occupied' : 'available']);
            $targetTable->update(['status' => 'occupied']);

            $this->saveChangeHistory(
                $sourceOrder->id,
                null,
                'table_swapped',
                null,
                null,
                null,
                $user?->id,
                "Moved from {$sourceLabel} to {$targetLabel}",
                ['table_id' => $sourceTable->id, 'table' => $sourceLabel],
                ['table_id' => $targetTable->id, 'table' => $targetLabel],
            );
            if ($targetOrder) {
                $this->saveChangeHistory(
                    $targetOrder->id,
                    null,
                    'table_swapped',
                    null,
                    null,
                    null,
                    $user?->id,
                    "Moved from {$targetLabel} to {$sourceLabel}",
                    ['table_id' => $targetTable->id, 'table' => $targetLabel],
                    ['table_id' => $sourceTable->id, 'table' => $sourceLabel],
                );
            }

            return response()->json([
                'message' => $targetOrder ? 'Table orders swapped successfully.' : 'Order moved to the new table.',
                'source_order' => $sourceOrder->fresh()->load(['table:id,table_no', 'floor:id,name']),
                'target_order' => $targetOrder?->fresh()->load(['table:id,table_no', 'floor:id,name']),
            ]);
        });
    }

    public function items(Request $request): JsonResponse
    {
        $type = $request->get('type');
        $search = $request->get('search');
        $categoryId = $request->get('category_id');

        $result = [];

        if (! $type || $type === 'food_menu') {
            $q = FoodMenu::query()->where('food_menus.is_active', true)->whereNull('food_menus.deleted_at')->with(['category:id,name', 'unit:id,name']);
            if ($search) {
                $q->where('name', 'like', "%{$search}%");
            }
            if ($categoryId) {
                $q->where('category_id', $categoryId);
            }
            $result['food_menus'] = $q->get();
        }

        if (! $type || $type === 'product') {
            $q = Product::query()->where('products.is_active', true)->whereNull('products.deleted_at')->with(['productCategory:id,name', 'productUnit:id,name']);
            if ($search) {
                $q->where('name', 'like', "%{$search}%");
            }
            $result['products'] = $q->get();
        }

        if (! $type || $type === 'combo') {
            $q = ComboMenu::query()->where('combo_menus.is_active', true)->whereNull('combo_menus.deleted_at')->with(['category:id,name', 'items']);
            if ($search) {
                $q->where('name', 'like', "%{$search}%");
            }
            $result['combos'] = $q->get();
        }

        return response()->json($result);
    }

    public function orders(Request $request): JsonResponse
    {
        $query = Order::query()
            ->with(['items.modifiers', 'items.comboComponents', 'floor:id,name', 'table:id,table_no', 'outlet:id,name', 'createdBy:id,name']);

        if ($request->filled('outlet_id')) {
            $query->where('outlet_id', $request->outlet_id);
        }
        if ($request->filled('order_type')) {
            $query->where('order_type', $request->order_type);
        }
        if ($request->filled('order_status')) {
            $query->where('order_status', $request->order_status);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('order_no', 'like', "%{$s}%")
                    ->orWhere('customer_name', 'like', "%{$s}%");
            });
        }

        $orders = $query->orderBy('created_at', 'desc')->paginate($this->boundedPageSize($request, 20));

        return response()->json($orders);
    }

    public function showOrder(int $id): JsonResponse
    {
        $order = Order::query()
            ->with(['items.modifiers', 'items.comboComponents', 'floor:id,name', 'table:id,table_no', 'outlet:id,name', 'createdBy:id,name', 'payments.paymentMethod:id,name'])
            ->findOrFail($id);

        return response()->json(['order' => $order]);
    }

    public function storeOrder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'outlet_id' => 'required|exists:locations,id',
            'order_type' => 'required|in:dine_in,takeaway,delivery',
            'floor_id' => 'nullable|exists:floors,id',
            'table_id' => 'nullable|exists:tables,id',
            'pax' => 'nullable|integer|min:1',
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:50',
            'pickup_time' => 'nullable',
            'delivery_partner' => 'nullable|string|max:255',
            'delivery_address' => 'nullable|string',
            'delivery_fee' => 'nullable|numeric|min:0',
            'order_note' => 'nullable|string',
            'order_discount_type' => 'nullable|in:fixed,percentage',
            'order_discount_value' => 'nullable|numeric|min:0',
            'tax_rate_id' => 'nullable|integer|exists:tax_rates,id',
            'charge_id' => 'nullable|integer|exists:charges,id',
            'auto_confirm' => 'nullable|boolean',
            'items' => 'required|array|min:1',
            'items.*.item_type' => 'required|in:food_menu,product,combo',
            'items.*.item_id' => 'required|integer',
            'items.*.qty' => 'required|numeric|min:0.01',
            'items.*.discount_type' => 'nullable|in:fixed,percentage',
            'items.*.discount_value' => 'nullable|numeric|min:0',
            'items.*.item_note' => 'nullable|string',
            'items.*.modifiers' => 'nullable|array',
            'items.*.modifiers.*.modifier_group_id' => 'nullable|integer|exists:modifiers,id',
            'items.*.modifiers.*.modifier_item_id' => 'nullable|integer',
            'payments' => 'nullable|array',
            'payments.*.payment_method_id' => 'required_with:payments|exists:payment_methods,id',
            'payments.*.amount' => 'required_with:payments|numeric|min:0.01',
        ], [
            'items.*.modifiers.*.modifier_group_id.exists' => 'Modifier group not found.',
        ]);

        $user = $request->user();
        $userId = $user?->id;

        return DB::transaction(function () use ($request, $validated, $userId, $user) {
            $orderType = $validated['order_type'];
            $outletId = $validated['outlet_id'];

            // Validate dine-in: table required
            if ($orderType === 'dine_in') {
                if (empty($validated['table_id'])) {
                    return response()->json(['message' => 'Table is required for dine-in orders.'], 422);
                }
                $table = RestaurantTable::query()
                    ->lockForUpdate()
                    ->findOrFail($validated['table_id']);

                if (! $table->is_active || $table->status === 'inactive') {
                    throw ValidationException::withMessages(['table_id' => 'Table is inactive.']);
                }
                if ((int) $table->floor_id !== (int) ($validated['floor_id'] ?? 0)) {
                    throw ValidationException::withMessages([
                        'table_id' => 'The table must belong to the selected floor.',
                    ]);
                }
                if ((int) $table->outlet_id !== (int) $outletId) {
                    $table->update(['outlet_id' => $outletId]);
                }
                if ($table->status === 'merged' && $table->merged_with_table_id) {
                    $primaryTable = RestaurantTable::query()->lockForUpdate()->find($table->merged_with_table_id);
                    if ($primaryTable) {
                        $table = $primaryTable;
                        $validated['table_id'] = $table->id;
                    }
                }

                if (! in_array($table->status, ['available', 'occupied', 'merged'])) {
                    throw ValidationException::withMessages([
                        'table_id' => 'Table is no longer available. Refresh the floor and choose another table.',
                    ]);
                }

                $existingActiveOrder = Order::query()
                    ->where('table_id', $table->id)
                    ->whereNotIn('order_status', ['completed', 'cancelled'])
                    ->where('payment_state', 'unpaid')
                    ->first();
                if ($existingActiveOrder) {
                    return $this->addItems($request, $existingActiveOrder->id);
                }
            }

            // Validate delivery: required fields
            if ($orderType === 'delivery') {
                if (empty($validated['delivery_partner'])) {
                    return response()->json(['message' => 'Delivery partner is required for delivery orders.'], 422);
                }
                if (empty($validated['customer_name'])) {
                    return response()->json(['message' => 'Customer name is required for delivery orders.'], 422);
                }
                if (empty($validated['customer_phone'])) {
                    return response()->json(['message' => 'Customer phone is required for delivery orders.'], 422);
                }
                if (empty($validated['delivery_address'])) {
                    return response()->json(['message' => 'Delivery address is required for delivery orders.'], 422);
                }
            }

            // Check discount permission
            $hasDiscountPermission = $user ? ($user->hasPermission('apply_discount') || $user->hasPermission('waiter_discount') || $user->role === 'admin') : true;
            if (! $hasDiscountPermission) {
                $validated['order_discount_value'] = 0;
                $validated['order_discount_type'] = null;
                if (! empty($validated['items'])) {
                    foreach ($validated['items'] as &$itm) {
                        $itm['discount_value'] = 0;
                        $itm['discount_type'] = null;
                    }
                    unset($itm);
                }
            }

            // Load tax and service charge (if explicitly selected by client)
            $taxRate = null;
            if (! empty($validated['tax_rate_id'])) {
                $taxRate = TaxRate::query()->where('is_active', true)->find($validated['tax_rate_id']);
            }
            $charge = null;
            if (! empty($validated['charge_id'])) {
                $charge = Charge::query()->where('is_active', true)->find($validated['charge_id']);
            }

            $orderNo = $this->generateOrderNo($orderType, $outletId);

            $defaultCustomer = $orderType === 'delivery' ? null : 'Walk-in Customer';

            $order = Order::query()->create([
                'order_no' => $orderNo,
                'outlet_id' => $outletId,
                'order_type' => $orderType,
                'floor_id' => $validated['floor_id'] ?? null,
                'table_id' => $validated['table_id'] ?? null,
                'pax' => $validated['pax'] ?? ($orderType === 'dine_in' ? 1 : null),
                'customer_name' => $validated['customer_name'] ?? $defaultCustomer,
                'customer_phone' => $validated['customer_phone'] ?? null,
                'pickup_time' => !empty($validated['pickup_time'])
                    ? Carbon::parse($validated['pickup_time'])
                    : ($orderType === 'takeaway' ? Carbon::now() : null),
                'delivery_partner' => $validated['delivery_partner'] ?? null,
                'delivery_address' => $validated['delivery_address'] ?? null,
                'delivery_fee' => $validated['delivery_fee'] ?? 0,
                'order_note' => $validated['order_note'] ?? null,
                'created_by' => $userId,
                'order_status' => 'pending',
                'confirmation_status' => 'draft',
                'print_status' => 'not_printed',
                'stock_deduction_status' => 'none',
            ]);

            $subtotal = 0;
            $itemDiscountTotal = 0;
            $totalCost = 0;

            foreach ($validated['items'] as $itemData) {
                $this->validateItemModifiers($itemData);

                $itemResult = $this->processOrderItem($itemData, $orderType, $outletId);
                $itemResult['order_id'] = $order->id;
                $itemResult['original_qty'] = $itemResult['qty'];
                $itemResult['active_qty'] = $itemResult['qty'];
                $itemResult['cancelled_qty'] = 0;
                $itemResult['printed_qty'] = 0;
                $itemResult['cancelled_printed_qty'] = 0;

                $orderItem = OrderItem::query()->create($itemResult);
                $subtotal += $orderItem->amount;
                $itemDiscountTotal += $orderItem->discount_amount;
                $totalCost += ($orderItem->cost_snapshot ?? 0) * $orderItem->qty;

                // Save modifiers with sort_order
                if (! empty($itemData['modifiers'])) {
                    $sortOrder = 0;
                    foreach ($itemData['modifiers'] as $mod) {
                        $modGroup = Modifier::query()->find($mod['modifier_group_id']);
                        $optionName = '';
                        $adjustment = 0;
                        if ($modGroup && $modGroup->options) {
                            foreach ($modGroup->options as $optionIndex => $opt) {
                                if ((int) ($opt['id'] ?? ($optionIndex + 1)) === (int) $mod['modifier_item_id']) {
                                    $optionName = $opt['name'] ?? '';
                                    $adjustment = (float) ($opt['price'] ?? 0);
                                    break;
                                }
                            }
                        }

                        OrderItemModifier::query()->create([
                            'order_item_id' => $orderItem->id,
                            'modifier_group_id' => $mod['modifier_group_id'],
                            'modifier_group_name_snapshot' => $modGroup?->name ?? '',
                            'modifier_item_id' => $mod['modifier_item_id'],
                            'modifier_item_name_snapshot' => $optionName,
                            'price_adjustment_snapshot' => $adjustment,
                            'sort_order' => $sortOrder++,
                        ]);
                    }
                }

                // Save combo components
                if ($itemData['item_type'] === 'combo') {
                    $combo = ComboMenu::query()->with('items')->find($itemData['item_id']);
                    if ($combo) {
                        foreach ($combo->items as $comp) {
                            $compItem = null;
                            $compName = '';
                            $unitName = '';
                            $costSnap = 0;
                            $printerId = null;

                            if ($comp->item_type === 'food_menu') {
                                $compItem = FoodMenu::query()->with('unit')->find($comp->item_id);
                                if ($compItem) {
                                    $compName = $compItem->name;
                                    $unitName = $compItem->unit?->name ?? '';
                                    $costSnap = $compItem->cost_per_unit ?? 0;
                                    $printerId = $compItem->printer_id;
                                }
                            } else {
                                $compItem = Product::query()->with('productUnit')->find($comp->item_id);
                                if ($compItem) {
                                    $compName = $compItem->name;
                                    $unitName = $compItem->productUnit?->name ?? '';
                                    $costSnap = $compItem->purchase_price_per_unit ?? 0;
                                    $printerId = $compItem->printer_id;
                                }
                            }

                            OrderComboComponent::query()->create([
                                'order_item_id' => $orderItem->id,
                                'item_type' => $comp->item_type,
                                'item_id' => $comp->item_id,
                                'item_name_snapshot' => $compName,
                                'qty_per_combo' => $comp->qty,
                                'ordered_combo_qty' => $itemData['qty'],
                                'total_qty' => $comp->qty * $itemData['qty'],
                                'unit_name_snapshot' => $unitName,
                                'cost_snapshot' => $costSnap,
                                'printer_id_snapshot' => $printerId,
                            ]);
                        }
                    }
                }
            }

            // Calculate order discount
            $orderDiscountAmount = 0;
            if (! empty($validated['order_discount_type']) && ! empty($validated['order_discount_value'])) {
                if ($validated['order_discount_type'] === 'fixed') {
                    $orderDiscountAmount = min((float) $validated['order_discount_value'], $subtotal);
                } elseif ($validated['order_discount_type'] === 'percentage') {
                    $pct = min((float) $validated['order_discount_value'], 100);
                    $orderDiscountAmount = round($subtotal * $pct / 100, 2);
                }
            }

            $taxableAmount = $subtotal - $orderDiscountAmount;
            $taxAmount = 0;
            if ($taxRate && $taxRate->value > 0) {
                $taxAmount = round($taxableAmount * $taxRate->value / 100, 2);
            }

            $serviceChargeAmount = 0;
            if ($charge && $charge->value > 0) {
                if ($charge->type === 'percentage') {
                    $serviceChargeAmount = round($taxableAmount * $charge->value / 100, 2);
                } else {
                    $serviceChargeAmount = (float) $charge->value;
                }
            }

            $deliveryFee = $orderType === 'delivery' ? (float) ($validated['delivery_fee'] ?? 0) : 0;

            $grandTotal = round($subtotal - $orderDiscountAmount + $taxAmount + $serviceChargeAmount + $deliveryFee, 2);

            $paidAmount = 0;
            $balanceAmount = $grandTotal;
            $changeAmount = 0;

            // No payment processing in Waiter Panel. Delivery payments are processed in Cashier Panel.
            $order->update([
                'subtotal' => $subtotal,
                'item_discount_amount' => $itemDiscountTotal,
                'order_discount_type' => $validated['order_discount_type'] ?? null,
                'order_discount_value' => $validated['order_discount_value'] ?? 0,
                'order_discount_amount' => $orderDiscountAmount,
                'tax_rate_snapshot' => $taxRate?->value ?? 0,
                'tax_amount' => $taxAmount,
                'service_charge_rate_snapshot' => $charge?->value ?? 0,
                'service_charge_amount' => $serviceChargeAmount,
                'delivery_fee' => $deliveryFee,
                'grand_total' => $grandTotal,
                'paid_amount' => $paidAmount,
                'balance_amount' => $balanceAmount,
                'change_amount' => $changeAmount,
                'payment_state' => 'unpaid',
            ]);

            // Update table status to occupied for dine-in
            if ($orderType === 'dine_in' && $order->table_id) {
                RestaurantTable::query()->where('id', $order->table_id)->update(['status' => 'occupied']);
            }

            // Auto-confirm order in single HTTP request for instant performance
            $autoConfirm = $validated['auto_confirm'] ?? true;
            if ($autoConfirm) {
                if ($order->stock_deduction_status !== 'deducted') {
                    $this->deductOrderStock($order, $outletId);
                    $order->update([
                        'stock_deduction_status' => 'deducted',
                        'stock_deducted_at' => Carbon::now(),
                    ]);
                    $order->refresh();
                }

                if ($order->confirmation_status !== 'confirmed') {
                    $order->update([
                        'confirmation_status' => 'confirmed',
                        'confirmed_at' => Carbon::now(),
                        'confirmed_by' => $userId,
                    ]);

                    OrderItem::query()
                        ->where('order_id', $order->id)
                        ->whereIn('item_type', ['food_menu', 'product', 'combo'])
                        ->update(['printed_qty' => DB::raw('active_qty')]);

                    $this->saveChangeHistory(
                        $order->id,
                        null,
                        'kot_sent',
                        null,
                        null,
                        null,
                        $userId,
                        'Order confirmed and sent to kitchen'
                    );

                    $this->dispatchPrint($order);
                }
            }

            $order->load(['items.modifiers', 'items.comboComponents', 'floor:id,name', 'table:id,table_no', 'outlet:id,name', 'createdBy:id,name', 'payments.paymentMethod:id,name']);

            return response()->json(['order' => $order, 'message' => 'Order created successfully.'], 201);
        });
    }

    public function confirmOrder(int $id): JsonResponse
    {
        $result = DB::transaction(function () use ($id) {
            $order = Order::query()->lockForUpdate()->findOrFail($id);

            if (in_array($order->order_status, ['completed', 'cancelled'])) {
                throw ValidationException::withMessages([
                    'order' => 'Cannot confirm a completed or cancelled order.',
                ]);
            }

            if ($order->stock_deduction_status !== 'deducted') {
                $this->deductOrderStock($order, (int) $order->outlet_id);
                $order->refresh();
            }

            $shouldPrint = $order->confirmation_status !== 'confirmed';
            if ($shouldPrint) {
                $order->update([
                    'confirmation_status' => 'confirmed',
                    'confirmed_at' => Carbon::now(),
                    'confirmed_by' => auth()->id(),
                ]);

                OrderItem::query()
                    ->where('order_id', $order->id)
                    ->whereIn('item_type', ['food_menu', 'product', 'combo'])
                    ->update(['printed_qty' => DB::raw('active_qty')]);

                $this->saveChangeHistory(
                    $order->id,
                    null,
                    'kot_sent',
                    null,
                    null,
                    null,
                    auth()->id(),
                    'Order confirmed and sent to kitchen',
                );
            }

            $order->load(['items.modifiers', 'items.comboComponents', 'floor:id,name', 'table:id,table_no', 'outlet:id,name', 'createdBy:id,name']);

            return ['order' => $order, 'should_print' => $shouldPrint];
        });

        if ($result['should_print']) {
            $this->dispatchPrint($result['order']);
        }

        return response()->json([
            'order' => $result['order'],
            'message' => $result['should_print']
                ? 'Order confirmed and sent to kitchen.'
                : 'Order is already confirmed.',
        ]);
    }

    public function updateOrder(Request $request, int $id): JsonResponse
    {
        $order = Order::query()->findOrFail($id);

        if (in_array($order->order_status, ['completed', 'cancelled'])) {
            return response()->json(['message' => 'Cannot edit a completed or cancelled order.'], 422);
        }

        if ($order->stock_deduction_status === 'deducted') {
            return response()->json(['message' => 'Cannot edit an order after stock has been deducted. Please cancel and create a new order.'], 422);
        }

        $validated = $request->validate([
            'floor_id' => 'nullable|exists:floors,id',
            'table_id' => 'nullable|exists:tables,id',
            'pax' => 'nullable|integer|min:1',
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:50',
            'pickup_time' => 'nullable|date',
            'delivery_partner' => 'nullable|string|max:255',
            'delivery_address' => 'nullable|string',
            'delivery_fee' => 'nullable|numeric|min:0',
            'order_note' => 'nullable|string',
            'order_discount_type' => 'nullable|in:fixed,percentage',
            'order_discount_value' => 'nullable|numeric|min:0',
            'items' => 'required|array|min:1',
            'items.*.item_type' => 'required|in:food_menu,product,combo',
            'items.*.item_id' => 'required|integer',
            'items.*.qty' => 'required|numeric|min:0.01',
            'items.*.discount_type' => 'nullable|in:fixed,percentage',
            'items.*.discount_value' => 'nullable|numeric|min:0',
            'items.*.item_note' => 'nullable|string',
            'items.*.modifiers' => 'nullable|array',
            'items.*.modifiers.*.modifier_group_id' => 'nullable|integer|exists:modifiers,id',
            'items.*.modifiers.*.modifier_item_id' => 'nullable|integer',
            'version_number' => 'nullable|integer|min:1',
        ]);

        $user = $request->user();

        return DB::transaction(function () use ($order, $validated, $user) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if (isset($validated['version_number'])
                && (int) $validated['version_number'] !== (int) $order->version_number) {
                return response()->json(['message' => 'Order was changed by another user. Refresh and try again.'], 409);
            }

            // Store old item quantities summed by item_type + item_id for accurate diff tracking
            $oldItemQtys = [];
            $oldItemNames = [];
            foreach ($order->items as $oldItem) {
                $k = $oldItem->item_type.'_'.$oldItem->item_id;
                $oldItemQtys[$k] = ($oldItemQtys[$k] ?? 0.0) + (float) $oldItem->qty;
                $oldItemNames[$k] = $oldItem->item_name_snapshot;
            }

            $order->items()->delete();
            $order->updated_by = $user?->id;

            $subtotal = 0;
            $itemDiscountTotal = 0;
            $totalCost = 0;
            $newFoodMenuQtys = [];
            $cancelledFoodMenuQtys = [];

            $consolidatedItems = [];
            foreach ($validated['items'] as $itemData) {
                $k = ($itemData['item_type'] ?? 'food_menu').'_'.($itemData['item_id'] ?? 0);
                if (isset($consolidatedItems[$k])) {
                    $consolidatedItems[$k]['qty'] = (float) $consolidatedItems[$k]['qty'] + (float) $itemData['qty'];
                } else {
                    $consolidatedItems[$k] = $itemData;
                }
            }

            foreach (array_values($consolidatedItems) as $itemData) {
                $this->validateItemModifiers($itemData);

                $itemResult = $this->processOrderItem($itemData, $order->order_type, $order->outlet_id);
                $itemResult['order_id'] = $order->id;
                $itemResult['original_qty'] = $itemResult['qty'];
                $itemResult['active_qty'] = $itemResult['qty'];
                $itemResult['cancelled_qty'] = 0;
                $itemResult['printed_qty'] = $order->confirmation_status === 'confirmed' ? $itemResult['qty'] : 0;
                $itemResult['cancelled_printed_qty'] = 0;

                $orderItem = OrderItem::query()->create($itemResult);
                $subtotal += $orderItem->amount;
                $itemDiscountTotal += $orderItem->discount_amount;
                $totalCost += ($orderItem->cost_snapshot ?? 0) * $orderItem->qty;

                // Track qty changes for food menu printing
                $itemKey = $itemData['item_type'].'_'.$itemData['item_id'];
                $oldQty = $oldItemQtys[$itemKey] ?? 0.0;
                $newQty = (float) $itemData['qty'];

                if (in_array($itemData['item_type'], ['food_menu', 'product'], true)) {
                    $itemPrinterId = $this->getItemPrinterId($itemData['item_type'], (int) $itemData['item_id']);
                    if ($newQty > $oldQty) {
                        $newFoodMenuQtys[] = [
                            'item_id' => $itemData['item_id'],
                            'item_name' => $orderItem->item_name_snapshot,
                            'additional_qty' => $newQty - $oldQty,
                            'modifiers' => $itemData['modifiers'] ?? [],
                            'note' => $itemData['item_note'] ?? '',
                            'printer_id' => $itemPrinterId,
                        ];
                    } elseif ($newQty < $oldQty) {
                        $cancelledFoodMenuQtys[] = [
                            'item_name' => $orderItem->item_name_snapshot,
                            'cancelled_qty' => $oldQty - $newQty,
                            'printer_id' => $itemPrinterId,
                        ];
                    }
                }

                // Save modifiers
                if (! empty($itemData['modifiers'])) {
                    $sortOrder = 0;
                    foreach ($itemData['modifiers'] as $mod) {
                        $modGroup = Modifier::query()->find($mod['modifier_group_id']);
                        $optionName = '';
                        $adjustment = 0;
                        if ($modGroup && $modGroup->options) {
                            foreach ($modGroup->options as $optionIndex => $opt) {
                                if ((int) ($opt['id'] ?? ($optionIndex + 1)) === (int) $mod['modifier_item_id']) {
                                    $optionName = $opt['name'] ?? '';
                                    $adjustment = (float) ($opt['price'] ?? 0);
                                    break;
                                }
                            }
                        }

                        OrderItemModifier::query()->create([
                            'order_item_id' => $orderItem->id,
                            'modifier_group_id' => $mod['modifier_group_id'],
                            'modifier_group_name_snapshot' => $modGroup?->name ?? '',
                            'modifier_item_id' => $mod['modifier_item_id'],
                            'modifier_item_name_snapshot' => $optionName,
                            'price_adjustment_snapshot' => $adjustment,
                            'sort_order' => $sortOrder++,
                        ]);
                    }
                }

                // Save combo components
                if ($itemData['item_type'] === 'combo') {
                    $combo = ComboMenu::query()->with('items')->find($itemData['item_id']);
                    if ($combo) {
                        foreach ($combo->items as $comp) {
                            $compItem = null;
                            $compName = '';
                            $unitName = '';
                            $costSnap = 0;
                            $printerId = null;

                            if ($comp->item_type === 'food_menu') {
                                $compItem = FoodMenu::query()->with('unit')->find($comp->item_id);
                                if ($compItem) {
                                    $compName = $compItem->name;
                                    $unitName = $compItem->unit?->name ?? '';
                                    $costSnap = $compItem->cost_per_unit ?? 0;
                                    $printerId = $compItem->printer_id;
                                }
                            } else {
                                $compItem = Product::query()->with('productUnit')->find($comp->item_id);
                                if ($compItem) {
                                    $compName = $compItem->name;
                                    $unitName = $compItem->productUnit?->name ?? '';
                                    $costSnap = $compItem->purchase_price_per_unit ?? 0;
                                }
                            }

                            // Track combo food menu qty changes
                            if ($comp->item_type === 'food_menu') {
                                $compKey = 'food_menu_'.$comp->item_id;
                                $oldCompQty = 0;
                                if (isset($oldItems[$itemData['item_type'].'_'.$itemData['item_id']])) {
                                    $oldCompQty = (float) $oldItems[$itemData['item_type'].'_'.$itemData['item_id']]->qty * (float) $comp->qty;
                                }
                                $newCompQty = (float) $itemData['qty'] * (float) $comp->qty;

                                if ($newCompQty > $oldCompQty) {
                                    $newFoodMenuQtys[] = [
                                        'item_id' => $comp->item_id,
                                        'item_name' => $compName,
                                        'additional_qty' => $newCompQty - $oldCompQty,
                                        'modifiers' => [],
                                        'note' => "From: {$combo->name}",
                                        'printer_id' => $printerId,
                                    ];
                                } elseif ($newCompQty < $oldCompQty) {
                                    $cancelledFoodMenuQtys[] = [
                                        'item_name' => $compName,
                                        'cancelled_qty' => $oldCompQty - $newCompQty,
                                        'printer_id' => $printerId,
                                    ];
                                }
                            }

                            OrderComboComponent::query()->create([
                                'order_item_id' => $orderItem->id,
                                'item_type' => $comp->item_type,
                                'item_id' => $comp->item_id,
                                'item_name_snapshot' => $compName,
                                'qty_per_combo' => $comp->qty,
                                'ordered_combo_qty' => $itemData['qty'],
                                'total_qty' => $comp->qty * $itemData['qty'],
                                'unit_name_snapshot' => $unitName,
                                'cost_snapshot' => $costSnap,
                                'printer_id_snapshot' => $printerId,
                            ]);
                        }
                    }
                }
            }

            // Recalculate order discount
            $orderDiscountAmount = 0;
            if (! empty($validated['order_discount_type']) && ! empty($validated['order_discount_value'])) {
                if ($validated['order_discount_type'] === 'fixed') {
                    $orderDiscountAmount = min((float) $validated['order_discount_value'], $subtotal);
                } elseif ($validated['order_discount_type'] === 'percentage') {
                    $pct = min((float) $validated['order_discount_value'], 100);
                    $orderDiscountAmount = round($subtotal * $pct / 100, 2);
                }
            }

            // Track completely removed items (items that were in oldItemQtys but removed from new payload)
            foreach ($oldItemQtys as $itemKey => $oldQty) {
                $foundInNew = false;
                foreach ($validated['items'] as $itemData) {
                    if ($itemData['item_type'].'_'.$itemData['item_id'] === $itemKey) {
                        $foundInNew = true;
                        break;
                    }
                }

                if (! $foundInNew && $oldQty > 0) {
                    [$type, $itemId] = explode('_', $itemKey, 2);
                    if ($type === 'food_menu' || $type === 'product') {
                        $cancelledFoodMenuQtys[] = [
                            'item_name' => $oldItemNames[$itemKey] ?? 'Item',
                            'cancelled_qty' => $oldQty,
                            'printer_id' => $this->getItemPrinterId($type, (int) $itemId),
                        ];
                        $this->saveChangeHistory(
                            $order->id,
                            null,
                            'item_cancelled',
                            $oldQty,
                            0,
                            -$oldQty,
                            $user?->id,
                            ($oldItemNames[$itemKey] ?? 'Item').' cancelled'
                        );
                    }
                }
            }

            $taxRate = TaxRate::query()->where('is_active', true)->where('type', 'percentage')->first();
            $chargeType = $order->order_type === 'dine_in' ? 'dinein' : $order->order_type;
            $charge = Charge::query()->where('is_active', true)
                ->where(function ($q) use ($chargeType) {
                    $q->where('apply_to', $chargeType)->orWhere('apply_to', 'all');
                })->first();

            $taxableAmount = $subtotal - $orderDiscountAmount;
            $taxAmount = $taxRate && $taxRate->value > 0 ? round($taxableAmount * $taxRate->value / 100, 2) : 0;
            $serviceChargeAmount = 0;
            if ($charge && $charge->value > 0) {
                $serviceChargeAmount = $charge->type === 'percentage'
                    ? round($taxableAmount * $charge->value / 100, 2)
                    : (float) $charge->value;
            }

            $deliveryFee = $order->order_type === 'delivery' ? (float) ($validated['delivery_fee'] ?? $order->delivery_fee) : 0;

            $grandTotal = round($subtotal - $orderDiscountAmount + $taxAmount + $serviceChargeAmount + $deliveryFee, 2);

            $updateData = [
                'subtotal' => $subtotal,
                'item_discount_amount' => $itemDiscountTotal,
                'order_discount_type' => $validated['order_discount_type'] ?? null,
                'order_discount_value' => $validated['order_discount_value'] ?? 0,
                'order_discount_amount' => $orderDiscountAmount,
                'tax_rate_snapshot' => $taxRate?->value ?? 0,
                'tax_amount' => $taxAmount,
                'service_charge_rate_snapshot' => $charge?->value ?? 0,
                'service_charge_amount' => $serviceChargeAmount,
                'delivery_fee' => $deliveryFee,
                'grand_total' => $grandTotal,
                'balance_amount' => max(0, $grandTotal - $order->paid_amount),
                'order_note' => $validated['order_note'] ?? $order->order_note,
                'customer_name' => $validated['customer_name'] ?? $order->customer_name,
                'customer_phone' => $validated['customer_phone'] ?? $order->customer_phone,
                'delivery_address' => $validated['delivery_address'] ?? $order->delivery_address,
                'delivery_partner' => $validated['delivery_partner'] ?? $order->delivery_partner,
                'pickup_time' => $validated['pickup_time'] ?? $order->pickup_time,
                'pax' => $validated['pax'] ?? $order->pax,
                'version_number' => (int) $order->version_number + 1,
            ];

            if ($validated['table_id'] ?? null) {
                $updateData['table_id'] = $validated['table_id'];
                $updateData['floor_id'] = $validated['floor_id'] ?? $order->floor_id;
            }

            $order->update($updateData);

            $order->load(['items.modifiers', 'items.comboComponents', 'floor:id,name', 'table:id,table_no', 'outlet:id,name', 'createdBy:id,name']);

            return [
                'order' => $order,
                'newFoodMenuQtys' => $newFoodMenuQtys,
                'cancelledFoodMenuQtys' => $cancelledFoodMenuQtys,
            ];
        });

        $order = $resultData['order'];
        $newFoodMenuQtys = $resultData['newFoodMenuQtys'];
        $cancelledFoodMenuQtys = $resultData['cancelledFoodMenuQtys'];

        if (! empty($newFoodMenuQtys) || ! empty($cancelledFoodMenuQtys)) {
            $this->printChangedItems($order, $newFoodMenuQtys, $cancelledFoodMenuQtys);
        }

        return response()->json(['order' => $order, 'message' => 'Order updated successfully.']);
    }

    public function cancelOrder(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'cancellation_reason' => 'required|string|max:1000',
        ]);

        $order = Order::query()->with('floor:id,name', 'table:id,table_no', 'createdBy:id,name')->findOrFail($id);

        if (in_array($order->order_status, ['completed', 'cancelled'])) {
            return response()->json(['message' => 'Order is already completed or cancelled.'], 422);
        }

        if ($order->payment_state === 'paid' || $order->payment_state === 'refunded') {
            return response()->json(['message' => 'This order has already been paid. Use Refund or Void instead.'], 422);
        }

        $user = $request->user();

        return DB::transaction(function () use ($order, $validated, $user) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if (in_array($order->order_status, ['completed', 'cancelled'])) {
                return response()->json(['message' => 'Order was modified by another user.'], 409);
            }

            // Reverse stock if already deducted
            if ($order->stock_deduction_status === 'deducted') {
                $this->reverseOrderStock($order);
                $order->stock_deduction_status = 'reversed';
            }

            $order->update([
                'order_status' => 'cancelled',
                'cancelled_at' => Carbon::now(),
                'cancelled_by' => $user?->id,
                'cancellation_reason' => $validated['cancellation_reason'],
            ]);

            if ($order->confirmation_status === 'confirmed') {
                $this->printCancellation($order);
            }

            $this->releaseDineInTableIfNoActiveOrders($order);

            $this->saveChangeHistory($order->id, null, 'order_cancelled',
                null, null, null, $user?->id, $validated['cancellation_reason']);

            return response()->json(['message' => 'Order cancelled successfully.']);
        });
    }

    public function completePayment(Request $request, int $id): JsonResponse
    {
        return app(CashierPanelController::class)->completePayment($request, $id);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'order_status' => 'required|in:pending,preparing,ready,on_the_way,delivered,completed,cancelled',
            'version_number' => 'nullable|integer|min:1',
        ]);

        $order = Order::query()->findOrFail($id);

        if ($order->order_status === 'cancelled') {
            return response()->json(['message' => 'Cannot update status of a cancelled order.'], 422);
        }

        $newStatus = $validated['order_status'];

        return DB::transaction(function () use ($order, $newStatus, $validated) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if (isset($validated['version_number'])
                && (int) $validated['version_number'] !== (int) $order->version_number) {
                return response()->json(['message' => 'Order was changed by another user. Refresh and try again.'], 409);
            }

            $allowedTransitions = [
                'pending' => ['preparing', 'cancelled'],
                'preparing' => ['ready', 'cancelled'],
                'ready' => ['on_the_way', 'completed', 'cancelled'],
                'on_the_way' => ['delivered', 'cancelled'],
                'delivered' => ['completed'],
                'completed' => [],
                'cancelled' => [],
            ];
            if ($newStatus !== $order->order_status
                && ! in_array($newStatus, $allowedTransitions[$order->order_status] ?? [], true)) {
                return response()->json([
                    'message' => "Order cannot move from {$order->order_status} to {$newStatus}.",
                    'errors' => ['order_status' => ['Invalid order status transition.']],
                ], 422);
            }

            if ($newStatus === 'cancelled') {
                return response()->json(['message' => 'Use the cancel action with a reason.'], 422);
            }

            if ($newStatus === 'completed') {
                if ($order->payment_state !== 'paid' || ! $order->sale()->exists()) {
                    return response()->json([
                        'message' => 'An order must be fully paid and have a completed sale before it can be completed.',
                        'errors' => ['order_status' => ['Complete payment at the cashier first.']],
                    ], 422);
                }
            }

            $order->update([
                'order_status' => $newStatus,
                'version_number' => (int) $order->version_number + 1,
            ]);

            // Release table when dine-in is completed
            if ($newStatus === 'completed' && $order->order_type === 'dine_in' && $order->table_id) {
                $activeOrderCount = Order::query()
                    ->where('table_id', $order->table_id)
                    ->where('id', '!=', $order->id)
                    ->whereNotIn('order_status', ['completed', 'cancelled'])
                    ->count();

                if ($activeOrderCount === 0) {
                    RestaurantTable::query()->where('id', $order->table_id)->update(['status' => 'available']);
                }

                $order->completed_at = Carbon::now();
                $order->save();
            }

            return response()->json(['message' => 'Order status updated successfully.']);
        });
    }

    public function reprint(Request $request, int $id): JsonResponse
    {
        $order = Order::query()->with(['items.modifiers', 'items.comboComponents', 'floor:id,name', 'table:id,table_no', 'outlet'])->findOrFail($id);

        if ($order->confirmation_status !== 'confirmed') {
            return response()->json(['message' => 'Confirm the order before reprinting its KOT.'], 422);
        }

        if (in_array($order->order_status, ['completed', 'cancelled'])) {
            return response()->json(['message' => 'Cannot reprint an inactive order.'], 422);
        }

        $validated = $request->validate([
            'items' => 'nullable|array',
            'items.*.item_type' => 'required_with:items|in:food_menu,product,combo',
            'items.*.item_id' => 'required_with:items|integer',
            'items.*.qty' => 'required_with:items|numeric|min:0.01',
            'items.*.item_note' => 'nullable|string',
            'items.*.modifiers' => 'nullable|array',
            'items.*.modifiers.*.modifier_group_id' => 'nullable|integer',
            'items.*.modifiers.*.modifier_item_id' => 'nullable|integer',
        ]);

        if (! empty($validated['items'])) {
            $newFoodMenuQtys = $this->resolveFoodMenuQtys($order, $validated['items']);
            if (! empty($newFoodMenuQtys)) {
                $this->dispatchPrintChanges($order, $newFoodMenuQtys, []);
            }
            $this->saveChangeHistory(
                $order->id,
                null,
                'kot_reprinted',
                null,
                null,
                null,
                $request->user()?->id,
                'Kitchen ticket reprint requested for selected items only',
            );
        } else {
            $this->saveChangeHistory(
                $order->id,
                null,
                'kot_reprinted',
                null,
                null,
                null,
                $request->user()?->id,
                'Kitchen ticket reprint requested',
            );
            $this->dispatchPrint($order, true);
        }

        return response()->json(['order' => $order, 'message' => 'Reprint sent successfully.']);
    }

    private function resolveFoodMenuQtys(Order $order, array $items): array
    {
        $newFoodMenuQtys = [];
        foreach ($items as $itemData) {
            $itemType = $itemData['item_type'] ?? 'food_menu';
            if ($itemType === 'food_menu' || $itemType === 'product') {
                $itemName = 'Item';
                $printerId = null;
                if ($itemType === 'food_menu') {
                    $menu = FoodMenu::query()->find($itemData['item_id']);
                    $itemName = $menu?->name ?? 'Item';
                    $printerId = $menu?->printer_id;
                } else {
                    $product = Product::query()->find($itemData['item_id']);
                    $itemName = $product?->name ?? 'Item';
                    $printerId = $product?->printer_id;
                }
                $newFoodMenuQtys[] = [
                    'item_id' => $itemData['item_id'],
                    'item_name' => $itemName,
                    'additional_qty' => (float) $itemData['qty'],
                    'modifiers' => $itemData['modifiers'] ?? [],
                    'note' => $itemData['item_note'] ?? '',
                    'printer_id' => $printerId,
                ];
            } elseif ($itemType === 'combo') {
                $combo = ComboMenu::query()->with('items')->find($itemData['item_id']);
                if ($combo) {
                    foreach ($combo->items as $comp) {
                        $compName = 'Component';
                        $printerId = null;
                        if ($comp->item_type === 'food_menu') {
                            $compItem = FoodMenu::query()->find($comp->item_id);
                            $compName = $compItem?->name ?? 'Component';
                            $printerId = $compItem?->printer_id;
                        } else {
                            $compItem = Product::query()->find($comp->item_id);
                            $compName = $compItem?->name ?? 'Component';
                            $printerId = $compItem?->printer_id;
                        }
                        $newFoodMenuQtys[] = [
                            'item_id' => $comp->item_id,
                            'item_name' => $compName,
                            'additional_qty' => (float) $comp->qty * (float) $itemData['qty'],
                            'modifiers' => [],
                            'note' => "From: {$combo->name}",
                            'printer_id' => $printerId,
                        ];
                    }
                }
            }
        }
        return $newFoodMenuQtys;
    }

    public function updateAdjustments(Request $request, int $id): JsonResponse
    {
        $before = Order::query()->findOrFail($id);
        $oldValues = [
            'discount_type' => $before->order_discount_type,
            'discount_value' => $before->order_discount_value,
            'discount_amount' => $before->order_discount_amount,
            'tax_rate' => $before->tax_rate_snapshot,
            'tax_amount' => $before->tax_amount,
            'service_charge_rate' => $before->service_charge_rate_snapshot,
            'service_charge_amount' => $before->service_charge_amount,
        ];

        $response = app(CashierPanelController::class)->updateCharges($request, $id);
        if ($response->getStatusCode() >= 300) {
            return $response;
        }

        $updated = Order::query()->findOrFail($id);
        $this->saveChangeHistory(
            $updated->id,
            null,
            'adjustments_updated',
            null,
            null,
            null,
            $request->user()?->id,
            'Tax, service charge, or discount updated',
            $oldValues,
            [
                'discount_type' => $updated->order_discount_type,
                'discount_value' => $updated->order_discount_value,
                'discount_amount' => $updated->order_discount_amount,
                'tax_rate' => $updated->tax_rate_snapshot,
                'tax_amount' => $updated->tax_amount,
                'service_charge_rate' => $updated->service_charge_rate_snapshot,
                'service_charge_amount' => $updated->service_charge_amount,
            ],
        );

        return $response;
    }

    public function reservations(Request $request): JsonResponse
    {
        $query = Reservation::query()->with(['table:id,table_no,floor_id,status', 'floor:id,name', 'outlet:id,name']);

        if ($request->filled('outlet_id')) {
            $query->where('outlet_id', $request->outlet_id);
        }
        if ($request->filled('date')) {
            $query->where('reservation_date', $request->date);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $reservations = $query->orderBy('reservation_date', 'desc')->orderBy('checkin_time', 'desc')->get();

        return response()->json(['reservations' => $reservations]);
    }

    public function markReservationArrived(Request $request, int $id): JsonResponse
    {
        $reservation = Reservation::query()->with(['table:id,table_no,floor_id,status'])->findOrFail($id);

        if (! in_array($reservation->status, ['pending', 'confirmed'], true)) {
            return response()->json(['message' => 'Only pending or confirmed reservations can be marked arrived.'], 422);
        }

        $reservation->update([
            'status' => 'arrived',
            'updated_by' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'Reservation marked as arrived.',
            'reservation' => $reservation->fresh()->load(['table:id,table_no,floor_id,status', 'floor:id,name', 'outlet:id,name']),
        ]);
    }

    public function seatReservation(Request $request, int $id): JsonResponse
    {
        $reservation = Reservation::query()->with(['table'])->findOrFail($id);

        if (! in_array($reservation->status, ['confirmed', 'arrived'], true)) {
            return response()->json(['message' => 'Reservation must be confirmed or arrived before seating.'], 422);
        }

        if ($reservation->table && $reservation->table->status === 'occupied') {
            return response()->json(['message' => 'Table is already occupied.'], 422);
        }

        $user = $request->user();

        return DB::transaction(function () use ($reservation, $user) {
            $reservation->update(['status' => 'seated']);

            RestaurantTable::query()->where('id', $reservation->table_id)->update(['status' => 'occupied']);

            $orderType = 'dine_in';
            $orderNo = $this->generateOrderNo($orderType, $reservation->outlet_id);
            $taxRate = TaxRate::query()->where('is_active', true)->where('type', 'percentage')->first();
            $charge = Charge::query()->where('is_active', true)
                ->where(function ($q) {
                    $q->where('apply_to', 'dinein')->orWhere('apply_to', 'all');
                })->first();

            $order = Order::query()->create([
                'order_no' => $orderNo,
                'outlet_id' => $reservation->outlet_id,
                'order_type' => $orderType,
                'floor_id' => $reservation->floor_id,
                'table_id' => $reservation->table_id,
                'pax' => $reservation->guest_count,
                'customer_name' => $reservation->customer_name,
                'customer_phone' => $reservation->customer_phone,
                'order_note' => $reservation->special_request ?? null,
                'created_by' => $user?->id,
                'order_status' => 'pending',
                'confirmation_status' => 'confirmed',
                'confirmed_at' => Carbon::now(),
                'confirmed_by' => $user?->id,
                'print_status' => 'not_printed',
                'stock_deduction_status' => 'none',
            ]);

            $preOrderItems = $reservation->preorder_items ?? [];
            if (! empty($preOrderItems)) {
                $subtotal = 0;
                $itemDiscountTotal = 0;
                $totalCost = 0;

                foreach ($preOrderItems as $preItem) {
                    $itemData = [
                        'item_type' => $preItem['item_type'] ?? 'food_menu',
                        'item_id' => $preItem['item_id'],
                        'qty' => $preItem['qty'] ?? 1,
                        'discount_type' => null,
                        'discount_value' => 0,
                        'item_note' => $preItem['note'] ?? null,
                        'modifiers' => $preItem['modifiers'] ?? [],
                    ];

                    $itemResult = $this->processOrderItem($itemData, $orderType, $reservation->outlet_id);
                    $itemResult['order_id'] = $order->id;
                    $itemResult['original_qty'] = $itemResult['qty'];
                    $itemResult['active_qty'] = $itemResult['qty'];
                    $itemResult['cancelled_qty'] = 0;
                    $itemResult['printed_qty'] = $itemResult['qty'];
                    $itemResult['cancelled_printed_qty'] = 0;

                    $orderItem = OrderItem::query()->create($itemResult);
                    $subtotal += $orderItem->amount;
                    $itemDiscountTotal += $orderItem->discount_amount;
                    $totalCost += ($orderItem->cost_snapshot ?? 0) * $orderItem->qty;

                    if (! empty($itemData['modifiers'])) {
                        $sortOrder = 0;
                        foreach ($itemData['modifiers'] as $mod) {
                            $modGroup = Modifier::query()->find($mod['modifier_group_id']);
                            $optionName = '';
                            $adjustment = 0;
                            if ($modGroup && $modGroup->options) {
                                foreach ($modGroup->options as $optionIndex => $opt) {
                                    if ((int) ($opt['id'] ?? ($optionIndex + 1)) === (int) $mod['modifier_item_id']) {
                                        $optionName = $opt['name'] ?? '';
                                        $adjustment = (float) ($opt['price'] ?? 0);
                                        break;
                                    }
                                }
                            }

                            OrderItemModifier::query()->create([
                                'order_item_id' => $orderItem->id,
                                'modifier_group_id' => $mod['modifier_group_id'],
                                'modifier_group_name_snapshot' => $modGroup?->name ?? '',
                                'modifier_item_id' => $mod['modifier_item_id'],
                                'modifier_item_name_snapshot' => $optionName,
                                'price_adjustment_snapshot' => $adjustment,
                                'sort_order' => $sortOrder++,
                            ]);
                        }
                    }
                }

                $taxableAmount = $subtotal;
                $taxAmount = $taxRate && $taxRate->value > 0 ? round($taxableAmount * $taxRate->value / 100, 2) : 0;
                $serviceChargeAmount = 0;
                if ($charge && $charge->value > 0) {
                    $serviceChargeAmount = $charge->type === 'percentage'
                        ? round($taxableAmount * $charge->value / 100, 2)
                        : (float) $charge->value;
                }

                $grandTotal = round($subtotal + $taxAmount + $serviceChargeAmount, 2);

                $order->update([
                    'subtotal' => $subtotal,
                    'item_discount_amount' => $itemDiscountTotal,
                    'tax_rate_snapshot' => $taxRate?->value ?? 0,
                    'tax_amount' => $taxAmount,
                    'service_charge_rate_snapshot' => $charge?->value ?? 0,
                    'service_charge_amount' => $serviceChargeAmount,
                    'grand_total' => $grandTotal,
                    'balance_amount' => $grandTotal,
                ]);

                $this->printOrder($order);
            }

            return response()->json([
                'message' => 'Customer seated successfully.',
                'reservation' => $reservation->fresh(),
                'order' => isset($order) ? $order->fresh()->load(['items.modifiers', 'items.comboComponents']) : null,
            ]);
        });
    }

    public function addItems(Request $request, int $id): JsonResponse
    {
        $order = Order::query()->findOrFail($id);

        if (in_array($order->order_status, ['completed', 'cancelled'])) {
            return response()->json(['message' => 'Cannot add items to a completed or cancelled order.'], 422);
        }

        if ($order->payment_state === 'paid' || $order->payment_state === 'refunded') {
            return response()->json(['message' => 'Cannot add items to a paid order.'], 422);
        }

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.item_type' => 'required|in:food_menu,product,combo',
            'items.*.item_id' => 'required|integer',
            'items.*.qty' => 'required|numeric|min:0.01',
            'items.*.discount_type' => 'nullable|in:fixed,percentage',
            'items.*.discount_value' => 'nullable|numeric|min:0',
            'items.*.item_note' => 'nullable|string',
            'items.*.modifiers' => 'nullable|array',
            'items.*.modifiers.*.modifier_group_id' => 'nullable|integer|exists:modifiers,id',
            'items.*.modifiers.*.modifier_item_id' => 'nullable|integer',
            'idempotency_key' => 'nullable|string|max:100',
        ]);

        $user = $request->user();

        return DB::transaction(function () use ($order, $validated, $user) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if (in_array($order->order_status, ['completed', 'cancelled'])) {
                return response()->json(['message' => 'Order was modified by another user.'], 409);
            }

            $subtotal = (float) $order->subtotal;
            $itemDiscountTotal = (float) $order->item_discount_amount;
            $totalCost = 0;
            $newFoodMenuQtys = [];
            $stockItems = [];

            foreach ($validated['items'] as $itemData) {
                $this->validateItemModifiers($itemData);

                // Check for identical existing line to merge
                $existingItem = $this->findIdenticalOrderItem($order, $itemData);

                if ($existingItem) {
                    $oldQty = (float) $existingItem->qty;
                    $newQty = $oldQty + (float) $itemData['qty'];
                    $itemResult = $this->processOrderItem($itemData, $order->order_type, $order->outlet_id);

                    $existingItem->update([
                        'qty' => $newQty,
                        'original_qty' => (float) $existingItem->original_qty + (float) $itemData['qty'],
                        'active_qty' => $newQty,
                        'amount' => ($existingItem->final_unit_price * $newQty) - $existingItem->discount_amount,
                    ]);

                    if ($order->confirmation_status === 'confirmed' &&
                        in_array($itemData['item_type'], ['food_menu', 'product', 'combo'])) {
                        $existingItem->increment('printed_qty', (float) $itemData['qty']);
                    }

                    $subtotal += ($itemResult['final_unit_price'] * (float) $itemData['qty']) - $itemResult['discount_amount'];
                    $totalCost += ($itemResult['cost_snapshot'] ?? 0) * (float) $itemData['qty'];

                    if ($itemData['item_type'] === 'food_menu' || $itemData['item_type'] === 'product') {
                        $newFoodMenuQtys[] = [
                            'item_id' => $itemData['item_id'],
                            'item_name' => $existingItem->item_name_snapshot,
                            'additional_qty' => (float) $itemData['qty'],
                            'modifiers' => $itemData['modifiers'] ?? [],
                            'note' => $itemData['item_note'] ?? '',
                            'printer_id' => $this->getItemPrinterId($itemData['item_type'], (int) $itemData['item_id']),
                        ];
                    } elseif ($itemData['item_type'] === 'combo') {
                        $components = OrderComboComponent::query()->where('order_item_id', $existingItem->id)->get();
                        foreach ($components as $comp) {
                            $additionalTotalQty = (float) $comp->qty_per_combo * (float) $itemData['qty'];
                            $comp->update([
                                'ordered_combo_qty' => $comp->ordered_combo_qty + (float) $itemData['qty'],
                                'total_qty' => $comp->total_qty + $additionalTotalQty,
                            ]);

                            $newFoodMenuQtys[] = [
                                'item_id' => $comp->item_id,
                                'item_name' => $comp->item_name_snapshot,
                                'additional_qty' => $additionalTotalQty,
                                'modifiers' => [],
                                'note' => "From: {$existingItem->item_name_snapshot}",
                                'printer_id' => $comp->printer_id_snapshot,
                            ];
                        }
                    }

                    $stockItems[] = array_merge($itemData, [
                        'order_item_id' => $existingItem->id,
                    ]);

                    $this->saveChangeHistory($order->id, $existingItem->id, 'item_qty_increased',
                        $oldQty, $newQty, $newQty - $oldQty, $user?->id);
                } else {
                    $itemResult = $this->processOrderItem($itemData, $order->order_type, $order->outlet_id);
                    $itemResult['order_id'] = $order->id;
                    $itemResult['original_qty'] = $itemResult['qty'];
                    $itemResult['active_qty'] = $itemResult['qty'];
                    $itemResult['cancelled_qty'] = 0;
                    $itemResult['printed_qty'] =
                        $order->confirmation_status === 'confirmed' &&
                        in_array($itemData['item_type'], ['food_menu', 'product', 'combo'])
                            ? $itemResult['qty']
                            : 0;
                    $itemResult['cancelled_printed_qty'] = 0;

                    $orderItem = OrderItem::query()->create($itemResult);
                    $subtotal += $orderItem->amount;
                    $itemDiscountTotal += $orderItem->discount_amount;
                    $totalCost += ($orderItem->cost_snapshot ?? 0) * $orderItem->qty;

                    if ($itemData['item_type'] === 'food_menu' || $itemData['item_type'] === 'product') {
                        $newFoodMenuQtys[] = [
                            'item_id' => $itemData['item_id'],
                            'item_name' => $orderItem->item_name_snapshot,
                            'additional_qty' => (float) $itemData['qty'],
                            'modifiers' => $itemData['modifiers'] ?? [],
                            'note' => $itemData['item_note'] ?? '',
                            'printer_id' => $this->getItemPrinterId($itemData['item_type'], (int) $itemData['item_id']),
                        ];
                    }

                    // Save modifiers
                    if (! empty($itemData['modifiers'])) {
                        $sortOrder = 0;
                        foreach ($itemData['modifiers'] as $mod) {
                            $modGroup = Modifier::query()->find($mod['modifier_group_id']);
                            $optionName = '';
                            $adjustment = 0;
                            if ($modGroup && $modGroup->options) {
                                foreach ($modGroup->options as $optionIndex => $opt) {
                                    if ((int) ($opt['id'] ?? ($optionIndex + 1)) === (int) $mod['modifier_item_id']) {
                                        $optionName = $opt['name'] ?? '';
                                        $adjustment = (float) ($opt['price'] ?? 0);
                                        break;
                                    }
                                }
                            }

                            OrderItemModifier::query()->create([
                                'order_item_id' => $orderItem->id,
                                'modifier_group_id' => $mod['modifier_group_id'],
                                'modifier_group_name_snapshot' => $modGroup?->name ?? '',
                                'modifier_item_id' => $mod['modifier_item_id'],
                                'modifier_item_name_snapshot' => $optionName,
                                'price_adjustment_snapshot' => $adjustment,
                                'sort_order' => $sortOrder++,
                            ]);
                        }
                    }

                    // Save combo components
                    if ($itemData['item_type'] === 'combo') {
                        $combo = ComboMenu::query()->with('items')->find($itemData['item_id']);
                        if ($combo) {
                            foreach ($combo->items as $comp) {
                                $compItem = null;
                                $compName = '';
                                $unitName = '';
                                $costSnap = 0;
                                $printerId = null;

                                if ($comp->item_type === 'food_menu') {
                                    $compItem = FoodMenu::query()->with('unit')->find($comp->item_id);
                                    if ($compItem) {
                                        $compName = $compItem->name;
                                        $unitName = $compItem->unit?->name ?? '';
                                        $costSnap = $compItem->cost_per_unit ?? 0;
                                        $printerId = $compItem->printer_id;
                                    }
                                } else {
                                    $compItem = Product::query()->with('productUnit')->find($comp->item_id);
                                    if ($compItem) {
                                        $compName = $compItem->name;
                                        $unitName = $compItem->productUnit?->name ?? '';
                                        $costSnap = $compItem->purchase_price_per_unit ?? 0;
                                        $printerId = $compItem->printer_id;
                                    }
                                }

                                if ($compItem) {
                                    $newFoodMenuQtys[] = [
                                        'item_id' => $comp->item_id,
                                        'item_name' => $compName,
                                        'additional_qty' => (float) $comp->qty * (float) $itemData['qty'],
                                        'modifiers' => [],
                                        'note' => "From: {$combo->name}",
                                        'printer_id' => $printerId,
                                    ];
                                }

                                OrderComboComponent::query()->create([
                                    'order_item_id' => $orderItem->id,
                                    'item_type' => $comp->item_type,
                                    'item_id' => $comp->item_id,
                                    'item_name_snapshot' => $compName,
                                    'qty_per_combo' => $comp->qty,
                                    'ordered_combo_qty' => $itemData['qty'],
                                    'total_qty' => $comp->qty * $itemData['qty'],
                                    'unit_name_snapshot' => $unitName,
                                    'cost_snapshot' => $costSnap,
                                    'printer_id_snapshot' => $printerId,
                                ]);
                            }
                        }
                    }

                    $stockItems[] = array_merge($itemData, [
                        'order_item_id' => $orderItem->id,
                    ]);

                    $this->saveChangeHistory($order->id, $orderItem->id, 'item_added',
                        null, $itemData['qty'], $itemData['qty'], $user?->id);
                }
            }

            // Recalculate order totals
            $taxRate = TaxRate::query()->where('is_active', true)->where('type', 'percentage')->first();
            $chargeType = $order->order_type === 'dine_in' ? 'dinein' : $order->order_type;
            $charge = Charge::query()->where('is_active', true)
                ->where(function ($q) use ($chargeType) {
                    $q->where('apply_to', $chargeType)->orWhere('apply_to', 'all');
                })->first();

            $taxableAmount = $subtotal - (float) $order->order_discount_amount;
            $taxAmount = $taxRate && $taxRate->value > 0 ? round($taxableAmount * $taxRate->value / 100, 2) : 0;
            $serviceChargeAmount = 0;
            if ($charge && $charge->value > 0) {
                $serviceChargeAmount = $charge->type === 'percentage'
                    ? round($taxableAmount * $charge->value / 100, 2)
                    : (float) $charge->value;
            }

            $deliveryFee = $order->order_type === 'delivery' ? (float) $order->delivery_fee : 0;
            $grandTotal = round($subtotal - (float) $order->order_discount_amount + $taxAmount + $serviceChargeAmount + $deliveryFee, 2);

            $order->update([
                'subtotal' => $subtotal,
                'item_discount_amount' => $itemDiscountTotal,
                'tax_rate_snapshot' => $taxRate?->value ?? 0,
                'tax_amount' => $taxAmount,
                'service_charge_rate_snapshot' => $charge?->value ?? 0,
                'service_charge_amount' => $serviceChargeAmount,
                'grand_total' => $grandTotal,
                'balance_amount' => max(0, $grandTotal - (float) $order->paid_amount),
                'version_number' => (int) $order->version_number + 1,
            ]);

            if ($order->confirmation_status !== 'confirmed') {
                $order->update(['confirmation_status' => 'confirmed']);
            }

            if ($order->stock_deduction_status === 'deducted') {
                $this->deductItemsStock($order, (int) $order->outlet_id, $stockItems);
            } else {
                $this->deductOrderStock($order, (int) $order->outlet_id);
            }

            // Print KOT for new items
            if (! empty($newFoodMenuQtys)) {
                $this->dispatchPrintChanges($order, $newFoodMenuQtys, []);
            }

            $order->load(['items.modifiers', 'items.comboComponents', 'floor:id,name', 'table:id,table_no', 'outlet:id,name', 'createdBy:id,name']);

            return response()->json(['order' => $order, 'message' => 'Items added successfully.']);
        });
    }

    public function cancelItem(Request $request, int $id, int $itemId): JsonResponse
    {
        $validated = $request->validate([
            'cancellation_reason' => 'required|string|max:1000',
            'cancel_qty' => 'nullable|numeric|min:0.01',
        ]);

        $order = Order::query()->findOrFail($id);

        if (in_array($order->order_status, ['completed', 'cancelled'])) {
            return response()->json(['message' => 'Cannot cancel items from a completed or cancelled order.'], 422);
        }

        if ($order->payment_state === 'paid' || $order->payment_state === 'refunded') {
            return response()->json(['message' => 'Cannot cancel items from a paid order. Use refund or void.'], 422);
        }

        $orderItem = OrderItem::query()->where('order_id', $order->id)->findOrFail($itemId);
        $user = $request->user();

        return DB::transaction(function () use ($order, $orderItem, $validated, $user) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $orderItem = OrderItem::query()->where('order_id', $order->id)->lockForUpdate()->findOrFail($orderItem->id);

            $cancelQty = $validated['cancel_qty'] ?? (float) $orderItem->active_qty;
            $oldActiveQty = (float) $orderItem->active_qty;

            if ($cancelQty > $oldActiveQty) {
                return response()->json(['message' => 'Cancel quantity exceeds available quantity.'], 422);
            }

            if ($cancelQty <= 0) {
                return response()->json(['message' => 'Cancel quantity must be greater than zero.'], 422);
            }

            $newActiveQty = $oldActiveQty - $cancelQty;
            $newCancelledQty = (float) $orderItem->cancelled_qty + $cancelQty;
            $cancelProportion = $cancelQty / $oldActiveQty;
            $cancelledAmount = round((float) $orderItem->amount * $cancelProportion, 2);
            $cancelledDiscount = round((float) $orderItem->discount_amount * $cancelProportion, 2);
            $remainingAmount = max(0, round((float) $orderItem->amount - $cancelledAmount, 2));
            $remainingDiscount = max(0, round((float) $orderItem->discount_amount - $cancelledDiscount, 2));

            $orderItem->update([
                'active_qty' => $newActiveQty,
                'cancelled_qty' => $newCancelledQty,
                'qty' => $newActiveQty,
                'amount' => $remainingAmount,
                'discount_amount' => $remainingDiscount,
            ]);

            // If this was a food menu item, track the cancelled print qty
            $cancelledFoodMenuQtys = [];
            if ($orderItem->item_type === 'food_menu' || $orderItem->item_type === 'product') {
                if ($order->confirmation_status === 'confirmed') {
                    $orderItem->increment('cancelled_printed_qty', $cancelQty);
                }
                $cancelledFoodMenuQtys[] = [
                    'item_name' => $orderItem->item_name_snapshot,
                    'cancelled_qty' => $cancelQty,
                    'printer_id' => $this->getItemPrinterId($orderItem->item_type, $orderItem->item_id),
                ];
            } elseif ($orderItem->item_type === 'combo') {
                $components = OrderComboComponent::query()
                    ->where('order_item_id', $orderItem->id)
                    ->get();
                foreach ($components as $comp) {
                    $compCancelQty = $cancelQty * (float) $comp->qty_per_combo;
                    $cancelledFoodMenuQtys[] = [
                        'item_name' => $comp->item_name_snapshot,
                        'cancelled_qty' => $compCancelQty,
                        'printer_id' => $comp->printer_id_snapshot,
                    ];
                }
            }

            // Recalculate order totals
            $newSubtotal = max(0, (float) $order->subtotal - $cancelledAmount);

            $taxRate = TaxRate::query()->where('is_active', true)->where('type', 'percentage')->first();
            $chargeType = $order->order_type === 'dine_in' ? 'dinein' : $order->order_type;
            $charge = Charge::query()->where('is_active', true)
                ->where(function ($q) use ($chargeType) {
                    $q->where('apply_to', $chargeType)->orWhere('apply_to', 'all');
                })->first();

            $taxableAmount = $newSubtotal - (float) $order->order_discount_amount;
            $taxAmount = $taxRate && $taxRate->value > 0 ? round($taxableAmount * $taxRate->value / 100, 2) : 0;
            $serviceChargeAmount = 0;
            if ($charge && $charge->value > 0) {
                $serviceChargeAmount = $charge->type === 'percentage'
                    ? round($taxableAmount * $charge->value / 100, 2)
                    : (float) $charge->value;
            }

            $deliveryFee = $order->order_type === 'delivery' ? (float) $order->delivery_fee : 0;
            $grandTotal = round($newSubtotal - (float) $order->order_discount_amount + $taxAmount + $serviceChargeAmount + $deliveryFee, 2);

            $order->update([
                'subtotal' => $newSubtotal,
                'item_discount_amount' => max(
                    0,
                    (float) $order->item_discount_amount - $cancelledDiscount,
                ),
                'tax_amount' => $taxAmount,
                'service_charge_amount' => $serviceChargeAmount,
                'grand_total' => $grandTotal,
                'balance_amount' => max(0, $grandTotal - (float) $order->paid_amount),
                'version_number' => (int) $order->version_number + 1,
            ]);

            $hasActiveItems = OrderItem::query()
                ->where('order_id', $order->id)
                ->where('active_qty', '>', 0)
                ->exists();

            if (! $hasActiveItems) {
                $order->update([
                    'order_status' => 'cancelled',
                    'cancelled_at' => Carbon::now(),
                    'cancelled_by' => $user?->id,
                    'cancellation_reason' => $validated['cancellation_reason'],
                ]);
                $this->releaseDineInTableIfNoActiveOrders($order);
            }

            // Reverse stock for cancelled quantity
            if ($order->stock_deduction_status === 'deducted') {
                $this->reverseItemsStock($order, [[
                    'item_type' => $orderItem->item_type,
                    'item_id' => $orderItem->item_id,
                    'order_item_id' => $orderItem->id,
                    'qty' => $cancelQty,
                ]]);
            }

            $this->saveChangeHistory($order->id, $orderItem->id, 'item_qty_reduced',
                $oldActiveQty, $newActiveQty, $cancelQty, $user?->id, $validated['cancellation_reason']);

            // Print cancellation
            if (! empty($cancelledFoodMenuQtys)) {
                $this->dispatchPrintChanges($order, [], $cancelledFoodMenuQtys);
            }

            $order->load(['items.modifiers', 'items.comboComponents', 'floor:id,name', 'table:id,table_no', 'outlet:id,name', 'createdBy:id,name']);

            return response()->json(['order' => $order, 'message' => 'Item cancelled successfully.']);
        });
    }

    public function splitData(int $id): JsonResponse
    {
        $order = Order::query()
            ->with(['items.modifiers', 'items.comboComponents', 'floor:id,name', 'table:id,table_no', 'outlet:id,name', 'createdBy:id,name'])
            ->findOrFail($id);

        if (in_array($order->order_status, ['completed', 'cancelled'])) {
            return response()->json(['message' => 'Cannot split a completed or cancelled order.'], 422);
        }

        if ($order->payment_state === 'paid') {
            return response()->json(['message' => 'Cannot split a paid order.'], 422);
        }

        $splittableItems = $order->items->filter(fn ($item) => (float) $item->active_qty > 0)->values();

        if ($splittableItems->count() < 1) {
            return response()->json(['message' => 'No items available to split.'], 422);
        }

        return response()->json([
            'order' => $order,
            'splittable_items' => $splittableItems,
        ]);
    }

    public function splitOrder(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.order_item_id' => 'required|integer|distinct|exists:order_items,id',
            'items.*.move_qty' => 'required|numeric|min:0.01',
            'target_table_id' => 'nullable|integer|exists:restaurant_tables,id',
            'note' => 'nullable|string|max:500',
            'idempotency_key' => 'nullable|string|max:100',
        ]);

        $sourceOrder = Order::query()->with(['items.modifiers', 'items.comboComponents'])->findOrFail($id);

        if (in_array($sourceOrder->order_status, ['completed', 'cancelled'])) {
            return response()->json(['message' => 'Cannot split a completed or cancelled order.'], 422);
        }

        if ($sourceOrder->payment_state !== 'unpaid') {
            return response()->json(['message' => 'Partially paid orders cannot be split. Complete or reverse the payment first.'], 422);
        }

        if ($sourceOrder->order_type === 'delivery') {
            return response()->json(['message' => 'Delivery orders cannot be split after payment.'], 422);
        }

        $user = $request->user();

        // Validate target table if provided
        $targetTableId = $validated['target_table_id'] ?? null;
        if ($targetTableId !== null) {
            $targetTable = RestaurantTable::query()->findOrFail($targetTableId);
            if (! $targetTable->is_active) {
                return response()->json(['message' => 'Target table is not active.'], 422);
            }
            if ($sourceOrder->table && $targetTable->floor_id !== $sourceOrder->floor_id) {
                return response()->json(['message' => 'Target table must be on the same floor.'], 422);
            }
            // Check target table doesn't already have an active order
            $hasActiveOrder = Order::query()
                ->where('table_id', $targetTableId)
                ->whereNotIn('order_status', ['completed', 'cancelled'])
                ->where('payment_state', '!=', 'paid')
                ->exists();
            if ($hasActiveOrder) {
                return response()->json(['message' => 'Target table already has an active order. Please choose another table.'], 422);
            }
        }

        return DB::transaction(function () use ($sourceOrder, $validated, $user, $targetTableId) {
            $sourceOrder = Order::query()->lockForUpdate()->findOrFail($sourceOrder->id);

            $requestedItemIds = collect($validated['items'])
                ->pluck('order_item_id')
                ->map(fn ($itemId) => (int) $itemId);
            $sourceItems = OrderItem::query()
                ->where('order_id', $sourceOrder->id)
                ->whereIn('id', $requestedItemIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($sourceItems->count() !== $requestedItemIds->count()) {
                throw ValidationException::withMessages([
                    'items' => 'Every split item must belong to the source order.',
                ]);
            }

            $totalActiveQty = (float) OrderItem::query()
                ->where('order_id', $sourceOrder->id)
                ->sum('active_qty');
            $totalMoveQty = (float) collect($validated['items'])->sum('move_qty');
            if ($totalMoveQty >= $totalActiveQty) {
                throw ValidationException::withMessages([
                    'items' => 'Keep at least one item in the original order.',
                ]);
            }

            $splitGroupId = $sourceOrder->split_group_id ?: (string) Str::uuid();
            $seq = ((int) Order::query()
                ->where('split_group_id', $splitGroupId)
                ->max('split_sequence')) + 1;

            $orderNoPrefix = match ($sourceOrder->order_type) {
                'dine_in' => 'DN',
                'takeaway' => 'TA',
                'delivery' => 'DV',
                default => 'OR',
            };
            $date = Carbon::now()->format('Ymd');
            $lastOrder = Order::query()->where('order_no', 'like', "{$orderNoPrefix}{$date}%")
                ->orderBy('id', 'desc')->first();
            $seqNum = $lastOrder ? (int) substr($lastOrder->order_no, -4) + 1 : 1;
            $newOrderNo = "{$orderNoPrefix}{$date}-".str_pad((string) $seqNum, 4, '0', STR_PAD_LEFT);

            $taxRate = TaxRate::query()->where('is_active', true)->where('type', 'percentage')->first();
            $chargeType = $sourceOrder->order_type === 'dine_in' ? 'dinein' : $sourceOrder->order_type;
            $charge = Charge::query()->where('is_active', true)
                ->where(function ($q) use ($chargeType) {
                    $q->where('apply_to', $chargeType)->orWhere('apply_to', 'all');
                })->first();

            $targetOrder = Order::query()->create([
                'order_no' => $newOrderNo,
                'parent_order_id' => $sourceOrder->parent_order_id ?: $sourceOrder->id,
                'split_group_id' => $splitGroupId,
                'split_sequence' => $seq,
                'split_from_order_id' => $sourceOrder->id,
                'table_merge_group_id' => null,
                'outlet_id' => $sourceOrder->outlet_id,
                'order_type' => $sourceOrder->order_type,
                'floor_id' => $targetTableId ? RestaurantTable::find($targetTableId)?->floor_id : $sourceOrder->floor_id,
                'table_id' => $targetTableId ?? $sourceOrder->table_id,
                'pax' => $sourceOrder->pax,
                'customer_name' => $sourceOrder->customer_name,
                'customer_phone' => $sourceOrder->customer_phone,
                'order_note' => $sourceOrder->order_note,
                'created_by' => $user?->id,
                'order_status' => 'pending',
                'confirmation_status' => $sourceOrder->confirmation_status,
                'confirmed_at' => $sourceOrder->confirmed_at,
                'confirmed_by' => $sourceOrder->confirmed_by,
                'print_status' => 'not_printed',
                'stock_deduction_status' => $sourceOrder->stock_deduction_status,
            ]);

            // Update source order split info
            $sourceOrder->update([
                'split_group_id' => $splitGroupId,
                'split_sequence' => 0,
                'version_number' => (int) $sourceOrder->version_number + 1,
            ]);

            $targetSubtotal = 0;
            $targetItemDiscount = 0;
            $sourceSubtotalReduction = 0;
            $splitItemRows = [];

            foreach ($validated['items'] as $splitItem) {
                $sourceItem = $sourceItems->get((int) $splitItem['order_item_id']);
                $moveQty = (float) $splitItem['move_qty'];
                $currentActiveQty = (float) $sourceItem->active_qty;

                if ($moveQty > $currentActiveQty) {
                    throw ValidationException::withMessages([
                        'items' => "Move quantity ({$moveQty}) exceeds available quantity ({$currentActiveQty}).",
                    ]);
                }

                $newSourceActiveQty = $currentActiveQty - $moveQty;

                // Calculate amounts for the moved portion
                $proportion = $moveQty / ($currentActiveQty ?: 1);
                $movedAmount = round((float) $sourceItem->amount * $proportion, 2);
                $movedDiscount = round((float) $sourceItem->discount_amount * $proportion, 2);
                $remainingAmount = round((float) $sourceItem->amount - $movedAmount, 2);
                $remainingDiscount = round((float) $sourceItem->discount_amount - $movedDiscount, 2);

                // Create target order item (clone with snapshots)
                $targetItem = OrderItem::query()->create([
                    'order_id' => $targetOrder->id,
                    'item_type' => $sourceItem->item_type,
                    'item_id' => $sourceItem->item_id,
                    'item_name_snapshot' => $sourceItem->item_name_snapshot,
                    'unit_name_snapshot' => $sourceItem->unit_name_snapshot,
                    'qty' => $moveQty,
                    'original_qty' => $moveQty,
                    'active_qty' => $moveQty,
                    'cancelled_qty' => 0,
                    'printed_qty' => $sourceOrder->confirmation_status === 'confirmed' ? $moveQty : 0,
                    'cancelled_printed_qty' => 0,
                    'base_unit_price_snapshot' => $sourceItem->base_unit_price_snapshot,
                    'modifier_price' => $sourceItem->modifier_price,
                    'final_unit_price' => $sourceItem->final_unit_price,
                    'discount_type' => $sourceItem->discount_type,
                    'discount_value' => $sourceItem->discount_value,
                    'discount_amount' => $movedDiscount,
                    'amount' => $movedAmount,
                    'item_note' => $sourceItem->item_note,
                    'cost_snapshot' => $sourceItem->cost_snapshot,
                ]);

                // Clone modifiers
                $sourceModifiers = OrderItemModifier::query()->where('order_item_id', $sourceItem->id)->get();
                foreach ($sourceModifiers as $mod) {
                    OrderItemModifier::query()->create([
                        'order_item_id' => $targetItem->id,
                        'modifier_group_id' => $mod->modifier_group_id,
                        'modifier_group_name_snapshot' => $mod->modifier_group_name_snapshot,
                        'modifier_item_id' => $mod->modifier_item_id,
                        'modifier_item_name_snapshot' => $mod->modifier_item_name_snapshot,
                        'price_adjustment_snapshot' => $mod->price_adjustment_snapshot,
                        'sort_order' => $mod->sort_order,
                    ]);
                }

                // Clone combo components
                $sourceComponents = OrderComboComponent::query()->where('order_item_id', $sourceItem->id)->get();
                foreach ($sourceComponents as $comp) {
                    $compMoveQty = $moveQty * ((float) $comp->qty_per_combo / ((float) $sourceItem->original_qty ?: 1));
                    OrderComboComponent::query()->create([
                        'order_item_id' => $targetItem->id,
                        'item_type' => $comp->item_type,
                        'item_id' => $comp->item_id,
                        'item_name_snapshot' => $comp->item_name_snapshot,
                        'qty_per_combo' => $comp->qty_per_combo,
                        'ordered_combo_qty' => $moveQty,
                        'total_qty' => $comp->qty_per_combo * $moveQty,
                        'unit_name_snapshot' => $comp->unit_name_snapshot,
                        'cost_snapshot' => $comp->cost_snapshot,
                        'printer_id_snapshot' => $comp->printer_id_snapshot,
                    ]);
                }

                // Update source item (if fully moved, active_qty becomes 0 but item is kept for audit/split history)
                $sourceItem->update([
                    'qty' => $newSourceActiveQty,
                    'active_qty' => $newSourceActiveQty,
                    'amount' => $remainingAmount,
                    'discount_amount' => $remainingDiscount,
                ]);

                $targetSubtotal += $movedAmount;
                $targetItemDiscount += $movedDiscount;
                $sourceSubtotalReduction += $movedAmount;

                // Save split history item
                $splitItemRows[] = [
                    'source_order_item_id' => $sourceItem->id,
                    'target_order_item_id' => $targetItem->id,
                    'moved_qty' => $moveQty,
                    'amount' => $movedAmount,
                    'discount_amount' => $movedDiscount,
                ];
            }

            // Recalculate source order
            $newSourceSubtotal = max(0, (float) $sourceOrder->subtotal - $sourceSubtotalReduction);
            $sourceTaxable = $newSourceSubtotal - (float) $sourceOrder->order_discount_amount;
            $sourceTax = $taxRate && $taxRate->value > 0 ? round($sourceTaxable * $taxRate->value / 100, 2) : 0;
            $sourceCharge = 0;
            if ($charge && $charge->value > 0) {
                $sourceCharge = $charge->type === 'percentage'
                    ? round($sourceTaxable * $charge->value / 100, 2)
                    : (float) $charge->value;
            }
            $sourceGrandTotal = round($newSourceSubtotal - (float) $sourceOrder->order_discount_amount + $sourceTax + $sourceCharge, 2);

            $sourceOrder->update([
                'subtotal' => $newSourceSubtotal,
                'item_discount_amount' => max(0, (float) $sourceOrder->item_discount_amount - $targetItemDiscount),
                'tax_amount' => $sourceTax,
                'service_charge_amount' => $sourceCharge,
                'grand_total' => $sourceGrandTotal,
                'balance_amount' => max(0, $sourceGrandTotal - (float) $sourceOrder->paid_amount),
                'version_number' => (int) $sourceOrder->version_number + 1,
            ]);

            // Recalculate target order
            $targetTaxable = $targetSubtotal;
            $targetTax = $taxRate && $taxRate->value > 0 ? round($targetTaxable * $taxRate->value / 100, 2) : 0;
            $targetCharge = 0;
            if ($charge && $charge->value > 0) {
                $targetCharge = $charge->type === 'percentage'
                    ? round($targetTaxable * $charge->value / 100, 2)
                    : (float) $charge->value;
            }
            $targetGrandTotal = round($targetSubtotal + $targetTax + $targetCharge, 2);

            $targetOrder->update([
                'subtotal' => $targetSubtotal,
                'item_discount_amount' => $targetItemDiscount,
                'tax_rate_snapshot' => $taxRate?->value ?? 0,
                'tax_amount' => $targetTax,
                'service_charge_rate_snapshot' => $charge?->value ?? 0,
                'service_charge_amount' => $targetCharge,
                'grand_total' => $targetGrandTotal,
                'balance_amount' => $targetGrandTotal,
                'version_number' => 1,
            ]);

            // Save split history
            $splitHistory = OrderSplitHistory::query()->create([
                'source_order_id' => $sourceOrder->id,
                'target_order_id' => $targetOrder->id,
                'split_group_id' => $splitGroupId,
                'split_by' => $user?->id,
                'split_at' => Carbon::now(),
                'note' => $validated['note'] ?? null,
            ]);

            foreach ($splitItemRows as $splitItemRow) {
                OrderSplitItem::query()->create([
                    'split_history_id' => $splitHistory->id,
                    ...$splitItemRow,
                ]);
            }

            $this->saveChangeHistory($sourceOrder->id, null, 'order_split',
                null, null, null, $user?->id, "Split to order {$targetOrder->order_no}");

            // Print KOT for split order if source was already confirmed
            if ($sourceOrder->confirmation_status === 'confirmed') {
                $targetOrder->load(['floor:id,name', 'table:id,table_no', 'createdBy:id,name']);
                $this->printOrder($targetOrder);
            }

            $sourceOrder->load(['items.modifiers', 'items.comboComponents', 'floor:id,name', 'table:id,table_no', 'outlet:id,name', 'createdBy:id,name']);
            $targetOrder->load(['items.modifiers', 'items.comboComponents', 'floor:id,name', 'table:id,table_no', 'outlet:id,name', 'createdBy:id,name']);

            return response()->json([
                'source_order' => $sourceOrder,
                'target_order' => $targetOrder,
                'message' => 'Order split successfully.',
            ]);
        });
    }

    public function mergeOptions(int $id): JsonResponse
    {
        $primaryTable = RestaurantTable::query()->findOrFail($id);

        $availableTables = RestaurantTable::query()
            ->where('outlet_id', $primaryTable->outlet_id)
            ->where('floor_id', $primaryTable->floor_id)
            ->where('id', '!=', $primaryTable->id)
            ->where('is_active', true)
            ->whereIn('status', ['available', 'occupied'])
            ->whereNull('merged_with_table_id')
            ->get();

        return response()->json([
            'primary_table' => $primaryTable,
            'available_tables' => $availableTables,
        ]);
    }

    public function mergeTables(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'secondary_table_ids' => 'required|array|min:1',
            'secondary_table_ids.*' => 'integer|distinct|exists:tables,id',
            'merge_mode' => 'required|in:tables_only,combine_unpaid_orders',
            'note' => 'nullable|string|max:500',
            'idempotency_key' => 'nullable|string|max:100',
        ]);

        $primaryTable = RestaurantTable::query()->findOrFail($id);
        $user = $request->user();

        return DB::transaction(function () use ($primaryTable, $validated, $user) {
            $primaryTable = RestaurantTable::query()->lockForUpdate()->findOrFail($primaryTable->id);

            if (! $primaryTable->is_active || $primaryTable->status === 'inactive') {
                throw ValidationException::withMessages(['table' => 'Primary table is inactive.']);
            }

            if ($primaryTable->status === 'merged' || $primaryTable->merged_with_table_id) {
                throw ValidationException::withMessages([
                    'table' => 'A merged secondary table cannot become a primary table.',
                ]);
            }

            $existingGroup = TableMergeGroup::query()
                ->where('primary_table_id', $primaryTable->id)
                ->where('status', 'active')
                ->first();

            $secondaryTables = RestaurantTable::query()
                ->whereIn('id', $validated['secondary_table_ids'])
                ->lockForUpdate()
                ->get();

            if ($secondaryTables->count() !== count($validated['secondary_table_ids']) ||
                $secondaryTables->contains('id', $primaryTable->id)) {
                throw ValidationException::withMessages([
                    'secondary_table_ids' => 'Select valid secondary tables only once.',
                ]);
            }

            foreach ($secondaryTables as $table) {
                if ($table->outlet_id !== $primaryTable->outlet_id) {
                    throw ValidationException::withMessages([
                        'secondary_table_ids' => 'Tables from different outlets cannot be merged.',
                    ]);
                }
                if ($table->floor_id !== $primaryTable->floor_id) {
                    throw ValidationException::withMessages([
                        'secondary_table_ids' => 'Tables from different floors cannot be merged.',
                    ]);
                }
                if (! $table->is_active || $table->status === 'inactive') {
                    throw ValidationException::withMessages([
                        'secondary_table_ids' => "Table {$table->table_no} is inactive.",
                    ]);
                }
                if ($table->status === 'merged') {
                    throw ValidationException::withMessages([
                        'secondary_table_ids' => "Table {$table->table_no} is already part of another merge group.",
                    ]);
                }
            }

            if ($validated['merge_mode'] === 'tables_only' &&
                $secondaryTables->contains(fn ($table) => $table->status === 'occupied')) {
                throw ValidationException::withMessages([
                    'merge_mode' => 'Occupied tables must use Combine Unpaid Orders.',
                ]);
            }

            $primaryOrder = Order::query()
                ->where('table_id', $primaryTable->id)
                ->whereNotIn('order_status', ['completed', 'cancelled'])
                ->lockForUpdate()
                ->first();

            if ($validated['merge_mode'] === 'combine_unpaid_orders') {
                if (! $primaryOrder) {
                    throw ValidationException::withMessages([
                        'merge_mode' => 'Primary table has no active order to combine into.',
                    ]);
                }

                if ($primaryOrder->payment_state !== 'unpaid') {
                    throw ValidationException::withMessages([
                        'merge_mode' => 'The primary order must be unpaid before combining tables.',
                    ]);
                }

                $nonUnpaidSecondary = Order::query()
                    ->whereIn('table_id', $secondaryTables->pluck('id'))
                    ->whereNotIn('order_status', ['completed', 'cancelled'])
                    ->where('payment_state', '!=', 'unpaid')
                    ->first();
                if ($nonUnpaidSecondary) {
                    throw ValidationException::withMessages([
                        'merge_mode' => "Order {$nonUnpaidSecondary->order_no} is not unpaid and cannot be combined.",
                    ]);
                }
            }

            if ($existingGroup) {
                $mergeGroup = $existingGroup;
            } else {
                // Create merge group
                $mergeGroup = TableMergeGroup::query()->create([
                    'outlet_id' => $primaryTable->outlet_id,
                    'floor_id' => $primaryTable->floor_id,
                    'primary_table_id' => $primaryTable->id,
                    'status' => 'active',
                    'merged_by' => $user?->id,
                    'merged_at' => Carbon::now(),
                    'note' => $validated['note'] ?? null,
                ]);

                // Add primary as member
                TableMergeMember::query()->create([
                    'merge_group_id' => $mergeGroup->id,
                    'table_id' => $primaryTable->id,
                    'member_type' => 'primary',
                    'original_status' => $primaryTable->status,
                    'active_status' => $primaryTable->status,
                ]);
            }

            if ($primaryOrder) {
                $primaryOrder->update(['table_merge_group_id' => $mergeGroup->id]);
                $this->saveChangeHistory(
                    $primaryOrder->id,
                    null,
                    'tables_merged',
                    null,
                    null,
                    null,
                    $user?->id,
                    'Tables merged into '.$primaryTable->table_no,
                    null,
                    ['table_ids' => $secondaryTables->pluck('id')->values()->all()],
                );
            }

            // Process secondary tables
            foreach ($secondaryTables as $table) {
                $originalStatus = $table->status;

                TableMergeMember::query()->create([
                    'merge_group_id' => $mergeGroup->id,
                    'table_id' => $table->id,
                    'member_type' => 'secondary',
                    'original_status' => $originalStatus,
                    'active_status' => 'merged',
                ]);

                $table->update([
                    'status' => 'merged',
                    'merged_with_table_id' => $primaryTable->id,
                ]);

                // Link secondary table's active orders to merge group
                if ($originalStatus === 'occupied') {
                    Order::query()
                        ->where('table_id', $table->id)
                        ->whereNotIn('order_status', ['completed', 'cancelled'])
                        ->update(['table_merge_group_id' => $mergeGroup->id]);
                }
            }

            // Optionally combine unpaid orders
            if ($validated['merge_mode'] === 'combine_unpaid_orders') {
                foreach ($secondaryTables as $table) {
                    $secondaryOrders = Order::query()
                        ->where('table_id', $table->id)
                        ->whereNotIn('order_status', ['completed', 'cancelled'])
                        ->get();

                    foreach ($secondaryOrders as $secOrder) {
                        $secOrder = Order::query()->lockForUpdate()->findOrFail($secOrder->id);

                        // Move all items to primary order, merging if identical
                        $items = OrderItem::query()->where('order_id', $secOrder->id)->get();
                        foreach ($items as $item) {
                            $matchingItem = OrderItem::query()
                                ->where('order_id', $primaryOrder->id)
                                ->where('item_type', $item->item_type)
                                ->where('item_id', $item->item_id)
                                ->where('final_unit_price', $item->final_unit_price)
                                ->where('item_note', $item->item_note)
                                ->get()
                                ->filter(function ($primItem) use ($item) {
                                    $primMods = OrderItemModifier::query()->where('order_item_id', $primItem->id)->pluck('modifier_item_id')->sort()->values()->toArray();
                                    $itemMods = OrderItemModifier::query()->where('order_item_id', $item->id)->pluck('modifier_item_id')->sort()->values()->toArray();
                                    return $primMods === $itemMods;
                                })
                                ->first();

                            if ($matchingItem) {
                                $matchingItem->update([
                                    'qty' => (float)$matchingItem->qty + (float)$item->qty,
                                    'original_qty' => (float)$matchingItem->original_qty + (float)$item->original_qty,
                                    'active_qty' => (float)$matchingItem->active_qty + (float)$item->active_qty,
                                    'amount' => (float)$matchingItem->amount + (float)$item->amount,
                                    'discount_amount' => (float)$matchingItem->discount_amount + (float)$item->discount_amount,
                                    'printed_qty' => (float)$matchingItem->printed_qty + (float)$item->printed_qty,
                                ]);
                                $item->delete(); // cascade deletes modifiers and combo components
                            } else {
                                $item->update(['order_id' => $primaryOrder->id]);
                            }
                        }

                        // Mark secondary order as merged
                        $secOrder->update([
                            'order_status' => 'cancelled',
                            'cancelled_at' => Carbon::now(),
                            'cancelled_by' => $user?->id,
                            'cancellation_reason' => 'Merged into '.$primaryOrder->order_no,
                        ]);
                    }

                    // Recalculate primary order
                    $this->recalculateOrder($primaryOrder);
                }
            }

            return response()->json([
                'merge_group' => $mergeGroup->load(['primaryTable', 'members.table']),
                'message' => 'Tables merged successfully.',
            ]);
        });
    }

    public function unmergeTables(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'table_ids' => 'required|array|min:1',
            'table_ids.*' => 'integer|exists:tables,id',
        ]);

        $mergeGroup = TableMergeGroup::query()
            ->with('members')
            ->where(function ($q) use ($id) {
                $q->where('id', $id)->orWhere('primary_table_id', $id);
            })
            ->where('status', 'active')
            ->first();

        if (! $mergeGroup) {
            return response()->json(['message' => 'Active merge group not found.'], 422);
        }

        $user = $request->user();

        return DB::transaction(function () use ($mergeGroup, $validated, $user) {
            $mergeGroup = TableMergeGroup::query()->lockForUpdate()->findOrFail($mergeGroup->id);
            $groupOrderIds = Order::query()
                ->where('table_merge_group_id', $mergeGroup->id)
                ->pluck('id');

            $members = TableMergeMember::query()
                ->where('merge_group_id', $mergeGroup->id)
                ->whereIn('table_id', $validated['table_ids'])
                ->lockForUpdate()
                ->get()
                ->keyBy('table_id');

            if ($members->count() !== count(array_unique($validated['table_ids']))) {
                throw ValidationException::withMessages([
                    'table_ids' => 'Every selected table must belong to this merge group.',
                ]);
            }

            foreach ($validated['table_ids'] as $tableId) {
                $member = $members->get((int) $tableId);
                $table = RestaurantTable::query()->lockForUpdate()->findOrFail($tableId);

                $activeOrders = Order::query()
                    ->where('table_id', $tableId)
                    ->whereNotIn('order_status', ['completed', 'cancelled'])
                    ->exists();

                if ($activeOrders) {
                    $table->update([
                        'status' => 'occupied',
                        'merged_with_table_id' => null,
                    ]);
                } else {
                    $table->update([
                        'status' => 'available',
                        'merged_with_table_id' => null,
                    ]);
                }

                $member->update(['active_status' => $table->status]);

                Order::query()
                    ->where('table_id', $tableId)
                    ->whereNotIn('order_status', ['completed', 'cancelled'])
                    ->update(['table_merge_group_id' => null]);
            }

            // Check remaining active secondary members
            $remainingSecondaryMembers = TableMergeMember::query()
                ->where('merge_group_id', $mergeGroup->id)
                ->where('member_type', 'secondary')
                ->where('active_status', 'merged')
                ->whereNotIn('table_id', $validated['table_ids'])
                ->get();

            $primaryDetached = in_array($mergeGroup->primary_table_id, $validated['table_ids']);

            if ($remainingSecondaryMembers->count() === 0) {
                // No secondary members left -> close merge group and reset primary
                $mergeGroup->update([
                    'status' => 'closed',
                    'unmerged_by' => $user?->id,
                    'unmerged_at' => Carbon::now(),
                ]);

                if (! $primaryDetached) {
                    $primaryTable = RestaurantTable::query()->find($mergeGroup->primary_table_id);
                    if ($primaryTable) {
                        $primaryTable->update(['merged_with_table_id' => null]);
                    }
                }

                Order::query()
                    ->where('table_merge_group_id', $mergeGroup->id)
                    ->update(['table_merge_group_id' => null]);
            } elseif ($primaryDetached) {
                // Primary was detached -> promote first remaining secondary table as new primary
                $newPrimaryMember = $remainingSecondaryMembers->first();
                $newPrimaryTableId = $newPrimaryMember->table_id;

                $newPrimaryMember->update(['member_type' => 'primary', 'active_status' => 'occupied']);
                $newPrimaryTable = RestaurantTable::query()->find($newPrimaryTableId);
                if ($newPrimaryTable && $newPrimaryTable->status === 'merged') {
                    $newPrimaryTable->update(['status' => 'occupied', 'merged_with_table_id' => null]);
                }

                $mergeGroup->update(['primary_table_id' => $newPrimaryTableId]);

                // Point other secondary tables to new primary ID
                foreach ($remainingSecondaryMembers->skip(1) as $sec) {
                    $secTable = RestaurantTable::query()->find($sec->table_id);
                    if ($secTable) {
                        $secTable->update(['merged_with_table_id' => $newPrimaryTableId]);
                    }
                }
            } else {
                // Primary remains, update remaining secondary tables' merged_with_table_id
                foreach ($remainingSecondaryMembers as $sec) {
                    $secTable = RestaurantTable::query()->find($sec->table_id);
                    if ($secTable) {
                        $secTable->update(['merged_with_table_id' => $mergeGroup->primary_table_id]);
                    }
                }
            }

            foreach ($groupOrderIds as $orderId) {
                $this->saveChangeHistory(
                    (int) $orderId,
                    null,
                    'tables_split',
                    null,
                    null,
                    null,
                    $user?->id,
                    'Detached table IDs: '.implode(', ', $validated['table_ids']),
                    ['merge_group_id' => $mergeGroup->id],
                    ['detached_table_ids' => array_values($validated['table_ids'])],
                );
            }

            return response()->json(['message' => 'Tables unmerged successfully.']);
        });
    }

    public function showMergeGroup(int $id): JsonResponse
    {
        $mergeGroup = TableMergeGroup::query()
            ->with(['primaryTable', 'members.table', 'orders'])
            ->findOrFail($id);

        return response()->json(['merge_group' => $mergeGroup]);
    }

    // ─── Private helpers ───────────────────────────────────────

    private function tableHasActiveMerge(RestaurantTable $table): bool
    {
        if ($table->status === 'merged' || $table->merged_with_table_id) {
            return true;
        }

        return TableMergeGroup::query()
            ->where('primary_table_id', $table->id)
            ->where('status', 'active')
            ->exists();
    }

    private function activityTitle(string $type): string
    {
        return match ($type) {
            'item_added' => 'Item added',
            'item_qty_increased' => 'Item quantity increased',
            'item_qty_reduced' => 'Item cancelled',
            'order_cancelled' => 'Order cancelled',
            'order_split' => 'Order split',
            'kot_sent' => 'KOT sent to kitchen',
            'kot_reprinted' => 'KOT reprint requested',
            'adjustments_updated' => 'Order adjustments updated',
            'table_swapped' => 'Table changed',
            'tables_merged' => 'Tables merged',
            'tables_split' => 'Tables split',
            default => Str::headline($type),
        };
    }

    private function activityDescription(OrderChangeHistory $history, ?string $itemName): string
    {
        if ($itemName) {
            $quantity = $history->changed_qty !== null
                ? ' x'.rtrim(rtrim(number_format((float) $history->changed_qty, 4), '0'), '.')
                : '';

            return $itemName.$quantity;
        }

        return $history->reason ?: $this->activityTitle($history->action_type);
    }

    private function generateOrderNo(string $orderType, int $outletId): string
    {
        $prefix = match ($orderType) {
            'dine_in' => 'DN',
            'takeaway' => 'TA',
            'delivery' => 'DV',
            default => 'OR',
        };

        $date = Carbon::now()->format('Ymd');
        $lastOrder = Order::query()->where('order_no', 'like', "{$prefix}{$date}%")
            ->orderBy('id', 'desc')->first();

        $seq = $lastOrder ? (int) substr($lastOrder->order_no, -4) + 1 : 1;

        return "{$prefix}{$date}-".str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    private function validateItemModifiers(array $itemData): void
    {
        if ($itemData['item_type'] !== 'food_menu') {
            return;
        }

        $menu = FoodMenu::query()->with('modifierGroups')->find($itemData['item_id']);
        if (! $menu) {
            return;
        }

        $selectedByGroup = [];
        foreach ($itemData['modifiers'] ?? [] as $mod) {
            $gId = $mod['modifier_group_id'] ?? null;
            if ($gId) {
                $selectedByGroup[$gId][] = $mod;
            }
        }

        $linkedGroupIds = $menu->modifierGroups->pluck('id')->map(fn ($id): int => (int) $id)->all();
        foreach (array_keys($selectedByGroup) as $selectedGroupId) {
            if (! in_array((int) $selectedGroupId, $linkedGroupIds, true)) {
                throw new \Exception("The selected modifier is not available for {$menu->name}.");
            }
        }

        foreach ($menu->modifierGroups as $group) {
            $pivot = $group->pivot;
            $groupId = $group->id;
            $selected = $selectedByGroup[$groupId] ?? [];
            $selectedCount = count($selected);
            $minSel = (int) ($pivot->min_selection ?: $group->min_selection);
            $maxSel = (int) ($pivot->max_selection ?: $group->max_selection);
            $isRequired = (bool) $pivot->is_required || (bool) $group->is_required;

            if ($isRequired && $selectedCount === 0) {
                throw new \Exception("Please select at least one option for {$group->name}.");
            }

            if ($selectedCount > 0 && $selectedCount < $minSel) {
                throw new \Exception("Please select at least {$minSel} option(s) for {$group->name}.");
            }

            if ($selectedCount > $maxSel) {
                throw new \Exception("You can select a maximum of {$maxSel} option(s) for {$group->name}.");
            }

            // Validate single selection
            if ($group->selection_type === 'single' && $selectedCount > 1) {
                throw new \Exception("Only one option can be selected for {$group->name}.");
            }

            // Validate each selected item exists in the group's options
            foreach ($selected as $sel) {
                $found = false;
                foreach ($group->options as $optionIndex => $opt) {
                    if ((int) ($opt['id'] ?? ($optionIndex + 1)) === (int) $sel['modifier_item_id']) {
                        $found = true;
                        break;
                    }
                }
                if (! $found) {
                    throw new \Exception("Invalid option selected for {$group->name}.");
                }
            }
        }
    }

    private function processOrderItem(array $itemData, string $orderType, int $outletId): array
    {
        $itemType = $itemData['item_type'];
        $itemId = $itemData['item_id'];
        $qty = (float) ($itemData['qty'] ?? 1);

        $itemName = '';
        $unitName = '';
        $basePrice = 0;
        $costSnap = 0;
        $modifierPrice = 0;

        $printerIdSnap = null;
        if ($itemType === 'food_menu') {
            $item = FoodMenu::query()->withoutGlobalScopes()
                ->whereNull('food_menus.deleted_at')
                ->where('food_menus.is_active', true)
                ->with([
                    'unit',
                    'locations' => fn ($query) => $query->whereKey($outletId),
                ])
                ->find($itemId);
            $outletPrice = $item?->locations?->first();
            if (! $item || ($outletPrice && isset($outletPrice->pivot->is_active) && ! $outletPrice->pivot->is_active)) {
                throw new \Exception("Food Menu #{$itemId} not found.");
            }
            $itemName = $item->name;
            $unitName = $item->unit?->name ?? '';
            $costSnap = $item->cost_per_unit ?? 0;
            $printerIdSnap = $item->printer_id;

            $dineInPrice = $outletPrice?->pivot->dine_in_price ?? $item->dine_in_price;
            $takeAwayPrice = $outletPrice?->pivot->take_away_price ?? $item->take_away_price;
            $deliveryPrice = $outletPrice?->pivot->delivery_price ?? $item->delivery_price;
            $basePrice = match ($orderType) {
                'dine_in' => (float) ($dineInPrice ?? 0),
                'takeaway' => (float) ($takeAwayPrice ?? 0),
                'delivery' => (float) ($deliveryPrice ?? 0),
                default => (float) ($dineInPrice ?? 0),
            };
        } elseif ($itemType === 'product') {
            $item = Product::query()->withoutGlobalScopes()
                ->whereNull('products.deleted_at')
                ->where('products.is_active', true)
                ->with([
                    'productUnit',
                    'locations' => fn ($query) => $query->whereKey($outletId),
                ])
                ->find($itemId);
            $outletPrice = $item?->locations?->first();
            if (! $item || ($outletPrice && isset($outletPrice->pivot->is_active) && ! $outletPrice->pivot->is_active)) {
                throw new \Exception("Product #{$itemId} not found.");
            }
            $itemName = $item->name;
            $unitName = $item->productUnit?->name ?? '';
            $basePrice = (float) ($outletPrice?->pivot->sell_price_per_unit ?? $item->sell_price_per_unit ?? 0);
            $costSnap = (float) ($item->purchase_price_per_unit ?? 0);
            $printerIdSnap = $item->printer_id;
        } elseif ($itemType === 'combo') {
            $item = ComboMenu::query()->withoutGlobalScopes()
                ->whereNull('combo_menus.deleted_at')
                ->where('combo_menus.is_active', true)
                ->with([
                    'locations' => fn ($query) => $query->whereKey($outletId),
                ])
                ->find($itemId);
            $outletPrice = $item?->locations?->first();
            if (! $item || ($outletPrice && isset($outletPrice->pivot->is_active) && ! $outletPrice->pivot->is_active)) {
                throw new \Exception("Combo #{$itemId} not found.");
            }
            $itemName = $item->name;
            $unitName = '';
            $costSnap = $item->cost_per_unit ?? 0;

            $dineInPrice = $outletPrice?->pivot->dine_in_price ?? $item->dine_in_price;
            $takeAwayPrice = $outletPrice?->pivot->take_away_price ?? $item->take_away_price;
            $deliveryPrice = $outletPrice?->pivot->delivery_price ?? $item->delivery_price;
            $basePrice = match ($orderType) {
                'dine_in' => (float) ($dineInPrice ?? 0),
                'takeaway' => (float) ($takeAwayPrice ?? 0),
                'delivery' => (float) ($deliveryPrice ?? 0),
                default => (float) ($dineInPrice ?? 0),
            };
        }

        // Calculate modifier price
        if (! empty($itemData['modifiers'])) {
            foreach ($itemData['modifiers'] as $mod) {
                $modGroup = Modifier::query()->find($mod['modifier_group_id']);
                if ($modGroup && $modGroup->options) {
                    foreach ($modGroup->options as $optionIndex => $opt) {
                        if ((int) ($opt['id'] ?? ($optionIndex + 1)) === (int) $mod['modifier_item_id']) {
                            $modifierPrice += (float) ($opt['price'] ?? 0);
                            break;
                        }
                    }
                }
            }
        }

        $finalUnitPrice = $basePrice + $modifierPrice;

        // Item discount
        $discountType = $itemData['discount_type'] ?? null;
        $discountValue = (float) ($itemData['discount_value'] ?? 0);
        $discountAmount = 0;

        if ($discountType && $discountValue > 0) {
            $lineGross = $finalUnitPrice * $qty;
            if ($discountType === 'fixed') {
                $discountAmount = min($discountValue, $lineGross);
            } elseif ($discountType === 'percentage') {
                $pct = min($discountValue, 100);
                $discountAmount = round($lineGross * $pct / 100, 2);
            }
        }

        $amount = ($finalUnitPrice * $qty) - $discountAmount;

        return [
            'item_type' => $itemType,
            'item_id' => $itemId,
            'item_name_snapshot' => $itemName,
            'unit_name_snapshot' => $unitName,
            'qty' => $qty,
            'base_unit_price_snapshot' => $basePrice,
            'modifier_price' => $modifierPrice,
            'final_unit_price' => $finalUnitPrice,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'discount_amount' => round($discountAmount, 2),
            'amount' => round($amount, 2),
            'item_note' => $itemData['item_note'] ?? null,
            'cost_snapshot' => $costSnap,
            'printer_id_snapshot' => $printerIdSnap,
        ];
    }

    private function validateMergedStock(Order $order, int $outletId): void
    {
        $items = $order->items()->get();

        // Merge all stock requirements
        $productRequirements = [];
        $ingredientRequirements = [];
        $productionFoodMenuRequirements = [];

        foreach ($items as $item) {
            if ($item->item_type === 'product') {
                $productRequirements[$item->item_id] = ($productRequirements[$item->item_id] ?? 0) + (float) $item->qty;
            } elseif ($item->item_type === 'food_menu') {
                $menu = FoodMenu::query()->find($item->item_id);
                if (! $menu) {
                    continue;
                }

                if ($menu->stock_deduction_method === 'production_stock') {
                    $productionFoodMenuRequirements[$menu->id] = ($productionFoodMenuRequirements[$menu->id] ?? 0) + (float) $item->qty;
                } elseif ($menu->stock_deduction_method === 'deduct_ingredient_on_sale') {
                    $mappings = FoodMenuIngredient::query()->where('food_menu_id', $menu->id)->get();
                    foreach ($mappings as $map) {
                        $ingredientRequirements[$map->ingredient_id] = ($ingredientRequirements[$map->ingredient_id] ?? 0) + ((float) $map->required_qty * (float) $item->qty);
                    }
                }
                // no_stock: do nothing
            } elseif ($item->item_type === 'combo') {
                $components = OrderComboComponent::query()
                    ->where('order_item_id', $item->id)
                    ->get();

                foreach ($components as $comp) {
                    if ($comp->item_type === 'product') {
                        $productRequirements[$comp->item_id] = ($productRequirements[$comp->item_id] ?? 0) + (float) $comp->total_qty;
                    } elseif ($comp->item_type === 'food_menu') {
                        $menu = FoodMenu::query()->find($comp->item_id);
                        if (! $menu) {
                            continue;
                        }

                        if ($menu->stock_deduction_method === 'production_stock') {
                            $productionFoodMenuRequirements[$menu->id] = ($productionFoodMenuRequirements[$menu->id] ?? 0) + (float) $comp->total_qty;
                        } elseif ($menu->stock_deduction_method === 'deduct_ingredient_on_sale') {
                            $mappings = FoodMenuIngredient::query()->where('food_menu_id', $menu->id)->get();
                            foreach ($mappings as $map) {
                                $ingredientRequirements[$map->ingredient_id] = ($ingredientRequirements[$map->ingredient_id] ?? 0) + ((float) $map->required_qty * (float) $comp->total_qty);
                            }
                        }
                    }
                }
            }
        }

        // Validate product stock
        foreach ($productRequirements as $productId => $requiredQty) {
            $product = Product::query()->find($productId);
            if (! $product) {
                continue;
            }

            $currentStock = $product->currentStockForLocation($outletId);
            if ($currentStock < $requiredQty) {
                $unitName = $product->productUnit?->name ?? 'units';
                throw new \Exception("Insufficient stock for {$product->name}. Required: {$requiredQty} {$unitName}, Available: {$currentStock} {$unitName}.");
            }
        }

        // Validate production food menu stock
        foreach ($productionFoodMenuRequirements as $menuId => $requiredQty) {
            $menu = FoodMenu::query()->find($menuId);
            if (! $menu) {
                continue;
            }

            $currentStock = (float) ($menu->current_stock_qty ?? 0);
            if ($currentStock < $requiredQty) {
                $unitName = $menu->unit?->name ?? 'units';
                throw new \Exception("Insufficient stock for {$menu->name}. Required: {$requiredQty} {$unitName}, Available: {$currentStock} {$unitName}.");
            }
        }

        // Validate ingredient stock
        foreach ($ingredientRequirements as $ingredientId => $requiredQty) {
            $totalStock = \App\Models\IngredientStockMovement::query()
                ->where('ingredient_id', $ingredientId)
                ->where('location_id', $outletId)
                ->selectRaw("COALESCE(SUM(CASE WHEN LOWER(direction) = 'in' THEN quantity_consumption ELSE -quantity_consumption END), 0) AS net")
                ->value('net');

            if ($totalStock < $requiredQty) {
                // $ing = Ingredient::find($ingredientId);
                // $unitName = $ing?->consumptionUnit?->name ?? 'units';
                // throw new \Exception("Insufficient ingredient stock for {$ing?->name}. Required: {$requiredQty} {$unitName}, Available: {$totalStock} {$unitName}.");
            }
        }
    }

    private function deductOrderStock(Order $order, int $outletId): void
    {
        app(OrderStockService::class)->deductOrderStock($order, $outletId);
    }

    private function deductItemsStock(Order $order, int $outletId, array $itemsData): void
    {
        app(OrderStockService::class)->deductItemsStock($order, $outletId, $itemsData);
    }

    private function reverseOrderStock(Order $order): void
    {
        app(OrderStockService::class)->reverseOrderStock($order);
    }

    private function reverseItemsStock(Order $order, array $itemsData): void
    {
        app(OrderStockService::class)->reverseItemsStock($order, $itemsData);
    }

    private function releaseDineInTableIfNoActiveOrders(Order $order): void
    {
        if ($order->order_type !== 'dine_in' || ! $order->table_id) {
            return;
        }

        $activeOrderCount = Order::query()
            ->where('table_id', $order->table_id)
            ->where('id', '!=', $order->id)
            ->whereNotIn('order_status', ['completed', 'cancelled'])
            ->count();

        if ($activeOrderCount > 0) {
            return;
        }

        RestaurantTable::query()
            ->where('id', $order->table_id)
            ->update(['status' => 'available']);

        if (! $order->table_merge_group_id) {
            return;
        }

        $mergeGroup = TableMergeGroup::query()->find($order->table_merge_group_id);
        if (! $mergeGroup) {
            return;
        }

        $remainingActive = Order::query()
            ->where('table_merge_group_id', $mergeGroup->id)
            ->whereNotIn('order_status', ['completed', 'cancelled'])
            ->count();

        if ($remainingActive > 0) {
            return;
        }

        TableMergeMember::query()
            ->where('merge_group_id', $mergeGroup->id)
            ->get()
            ->each(function ($member) {
                RestaurantTable::query()
                    ->where('id', $member->table_id)
                    ->update([
                        'status' => 'available',
                        'merged_with_table_id' => null,
                    ]);
            });

        RestaurantTable::query()
            ->where('id', $mergeGroup->primary_table_id)
            ->update([
                'status' => 'available',
                'merged_with_table_id' => null,
            ]);

        $mergeGroup->update(['status' => 'unmerged']);
    }

    private function createSaleFromOrder(Order $order, int $outletId, ?int $userId, float $totalCost, ?int $registerId = null): void
    {
        if ($order->sale()->exists()) {
            Log::warning("Sale already exists for order {$order->order_no}, skipping.");

            return;
        }

        $saleNo = 'SALE-'.$order->order_no;
        $saleAmount = (float) $order->grand_total;

        $sale = Sale::query()->create([
            'sale_no' => $saleNo,
            'order_id' => $order->id,
            'outlet_id' => $outletId,
            'cash_register_id' => $registerId,
            'total_amount' => $saleAmount,
            'total_cost' => round($totalCost, 4),
            'profit_amount' => round($saleAmount - $totalCost, 4),
            'sale_at' => Carbon::now(),
            'created_by' => $userId,
            'status' => 'completed',
        ]);

        foreach ($order->items as $orderItem) {
            $saleItem = SaleItem::query()->create([
                'sale_id' => $sale->id,
                'item_type' => $orderItem->item_type,
                'item_id' => $orderItem->item_id,
                'item_name_snapshot' => $orderItem->item_name_snapshot,
                'unit_name_snapshot' => $orderItem->unit_name_snapshot,
                'qty' => $orderItem->qty,
                'base_unit_price_snapshot' => $orderItem->base_unit_price_snapshot,
                'modifier_price_snapshot' => $orderItem->modifier_price,
                'final_unit_price_snapshot' => $orderItem->final_unit_price,
                'discount_amount' => $orderItem->discount_amount,
                'amount' => $orderItem->amount,
                'cost_snapshot' => $orderItem->cost_snapshot,
                'item_note_snapshot' => $orderItem->item_note,
            ]);

            foreach ($orderItem->modifiers as $modifier) {
                SaleModifier::query()->create([
                    'sale_item_id' => $saleItem->id,
                    'modifier_group_name_snapshot' => $modifier->modifier_group_name_snapshot,
                    'modifier_item_name_snapshot' => $modifier->modifier_item_name_snapshot,
                    'price_adjustment_snapshot' => $modifier->price_adjustment_snapshot,
                ]);
            }
        }

        $paymentMethods = PaymentMethod::query()
            ->whereIn('id', $order->payments->pluck('payment_method_id')->unique())
            ->get()->keyBy('id');
        foreach ($order->payments as $payment) {
            $method = $paymentMethods->get($payment->payment_method_id);
            SalePayment::query()->create([
                'sale_id' => $sale->id,
                'payment_method_id' => $payment->payment_method_id,
                'payment_method_name_snapshot' => $method?->name ?? 'Unknown',
                'amount' => $payment->amount,
            ]);
        }

        $order->update([
            'sale_id' => $sale->id,
            'completed_at' => Carbon::now(),
        ]);
    }

    private function updateRegisterPaymentTotals(CashRegister $register, array $payments, float $settlementTotal): void
    {
        $methodIds = collect($payments)->pluck('payment_method_id')->unique();
        $methods = PaymentMethod::query()->whereIn('id', $methodIds)->get()->keyBy('id');
        $cash = 0.0;
        $other = 0.0;
        $remaining = max(0, $settlementTotal);

        foreach ($payments as $payment) {
            $amount = min((float) ($payment['amount'] ?? 0), $remaining);
            $remaining -= $amount;
            $method = $methods->get($payment['payment_method_id'] ?? null);
            if ($this->isCashPaymentName($method?->name)) {
                $cash += $amount;
            } else {
                $other += $amount;
            }
        }

        $register->update([
            'cash_sale_amount' => round((float) $register->cash_sale_amount + $cash, 2),
            'other_payment_amount' => round((float) $register->other_payment_amount + $other, 2),
        ]);
    }

    private function isCashPaymentName(?string $name): bool
    {
        return str_contains(strtolower(trim((string) $name)), 'cash');
    }

    private function resolveTableLabel(Order $order): string
    {
        if ($order->relationLoaded('floor') && $order->floor) {
            if ($order->relationLoaded('table') && $order->table) {
                return "{$order->floor->name} - {$order->table->table_no}";
            }

            return $order->floor->name;
        }
        if ($order->relationLoaded('table') && $order->table) {
            return $order->table->table_no;
        }
        if ($order->table_id) {
            $table = RestaurantTable::query()->with('floor:id,name')->find($order->table_id);
            if ($table) {
                $floorName = $table->floor?->name ?? '';

                return $floorName ? "{$floorName} - {$table->table_no}" : $table->table_no;
            }
        }

        return '';
    }

    private function getItemPrinterId(string $itemType, int $itemId): ?int
    {
        if ($itemType === 'food_menu') {
            return FoodMenu::query()->whereKey($itemId)->value('printer_id');
        }
        if ($itemType === 'product') {
            return Product::query()->whereKey($itemId)->value('printer_id');
        }

        return null;
    }

    private function getFoodMenuPrinterId(int $foodMenuId, string $itemType = 'food_menu'): ?int
    {
        return $this->getItemPrinterId($itemType, $foodMenuId);
    }

    private function printOrder(Order $order, bool $isReprint = false): void
    {
        $hasFailure = false;
        $printCount = 0;
        $totalCount = 0;

        try {
            $printerGroups = $this->buildPrinterGroups($order);

            foreach ($printerGroups as $printerId => $printItems) {
                $totalCount++;
                $printer = Printer::query()->find($printerId);
                if (! $printer) {
                    continue;
                }

                try {
                    $paperWidth = $printer->paper_size === '58mm' ? 32 : 42;
                    $text = $this->buildPrintSlipText($order, $printItems, $isReprint, $paperWidth);
                    $this->sendToPrinterSocket($printer, $text);

                    PrintLog::query()->create([
                        'document_type' => 'order',
                        'order_id' => $order->id,
                        'printer_id' => $printer->id,
                        'print_status' => 'success',
                        'is_reprint' => $isReprint,
                        'copy_count' => $printer->copies ?? 1,
                        'printed_by' => auth()->id() ?? 'System',
                        'printed_at' => Carbon::now(),
                    ]);
                    $printCount++;
                } catch (\Exception $e) {
                    $hasFailure = true;
                    PrintLog::query()->create([
                        'document_type' => 'order',
                        'order_id' => $order->id,
                        'printer_id' => $printer->id,
                        'print_status' => 'failed',
                        'error_message' => $e->getMessage(),
                        'is_reprint' => $isReprint,
                        'copy_count' => $printer->copies ?? 1,
                        'printed_by' => auth()->id() ?? 'System',
                        'printed_at' => Carbon::now(),
                    ]);
                    Log::error("Print failed for printer {$printer->name}: ".$e->getMessage());
                }
            }

            if ($totalCount === 0) {
                PrintLog::query()->create([
                    'document_type' => 'order',
                    'order_id' => $order->id,
                    'printer_id' => null,
                    'print_status' => 'failed',
                    'error_message' => 'No kitchen printer is assigned to the order items.',
                    'is_reprint' => $isReprint,
                    'copy_count' => 0,
                    'printed_by' => auth()->id() ?? 'System',
                    'printed_at' => Carbon::now(),
                ]);
                $order->updateQuietly(['print_status' => 'print_failed']);
            } elseif ($hasFailure && $printCount > 0) {
                $order->updateQuietly(['print_status' => 'partially_printed']);
            } elseif ($hasFailure) {
                $order->updateQuietly(['print_status' => 'print_failed']);
            } else {
                $order->updateQuietly(['print_status' => 'printed']);
            }
        } catch (\Exception $e) {
            Log::error("Print failed for Order #{$order->order_no}: ".$e->getMessage());
            $order->updateQuietly(['print_status' => 'print_failed']);
        }
    }

    private function dispatchPrint(Order $order, bool $isReprint = false): void
    {
        // Backend printing disabled.
        // All KOT printing is handled by the Flutter frontend via 80mm web printing.
    }

    private function dispatchPrintChanges(Order $order, array $newItems, array $cancelledItems): void
    {
        // Backend printing disabled.
        // All KOT change printing is handled by the Flutter frontend via 80mm web printing.
    }

    private function printChangedItems(Order $order, array $newItems, array $cancelledItems): void
    {
        $defaultPrinter = Printer::query()
            ->where('is_active', true)
            ->first() ?? Printer::query()->first();

        if (! $defaultPrinter) {
            $defaultPrinter = Printer::query()->firstOrCreate(
                ['name' => 'Local Kitchen Printer'],
                [
                    'ip_address' => '127.0.0.1',
                    'port' => 9100,
                    'paper_size' => '80mm',
                    'copies' => 1,
                    'is_active' => true,
                    'note' => 'Default local kitchen printer',
                ]
            );
        }

        $newByPrinter = [];
        foreach ($newItems as $item) {
            $printerId = $item['printer_id'] ?? $defaultPrinter?->id;
            if (! $printerId) {
                continue;
            }
            $newByPrinter[$printerId][] = $item;
        }

        $cancelledByPrinter = [];
        foreach ($cancelledItems as $item) {
            $printerId = $item['printer_id'] ?? $defaultPrinter?->id;
            if (! $printerId) {
                continue;
            }
            $cancelledByPrinter[$printerId][] = $item;
        }

        $allPrinterIds = array_unique(array_merge(array_keys($newByPrinter), array_keys($cancelledByPrinter)));
        if (empty($allPrinterIds)) {
            $activePrinters = Printer::query()->where('is_active', true)->get();
            if ($activePrinters->isEmpty()) {
                $activePrinters = Printer::query()->get();
            }
            $allPrinterIds = $activePrinters->pluck('id')->all();
        }

        foreach ($allPrinterIds as $printerId) {
            $printer = Printer::query()->find($printerId);
            if (! $printer || ! $printer->is_active) {
                $printer = $defaultPrinter;
            }
            if (! $printer) {
                continue;
            }

            $lines = [];
            $tableLabel = $this->resolveTableLabel($order);
            $addItems = $newByPrinter[$printerId] ?? (empty($newByPrinter) ? $newItems : []);
            $cancelItems = $cancelledByPrinter[$printerId] ?? (empty($cancelledByPrinter) ? $cancelledItems : []);
            $title = $this->kitchenChangeTitle($addItems, $cancelItems);

            $lines[] = "Order: {$order->order_no}";
            if ($tableLabel) {
                $lines[] = "Table: {$tableLabel}";
            }
            $lines[] = '========================================';

            if (! empty($addItems)) {
                $lines[] = '>> ADDED <<';
                $lines[] = "ITEM\tQTY";
                $lines[] = '----------------------------------------';
                foreach ($addItems as $item) {
                    $qtyText = $this->formatPrintQty((float) $item['additional_qty']);
                    $lines[] = "{$item['item_name']}\t+{$qtyText}";
                    if (! empty($item['modifiers'])) {
                        $modNames = [];
                        foreach ($item['modifiers'] as $mod) {
                            $g = Modifier::find($mod['modifier_group_id']);
                            if ($g) {
                                foreach ($g->options as $optionIndex => $opt) {
                                    if ((int) ($opt['id'] ?? ($optionIndex + 1)) === (int) $mod['modifier_item_id']) {
                                        $modNames[] = $opt['name'] ?? '';
                                        break;
                                    }
                                }
                            }
                        }
                        if (! empty($modNames)) {
                            $lines[] = '  + '.implode(', ', $modNames);
                        }
                    }
                    if (! empty($item['note'])) {
                        $lines[] = "  Note: {$item['note']}";
                    }
                }
                $lines[] = '----------------------------------------';
            }

            if (! empty($cancelItems)) {
                $lines[] = '>> CANCELLED <<';
                $lines[] = "ITEM\tQTY";
                $lines[] = '----------------------------------------';
                foreach ($cancelItems as $item) {
                    $qtyText = $this->formatPrintQty((float) $item['cancelled_qty']);
                    $lines[] = "{$item['item_name']}\t-{$qtyText}";
                }
                $lines[] = '----------------------------------------';
            }

            $lines[] = 'Time: '.Carbon::now()->format('d/m/Y h:i A');
            $lines[] = '*** END ***';

            $text = implode("\n", $lines);

            try {
                $this->sendToPrinterSocket($printer, $text, $title, 'TICKET');

                PrintLog::query()->create([
                    'document_type' => 'order_update',
                    'order_id' => $order->id,
                    'printer_id' => $printer->id,
                    'print_status' => 'success',
                    'is_reprint' => false,
                    'copy_count' => $printer->copies ?? 1,
                    'printed_by' => auth()->id() ?? 'System',
                    'printed_at' => Carbon::now(),
                ]);
            } catch (\Exception $e) {
                Log::error("Update print failed for printer {$printer->name}: ".$e->getMessage());
                PrintLog::query()->create([
                    'document_type' => 'order_update',
                    'order_id' => $order->id,
                    'printer_id' => $printer->id,
                    'print_status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'is_reprint' => false,
                    'copy_count' => $printer->copies ?? 1,
                    'printed_by' => auth()->id() ?? 'System',
                    'printed_at' => Carbon::now(),
                ]);
            }
        }
    }

    private function printCancellation(Order $order): void
    {
        try {
            $printerGroups = $this->buildPrinterGroups($order);

            foreach ($printerGroups as $printerId => $printItems) {
                $printer = Printer::query()->find($printerId);
                if (! $printer) {
                    continue;
                }

                $lines = [];
                $lines[] = "Order: {$order->order_no}";
                $lines[] = 'ALL ITEMS CANCELLED';
                if ($order->cancellation_reason) {
                    $lines[] = "Reason: {$order->cancellation_reason}";
                }

                $tableLabel = $this->resolveTableLabel($order);
                if ($tableLabel) {
                    $lines[] = "Table: {$tableLabel}";
                }

                $lines[] = '========================================';
                $lines[] = "ITEM\tQTY";
                $lines[] = '----------------------------------------';

                foreach ($printItems as $printItem) {
                    $qtyText = $this->formatPrintQty((float) $printItem['qty']);
                    $lines[] = "{$printItem['name']}\t{$qtyText}";
                    if ($printItem['modifiers']) {
                        $lines[] = "  + {$printItem['modifiers']}";
                    }
                }

                $lines[] = '========================================';
                $lines[] = 'Time: '.Carbon::now()->format('d/m/Y h:i A');
                $lines[] = '*** END ***';

                $text = implode("\n", $lines);

                try {
                    $this->sendToPrinterSocket($printer, $text, 'ORDER CANCELLED', 'TICKET');
                    PrintLog::query()->create([
                        'document_type' => 'order_cancellation',
                        'order_id' => $order->id,
                        'printer_id' => $printer->id,
                        'print_status' => 'success',
                        'is_reprint' => false,
                        'copy_count' => $printer->copies ?? 1,
                        'printed_by' => auth()->id() ?? 'System',
                        'printed_at' => Carbon::now(),
                    ]);
                } catch (\Exception $e) {
                    Log::error("Cancellation print failed for printer {$printer->name}: ".$e->getMessage());
                    PrintLog::query()->create([
                        'document_type' => 'order_cancellation',
                        'order_id' => $order->id,
                        'printer_id' => $printer->id,
                        'print_status' => 'failed',
                        'error_message' => $e->getMessage(),
                        'is_reprint' => false,
                        'copy_count' => $printer->copies ?? 1,
                        'printed_by' => auth()->id() ?? 'System',
                        'printed_at' => Carbon::now(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error("Cancellation print failed for Order #{$order->order_no}: ".$e->getMessage());
        }
    }

    private function resolvePrinterForOutlet(?int $targetPrinterId, ?int $outletId, ?Printer $fallbackPrinter): ?Printer
    {
        if ($targetPrinterId) {
            $target = Printer::query()->find($targetPrinterId);
            if ($target && $target->is_active) {
                if ($outletId === null || $target->location_id === null || (int) $target->location_id === (int) $outletId) {
                    return $target;
                }
                $matched = Printer::query()
                    ->where('is_active', true)
                    ->where('location_id', $outletId)
                    ->where('name', $target->name)
                    ->first()
                    ?? Printer::query()
                        ->where('is_active', true)
                        ->where('location_id', $outletId)
                        ->first();
                if ($matched) {
                    return $matched;
                }
            }
        }

        return $fallbackPrinter;
    }

    private function buildPrinterGroups(Order $order): array
    {
        $printerGroups = [];
        $items = $order->items()->get();
        $outletId = $order->outlet_id ? (int) $order->outlet_id : null;

        $defaultPrinter = Printer::query()
            ->where('is_active', true)
            ->when($outletId, fn ($q) => $q->where(fn ($q2) => $q2->where('location_id', $outletId)->orWhereNull('location_id')))
            ->orderByRaw('location_id IS NOT NULL DESC')
            ->first() ?? Printer::query()->first();

        $kitchenPrinter = Printer::query()
            ->where('is_active', true)
            ->when($outletId, fn ($q) => $q->where('location_id', $outletId))
            ->where(function ($q) {
                $q->where('name', 'like', '%Kitchen%')->orWhere('name', 'like', '%KDS%');
            })
            ->first();

        if (! $kitchenPrinter && $outletId) {
            $kitchenPrinter = Printer::query()
                ->where('is_active', true)
                ->where('location_id', $outletId)
                ->first();
        }

        if (! $kitchenPrinter) {
            $kitchenPrinter = $defaultPrinter;
        }

        $productPrinter = Printer::query()
            ->where('is_active', true)
            ->when($outletId, fn ($q) => $q->where('location_id', $outletId))
            ->where(function ($q) {
                $q->where('name', 'like', '%Product%')->orWhere('name', 'like', '%Bar%');
            })
            ->first();

        if (! $productPrinter && $outletId) {
            $productPrinter = Printer::query()
                ->where('is_active', true)
                ->where('location_id', $outletId)
                ->first();
        }

        if (! $productPrinter) {
            $productPrinter = $defaultPrinter;
        }

        foreach ($items as $item) {
            if ($item->item_type === 'food_menu') {
                $menu = FoodMenu::query()->with('printer')->find($item->item_id);
                $rawPrinterId = $item->printer_id_snapshot ?? $menu?->printer_id;
                $resolved = $this->resolvePrinterForOutlet($rawPrinterId, $outletId, $kitchenPrinter);
                $printerId = $resolved?->id;
                if ($printerId) {
                    $modifiers = $item->modifiers->pluck('modifier_item_name_snapshot')->filter()->join(', ');
                    
                    $foundKey = null;
                    if (isset($printerGroups[$printerId])) {
                        foreach ($printerGroups[$printerId] as $idx => $existingItem) {
                            if ($existingItem['name'] === $item->item_name_snapshot && $existingItem['modifiers'] === $modifiers) {
                                $foundKey = $idx;
                                break;
                            }
                        }
                    }

                    if ($foundKey !== null) {
                        $printerGroups[$printerId][$foundKey]['qty'] += (float) $item->qty;
                    } else {
                        $printerGroups[$printerId][] = [
                            'name' => $item->item_name_snapshot,
                            'qty' => (float) $item->qty,
                            'modifiers' => $modifiers,
                            'note' => $item->item_note,
                            'combo_from' => null,
                        ];
                    }
                }
            } elseif ($item->item_type === 'product') {
                $product = Product::query()->with('printer')->find($item->item_id);
                $rawPrinterId = $item->printer_id_snapshot ?? $product?->printer_id;
                $resolved = $this->resolvePrinterForOutlet($rawPrinterId, $outletId, $productPrinter);
                $printerId = $resolved?->id;
                if ($printerId) {
                    $foundKey = null;
                    if (isset($printerGroups[$printerId])) {
                        foreach ($printerGroups[$printerId] as $idx => $existingItem) {
                            if ($existingItem['name'] === $item->item_name_snapshot) {
                                $foundKey = $idx;
                                break;
                            }
                        }
                    }

                    if ($foundKey !== null) {
                        $printerGroups[$printerId][$foundKey]['qty'] += (float) $item->qty;
                    } else {
                        $printerGroups[$printerId][] = [
                            'name' => $item->item_name_snapshot,
                            'qty' => (float) $item->qty,
                            'modifiers' => '',
                            'note' => $item->item_note,
                            'combo_from' => null,
                        ];
                    }
                }
            } elseif ($item->item_type === 'combo') {
                $components = OrderComboComponent::query()
                    ->where('order_item_id', $item->id)
                    ->get();

                foreach ($components as $comp) {
                    $targetDefault = $comp->item_type === 'product' ? $productPrinter : $kitchenPrinter;
                    $printerId = $comp->printer_id_snapshot ?? $targetDefault?->id ?? $defaultPrinter?->id;
                    if ($printerId) {
                        $foundKey = null;
                        if (isset($printerGroups[$printerId])) {
                            foreach ($printerGroups[$printerId] as $idx => $existingItem) {
                                if ($existingItem['name'] === $comp->item_name_snapshot) {
                                    $foundKey = $idx;
                                    break;
                                }
                            }
                        }

                        if ($foundKey !== null) {
                            $printerGroups[$printerId][$foundKey]['qty'] += (float) $comp->total_qty;
                        } else {
                            $printerGroups[$printerId][] = [
                                'name' => $comp->item_name_snapshot,
                                'qty' => (float) $comp->total_qty,
                                'modifiers' => '',
                                'note' => "From: {$item->item_name_snapshot}",
                                'combo_from' => $item->item_name_snapshot,
                            ];
                        }
                    }
                }
            }
        }

        return $printerGroups;
    }

    private function buildPrintSlipText(Order $order, array $items, bool $isReprint = false, int $paperWidth = 42): string
    {
        static $kotSequence = 0;
        $kotSequence++;

        $orderTypeLabel = strtoupper(match ($order->order_type) {
            'dine_in' => 'DINE-IN',
            'takeaway' => 'TAKEAWAY',
            'delivery' => 'DELIVERY',
            default => $order->order_type,
        });

        $lines = [];
        $lines[] = "Order: {$order->order_no}";
        $lines[] = "Type: {$orderTypeLabel}";

        $tableLabel = $this->resolveTableLabel($order);
        if ($tableLabel) {
            $lines[] = "Table: {$tableLabel}";
        }

        if ($order->order_type === 'takeaway' && $order->pickup_time) {
            $lines[] = 'Pickup: '.Carbon::parse($order->pickup_time)->format('H:i');
        }

        if ($order->order_type === 'delivery') {
            $lines[] = "Partner: {$order->delivery_partner}";
            $lines[] = "Customer: {$order->customer_name}";
            if ($order->delivery_address) {
                $lines[] = "Address: {$order->delivery_address}";
            }
        }

        if ($order->customer_name) {
            $lines[] = "Customer: {$order->customer_name}";
        }

        if ($order->pax) {
            $lines[] = "Pax: {$order->pax}";
        }

        $staff = $order->createdBy?->name ?? 'System';
        $lines[] = "Staff: {$staff}";
        $lines[] = 'Time: '.Carbon::now()->format('d/m/Y h:i A');

        $lines[] = '========================================';
        $lines[] = "ITEM\tQTY";
        $lines[] = '----------------------------------------';

        foreach ($items as $idx => $printItem) {
            $qtyText = $this->formatPrintQty((float) $printItem['qty']);
            $lines[] = "{$printItem['name']}\t{$qtyText}";

            if (! empty($printItem['modifiers'])) {
                $lines[] = "  + {$printItem['modifiers']}";
            }
            if (! empty($printItem['note'])) {
                $lines[] = "  Note: {$printItem['note']}";
            }
            if (! empty($printItem['combo_from'])) {
                $lines[] = "  Part of {$printItem['combo_from']}";
            }
            if ($idx < count($items) - 1) {
                $lines[] = '----------------------------------------';
            }
        }

        $lines[] = '========================================';
        $lines[] = '*** END OF KOT ***';

        if ($isReprint) {
            $lines[] = '*** REPRINT ***';
        }

        return implode("\n", $lines);
    }

    private function kitchenItemHeader(int $paperWidth): string
    {
        $qtyWidth = 6;
        $nameWidth = max(10, $paperWidth - $qtyWidth - 1);

        return $this->padRightPrint('FOOD MENU NAME', $nameWidth).' '.$this->padLeftPrint('QTY', $qtyWidth);
    }

    private function kitchenChangeTitle(array $addItems, array $cancelItems): string
    {
        if (! empty($addItems) && empty($cancelItems)) {
            return 'ORDERED ADDED';
        }

        if (empty($addItems) && ! empty($cancelItems)) {
            return 'ORDERED CANCELLED';
        }

        return 'ORDER UPDATES';
    }

    private function kitchenItemRows(string $name, float $qty, int $paperWidth, string $prefix = ''): array
    {
        $qtyWidth = 6;
        $nameWidth = max(10, $paperWidth - $qtyWidth - 1);
        $qtyText = $this->formatPrintQty($qty);
        $nameParts = $this->wrapPrintText($prefix.$name, $nameWidth);
        $rows = [];

        foreach ($nameParts as $index => $part) {
            $rows[] = $this->padRightPrint($part, $nameWidth).' '
                .($index === 0 ? $this->padLeftPrint($qtyText, $qtyWidth) : str_repeat(' ', $qtyWidth));
        }

        return $rows;
    }

    private function kitchenDetailRows(string $text, int $paperWidth): array
    {
        return array_map(
            fn (string $line): string => '  '.$line,
            $this->wrapPrintText($text, max(10, $paperWidth - 2))
        );
    }

    private function wrapPrintText(string $text, int $width): array
    {
        $text = $this->normalizePrintText($text);
        if ($text === '') {
            return [''];
        }

        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            while (mb_strlen($word) > $width) {
                if ($current !== '') {
                    $lines[] = $current;
                    $current = '';
                }
                $lines[] = mb_substr($word, 0, $width);
                $word = mb_substr($word, $width);
            }

            $candidate = $current === '' ? $word : $current.' '.$word;
            if (mb_strlen($candidate) <= $width) {
                $current = $candidate;
                continue;
            }

            if ($current !== '') {
                $lines[] = $current;
            }
            $current = $word;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines ?: [''];
    }

    private function normalizePrintText(string $text): string
    {
        $text = preg_replace('/[\r\n\t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s{2,}/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function formatPrintQty(float $qty): string
    {
        if (abs($qty - round($qty)) < 0.00001) {
            return (string) (int) round($qty);
        }

        return rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
    }

    private function padRightPrint(string $text, int $width): string
    {
        $text = mb_strlen($text) > $width ? mb_substr($text, 0, $width) : $text;

        return $text.str_repeat(' ', max(0, $width - mb_strlen($text)));
    }

    private function padLeftPrint(string $text, int $width): string
    {
        $text = mb_strlen($text) > $width ? mb_substr($text, 0, $width) : $text;

        return str_repeat(' ', max(0, $width - mb_strlen($text))).$text;
    }

    private function sendToPrinterSocket(
        Printer $printer,
        string $text,
        string $documentTitle = 'KITCHEN ORDER',
        string $documentSubtitle = 'TICKET',
    ): void
    {
        $escPosService = app(\App\Services\EscPosPrintService::class);
        $formatted = $escPosService->preparePayload($printer, $text, $documentTitle, $documentSubtitle);

        if (empty($formatted)) {
            $formatted = $escPosService->fallbackEscPosPayload($printer, $text, $documentTitle, $documentSubtitle);
        }

        $queueService = app(\App\Services\PrinterQueueService::class);
        $success = $queueService->sendToPrinter($printer, $formatted, $text, 'order');

        if (! $success) {
            $fallbackFormatted = $escPosService->fallbackEscPosPayload($printer, $text, $documentTitle, $documentSubtitle);
            $success = $queueService->sendToPrinter($printer, $fallbackFormatted, $text, 'order');
        }

        if (! $success) {
            throw new \Exception("Cannot connect to printer {$printer->name} ({$printer->ip_address}:{$printer->port})");
        }
    }

    private function wrapWithEscPos(
        Printer $printer,
        string $body,
        string $documentTitle = 'KITCHEN ORDER',
        string $documentSubtitle = 'TICKET',
    ): string
    {
        $paperWidth = $printer->paper_size === '58mm' ? 32 : 42;

        $header = "\x1B\x40"
            ."\x1B\x61\x01"
            ."\x1B\x21\x30"
            .$documentTitle."\n"
            ."\x1B\x21\x00"
            ."\x1B\x45\x01"
            .$documentSubtitle."\n"
            ."\x1B\x45\x00"
            .str_repeat('=', $paperWidth)."\n"
            ."\x1B\x61\x00";

        $footer = "\x1B\x61\x01"
            ."\n"
            .str_repeat('=', $paperWidth)."\n"
            ."\x1B\x21\x10"
            ."Thank you!\n"
            ."\x1B\x21\x00"
            ."\x1B\x61\x00"
            ."\n\n\n\n"
            ."\x1D\x56\x00";

        return $header.$body.$footer;
    }

    private function escPosBold(string $text): string
    {
        return "\x1B\x45\x01".$text."\x1B\x45\x00";
    }

    private function escPosLine(int $paperWidth = 42): string
    {
        return str_repeat('-', $paperWidth);
    }

    private function escPosThickLine(int $paperWidth = 42): string
    {
        return str_repeat('=', $paperWidth);
    }

    private function findIdenticalOrderItem(Order $order, array $itemData): ?OrderItem
    {
        $items = $order->items()->where('item_type', $itemData['item_type'])
            ->where('item_id', $itemData['item_id'])
            ->get();

        $newModifierMap = $this->buildModifierMap($itemData['modifiers'] ?? []);
        $newNote = trim($itemData['item_note'] ?? '');
        $newDiscountType = $itemData['discount_type'] ?? null;
        $newDiscountValue = (float) ($itemData['discount_value'] ?? 0);

        foreach ($items as $existing) {
            $existingNote = trim($existing->item_note ?? '');
            if ($existingNote !== $newNote) {
                continue;
            }
            if ($existing->discount_type !== $newDiscountType) {
                continue;
            }
            if ((float) ($existing->discount_value ?? 0) !== $newDiscountValue) {
                continue;
            }

            $existingModifiers = OrderItemModifier::query()
                ->where('order_item_id', $existing->id)
                ->get();

            $existingModifierMap = [];
            foreach ($existingModifiers as $m) {
                $existingModifierMap[$m->modifier_group_id][] = $m->modifier_item_id;
            }

            if ($this->modifierMapsEqual($newModifierMap, $existingModifierMap)) {
                return $existing;
            }
        }

        return null;
    }

    private function buildModifierMap(array $modifiers): array
    {
        $map = [];
        foreach ($modifiers as $mod) {
            $gId = (int) ($mod['modifier_group_id'] ?? 0);
            $mId = (int) ($mod['modifier_item_id'] ?? 0);
            $map[$gId][] = $mId;
        }

        return $map;
    }

    private function modifierMapsEqual(array $a, array $b): bool
    {
        $allKeys = array_unique(array_merge(array_keys($a), array_keys($b)));
        foreach ($allKeys as $key) {
            $aVals = $a[$key] ?? [];
            $bVals = $b[$key] ?? [];
            sort($aVals);
            sort($bVals);
            if ($aVals !== $bVals) {
                return false;
            }
        }

        return true;
    }

    private function saveChangeHistory(int $orderId, ?int $orderItemId, string $actionType,
        mixed $oldQty, mixed $newQty, mixed $changedQty,
        ?int $changedBy, ?string $reason = null,
        ?array $oldValues = null, ?array $newValues = null): void
    {
        try {
            OrderChangeHistory::query()->create([
                'order_id' => $orderId,
                'action_type' => $actionType,
                'order_item_id' => $orderItemId,
                'old_qty' => $oldQty,
                'new_qty' => $newQty,
                'changed_qty' => $changedQty,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'reason' => $reason,
                'changed_by' => $changedBy,
                'changed_at' => Carbon::now(),
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to save change history: '.$e->getMessage());
        }
    }

    private function recalculateOrder(Order $order): void
    {
        $items = OrderItem::query()->where('order_id', $order->id)->get();
        $subtotal = 0;
        $itemDiscount = 0;

        foreach ($items as $item) {
            $subtotal += (float) $item->amount;
            $itemDiscount += (float) $item->discount_amount;
        }

        $taxRate = TaxRate::query()->where('is_active', true)->where('type', 'percentage')->first();
        $chargeType = $order->order_type === 'dine_in' ? 'dinein' : $order->order_type;
        $charge = Charge::query()->where('is_active', true)
            ->where(function ($q) use ($chargeType) {
                $q->where('apply_to', $chargeType)->orWhere('apply_to', 'all');
            })->first();

        $taxableAmount = $subtotal - (float) $order->order_discount_amount;
        $taxAmount = $taxRate && $taxRate->value > 0 ? round($taxableAmount * $taxRate->value / 100, 2) : 0;
        $serviceChargeAmount = 0;
        if ($charge && $charge->value > 0) {
            $serviceChargeAmount = $charge->type === 'percentage'
                ? round($taxableAmount * $charge->value / 100, 2)
                : (float) $charge->value;
        }

        $grandTotal = round($subtotal - (float) $order->order_discount_amount + $taxAmount + $serviceChargeAmount + (float) $order->delivery_fee, 2);

        $order->update([
            'subtotal' => $subtotal,
            'item_discount_amount' => $itemDiscount,
            'tax_amount' => $taxAmount,
            'service_charge_amount' => $serviceChargeAmount,
            'grand_total' => $grandTotal,
            'balance_amount' => max(0, $grandTotal - (float) $order->paid_amount),
            'version_number' => (int) $order->version_number + 1,
        ]);
    }
}
