<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashRegister;
use App\Models\Charge;
use App\Models\Discount;
use App\Models\Floor;
use App\Models\FoodMenu;
use App\Models\FoodMenuIngredient;
use App\Models\IngredientStockMovement;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderComboComponent;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Printer;
use App\Models\PrintLog;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\Refund;
use App\Models\RestaurantTable;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleModifier;
use App\Models\SalePayment;
use App\Models\Scopes\OutletScope;
use App\Models\TaxRate;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CashierPanelController extends Controller
{
    public function createData(Request $request): JsonResponse
    {
        $user = auth()->user();
        $outletId = $request->integer('location_id') ?: $request->integer('outlet_id') ?: null;

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

        $paymentMethods = PaymentMethod::query()->where('is_active', true)->get(['id', 'name']);
        $printers = Printer::query()->where('is_active', true)->get(['id', 'name', 'ip_address', 'port', 'paper_size', 'copies']);
        $taxRates = TaxRate::query()->where('is_active', true)->get(['id', 'name', 'value', 'type']);
        $charges = Charge::query()->where('is_active', true)->get(['id', 'name', 'value', 'type', 'apply_to']);
        $discounts = Discount::query()->where('is_active', true)->get(['id', 'name', 'value', 'type']);
        $floors = Floor::withoutGlobalScope(OutletScope::class)
            ->where('is_active', true)
            ->whereIn('location_id', $allowedOutletIds)
            ->when($outletId, fn ($q) => $q->where('location_id', $outletId))
            ->with(['tables' => function ($q) use ($outletId, $allowedOutletIds) {
                $q->withoutGlobalScope(OutletScope::class)
                    ->where('is_active', true)
                    ->whereIn('outlet_id', $allowedOutletIds)
                    ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
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
            ->with(['items.modifiers', 'items.comboComponents'])
            ->latest('created_at')
            ->get()
            ->groupBy('table_id');

        foreach ($floors as $floor) {
            foreach ($floor->tables as $table) {
                $orders = $activeOrders->get($table->id);
                $table->active_order = $orders?->first();
                $table->active_orders = $orders ?? [];
            }
        }

        return response()->json([
            'outlets' => $outlets,
            'payment_methods' => $paymentMethods,
            'printers' => $printers,
            'tax_rates' => $taxRates,
            'charges' => $charges,
            'discounts' => $discounts,
            'floors' => $floors,
            'user' => $user?->only(['id', 'name', 'email']),
        ]);
    }

    /**
     * Check current register status for an outlet.
     */
    public function registerStatus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'outlet_id' => 'nullable|integer|exists:locations,id',
        ]);

        $registerQuery = CashRegister::query()
            ->with('outlet:id,name')
            ->where('status', 'open')
            ->latest('opened_at');

        if (! empty($validated['outlet_id'])) {
            $registerQuery->where('outlet_id', $validated['outlet_id']);
        }

        $register = $registerQuery->first();

        return response()->json([
            'register' => $register,
            'summary' => $register ? $this->registerSummary($register) : null,
        ]);
    }

    /**
     * Open register before starting payments.
     */
    public function openRegister(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'outlet_id' => 'required|integer|exists:locations,id',
            'opening_balance' => 'required|numeric|min:0',
            'cashier_name' => 'nullable|string|max:120',
            'note' => 'nullable|string|max:1000',
        ]);

        $user = $request->user();

        return DB::transaction(function () use ($validated, $user) {
            $existing = CashRegister::query()
                ->where('outlet_id', $validated['outlet_id'])
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return response()->json([
                    'message' => 'This outlet already has an open register.',
                ], 422);
            }

            $register = CashRegister::query()->create([
                'outlet_id' => $validated['outlet_id'],
                'cashier_id' => $user?->id,
                'cashier_name_snapshot' => trim($validated['cashier_name'] ?? '') ?: ($user?->name ?? 'Cashier'),
                'opened_at' => Carbon::now(),
                'opening_balance' => $validated['opening_balance'],
                'cash_sale_amount' => 0,
                'other_payment_amount' => 0,
                'opening_note' => $validated['note'] ?? null,
                'status' => 'open',
            ]);

            $register->load('outlet:id,name');

            return response()->json([
                'register' => $register,
                'summary' => $this->registerSummary($register),
                'message' => 'Register opened successfully.',
            ], 201);
        });
    }

    /**
     * Close shift register and record difference amount.
     */
    public function closeRegister(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'actual_closing_balance' => 'required|numeric|min:0',
            'note' => 'nullable|string|max:1000',
        ]);

        return DB::transaction(function () use ($id, $validated) {
            $register = CashRegister::query()->lockForUpdate()->findOrFail($id);

            if ($register->status !== 'open') {
                return response()->json(['message' => 'This register is already closed.'], 422);
            }

            $expected = round((float) $register->opening_balance + (float) $register->cash_sale_amount, 2);
            $actual = round((float) $validated['actual_closing_balance'], 2);
            $diff = round($actual - $expected, 2);

            $register->update([
                'expected_closing_balance' => $expected,
                'actual_closing_balance' => $actual,
                'difference_amount' => $diff,
                'closing_note' => $validated['note'] ?? null,
                'closed_at' => Carbon::now(),
                'status' => 'closed',
            ]);

            $register->load('outlet:id,name');

            return response()->json([
                'register' => $register,
                'summary' => $this->registerSummary($register),
                'message' => 'Register closed successfully.',
            ]);
        });
    }

    /**
     * Fetch active Waiter Panel orders categorized by tab (dine_in, takeaway, delivery).
     */
    public function orders(Request $request): JsonResponse
    {
        $query = Order::query()
            ->with([
                'items.modifiers',
                'items.comboComponents',
                'payments.paymentMethod:id,name',
                'floor:id,name',
                'table:id,table_no',
                'outlet:id,name',
                'createdBy:id,name',
                'sale',
            ]);

        if ($request->filled('outlet_id')) {
            $query->where('outlet_id', $request->outlet_id);
        }

        if ($request->filled('floor_id')) {
            $query->where('floor_id', $request->floor_id);
        }

        if ($request->filled('order_type')) {
            $typeMap = [
                'dine_in' => 'dine_in',
                'dine-in' => 'dine_in',
                'take_away' => 'takeaway',
                'take-away' => 'takeaway',
                'takeaway' => 'takeaway',
                'delivery' => 'delivery',
            ];
            $typeKey = strtolower($request->order_type);
            $targetType = $typeMap[$typeKey] ?? $request->order_type;
            $query->where('order_type', $targetType);
        }

        if ($request->filled('order_status')) {
            if ($request->order_status === 'active') {
                $query->whereNotIn('order_status', ['completed', 'cancelled']);
            } else {
                $query->where('order_status', $request->order_status);
            }
        } else {
            // Cashier Panel settlement queue: Active orders placed from Waiter Panel
            $query->whereNotIn('order_status', ['completed', 'cancelled']);
            if (($targetType ?? null) !== 'delivery') {
                $query->where('payment_state', 'unpaid');
            }
        }

        if ($request->filled('search')) {
            $s = trim($request->search);
            $cleanTableNum = preg_replace('/[^0-9]/', '', $s);

            $query->where(function ($q) use ($s, $cleanTableNum) {
                $q->where('order_no', 'like', "%{$s}%")
                    ->orWhere('id', $s)
                    ->orWhere('customer_name', 'like', "%{$s}%")
                    ->orWhere('customer_phone', 'like', "%{$s}%")
                    ->orWhereHas('table', function ($tQ) use ($s, $cleanTableNum) {
                        $tQ->where('table_no', 'like', "%{$s}%")
                            ->orWhere('name', 'like', "%{$s}%");
                        if (! empty($cleanTableNum)) {
                            $tQ->orWhere('table_no', 'like', "%{$cleanTableNum}%")
                                ->orWhere('name', 'like', "%{$cleanTableNum}%");
                        }
                });

                if (! empty($cleanTableNum)) {
                    $q->orWhere('id', (int) $cleanTableNum);
                }
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $orders = $query->orderBy('created_at', 'desc')
            ->paginate($this->boundedPageSize($request, 50));

        return response()->json($orders);
    }

    /**
     * Show single order details with combo items and modifiers.
     */
    public function showOrder(int $id): JsonResponse
    {
        $order = Order::query()
            ->with([
                'items.modifiers',
                'items.comboComponents',
                'payments.paymentMethod:id,name',
                'floor:id,name',
                'table:id,table_no',
                'outlet:id,name',
                'createdBy:id,name',
                'sale',
            ])
            ->findOrFail($id);

        return response()->json(['order' => $order]);
    }

    /**
     * Update discount, tax, service charge before payment submit.
     */
    public function updateCharges(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'tax_rate_id' => 'nullable|integer|exists:tax_rates,id',
            'charge_id' => 'nullable|integer|exists:charges,id',
            'discount_id' => 'nullable|integer|exists:discounts,id',
            'custom_discount_type' => 'nullable|string|in:amount,percent',
            'custom_discount_value' => 'nullable|numeric|min:0',
            'custom_service_charge' => 'nullable|numeric|min:0',
            'custom_tax_percent' => 'nullable|numeric|min:0',
            'delivery_fee' => 'nullable|numeric|min:0',
        ]);

        $order = Order::query()->with('items')->findOrFail($id);

        if (in_array($order->order_status, ['completed', 'cancelled'], true)) {
            return response()->json(['message' => 'Cannot modify a completed or cancelled order.'], 422);
        }

        if (in_array($order->payment_state, ['paid', 'refunded'], true) && $order->order_type !== 'delivery') {
            return response()->json(['message' => 'Cannot modify charges after payment.'], 422);
        }

        $subtotal = (float) $order->subtotal;

        $orderDiscountAmount = 0;
        $orderDiscountType = null;
        $orderDiscountValue = 0;

        if ($request->filled('custom_discount_type') && $request->filled('custom_discount_value')) {
            $orderDiscountType = $request->input('custom_discount_type');
            $orderDiscountValue = (float) $request->input('custom_discount_value');

            if ($orderDiscountType === 'amount') {
                $orderDiscountAmount = min($orderDiscountValue, $subtotal);
            } else {
                $pct = min($orderDiscountValue, 100);
                $orderDiscountAmount = round($subtotal * $pct / 100, 2);
            }
        } elseif (! empty($validated['discount_id'])) {
            $discount = Discount::query()->find($validated['discount_id']);
            if ($discount && $discount->is_active) {
                $orderDiscountType = $discount->type === 'fixed' ? 'amount' : 'percent';
                $orderDiscountValue = (float) $discount->value;
                if ($discount->type === 'fixed') {
                    $orderDiscountAmount = min($orderDiscountValue, $subtotal);
                } else {
                    $pct = min($orderDiscountValue, 100);
                    $orderDiscountAmount = round($subtotal * $pct / 100, 2);
                }
            }
        }

        $taxableAmount = max(0, $subtotal - $orderDiscountAmount);

        $serviceChargeAmount = 0;
        $serviceChargeRateSnapshot = 0;
        if ($request->filled('custom_service_charge')) {
            $serviceChargeAmount = (float) $request->input('custom_service_charge');
        } elseif (! empty($validated['charge_id'])) {
            $charge = Charge::query()->find($validated['charge_id']);
            if ($charge && $charge->is_active) {
                $serviceChargeRateSnapshot = (float) $charge->value;
                if ($charge->type === 'percentage') {
                    $serviceChargeAmount = round($taxableAmount * $serviceChargeRateSnapshot / 100, 2);
                } else {
                    $serviceChargeAmount = $serviceChargeRateSnapshot;
                }
            }
        }

        $taxAmount = 0;
        $taxRateSnapshot = 0;
        if ($request->filled('custom_tax_percent')) {
            $taxRateSnapshot = (float) $request->input('custom_tax_percent');
            $taxAmount = round($taxableAmount * $taxRateSnapshot / 100, 2);
        } elseif (! empty($validated['tax_rate_id'])) {
            $taxRate = TaxRate::query()->find($validated['tax_rate_id']);
            if ($taxRate && $taxRate->is_active) {
                $taxRateSnapshot = (float) $taxRate->value;
                $taxAmount = $taxRate->type === 'fixed'
                    ? min($taxRateSnapshot, $taxableAmount)
                    : round($taxableAmount * $taxRateSnapshot / 100, 2);
            }
        }

        $deliveryFee = array_key_exists('delivery_fee', $validated)
            ? (float) ($validated['delivery_fee'] ?? 0)
            : (float) ($order->delivery_fee ?? 0);
        $grandTotal = round(max(0, $subtotal - $orderDiscountAmount + $taxAmount + $serviceChargeAmount + $deliveryFee), 2);

        $order->update([
            'order_discount_type' => $orderDiscountType,
            'order_discount_value' => $orderDiscountValue,
            'order_discount_amount' => $orderDiscountAmount,
            'tax_rate_snapshot' => $taxRateSnapshot,
            'tax_amount' => $taxAmount,
            'service_charge_rate_snapshot' => $serviceChargeRateSnapshot,
            'service_charge_amount' => $serviceChargeAmount,
            'delivery_fee' => $deliveryFee,
            'grand_total' => $grandTotal,
            'balance_amount' => max(0, $grandTotal - (float) $order->paid_amount),
            'version_number' => (int) $order->version_number + 1,
        ]);

        $order = $order->fresh()->load([
            'items.modifiers',
            'items.comboComponents',
            'payments.paymentMethod:id,name',
            'floor:id,name',
            'table:id,table_no',
            'outlet:id,name',
            'createdBy:id,name',
        ]);

        return response()->json(['order' => $order]);
    }

    /**
     * Calculate payment amounts, change, and balance.
     */
    public function calculatePayment(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'payments' => 'required|array|min:1',
            'payments.*.payment_method_id' => 'required|exists:payment_methods,id',
            'payments.*.amount' => 'required|numeric|min:0.01',
            'payments.*.reference' => 'nullable|string|max:120',
        ]);

        $order = Order::query()->findOrFail($id);

        $registerOpen = CashRegister::query()
            ->where('outlet_id', $order->outlet_id)
            ->where('status', 'open')
            ->exists();
        if (! $registerOpen) {
            return response()->json(['message' => 'Please open register before payment.'], 422);
        }

        $paidAmount = collect($validated['payments'])->sum('amount');
        $balance = max(0, $order->grand_total - $paidAmount);
        $change = max(0, $paidAmount - $order->grand_total);

        return response()->json([
            'grand_total' => (float) $order->grand_total,
            'paid_amount' => round($paidAmount, 2),
            'balance' => round($balance, 2),
            'change' => round($change, 2),
        ]);
    }

    /**
     * Submit payment, generate sale invoice, update register cash, release Dine-in table.
     */
    public function completePayment(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'payments' => 'required|array|min:1',
            'payments.*.payment_method_id' => 'required|exists:payment_methods,id',
            'payments.*.amount' => 'required|numeric|min:0.01',
            'payments.*.reference' => 'nullable|string|max:120',
        ]);

        $user = $request->user();

        return DB::transaction(function () use ($id, $validated, $user) {
            $order = Order::query()->where('id', $id)->lockForUpdate()->firstOrFail();

            if (in_array($order->order_status, ['completed', 'cancelled'])) {
                return response()->json(['message' => 'Order is already completed or cancelled.'], 422);
            }

            // CRITICAL VALIDATION: Register must be open before payment submit!
            $register = CashRegister::query()
                ->where('outlet_id', $order->outlet_id)
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();

            if (! $register) {
                return response()->json(['message' => 'Please open register before payment.'], 422);
            }

            $order->load(['items.modifiers', 'items.comboComponents']);

            $totalPaid = collect($validated['payments'])->sum('amount');
            $previousPaid = round((float) $order->paid_amount, 2);
            $calcPaid = round($totalPaid + $previousPaid, 2);
            $calcGrand = round((float) $order->grand_total, 2);
            $outstandingBeforePayment = max(0, round($calcGrand - $previousPaid, 2));

            $diff = $calcGrand - $calcPaid;
            // 1. Kyat rounding tolerance (e.g. 0.25 or 0.50 MMK difference is rounded as full payment)
            if ($diff > 0 && $diff <= 1.0) {
                $calcPaid = $calcGrand;
                $diff = 0;
            }

            // Record payment details
            foreach ($validated['payments'] as $paymentData) {
                Payment::query()->create([
                    'order_id' => $order->id,
                    'cash_register_id' => $register->id,
                    'payment_method_id' => $paymentData['payment_method_id'],
                    'amount' => $paymentData['amount'],
                    'reference' => $paymentData['reference'] ?? null,
                    'created_by' => $user?->id,
                ]);
            }

            if ($diff <= 1.0) {
                $balanceAmount = 0.0;
                $changeAmount = max(0, round($calcPaid - $calcGrand, 2));
                $paymentState = 'paid';
                $orderStatus = 'completed';
            } else {
                $balanceAmount = round($diff, 2);
                $changeAmount = 0.0;
                $paymentState = 'partially_paid';
                $orderStatus = 'confirmed';
            }

            // Register totals reflect only the amount due from this payment,
            // excluding any change returned to the customer.
            $this->updateRegisterPaymentTotals(
                $register,
                $validated['payments'],
                min((float) $totalPaid, (float) $outstandingBeforePayment),
            );

            if ($paymentState === 'paid') {
                if ($order->stock_deduction_status !== 'deducted') {
                    $this->deductOrderStock($order, $order->outlet_id);
                }

                $allPayments = $order->payments()
                    ->get(['payment_method_id', 'amount'])
                    ->map(fn (Payment $payment): array => [
                        'payment_method_id' => (int) $payment->payment_method_id,
                        'amount' => (float) $payment->amount,
                    ])
                    ->all();

                // Financial sales are created only after the order is fully
                // paid, and include every payment in a split settlement.
                $this->createSaleWithDetails($order, $order->outlet_id, $user?->id, $allPayments, $register->id);
            }

            $firstPaymentMethodId = $validated['payments'][0]['payment_method_id'] ?? $order->payment_method_id;

            $order->update([
                'payment_method_id' => $firstPaymentMethodId,
                'paid_amount' => $calcPaid,
                'balance_amount' => $balanceAmount,
                'change_amount' => $changeAmount,
                'payment_completed_at' => $paymentState === 'paid' ? Carbon::now() : $order->payment_completed_at,
                'payment_state' => $paymentState,
                'order_status' => $orderStatus,
                'stock_deduction_status' => $paymentState === 'paid'
                    ? 'deducted'
                    : $order->stock_deduction_status,
                'stock_deducted_at' => $paymentState === 'paid'
                    ? ($order->stock_deducted_at ?? Carbon::now())
                    : $order->stock_deducted_at,
            ]);

            // Release Dine-in Table if fully paid and no active orders remain
            if ($paymentState === 'paid' && $order->order_type === 'dine_in' && $order->table_id) {
                $activeOrderCount = Order::query()
                    ->where('table_id', $order->table_id)
                    ->where('id', '!=', $order->id)
                    ->whereNotIn('order_status', ['completed', 'cancelled'])
                    ->count();

                if ($activeOrderCount === 0) {
                    RestaurantTable::query()->where('id', $order->table_id)->update(['status' => 'available']);
                }
            }

            if ($paymentState === 'paid') {
                $this->printBillDocument($order);
            }

            $order->load([
                'items.modifiers',
                'items.comboComponents',
                'payments.paymentMethod:id,name',
                'floor:id,name',
                'table:id,table_no',
                'outlet:id,name',
                'createdBy:id,name',
                'sale',
            ]);

            return response()->json([
                'order' => $order,
                'message' => 'Payment completed successfully.',
            ]);
        });
    }

    public function printCheck(Request $request, int $id): JsonResponse
    {
        $order = Order::query()
            ->with(['items.modifiers', 'payments.paymentMethod:id,name', 'floor:id,name', 'table:id,table_no', 'outlet:id,name,address,phone,image_url', 'createdBy:id,name'])
            ->findOrFail($id);

        $customPayments = $request->input('payments');
        $checkText = $this->buildCheckText($order, is_array($customPayments) ? $customPayments : null);
        if ($request->boolean('direct_print', false)) {
            $this->sendDocumentToPrinter($order, $checkText, 'check', false, $request->input('printer_id'));
        }

        return response()->json([
            'message' => 'Check printed successfully.',
            'check_text' => $checkText,
        ]);
    }

    public function printBill(Request $request, int $id): JsonResponse
    {
        $order = Order::query()
            ->with(['items.modifiers', 'payments.paymentMethod:id,name', 'floor:id,name', 'table:id,table_no', 'outlet:id,name,address,phone,image_url', 'createdBy:id,name', 'sale.createdBy:id,name'])
            ->findOrFail($id);

        $customPayments = $request->input('payments');
        $billText = $this->buildBillText($order, is_array($customPayments) ? $customPayments : null);
        $isReprint = $request->boolean('is_reprint', false);
        if ($request->boolean('direct_print', false)) {
            $this->sendDocumentToPrinter($order, $billText, 'bill', $isReprint, $request->input('printer_id'));
        }

        return response()->json([
            'message' => 'Bill printed successfully.',
            'check_text' => $billText,
            'bill_text' => $billText,
        ]);
    }

    /**
     * Cancel an active order with reason and release table.
     */
    public function cancelOrder(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'cancellation_reason' => 'required|string|max:1000',
        ]);

        $order = Order::query()->findOrFail($id);

        if (in_array($order->order_status, ['completed', 'cancelled'])) {
            return response()->json(['message' => 'Order is already completed or cancelled.'], 422);
        }

        $user = $request->user();

        return DB::transaction(function () use ($order, $validated, $user) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->stock_deduction_status === 'deducted') {
                $this->reverseOrderStock($order);
                $order->stock_deduction_status = 'reversed';
            }

            if ($order->order_type === 'dine_in' && $order->table_id) {
                $activeOrderCount = Order::query()
                    ->where('table_id', $order->table_id)
                    ->where('id', '!=', $order->id)
                    ->whereNotIn('order_status', ['completed', 'cancelled'])
                    ->count();

                if ($activeOrderCount === 0) {
                    RestaurantTable::query()->where('id', $order->table_id)->update(['status' => 'available']);
                }
            }

            $order->update([
                'order_status' => 'cancelled',
                'cancelled_at' => Carbon::now(),
                'cancelled_by' => $user?->id,
                'cancellation_reason' => $validated['cancellation_reason'],
            ]);

            return response()->json(['message' => 'Order cancelled successfully.']);
        });
    }

    /**
     * List completed sales with filters and pagination.
     */
    public function indexSales(Request $request): JsonResponse
    {
        $query = Sale::query()
            ->with([
                'items.modifiers',
                'payments.paymentMethod:id,name',
                'refunds:id,sale_id,refund_amount',
                'order.table',
                'order.floor',
                'order.createdBy:id,name',
                'createdBy:id,name',
                'outlet:id,name',
            ]);

        if ($request->filled('outlet_id')) {
            $query->where('outlet_id', $request->outlet_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $s = trim($request->search);
            $cleanNum = preg_replace('/[^0-9]/', '', $s);

            $query->where(function ($q) use ($s, $cleanNum) {
                $q->where('sale_no', 'like', "%{$s}%")
                    ->orWhereHas('order', function ($oq) use ($s, $cleanNum) {
                        $oq->where('order_no', 'like', "%{$s}%")
                            ->orWhere('id', $s)
                            ->orWhere('customer_name', 'like', "%{$s}%")
                            ->orWhere('customer_phone', 'like', "%{$s}%")
                            ->orWhere('table_no', 'like', "%{$s}%")
                            ->orWhereHas('table', function ($tQ) use ($s, $cleanNum) {
                                $tQ->where('table_no', 'like', "%{$s}%")
                                    ->orWhere('name', 'like', "%{$s}%");
                                if (! empty($cleanNum)) {
                                    $tQ->orWhere('table_no', 'like', "%{$cleanNum}%")
                                        ->orWhere('name', 'like', "%{$cleanNum}%");
                                }
                            });

                        if (! empty($cleanNum)) {
                            $oq->orWhere('table_no', 'like', "%{$cleanNum}%")
                                ->orWhere('id', (int) $cleanNum);
                        }
                    });
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('sale_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('sale_at', '<=', $request->date_to);
        }

        if ($request->filled('order_type')) {
            $query->whereHas('order', function ($oq) use ($request) {
                $oq->where('order_type', $request->order_type);
            });
        }

        if ($request->filled('payment_type')) {
            $paymentType = $request->payment_type;
            $query->whereHas('payments', function ($pq) use ($paymentType) {
                $pq->where('payment_method_name_snapshot', $paymentType)
                    ->orWhereHas('paymentMethod', fn ($pmq) => $pmq->where('name', $paymentType));
            });
        }

        if ($request->filled('payment_status')) {
            $paidSubquery = 'COALESCE((SELECT SUM(amount) FROM sale_payments WHERE sale_payments.sale_id = sales.id), 0)';
            $refundSubquery = 'COALESCE((SELECT SUM(refund_amount) FROM refunds WHERE refunds.sale_id = sales.id), 0)';
            $netPaidExpression = "({$paidSubquery} - {$refundSubquery})";

            match ($request->payment_status) {
                'paid' => $query->whereRaw("{$netPaidExpression} >= sales.total_amount"),
                'partial' => $query
                    ->whereRaw("{$netPaidExpression} > 0")
                    ->whereRaw("{$netPaidExpression} < sales.total_amount"),
                'unpaid' => $query->whereRaw("{$netPaidExpression} <= 0"),
                default => null,
            };
        }

        $sales = $query->orderBy('sale_at', 'desc')
            ->paginate($this->boundedPageSize($request, 30));

        $sales->getCollection()->transform(function (Sale $sale) {
            $paidAmount = round((float) $sale->payments->sum('amount'), 2);
            $refundedAmount = round((float) $sale->refunds->sum('refund_amount'), 2);
            $netPaid = max(0, round($paidAmount - $refundedAmount, 2));
            $balanceAmount = max(0, round((float) $sale->total_amount - $netPaid, 2));

            $sale->setAttribute('paid_amount', $netPaid);
            $sale->setAttribute('refunded_amount', $refundedAmount);
            $sale->setAttribute('balance_amount', $balanceAmount);
            $sale->setAttribute(
                'payment_status',
                $balanceAmount <= 0 ? 'paid' : ($netPaid > 0 ? 'partial' : 'unpaid')
            );

            return $sale;
        });

        return response()->json($sales);
    }

    public function showSale(int $id): JsonResponse
    {
        $sale = Sale::query()
            ->with(['items.modifiers', 'payments.paymentMethod:id,name', 'order.createdBy:id,name', 'createdBy:id,name', 'outlet:id,name'])
            ->findOrFail($id);

        return response()->json(['sale' => $sale]);
    }

    /**
     * Reprint bill for completed sale.
     */
    public function reprintSaleBill(Request $request, int $id): JsonResponse
    {
        $sale = Sale::query()->with(['order'])->findOrFail($id);
        $order = Order::query()
            ->with(['items.modifiers', 'payments.paymentMethod:id,name', 'floor:id,name', 'table:id,table_no', 'outlet:id,name,address,phone,image_url', 'createdBy:id,name', 'sale.createdBy:id,name'])
            ->findOrFail($sale->order_id);

        $billText = $this->buildReprintBillText($order, $sale);
        $this->sendDocumentToPrinter($order, $billText, 'bill_reprint', true, $request->input('printer_id'));

        return response()->json([
            'message' => 'Bill reprinted successfully.',
            'bill_text' => $billText,
        ]);
    }

    private function buildReprintBillText(Order $order, Sale $sale): string
    {
        $text = $this->buildBillText($order);
        return str_replace(
            '*** SALE INVOICE VOUCHER ***',
            "*** SALE INVOICE (REPRINT) ***\nReprinted: ".Carbon::now()->format('d/m/Y H:i'),
            $text
        );
    }

    public function voidSale(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'void_reason' => 'required|string|max:1000',
        ]);

        $sale = Sale::query()->with(['order', 'payments'])->findOrFail($id);

        if ($sale->status === 'voided') {
            return response()->json(['message' => 'Sale is already voided.'], 422);
        }

        $user = $request->user();

        return DB::transaction(function () use ($sale, $validated, $user) {
            $order = $sale->order;

            if ($order) {
                $this->reverseOrderStock($order);

                if ($order->order_type === 'dine_in' && $order->table_id) {
                    $activeOrderCount = Order::query()
                        ->where('table_id', $order->table_id)
                        ->where('id', '!=', $order->id)
                        ->whereNotIn('order_status', ['completed', 'cancelled'])
                        ->count();

                    if ($activeOrderCount === 0) {
                        RestaurantTable::query()->where('id', $order->table_id)->update(['status' => 'available']);
                    }
                }

                $order->update([
                    'order_status' => 'cancelled',
                    'cancelled_at' => Carbon::now(),
                    'cancelled_by' => $user?->id,
                    'cancellation_reason' => $validated['void_reason'],
                ]);
            }

            $sale->update(['status' => 'voided']);
            $this->reverseRegisterPaymentTotals($sale, (float) $sale->total_amount);

            return response()->json(['message' => 'Sale voided successfully.']);
        });
    }

    public function refundSale(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'refund_reason' => 'required|string|max:1000',
            'refund_amount' => 'required|numeric|min:0.01',
            'return_to_stock' => 'required|boolean',
        ]);

        $sale = Sale::query()->with(['order', 'payments', 'refunds'])->findOrFail($id);

        if (in_array($sale->status, ['voided', 'refunded'])) {
            return response()->json(['message' => 'Sale is already voided or refunded.'], 422);
        }

        $alreadyRefunded = (float) $sale->refunds->sum('refund_amount');
        $remainingRefundable = max(0, (float) $sale->total_amount - $alreadyRefunded);

        if ((float) $validated['refund_amount'] > $remainingRefundable) {
            return response()->json(['message' => 'Refund amount exceeds the remaining paid amount.'], 422);
        }

        $user = $request->user();

        return DB::transaction(function () use ($sale, $validated, $user, $alreadyRefunded) {
            $refundNo = 'RFND-'.$sale->sale_no.'-'.str_pad((string) ($sale->refunds->count() + 1), 2, '0', STR_PAD_LEFT);

            Refund::query()->create([
                'refund_no' => $refundNo,
                'sale_id' => $sale->id,
                'refund_type' => $validated['refund_amount'] >= $sale->total_amount ? 'full' : 'partial',
                'refund_amount' => $validated['refund_amount'],
                'return_to_stock' => $validated['return_to_stock'],
                'reason' => $validated['refund_reason'],
                'created_by' => $user?->id,
            ]);

            $order = $sale->order;

            if ($validated['return_to_stock'] && $order) {
                $this->reverseOrderStock($order);
            }

            $isFullRefund = ((float) $validated['refund_amount'] + $alreadyRefunded) >= (float) $sale->total_amount;

            $sale->update(['status' => $isFullRefund ? 'refunded' : 'completed']);
            $this->reverseRegisterPaymentTotals($sale, (float) $validated['refund_amount']);

            if ($order) {
                $orderStatus = $isFullRefund ? 'cancelled' : 'completed';

                if ($isFullRefund && $order->order_type === 'dine_in' && $order->table_id) {
                    $activeOrderCount = Order::query()
                        ->where('table_id', $order->table_id)
                        ->where('id', '!=', $order->id)
                        ->whereNotIn('order_status', ['completed', 'cancelled'])
                        ->count();

                    if ($activeOrderCount === 0) {
                        RestaurantTable::query()->where('id', $order->table_id)->update(['status' => 'available']);
                    }
                }

                $order->update([
                    'order_status' => $orderStatus,
                    'payment_state' => $isFullRefund ? 'refunded' : 'partial_refund',
                ]);
            }

            return response()->json(['message' => 'Sale refunded successfully.']);
        });
    }

    private function registerSummary(CashRegister $register): array
    {
        $opening = (float) $register->opening_balance;
        $cashSales = (float) $register->cash_sale_amount;
        $otherPayments = (float) $register->other_payment_amount;

        return [
            'opening_balance' => round($opening, 2),
            'cash_sale_amount' => round($cashSales, 2),
            'other_payment_amount' => round($otherPayments, 2),
            'total_collected' => round($cashSales + $otherPayments, 2),
            'expected_closing_balance' => round($opening + $cashSales, 2),
        ];
    }

    private function updateRegisterPaymentTotals(CashRegister $register, array $payments, float $settlementTotal): void
    {
        $names = PaymentMethod::query()
            ->whereIn('id', collect($payments)->pluck('payment_method_id')->filter()->unique())
            ->pluck('name', 'id');

        $remaining = round($settlementTotal, 2);
        $cashAmount = 0.0;
        $otherAmount = 0.0;

        foreach ($payments as $payment) {
            if ($remaining <= 0) {
                break;
            }

            $allocated = min($remaining, max(0, (float) $payment['amount']));
            $remaining = round($remaining - $allocated, 2);
            $methodName = (string) ($names->get($payment['payment_method_id']) ?? '');

            if ($this->isCashPaymentName($methodName)) {
                $cashAmount += $allocated;
            } else {
                $otherAmount += $allocated;
            }
        }

        $register->update([
            'cash_sale_amount' => round((float) $register->cash_sale_amount + $cashAmount, 2),
            'other_payment_amount' => round((float) $register->other_payment_amount + $otherAmount, 2),
        ]);
    }

    private function reverseRegisterPaymentTotals(Sale $sale, float $amount): void
    {
        if (! $sale->cash_register_id || $amount <= 0) {
            return;
        }

        $register = CashRegister::query()->lockForUpdate()->find($sale->cash_register_id);

        if (! $register || $register->status !== 'open') {
            return;
        }

        $remaining = round($amount, 2);
        $cashAmount = 0.0;
        $otherAmount = 0.0;

        foreach ($sale->payments as $payment) {
            if ($remaining <= 0) {
                break;
            }

            $allocated = min($remaining, max(0, (float) $payment->amount));
            $remaining = round($remaining - $allocated, 2);

            if ($this->isCashPaymentName($payment->payment_method_name_snapshot)) {
                $cashAmount += $allocated;
            } else {
                $otherAmount += $allocated;
            }
        }

        $register->update([
            'cash_sale_amount' => max(0, round((float) $register->cash_sale_amount - $cashAmount, 2)),
            'other_payment_amount' => max(0, round((float) $register->other_payment_amount - $otherAmount, 2)),
        ]);
    }

    private function isCashPaymentName(?string $name): bool
    {
        return str_contains(strtolower(trim((string) $name)), 'cash');
    }

    private function createSaleWithDetails(Order $order, int $outletId, ?int $userId, array $paymentsData, int $registerId): void
    {
        if ($order->sale()->exists()) {
            return;
        }

        $saleNo = 'SALE-'.$order->order_no;
        $totalCost = $order->items()->sum(DB::raw('cost_snapshot * qty'));

        $sale = Sale::query()->create([
            'sale_no' => $saleNo,
            'order_id' => $order->id,
            'outlet_id' => $outletId,
            'cash_register_id' => $registerId,
            'total_amount' => $order->grand_total,
            'total_cost' => round($totalCost, 4),
            'profit_amount' => round($order->grand_total - $totalCost, 4),
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

            foreach ($orderItem->modifiers as $mod) {
                SaleModifier::query()->create([
                    'sale_item_id' => $saleItem->id,
                    'modifier_group_name_snapshot' => $mod->modifier_group_name_snapshot,
                    'modifier_item_name_snapshot' => $mod->modifier_item_name_snapshot,
                    'price_adjustment_snapshot' => $mod->price_adjustment_snapshot,
                ]);
            }
        }

        $paymentMethodIds = collect($paymentsData)->pluck('payment_method_id')->unique();
        $paymentMethods = PaymentMethod::query()->whereIn('id', $paymentMethodIds)->get()->keyBy('id');

        foreach ($paymentsData as $paymentData) {
            $pm = $paymentMethods->get($paymentData['payment_method_id']);

            SalePayment::query()->create([
                'sale_id' => $sale->id,
                'payment_method_id' => $paymentData['payment_method_id'],
                'payment_method_name_snapshot' => $pm?->name ?? 'Unknown',
                'amount' => $paymentData['amount'],
            ]);
        }
    }

    private function buildCheckText(Order $order, ?array $customPayments = null): string
    {
        $w = 44;
        $sep = str_repeat('=', $w)."\n";

        $outlet = $order->outlet;
        $outletName = strtoupper($outlet?->name ?? 'POS OUTLET');
        $outletAddress = $outlet?->address ? trim($outlet->address) : '';
        $outletPhone = $outlet?->phone ? trim($outlet->phone) : '';
        $rawLogo = $outlet?->image_url ? trim($outlet->image_url) : '';
        $outletLogo = $this->resolveLogoPath($rawLogo);

        $text = '';
        if ($outletLogo !== '') {
            $text .= "[OUTLET_LOGO:{$outletLogo}]\n";
        }
        $text .= str_pad($outletName, $w, ' ', STR_PAD_BOTH)."\n";

        if ($outletAddress !== '') {
            $text .= str_pad($outletAddress, $w, ' ', STR_PAD_BOTH)."\n";
        }
        if ($outletPhone !== '') {
            $text .= str_pad('Tel: '.$outletPhone, $w, ' ', STR_PAD_BOTH)."\n";
        }

        $text .= "\n";
        $text .= str_pad('*** ORDER CHECK VOUCHER ***', $w, ' ', STR_PAD_BOTH)."\n";
        $text .= $sep;

        $text .= "Order No: {$order->order_no}\n";
        $text .= "Type    : ".ucfirst(str_replace('_', ' ', $order->order_type))."\n";

        if ($order->floor && $order->table) {
            $text .= "Table   : {$order->floor->name} - {$order->table->table_no}\n";
        }
        $waiter = $order->createdBy?->name ?? '—';
        $customerName = $order->customer_name ?: ($order->customer?->name ?? 'Walk-in Customer');
        $text .= "Waiter  : {$waiter}\n";
        $text .= "Customer: {$customerName}\n";
        $text .= "Date    : ".Carbon::now('Asia/Yangon')->format('d/m/Y h:i A')."\n";

        $text .= $sep;
        $text .= $this->buildReceiptItemsTableText($order, $w);

        $text .= $this->formatSummaryLine('Subtotal:', number_format((float) $order->subtotal, 0), $w);

        if ((float) $order->order_discount_amount > 0) {
            $discVal = (float) ($order->order_discount_value ?? 0);
            $discType = strtolower((string) ($order->order_discount_type ?? 'fixed'));
            $discLabel = ($discType === 'percent' || $discType === 'percentage') && $discVal > 0 
                ? 'Discount ('.(int)$discVal.'%):' 
                : 'Discount:';
            $text .= $this->formatSummaryLine($discLabel, '-'.number_format((float) $order->order_discount_amount, 0), $w);
        }
        if ((float) $order->service_charge_amount > 0) {
            $scRate = (float) ($order->service_charge_rate_snapshot ?? 0);
            $scLabel = $scRate > 0 ? 'Service Charge ('.(int)$scRate.'%):' : 'Service Charge:';
            $text .= $this->formatSummaryLine($scLabel, number_format((float) $order->service_charge_amount, 0), $w);
        }
        if ((float) $order->tax_amount > 0) {
            $taxRate = (float) ($order->tax_rate_snapshot ?? 0);
            $taxLabel = $taxRate > 0 ? 'Commercial Tax ('.(int)$taxRate.'%):' : 'Tax:';
            $text .= $this->formatSummaryLine($taxLabel, number_format((float) $order->tax_amount, 0), $w);
        }

        $majorCurrency = \App\Models\Currency::where('is_major', true)->first();
        $currencySymbol = $majorCurrency?->symbol ?? 'Ks';

        $text .= $sep;
        $text .= $this->formatSummaryLine('TOTAL AMOUNT:', number_format((float) $order->grand_total, 0).' '.$currencySymbol, $w);
        $text .= $sep;

        if (!empty($customPayments) && is_array($customPayments)) {
            $text .= "PAYMENT BREAKDOWN:\n";
            $pmIds = collect($customPayments)->pluck('payment_method_id')->filter()->unique();
            $pmNames = \App\Models\PaymentMethod::whereIn('id', $pmIds)->pluck('name', 'id');
            foreach ($customPayments as $p) {
                $amt = (float) ($p['amount'] ?? 0);
                if ($amt > 0) {
                    $pmName = $pmNames->get($p['payment_method_id']) ?? 'Payment';
                    $text .= $this->formatSummaryLine("  {$pmName}:", number_format($amt, 0), $w);
                }
            }
            $text .= $sep;
        } elseif ($order->payments && $order->payments->isNotEmpty()) {
            $text .= "PAYMENT BREAKDOWN:\n";
            foreach ($order->payments as $payment) {
                $pmName = $payment->paymentMethod?->name ?? 'Payment';
                $text .= $this->formatSummaryLine("  {$pmName}:", number_format((float) $payment->amount, 0), $w);
            }
            $text .= $sep;
        }

        $text .= str_pad('Thank you for dining with us!', $w, ' ', STR_PAD_BOTH)."\n";
        $text .= str_pad('Please visit again!', $w, ' ', STR_PAD_BOTH)."\n";
        return $text;
    }

    private function buildBillText(Order $order, ?array $customPayments = null): string
    {
        $w = 44;
        $sep = str_repeat('=', $w)."\n";

        $outlet = $order->outlet;
        $outletName = strtoupper($outlet?->name ?? 'POS OUTLET');
        $outletAddress = $outlet?->address ? trim($outlet->address) : '';
        $outletPhone = $outlet?->phone ? trim($outlet->phone) : '';
        $rawLogo = $outlet?->image_url ? trim($outlet->image_url) : '';
        $outletLogo = $this->resolveLogoPath($rawLogo);

        $text = '';
        if ($outletLogo !== '') {
            $text .= "[OUTLET_LOGO:{$outletLogo}]\n";
        }
        $text .= str_pad($outletName, $w, ' ', STR_PAD_BOTH)."\n";

        if ($outletAddress !== '') {
            $text .= str_pad($outletAddress, $w, ' ', STR_PAD_BOTH)."\n";
        }
        if ($outletPhone !== '') {
            $text .= str_pad('Tel: '.$outletPhone, $w, ' ', STR_PAD_BOTH)."\n";
        }

        $text .= "\n";
        $text .= str_pad('*** SALE INVOICE VOUCHER ***', $w, ' ', STR_PAD_BOTH)."\n";
        $text .= $sep;

        $invoiceNo = $order->sale?->sale_no ?? '—';
        $text .= "Invoice : {$invoiceNo}\n";
        $text .= "Order No: {$order->order_no}\n";
        $text .= "Type    : ".ucfirst(str_replace('_', ' ', $order->order_type))."\n";

        if ($order->floor && $order->table) {
            $text .= "Table   : {$order->floor->name} - {$order->table->table_no}\n";
        }
        $waiter = $order->createdBy?->name ?? '—';
        $cashier = $order->sale?->createdBy?->name ?? 'Cashier';
        $customerName = $order->customer_name ?: ($order->customer?->name ?? 'Walk-in Customer');
        $text .= "Waiter  : {$waiter}\n";
        $text .= "Cashier : {$cashier}\n";
        $text .= "Customer: {$customerName}\n";

        if ($order->payment_completed_at) {
            $rawTs = Carbon::parse($order->payment_completed_at)->format('Y-m-d H:i:s');
            $settledAt = Carbon::createFromFormat('Y-m-d H:i:s', $rawTs, 'UTC')->setTimezone('Asia/Yangon')->format('d/m/Y h:i A');
        } else {
            $settledAt = Carbon::now('Asia/Yangon')->format('d/m/Y h:i A');
        }
        $text .= "Date    : {$settledAt}\n";

        $text .= $sep;
        $text .= $this->buildReceiptItemsTableText($order, $w);

        $text .= $this->formatSummaryLine('Subtotal:', number_format((float) $order->subtotal, 0), $w);

        if ((float) $order->order_discount_amount > 0) {
            $discVal = (float) ($order->order_discount_value ?? 0);
            $discType = strtolower((string) ($order->order_discount_type ?? 'fixed'));
            $discLabel = ($discType === 'percent' || $discType === 'percentage') && $discVal > 0 
                ? 'Discount ('.(int)$discVal.'%):' 
                : 'Discount:';
            $text .= $this->formatSummaryLine($discLabel, '-'.number_format((float) $order->order_discount_amount, 0), $w);
        }
        if ((float) $order->service_charge_amount > 0) {
            $scRate = (float) ($order->service_charge_rate_snapshot ?? 0);
            $scLabel = $scRate > 0 ? 'Service Charge ('.(int)$scRate.'%):' : 'Service Charge:';
            $text .= $this->formatSummaryLine($scLabel, number_format((float) $order->service_charge_amount, 0), $w);
        }
        if ((float) $order->tax_amount > 0) {
            $taxRate = (float) ($order->tax_rate_snapshot ?? 0);
            $taxLabel = $taxRate > 0 ? 'Commercial Tax ('.(int)$taxRate.'%):' : 'Tax:';
            $text .= $this->formatSummaryLine($taxLabel, number_format((float) $order->tax_amount, 0), $w);
        }

        $majorCurrency = \App\Models\Currency::where('is_major', true)->first();
        $currencySymbol = $majorCurrency?->symbol ?? 'Ks';

        $text .= $sep;
        $text .= $this->formatSummaryLine('TOTAL AMOUNT:', number_format((float) $order->grand_total, 0).' '.$currencySymbol, $w);
        $text .= $sep;

        $text .= "PAYMENT BREAKDOWN:\n";
        if (!empty($customPayments) && is_array($customPayments)) {
            $pmIds = collect($customPayments)->pluck('payment_method_id')->filter()->unique();
            $pmNames = \App\Models\PaymentMethod::whereIn('id', $pmIds)->pluck('name', 'id');
            foreach ($customPayments as $p) {
                $amt = (float) ($p['amount'] ?? 0);
                if ($amt > 0) {
                    $pmName = $pmNames->get($p['payment_method_id']) ?? 'Payment';
                    $text .= $this->formatSummaryLine("  {$pmName}:", number_format($amt, 0), $w);
                }
            }
        } else {
            foreach ($order->payments as $payment) {
                $pmName = $payment->paymentMethod?->name ?? 'Payment';
                $text .= $this->formatSummaryLine("  {$pmName}:", number_format((float) $payment->amount, 0), $w);
            }
        }

        if ((float) $order->change_amount > 0) {
            $text .= $this->formatSummaryLine('  Change:', number_format((float) $order->change_amount, 0), $w);
        }
        if ((float) $order->balance_amount > 0) {
            $text .= $this->formatSummaryLine('  Balance Due:', number_format((float) $order->balance_amount, 0), $w);
        }
        if ((float) $order->change_amount > 0) {
            $text .= $this->formatSummaryLine('  Change:', number_format((float) $order->change_amount, 0), $w);
        }
        if ((float) $order->balance_amount > 0) {
            $text .= $this->formatSummaryLine('  Balance Due:', number_format((float) $order->balance_amount, 0), $w);
        }

        $text .= $sep;
        $text .= str_pad('Thank you for dining with us!', $w, ' ', STR_PAD_BOTH)."\n";
        $text .= str_pad('Please visit again!', $w, ' ', STR_PAD_BOTH)."\n";
        return $text;
    }

    private function buildReceiptItemsTableText(Order $order, int $paperWidth): string
    {
        $text = $this->receiptItemHeader($paperWidth)."\n";
        $text .= str_repeat('-', $paperWidth)."\n";

        foreach ($order->items as $item) {
            foreach ($this->receiptItemRows(
                $item->item_name_snapshot,
                (float) $item->qty,
                (float) $item->final_unit_price,
                (float) $item->amount,
                $paperWidth
            ) as $row) {
                $text .= $row."\n";
            }

            if ($item->modifiers->isNotEmpty()) {
                $modNames = $item->modifiers->pluck('modifier_item_name_snapshot')->filter()->join(', ');
                if ($modNames !== '') {
                    foreach ($this->receiptDetailRows('+ '.$modNames, $paperWidth) as $row) {
                        $text .= $row."\n";
                    }
                }
            }

            if (! empty($item->item_note)) {
                foreach ($this->receiptDetailRows('Note: '.$item->item_note, $paperWidth) as $row) {
                    $text .= $row."\n";
                }
            }
        }

        return $text.str_repeat('-', $paperWidth)."\n";
    }

    private function receiptItemHeader(int $paperWidth): string
    {
        return "ITEM\tQTY\tPRICE\tAMOUNT";
    }

    private function receiptItemRows(string $name, float $qty, float $unitPrice, float $amount, int $paperWidth): array
    {
        $qtyText = $this->formatPrintQty($qty);
        $priceText = number_format($unitPrice, 0);
        $amountText = number_format($amount, 0);

        return ["{$name}\t{$qtyText}\t{$priceText}\t{$amountText}"];
    }

    private function receiptDetailRows(string $text, int $paperWidth): array
    {
        return array_map(
            fn (string $line): string => '  '.$line,
            $this->wrapPrintText($text, max(10, $paperWidth - 2))
        );
    }

    private function receiptColumnWidths(int $paperWidth): array
    {
        $qtyWidth = 5;
        $priceWidth = 9;
        $amountWidth = 10;
        $nameWidth = max(12, $paperWidth - $qtyWidth - $priceWidth - $amountWidth);

        return [$nameWidth, $qtyWidth, $priceWidth, $amountWidth];
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

    private function sendDocumentToPrinter(Order $order, string $text, string $documentType, bool $isReprint = false, $printerId = null): void
    {
        // Backend printing disabled.
        // All Bill/Check printing is handled by the Flutter frontend via 80mm web printing.
    }

    private function printBillDocument(Order $order): void
    {
        try {
            $order->load(['items.modifiers', 'payments.paymentMethod:id,name', 'floor:id,name', 'table:id,table_no', 'outlet:id,name,address,phone,image_url', 'createdBy:id,name', 'sale.createdBy:id,name']);
            $billText = $this->buildBillText($order);
            $this->sendDocumentToPrinter($order, $billText, 'bill', false, request()->input('printer_id'));
        } catch (\Exception $e) {
            Log::error("Bill print failed for Order #{$order->order_no}: ".$e->getMessage());
        }
    }

    private function deductOrderStock(Order $order, int $outletId): void
    {
        app(\App\Services\OrderStockService::class)->deductOrderStock($order, $outletId);
    }

    private function reverseOrderStock(Order $order): void
    {
        app(\App\Services\OrderStockService::class)->reverseOrderStock($order);
    }

    private function resolveLogoPath(?string $logoUrl): string
    {
        if (empty($logoUrl)) {
            return '';
        }

        $logoUrl = trim($logoUrl);
        if (filter_var($logoUrl, FILTER_VALIDATE_URL)) {
            return $logoUrl;
        }

        return asset(ltrim($logoUrl, '/'));
    }

    private function formatSummaryLine(string $label, string $value, int $w = 44): string
    {
        $spaces = max(1, $w - mb_strlen($label) - mb_strlen($value));
        return $label . str_repeat(' ', $spaces) . $value . "\n";
    }
}
