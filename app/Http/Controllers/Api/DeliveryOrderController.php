<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Charge;
use App\Models\ComboMenu;
use App\Models\ComboMenuItem;
use App\Models\Floor;
use App\Models\FoodMenu;
use App\Models\FoodMenuIngredient;
use App\Models\Location;
use App\Models\Modifier;
use App\Models\Order;
use App\Models\OrderComboComponent;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Printer;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\Refund;
use App\Models\Sale;
use App\Models\TaxRate;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class DeliveryOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'outlet_id' => ['nullable', 'integer', 'exists:locations,id'],
            'delivery_partner' => ['nullable', 'string', 'max:255'],
            'order_status' => ['nullable', 'string', Rule::in(['pending', 'preparing', 'ready', 'on_the_way', 'delivered', 'completed', 'cancelled'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'sort_col' => ['nullable', 'string', Rule::in(['created_at', 'grand_total', 'order_status'])],
            'sort_dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer'],
        ]);

        $sortCol = $payload['sort_col'] ?? 'created_at';
        $sortDir = $payload['sort_dir'] ?? 'desc';

        $query = Order::query()
            ->where('order_type', 'delivery')
            ->with(['outlet:id,name', 'createdBy:id,name', 'payments.paymentMethod:id,name']);

        $this->applyFilters($query, $payload);
        $query->orderBy($sortCol, $sortDir)->orderBy('id', 'desc');

        $perPage = (int) ($payload['per_page'] ?? 20);
        $perPage = in_array($perPage, [10, 20, 30, 50, 100], true) ? $perPage : 20;

        $records = $query->paginate($perPage)->through(
            fn (Order $order): array => $this->listResource($order)
        );

        return response()->json(['data' => $records]);
    }

    public function createData(): JsonResponse
    {
        $outlets = Location::query()->where('is_active', true)->get(['id', 'name']);
        $foodMenus = FoodMenu::query()
            ->where('is_active', true)
            ->with(['category:id,name', 'unit:id,name', 'modifierGroups' => function ($q) {
                $q->withPivot(['is_required', 'min_selection', 'max_selection', 'sort_order']);
            }])
            ->get();
        $products = Product::query()
            ->where('is_active', true)
            ->with(['productCategory:id,name', 'productUnit:id,name'])
            ->get();
        $combos = ComboMenu::query()
            ->where('is_active', true)
            ->with(['category:id,name', 'items'])
            ->get();
        $modifiers = Modifier::query()->where('is_active', true)->get();
        $paymentMethods = PaymentMethod::query()->where('is_active', true)->get(['id', 'name']);
        $taxRates = TaxRate::query()->where('is_active', true)->get(['id', 'name', 'value', 'type']);
        $charges = Charge::query()->where('is_active', true)->get(['id', 'name', 'value', 'type', 'apply_to']);
        $printers = Printer::query()->where('is_active', true)->get(['id', 'name', 'ip_address', 'port', 'paper_size', 'copies']);
        $foodMenuCategories = \App\Models\Category::query()->where('is_active', true)->get(['id', 'name']);

        return response()->json([
            'data' => [
                'outlets' => $outlets,
                'food_menus' => $foodMenus,
                'products' => $products,
                'combos' => $combos,
                'modifiers' => $modifiers,
                'payment_methods' => $paymentMethods,
                'tax_rates' => $taxRates,
                'charges' => $charges,
                'printers' => $printers,
                'food_menu_categories' => $foodMenuCategories,
                  'delivery_partners' => config('services.delivery.partners', []),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'outlet_id' => ['required', 'integer', 'exists:locations,id'],
            'delivery_partner' => ['required', 'string', 'max:255'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:50'],
            'delivery_address' => ['required', 'string', 'max:1000'],
            'delivery_fee' => ['nullable', 'numeric', 'min:0'],
            'delivery_note' => ['nullable', 'string', 'max:500'],
            'order_discount_type' => ['nullable', 'in:fixed,percentage'],
            'order_discount_value' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_type' => ['required', 'in:food_menu,product,combo'],
            'items.*.item_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'numeric', 'min:0.01'],
            'items.*.discount_type' => ['nullable', 'in:fixed,percentage'],
            'items.*.discount_value' => ['nullable', 'numeric', 'min:0'],
            'items.*.item_note' => ['nullable', 'string', 'max:500'],
            'items.*.modifiers' => ['nullable', 'array'],
            'items.*.modifiers.*.modifier_group_id' => ['nullable', 'integer'],
            'items.*.modifiers.*.modifier_item_id' => ['nullable', 'integer'],
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'payments.*.amount' => ['required', 'numeric', 'min:0.01'],
            'payments.*.reference_no' => ['nullable', 'string', 'max:100'],
            'print_bill' => ['nullable', 'boolean'],
            'receipt_printer_id' => ['nullable', 'integer', 'exists:printers,id'],
        ]);

        $outlet = Location::query()->findOrFail($validated['outlet_id']);
        if (!$outlet->is_active) {
            abort(422, 'Selected Outlet is inactive.');
        }

        $user = $request->user();

        return DB::transaction(function () use ($validated, $user) {
            $orderNo = $this->generateOrderNo($validated['outlet_id']);
            $outletId = $validated['outlet_id'];
            $userId = $user?->id;

            // Load pricing config
            $taxRate = TaxRate::query()->where('is_active', true)->where('type', 'percentage')->first();
            $charge = Charge::query()->where('is_active', true)
                ->where(fn ($q) => $q->where('apply_to', 'delivery')->orWhere('apply_to', 'all'))
                ->first();

            // Create order header
            $order = Order::query()->create([
                'order_no' => $orderNo,
                'outlet_id' => $outletId,
                'order_type' => 'delivery',
                'customer_name' => $validated['customer_name'],
                'customer_phone' => $validated['customer_phone'],
                'delivery_partner' => $validated['delivery_partner'],
                'delivery_address' => $validated['delivery_address'],
                'delivery_fee' => $validated['delivery_fee'] ?? 0,
                'order_note' => $validated['delivery_note'] ?? null,
                'created_by' => $userId,
                'order_status' => 'pending',
                'print_status' => 'not_printed',
                'stock_deduction_status' => 'none',
            ]);

            // Process items
            $subtotal = 0;
            $itemDiscountTotal = 0;
            $totalCost = 0;

            foreach ($validated['items'] as $itemData) {
                $this->validateItemModifiers($itemData);
                $itemResult = $this->processOrderItem($itemData, $outletId);
                $itemResult['order_id'] = $order->id;

                $orderItem = OrderItem::query()->create($itemResult);
                $subtotal += $orderItem->amount;
                $itemDiscountTotal += $orderItem->discount_amount;
                $totalCost += ($orderItem->cost_snapshot ?? 0) * $orderItem->qty;

                // Save modifiers
                if (!empty($itemData['modifiers'])) {
                    foreach ($itemData['modifiers'] as $mod) {
                        $modGroup = Modifier::query()->find($mod['modifier_group_id']);
                        $option = $this->modifierOptionSnapshot($mod);
                        $adjustment = (float) ($option['price'] ?? 0);

                        OrderItemModifier::query()->create([
                            'order_item_id' => $orderItem->id,
                            'modifier_group_id' => $mod['modifier_group_id'],
                            'modifier_group_name_snapshot' => $modGroup?->name ?? '',
                            'modifier_item_id' => $mod['modifier_item_id'],
                            'modifier_item_name_snapshot' => $option['name'] ?? '',
                            'price_adjustment_snapshot' => $adjustment,
                        ]);
                    }
                }

                // Save combo components
                if ($itemData['item_type'] === 'combo') {
                    $combo = ComboMenu::query()->with('items')->find($itemData['item_id']);
                    if ($combo) {
                        foreach ($combo->items as $comp) {
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
            if (!empty($validated['order_discount_type']) && !empty($validated['order_discount_value'])) {
                if ($validated['order_discount_type'] === 'fixed') {
                    $orderDiscountAmount = min($validated['order_discount_value'], $subtotal);
                } elseif ($validated['order_discount_type'] === 'percentage') {
                    $pct = min($validated['order_discount_value'], 100);
                    $orderDiscountAmount = round($subtotal * $pct / 100, 2);
                }
            }

            // Tax
            $taxableAmount = $subtotal - $orderDiscountAmount;
            $taxAmount = 0;
            if ($taxRate && $taxRate->value > 0) {
                $taxAmount = round($taxableAmount * $taxRate->value / 100, 2);
            }

            // Service charge
            $serviceChargeAmount = 0;
            if ($charge && $charge->value > 0) {
                $serviceChargeAmount = $charge->type === 'percentage'
                    ? round($taxableAmount * $charge->value / 100, 2)
                    : $charge->value;
            }

            $deliveryFee = (float) ($validated['delivery_fee'] ?? 0);
            $grandTotal = round($subtotal - $orderDiscountAmount + $taxAmount + $serviceChargeAmount + $deliveryFee, 2);

            // Validate payments
            $totalPaid = 0;
            foreach ($validated['payments'] as $paymentData) {
                $totalPaid += (float) $paymentData['amount'];
            }

            if ($totalPaid < $grandTotal) {
                abort(422, "Total paid amount ({$totalPaid}) is less than grand total ({$grandTotal}). Full payment is required for delivery orders.");
            }

            $balanceAmount = max(0, $grandTotal - $totalPaid);
            $changeAmount = max(0, $totalPaid - $grandTotal);

            // Save payments
            foreach ($validated['payments'] as $paymentData) {
                Payment::query()->create([
                    'order_id' => $order->id,
                    'payment_method_id' => $paymentData['payment_method_id'],
                    'amount' => $paymentData['amount'],
                    'reference_no' => $paymentData['reference_no'] ?? null,
                    'created_by' => $userId,
                ]);
            }

            // Merge stock requirements and validate
            $stockRequirements = $this->calculateStockRequirements($validated['items'], $outletId);
            $this->validateStock($stockRequirements, $outletId);

            // Update order with calculated values
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
                'paid_amount' => $totalPaid,
                'balance_amount' => $balanceAmount,
                'change_amount' => $changeAmount,
                'payment_completed_at' => Carbon::now(),
                'stock_deduction_status' => 'deducted',
                'stock_deducted_at' => Carbon::now(),
            ]);

            // Deduct stock
            $this->executeStockDeduction($stockRequirements, $order->order_no, $outletId);

            // Create sale
            $sale = $this->createSale($order, $outletId, $userId, $totalCost);

            // Update order with sale_id
            $order->update(['sale_id' => $sale->id]);

            // Attempt printing
            $printBill = $validated['print_bill'] ?? false;
            $receiptPrinterId = $validated['receipt_printer_id'] ?? null;

            try {
                $this->printKitchenItems($order);
                if ($printBill && $receiptPrinterId) {
                    $this->printDeliveryBill($order, $receiptPrinterId);
                }
            } catch (\Exception $e) {
                Log::error("Delivery printing failed for Order #{$order->order_no}: " . $e->getMessage());
                // Printing failure does not rollback the transaction
            }

            $order->load(['items.modifiers', 'items.comboComponents', 'outlet:id,name', 'createdBy:id,name', 'payments.paymentMethod:id,name']);

            return response()->json(['order' => $this->detailResource($order), 'message' => 'Delivery order created successfully.'], 201);
        });
    }

    public function show(int $id): JsonResponse
    {
        $order = Order::query()
            ->where('order_type', 'delivery')
            ->with(['items.modifiers', 'items.comboComponents', 'outlet:id,name', 'createdBy:id,name', 'updatedBy:id,name', 'payments.paymentMethod:id,name', 'sale'])
            ->findOrFail($id);

        return response()->json(['data' => $this->detailResource($order)]);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'order_status' => ['required', Rule::in(['pending', 'preparing', 'ready', 'on_the_way', 'delivered', 'completed', 'cancelled'])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $order = Order::query()->where('order_type', 'delivery')->findOrFail($id);

        if ($order->order_status === 'cancelled') {
            abort(422, 'Cannot update status of a cancelled order.');
        }

        $oldStatus = $order->order_status;
        $newStatus = $validated['order_status'];

        $allowed = $this->allowedTransitions($oldStatus);
        if (!in_array($newStatus, $allowed, true)) {
            abort(422, "Status transition from {$oldStatus} to {$newStatus} is not allowed.");
        }

        DB::transaction(function () use ($order, $newStatus) {
            $order->update(['order_status' => $newStatus]);

            if ($newStatus === 'completed') {
                $order->completed_at = Carbon::now();
                $order->save();
            }

            // Log status change (using a simple approach since no status_history table)
            Log::info("Delivery Order #{$order->order_no} status changed from {$order->getOriginal('order_status')} to {$newStatus}");
        });

        return response()->json(['message' => 'Delivery order status updated successfully.', 'order' => $this->detailResource($order->fresh())]);
    }

    public function reprint(int $id): JsonResponse
    {
        $order = Order::query()
            ->where('order_type', 'delivery')
            ->with(['items.modifiers', 'items.comboComponents', 'outlet'])
            ->findOrFail($id);

        $this->printKitchenItems($order);

        return response()->json(['message' => 'Reprint sent successfully.']);
    }

    public function printBill(int $id): JsonResponse
    {
        $validated = request()->validate([
            'printer_id' => ['nullable', 'integer', 'exists:printers,id'],
        ]);

        $order = Order::query()
            ->where('order_type', 'delivery')
            ->with(['items.modifiers', 'items.comboComponents', 'outlet', 'payments.paymentMethod:id,name'])
            ->findOrFail($id);

        $printerId = $validated['printer_id'] ?? null;
        if (!$printerId) {
            $printer = Printer::query()->where('is_active', true)->first();
            if (!$printer) {
                abort(422, 'No active printer found.');
            }
            $printerId = $printer->id;
        }

        $this->printDeliveryBill($order, $printerId);

        return response()->json(['message' => 'Bill printed successfully.']);
    }

    public function refund(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'refund_amount' => ['required', 'numeric', 'min:0.01'],
            'refund_reason' => ['required', 'string', 'max:1000'],
            'return_to_stock' => ['nullable', 'boolean'],
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
        ]);

        $order = Order::query()->where('order_type', 'delivery')->findOrFail($id);

        if ($order->order_status === 'cancelled') {
            abort(422, 'Order is already cancelled.');
        }

        if (!$order->sale()->exists()) {
            abort(422, 'No sale found for this order.');
        }

        $sale = $order->sale;
        $refundAmount = (float) $validated['refund_amount'];
        $saleAmount = (float) $sale->total_amount;

        if ($refundAmount > $saleAmount) {
            abort(422, "Refund amount ({$refundAmount}) cannot exceed sale amount ({$saleAmount}).");
        }

        $existingRefunds = (float) Refund::query()->where('sale_id', $sale->id)->sum('refund_amount');
        if (($existingRefunds + $refundAmount) > $saleAmount) {
            abort(422, 'Total refund amount would exceed sale amount.');
        }

        $returnToStock = $validated['return_to_stock'] ?? true;
        $user = $request->user();

        DB::transaction(function () use ($order, $sale, $validated, $refundAmount, $returnToStock, $user) {
            // Create refund record
            $refundNo = 'RFND-' . $order->order_no . '-' . Refund::query()->where('sale_id', $sale->id)->count() + 1;
            Refund::query()->create([
                'refund_no' => $refundNo,
                'sale_id' => $sale->id,
                'order_id' => $order->id,
                'refund_type' => 'partial',
                'refund_amount' => $refundAmount,
                'return_to_stock' => $returnToStock,
                'reason' => $validated['refund_reason'],
                'payment_method_id' => $validated['payment_method_id'] ?? null,
                'created_by' => $user?->id,
            ]);

            // Reverse stock if requested
            if ($returnToStock && $order->stock_deduction_status === 'deducted') {
                $this->reverseStock($order);
            }

            // Update order status
            $order->update([
                'order_status' => 'cancelled',
                'cancelled_at' => Carbon::now(),
                'cancelled_by' => $user?->id,
                'cancellation_reason' => $validated['refund_reason'],
            ]);

            // Update sale total
            $newTotal = $saleAmount - $refundAmount;
            $sale->update([
                'total_amount' => round($newTotal, 2),
                'total_cost' => round((float) $sale->total_cost * ($newTotal / max($saleAmount, 1)), 4),
            ]);
        });

        return response()->json(['message' => 'Refund processed successfully.']);
    }

    public function void(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'void_reason' => ['required', 'string', 'max:1000'],
            'return_to_stock' => ['nullable', 'boolean'],
        ]);

        $order = Order::query()->where('order_type', 'delivery')->findOrFail($id);

        if ($order->order_status === 'cancelled') {
            abort(422, 'Order is already cancelled.');
        }

        $sale = $order->sale;
        if (!$sale) {
            // No sale — just cancel the order
            return $this->simpleCancel($order, $request);
        }

        $returnToStock = $validated['return_to_stock'] ?? true;
        $user = $request->user();

        DB::transaction(function () use ($order, $sale, $validated, $returnToStock, $user) {
            // Reverse stock if requested
            if ($returnToStock && $order->stock_deduction_status === 'deducted') {
                $this->reverseStock($order);
            }

            $order->update([
                'order_status' => 'cancelled',
                'cancelled_at' => Carbon::now(),
                'cancelled_by' => $user?->id,
                'cancellation_reason' => $validated['void_reason'],
            ]);

            if ($sale) {
                $sale->update(['status' => 'voided']);
            }
        });

        return response()->json(['message' => 'Delivery order voided successfully.']);
    }

    // ─── Private helpers ───────────────────────────────────────

    private function generateOrderNo(int $outletId): string
    {
        $prefix = 'DV';
        $date = Carbon::now()->format('Ymd');
        $lastOrder = Order::query()->where('order_type', 'delivery')
            ->where('order_no', 'like', "{$prefix}{$date}%")
            ->orderBy('id', 'desc')->first();

        $seq = $lastOrder ? (int) substr($lastOrder->order_no, -4) + 1 : 1;

        return "{$prefix}{$date}-" . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    private function processOrderItem(array $itemData, int $outletId): array
    {
        $itemType = $itemData['item_type'];
        $itemId = $itemData['item_id'];
        $qty = (float) ($itemData['qty'] ?? 1);

        $itemName = '';
        $unitName = '';
        $basePrice = 0;
        $costSnap = 0;
        $modifierPrice = 0;

        if ($itemType === 'food_menu') {
            $item = FoodMenu::query()->with('unit')->find($itemId);
            if (!$item) abort(422, "Food Menu #{$itemId} not found.");
            $itemName = $item->name;
            $unitName = $item->unit?->name ?? '';
            $costSnap = $item->cost_per_unit ?? 0;
            $basePrice = (float) ($item->delivery_price ?? 0);
        } elseif ($itemType === 'product') {
            $item = Product::query()->with('productUnit')->find($itemId);
            if (!$item) abort(422, "Product #{$itemId} not found.");
            $itemName = $item->name;
            $unitName = $item->productUnit?->name ?? '';
            $basePrice = (float) ($item->sell_price_per_unit ?? 0);
            $costSnap = (float) ($item->purchase_price_per_unit ?? 0);
        } elseif ($itemType === 'combo') {
            $item = ComboMenu::query()->find($itemId);
            if (!$item) abort(422, "Combo #{$itemId} not found.");
            $itemName = $item->name;
            $unitName = '';
            $costSnap = $item->cost_per_unit ?? 0;
            $basePrice = (float) ($item->delivery_price ?? 0);
        }

        // Calculate modifier price
        if (!empty($itemData['modifiers'])) {
            foreach ($itemData['modifiers'] as $mod) {
                $option = $this->modifierOptionSnapshot($mod);
                $modifierPrice += (float) ($option['price'] ?? 0);
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
        ];
    }

    private function validateItemModifiers(array $itemData): void
    {
        if (($itemData['item_type'] ?? '') !== 'food_menu') {
            return;
        }

        $menu = FoodMenu::query()->with('modifierGroups')->find($itemData['item_id']);
        if (! $menu) {
            return;
        }

        $selectedByGroup = [];
        foreach ($itemData['modifiers'] ?? [] as $mod) {
            $groupId = $mod['modifier_group_id'] ?? null;
            if ($groupId) {
                $selectedByGroup[$groupId][] = $mod;
            }
        }

        $linkedGroupIds = $menu->modifierGroups->pluck('id')->map(fn ($id): int => (int) $id)->all();
        foreach (array_keys($selectedByGroup) as $selectedGroupId) {
            if (! in_array((int) $selectedGroupId, $linkedGroupIds, true)) {
                abort(422, "The selected modifier is not available for {$menu->name}.");
            }
        }

        foreach ($menu->modifierGroups as $group) {
            $pivot = $group->pivot;
            $selected = $selectedByGroup[$group->id] ?? [];
            $selectedCount = count($selected);
            $minSel = (int) ($pivot->min_selection ?: $group->min_selection);
            $maxSel = (int) ($pivot->max_selection ?: $group->max_selection);
            $isRequired = (bool) $pivot->is_required || (bool) $group->is_required;

            if ($isRequired && $selectedCount === 0) {
                abort(422, "Please select at least one option for {$group->name}.");
            }
            if ($selectedCount > 0 && $selectedCount < $minSel) {
                abort(422, "Please select at least {$minSel} option(s) for {$group->name}.");
            }
            if ($selectedCount > $maxSel) {
                abort(422, "You can select a maximum of {$maxSel} option(s) for {$group->name}.");
            }
            if ($group->selection_type === 'single' && $selectedCount > 1) {
                abort(422, "Only one option can be selected for {$group->name}.");
            }

            foreach ($selected as $selection) {
                if ($this->modifierOptionSnapshot($selection) === null) {
                    abort(422, "Invalid option selected for {$group->name}.");
                }
            }
        }
    }

    private function modifierOptionSnapshot(array $modifier): ?array
    {
        $group = Modifier::query()->find($modifier['modifier_group_id'] ?? null);
        if (! $group || ! is_array($group->options)) {
            return null;
        }

        foreach ($group->options as $optionIndex => $option) {
            if ((int) ($option['id'] ?? ($optionIndex + 1)) === (int) ($modifier['modifier_item_id'] ?? 0)) {
                return [
                    'name' => (string) ($option['name'] ?? ''),
                    'price' => (float) ($option['price'] ?? 0),
                ];
            }
        }

        return null;
    }

    private function calculateStockRequirements(array $items, int $outletId): array
    {
        $requirements = [
            'products' => [],
            'ingredients' => [],
            'food_menus' => [],
        ];

        foreach ($items as $itemData) {
            $itemType = $itemData['item_type'];
            $itemId = $itemData['item_id'];
            $qty = (float) $itemData['qty'];

            if ($itemType === 'product') {
                $requirements['products'][$itemId] = ($requirements['products'][$itemId] ?? 0) + $qty;
            } elseif ($itemType === 'food_menu') {
                $menu = FoodMenu::query()->find($itemId);
                if (!$menu) continue;

                if ($menu->stock_deduction_method === 'deduct_ingredient_on_sale') {
                    $mappings = FoodMenuIngredient::query()->where('food_menu_id', $menu->id)->get();
                    foreach ($mappings as $map) {
                        $requirements['ingredients'][$map->ingredient_id] = ($requirements['ingredients'][$map->ingredient_id] ?? 0) + ((float) $map->required_qty * $qty);
                    }
                } elseif ($menu->stock_deduction_method === 'production_stock') {
                    $requirements['food_menus'][$itemId] = ($requirements['food_menus'][$itemId] ?? 0) + $qty;
                }
                // no_stock = no deduction
            } elseif ($itemType === 'combo') {
                $components = ComboMenuItem::query()->where('combo_menu_id', $itemId)->get();
                foreach ($components as $comp) {
                    $compQty = $qty * (float) $comp->qty;
                    if ($comp->item_type === 'product') {
                        $requirements['products'][$comp->item_id] = ($requirements['products'][$comp->item_id] ?? 0) + $compQty;
                    } elseif ($comp->item_type === 'food_menu') {
                        $menu = FoodMenu::query()->find($comp->item_id);
                        if (!$menu) continue;

                        if ($menu->stock_deduction_method === 'deduct_ingredient_on_sale') {
                            $mappings = FoodMenuIngredient::query()->where('food_menu_id', $menu->id)->get();
                            foreach ($mappings as $map) {
                                $requirements['ingredients'][$map->ingredient_id] = ($requirements['ingredients'][$map->ingredient_id] ?? 0) + ((float) $map->required_qty * $compQty);
                            }
                        } elseif ($menu->stock_deduction_method === 'production_stock') {
                            $requirements['food_menus'][$comp->item_id] = ($requirements['food_menus'][$comp->item_id] ?? 0) + $compQty;
                        }
                    }
                }
            }
        }

        return $requirements;
    }

    private function validateStock(array $requirements, int $outletId): void
    {
        // Validate product stock
        foreach ($requirements['products'] as $productId => $requiredQty) {
            $product = Product::query()->find($productId);
            if (!$product) continue;

            $currentStock = $product->currentStockForLocation($outletId);
            if ($currentStock < $requiredQty) {
                abort(422, "Insufficient stock for {$product->name}. Required: {$requiredQty}, Available: {$currentStock}.");
            }
        }

        // Validate food menu production stock
        foreach ($requirements['food_menus'] as $menuId => $requiredQty) {
            $menu = FoodMenu::query()->find($menuId);
            if (!$menu) continue;
            $currentStock = (float) ($menu->current_stock_qty ?? 0);
            if ($currentStock < $requiredQty) {
                abort(422, "Insufficient stock for {$menu->name}. Required: {$requiredQty}, Available: {$currentStock}.");
            }
        }

        // Validate ingredient stock
        foreach ($requirements['ingredients'] as $ingredientId => $requiredQty) {
            $totalStock = \App\Models\IngredientStockMovement::query()
                ->where('ingredient_id', $ingredientId)
                ->where('location_id', $outletId)
                ->selectRaw("COALESCE(SUM(CASE WHEN LOWER(direction) = 'in' THEN quantity_consumption ELSE -quantity_consumption END), 0) AS net")
                ->value('net');

            if ($totalStock < $requiredQty) {
                // $ing = \App\Models\Ingredient::find($ingredientId);
                // abort(422, "Insufficient ingredient stock for {$ing?->name}. Required: {$requiredQty}, Available: {$totalStock}.");
            }
        }
    }

    private function executeStockDeduction(array $requirements, string $orderNo, int $outletId): void
    {
        // Deduct product stock
        foreach ($requirements['products'] as $productId => $requiredQty) {
            $product = Product::query()->find($productId);
            if (!$product) continue;

            ProductStockMovement::query()->create([
                'product_id' => $productId,
                'location_id' => $outletId,
                'direction' => 'out',
                'reason_code' => 'order_sale',
                'quantity' => $requiredQty,
                'unit_cost' => $product->purchase_price_per_unit ?? 0,
                'amount' => round(($product->purchase_price_per_unit ?? 0) * $requiredQty, 2),
                'reference' => $orderNo,
                'note' => "Delivery Order {$orderNo}",
                'occurred_at' => Carbon::now(),
                'created_by_name' => 'System',
            ]);
        }

        // Deduct food menu production stock
        foreach ($requirements['food_menus'] as $menuId => $requiredQty) {
            \App\Models\FoodMenuProductionDetail::query()
                ->whereHas('production', fn ($q) => $q->where('food_menu_id', $menuId)->where('status', 'completed'))
                ->orderBy('id')
                ->take((int) ceil($requiredQty))
                ->update(['consumed_in_order' => $orderNo]);
        }

        // Deduct ingredient stock via FIFO
        $fifoService = app(\App\Services\FifoInventoryService::class);
        foreach ($requirements['ingredients'] as $ingredientId => $requiredQty) {
            $fifoService->consumeStock(
                ingredientId: $ingredientId,
                locationId: $outletId,
                quantity: $requiredQty,
                direction: 'OUT',
                reasonCode: 'order_sale_ingredient',
                reference: $orderNo,
                note: "Delivery Order {$orderNo} ingredient deduction",
                productId: null,
                foodMenuId: null
            );
        }
    }

    private function reverseStock(Order $order): void
    {
        // Reverse product stock
        $productMovements = ProductStockMovement::query()
            ->where('reference', $order->order_no)
            ->whereRaw("LOWER(direction) = 'out'")
            ->get();

        foreach ($productMovements as $movement) {
            ProductStockMovement::query()->create([
                'product_id' => $movement->product_id,
                'location_id' => $movement->location_id,
                'direction' => 'in',
                'reason_code' => 'order_cancellation',
                'quantity' => $movement->quantity,
                'unit_cost' => $movement->unit_cost,
                'amount' => $movement->amount,
                'reference' => 'CANCEL-' . $order->order_no,
                'note' => "Cancellation reversal for Order {$order->order_no}",
                'occurred_at' => Carbon::now(),
                'created_by_name' => 'System',
            ]);
        }

        // Reverse ingredient stock
        $ingredientMovements = \App\Models\IngredientStockMovement::query()
            ->where('reference', $order->order_no)
            ->whereRaw("LOWER(direction) = 'out'")
            ->get();

        foreach ($ingredientMovements as $movement) {
            \App\Models\IngredientStockMovement::query()->create([
                'ingredient_id' => $movement->ingredient_id,
                'location_id' => $movement->location_id,
                'direction' => 'in',
                'quantity_consumption' => $movement->quantity_consumption,
                'reference' => 'CANCEL-' . $order->order_no,
                'note' => "Cancellation reversal for Order {$order->order_no}",
                'occurred_at' => Carbon::now(),
                'created_by' => null,
            ]);
        }

        $order->update(['stock_deduction_status' => 'reversed']);
    }

    private function createSale(Order $order, int $outletId, ?int $userId, float $totalCost): Sale
    {
        if ($order->sale()->exists()) {
            return $order->sale;
        }

        $saleNo = 'SALE-' . $order->order_no;
        $saleAmount = (float) $order->grand_total;

        return Sale::query()->create([
            'sale_no' => $saleNo,
            'order_id' => $order->id,
            'outlet_id' => $outletId,
            'total_amount' => $saleAmount,
            'total_cost' => round($totalCost, 4),
            'profit_amount' => round($saleAmount - $totalCost, 4),
            'sale_at' => Carbon::now(),
            'created_by' => $userId,
        ]);
    }

    private function simpleCancel(Order $order, Request $request): JsonResponse
    {
        $validated = $request->validate(['void_reason' => 'required|string|max:1000']);

        DB::transaction(function () use ($order, $validated, $request) {
            if ($order->stock_deduction_status === 'deducted') {
                $this->reverseStock($order);
            }

            $order->update([
                'order_status' => 'cancelled',
                'cancelled_at' => Carbon::now(),
                'cancelled_by' => $request->user()?->id,
                'cancellation_reason' => $validated['void_reason'],
            ]);
        });

        return response()->json(['message' => 'Delivery order cancelled.']);
    }

    private function printKitchenItems(Order $order): void
    {
        $printerGroups = [];
        $items = $order->items()->get();

        foreach ($items as $item) {
            if ($item->item_type === 'food_menu') {
                $menu = FoodMenu::query()->with('printer')->find($item->item_id);
                if ($menu && $menu->printer) {
                    $printerId = $menu->printer_id;
                    $printerGroups[$printerId][] = [
                        'name' => $item->item_name_snapshot,
                        'qty' => (float) $item->qty,
                        'modifiers' => $item->modifiers->pluck('modifier_item_name_snapshot')->join(', '),
                        'note' => $item->item_note,
                    ];
                }
            } elseif ($item->item_type === 'combo') {
                $components = OrderComboComponent::query()
                    ->where('order_item_id', $item->id)
                    ->where('item_type', 'food_menu')
                    ->whereNotNull('printer_id_snapshot')
                    ->get();

                foreach ($components as $comp) {
                    $printerGroups[$comp->printer_id_snapshot][] = [
                        'name' => $comp->item_name_snapshot,
                        'qty' => (float) $comp->total_qty,
                        'modifiers' => '',
                        'note' => "From: {$item->item_name_snapshot}",
                    ];
                }
            }
        }

        foreach ($printerGroups as $printerId => $printItems) {
            $printer = Printer::query()->find($printerId);
            if (!$printer) continue;

            $this->sendKitchenPrint($printer, $order, $printItems);
        }

        $order->update(['print_status' => 'printed']);
    }

    private function sendKitchenPrint(Printer $printer, Order $order, array $items): void
    {
        $lines = [];
        $lines[] = 'GLOBAL POS';
        $lines[] = str_repeat('-', 32);
        $lines[] = "Order: {$order->order_no}";
        $lines[] = 'Type: Delivery';
        $lines[] = "Partner: {$order->delivery_partner}";
        $lines[] = "Customer: {$order->customer_name}";
        $lines[] = str_repeat('-', 32);

        foreach ($items as $idx => $item) {
            $lines[] = "{$item['name']} x{$item['qty']}";
            if ($item['modifiers']) {
                $lines[] = "  ({$item['modifiers']})";
            }
            if ($item['note']) {
                $lines[] = "  Note: {$item['note']}";
            }
            if ($idx < count($items) - 1) {
                $lines[] = '---';
            }
        }

        $lines[] = str_repeat('-', 32);
        $lines[] = 'Created: ' . Carbon::now()->format('d/m/Y H:i');
        $lines[] = '';
        $lines[] = 'Thank you!';

        $text = implode("\n", $lines);

        $this->rawPrint($printer, $text);
    }

    private function printDeliveryBill(Order $order, int $printerId): void
    {
        $printer = Printer::query()->find($printerId);
        if (!$printer) return;

        $outlet = $order->outlet;
        $lines = [];
        $lines[] = $outlet?->name ?? 'GLOBAL POS';
        $lines[] = $outlet?->address ?? '';
        $lines[] = $outlet?->phone ?? '';
        $lines[] = str_repeat('-', 32);
        $lines[] = 'DELIVERY BILL';
        $lines[] = str_repeat('-', 32);
        $lines[] = "Invoice: SALE-{$order->order_no}";
        $lines[] = "Order: {$order->order_no}";
        $lines[] = 'Date: ' . $order->created_at->format('d/m/Y H:i');
        $lines[] = "Partner: {$order->delivery_partner}";
        $lines[] = "Customer: {$order->customer_name}";
        $lines[] = "Phone: {$order->customer_phone}";
        $lines[] = "Address: {$order->delivery_address}";
        $lines[] = str_repeat('-', 32);

        // Items
        foreach ($order->items as $item) {
            $line = "{$item->item_name_snapshot} x{$item->qty} @ " . number_format((float) $item->final_unit_price, 2);
            $lines[] = $line;
            if ($item->modifiers->isNotEmpty()) {
                $modNames = $item->modifiers->pluck('modifier_item_name_snapshot')->join(', ');
                $lines[] = "  ({$modNames})";
            }
            if ($item->item_note) {
                $lines[] = "  Note: {$item->item_note}";
            }
            $lines[] = '  Amount: ' . number_format((float) $item->amount, 2);
            $lines[] = '---';
        }

        // Summary
        $lines[] = str_repeat('-', 32);
        $lines[] = 'Subtotal: ' . number_format((float) $order->subtotal, 2);
        if ((float) $order->item_discount_amount > 0) {
            $lines[] = 'Item Discount: -' . number_format((float) $order->item_discount_amount, 2);
        }
        if ((float) $order->order_discount_amount > 0) {
            $lines[] = 'Order Discount: -' . number_format((float) $order->order_discount_amount, 2);
        }
        if ((float) $order->service_charge_amount > 0) {
            $lines[] = 'Service Charge: ' . number_format((float) $order->service_charge_amount, 2);
        }
        if ((float) $order->tax_amount > 0) {
            $lines[] = 'Tax: ' . number_format((float) $order->tax_amount, 2);
        }
        if ((float) $order->delivery_fee > 0) {
            $lines[] = 'Delivery Fee: ' . number_format((float) $order->delivery_fee, 2);
        }
        $lines[] = str_repeat('-', 32);
        $lines[] = 'Grand Total: ' . number_format((float) $order->grand_total, 2);

        // Payment
        $lines[] = str_repeat('-', 32);
        foreach ($order->payments as $payment) {
            $pmName = $payment->paymentMethod?->name ?? 'Payment';
            $lines[] = "{$pmName}: " . number_format((float) $payment->amount, 2);
        }
        $lines[] = 'Paid: ' . number_format((float) $order->paid_amount, 2);
        if ((float) $order->change_amount > 0) {
            $lines[] = 'Change: ' . number_format((float) $order->change_amount, 2);
        }

        $lines[] = str_repeat('-', 32);
        $lines[] = 'Thank you!';
        $lines[] = 'Printed: ' . Carbon::now()->format('d/m/Y H:i');
        $lines[] = '';

        $text = implode("\n", $lines);
        $this->rawPrint($printer, $text);

        PrintLog::query()->create([
            'order_id' => $order->id,
            'printer_id' => $printer->id,
            'document_type' => 'delivery_bill',
            'print_status' => 'success',
            'printed_at' => Carbon::now(),
        ]);
    }

    private function rawPrint(Printer $printer, string $text): void
    {
        try {
            $socket = @fsockopen($printer->ip_address, $printer->port, $errno, $errstr, 5);
            if ($socket) {
                fwrite($socket, $text . "\n\n\n\n");
                fclose($socket);
            } else {
                throw new \Exception("Cannot connect to printer {$printer->name}: {$errstr}");
            }
        } catch (\Exception $e) {
            Log::error("Printer {$printer->name} ({$printer->ip_address}:{$printer->port}) error: " . $e->getMessage());
            throw $e;
        }
    }

    private function allowedTransitions(string $currentStatus): array
    {
        return match ($currentStatus) {
            'pending' => ['preparing', 'cancelled'],
            'preparing' => ['ready', 'cancelled'],
            'ready' => ['on_the_way', 'cancelled'],
            'on_the_way' => ['delivered'],
            'delivered' => ['completed'],
            'completed' => [],
            'cancelled' => [],
            default => [],
        };
    }

    private function applyFilters(Builder $query, array $payload): void
    {
        $query
            ->when(isset($payload['search']) && trim((string) $payload['search']) !== '', function (Builder $q) use ($payload): void {
                $s = trim((string) $payload['search']);
                $q->where(function (Builder $qq) use ($s): void {
                    $qq->where('order_no', 'like', "%{$s}%")
                        ->orWhere('customer_name', 'like', "%{$s}%")
                        ->orWhere('customer_phone', 'like', "%{$s}%")
                        ->orWhere('delivery_address', 'like', "%{$s}%");
                });
            })
            ->when(isset($payload['outlet_id']), fn (Builder $q) => $q->where('outlet_id', $payload['outlet_id']))
            ->when(isset($payload['delivery_partner']), fn (Builder $q) => $q->where('delivery_partner', $payload['delivery_partner']))
            ->when(isset($payload['order_status']), fn (Builder $q) => $q->where('order_status', $payload['order_status']))
            ->when(isset($payload['date_from']), fn (Builder $q) => $q->whereDate('created_at', '>=', $payload['date_from']))
            ->when(isset($payload['date_to']), fn (Builder $q) => $q->whereDate('created_at', '<=', $payload['date_to']));
    }

    private function listResource(Order $order): array
    {
        $paymentMethods = $order->payments->pluck('paymentMethod.name')->unique()->join(', ');

        return [
            'id' => $order->id,
            'order_no' => $order->order_no,
            'outlet_name' => $order->outlet?->name ?? '',
            'delivery_partner' => $order->delivery_partner,
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'delivery_address' => $order->delivery_address,
            'delivery_fee' => (float) ($order->delivery_fee ?? 0),
            'grand_total' => (float) $order->grand_total,
            'paid_amount' => (float) $order->paid_amount,
            'payment_methods' => $paymentMethods,
            'order_status' => $order->order_status,
            'created_by' => $order->createdBy?->name,
            'created_at' => $order->created_at?->toIso8601String(),
        ];
    }

    private function detailResource(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_no' => $order->order_no,
            'sale_no' => $order->sale?->sale_no,
            'outlet_id' => $order->outlet_id,
            'outlet_name' => $order->outlet?->name ?? '',
            'order_type' => $order->order_type,
            'order_status' => $order->order_status,
            'delivery_partner' => $order->delivery_partner,
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'delivery_address' => $order->delivery_address,
            'delivery_fee' => (float) ($order->delivery_fee ?? 0),
            'delivery_note' => $order->order_note,
            'subtotal' => (float) ($order->subtotal ?? 0),
            'item_discount_amount' => (float) ($order->item_discount_amount ?? 0),
            'order_discount_type' => $order->order_discount_type,
            'order_discount_value' => (float) ($order->order_discount_value ?? 0),
            'order_discount_amount' => (float) ($order->order_discount_amount ?? 0),
            'tax_rate_snapshot' => (float) ($order->tax_rate_snapshot ?? 0),
            'tax_amount' => (float) ($order->tax_amount ?? 0),
            'service_charge_rate_snapshot' => (float) ($order->service_charge_rate_snapshot ?? 0),
            'service_charge_amount' => (float) ($order->service_charge_amount ?? 0),
            'grand_total' => (float) ($order->grand_total ?? 0),
            'paid_amount' => (float) ($order->paid_amount ?? 0),
            'balance_amount' => (float) ($order->balance_amount ?? 0),
            'change_amount' => (float) ($order->change_amount ?? 0),
            'payment_state' => 'paid',
            'stock_deduction_status' => $order->stock_deduction_status,
            'stock_deducted_at' => $order->stock_deducted_at?->toIso8601String(),
            'payment_completed_at' => $order->payment_completed_at?->toIso8601String(),
            'completed_at' => $order->completed_at?->toIso8601String(),
            'cancelled_at' => $order->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $order->cancellation_reason,
            'created_by' => $order->createdBy?->name,
            'updated_by' => $order->updatedBy?->name,
            'created_at' => $order->created_at?->toIso8601String(),
            'updated_at' => $order->updated_at?->toIso8601String(),
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'item_type' => $item->item_type,
                'item_name_snapshot' => $item->item_name_snapshot,
                'unit_name_snapshot' => $item->unit_name_snapshot,
                'qty' => (float) $item->qty,
                'base_unit_price_snapshot' => (float) ($item->base_unit_price_snapshot ?? 0),
                'modifier_price' => (float) ($item->modifier_price ?? 0),
                'final_unit_price' => (float) ($item->final_unit_price ?? 0),
                'discount_amount' => (float) ($item->discount_amount ?? 0),
                'amount' => (float) ($item->amount ?? 0),
                'item_note' => $item->item_note,
                'modifiers' => $item->modifiers->map(fn ($m) => [
                    'modifier_group_name_snapshot' => $m->modifier_group_name_snapshot,
                    'modifier_item_name_snapshot' => $m->modifier_item_name_snapshot,
                    'price_adjustment_snapshot' => (float) ($m->price_adjustment_snapshot ?? 0),
                ])->all(),
                'combo_components' => $item->comboComponents->map(fn ($c) => [
                    'item_type' => $c->item_type,
                    'item_name_snapshot' => $c->item_name_snapshot,
                    'total_qty' => (float) $c->total_qty,
                    'unit_name_snapshot' => $c->unit_name_snapshot,
                ])->all(),
            ])->all(),
            'payments' => $order->payments->map(fn ($p) => [
                'id' => $p->id,
                'payment_method_id' => $p->payment_method_id,
                'payment_method_name' => $p->paymentMethod?->name ?? '',
                'amount' => (float) $p->amount,
                'reference_no' => $p->reference_no,
            ])->all(),
        ];
    }
}
