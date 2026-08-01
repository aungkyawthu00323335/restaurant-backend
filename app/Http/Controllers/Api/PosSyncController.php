<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PosSyncController extends Controller
{
    /**
     * Ingest batch of offline transactions from Flutter POS client SQLite queue.
     */
    public function syncBatch(Request $request): JsonResponse
    {
        $request->validate([
            'orders' => 'required|array|max:100',
            'orders.*.client_uuid' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'orders.*.order_type' => 'required|string|max:20',
            'orders.*.total_amount' => 'required|numeric|min:0|max:999999999999.99',
            'orders.*.subtotal' => 'nullable|numeric|min:0|max:999999999999.99',
            'orders.*.discount_amount' => 'nullable|numeric|min:0|max:999999999999.99',
            'orders.*.tax_amount' => 'nullable|numeric|min:0|max:999999999999.99',
            'orders.*.charge_amount' => 'nullable|numeric|min:0|max:999999999999.99',
            'orders.*.order_number' => 'nullable|string|max:80',
        ]);

        $orders = $request->input('orders');
        $outletId = app()->bound('current_outlet_id') ? (int) app('current_outlet_id') : 0;
        if ($outletId < 1) {
            return response()->json([
                'status' => false,
                'message' => 'An outlet context is required for POS synchronization.',
            ], 422);
        }

        $syncedOrders = [];
        $skippedDuplicates = [];
        $failedOrders = [];

        DB::beginTransaction();
        try {
            foreach ($orders as $offlineOrder) {
                $clientUuid = $offlineOrder['client_uuid'];

                // Deduplication check via idempotency key / notes
                $existingOrder = Order::query()
                    ->where('outlet_id', $outletId)
                    ->where('order_note', 'like', "%UUID: {$clientUuid}%")
                    ->first();
                if ($existingOrder) {
                    $skippedDuplicates[] = [
                        'client_uuid' => $clientUuid,
                        'internal_id' => $existingOrder->id,
                        'order_number' => $existingOrder->order_no,
                    ];
                    continue;
                }

                // Create Order
                $orderNumber = $offlineOrder['order_number'] ?? ('ORD-OFFLINE-' . Str::upper(Str::random(16)));
                $orderType = strtolower((string) ($offlineOrder['order_type'] ?? 'dine_in'));
                $orderType = str_replace(['-', ' '], '_', $orderType);
                if ($orderType === 'take_away') {
                    $orderType = 'takeaway';
                }

                if (! in_array($orderType, ['dine_in', 'takeaway', 'delivery'], true)) {
                    $failedOrders[] = ['client_uuid' => $clientUuid, 'message' => 'Unsupported order type.'];
                    continue;
                }

                $newOrder = Order::create([
                    'order_no' => $orderNumber,
                    'outlet_id' => $outletId,
                    'created_by' => auth()->id(),
                    'order_type' => $orderType,
                    'order_status' => 'completed',
                    'confirmation_status' => 'confirmed',
                    'payment_state' => 'paid',
                    'stock_deduction_status' => 'none',
                    'subtotal' => $offlineOrder['subtotal'] ?? $offlineOrder['total_amount'],
                    'order_discount_amount' => $offlineOrder['discount_amount'] ?? 0.00,
                    'tax_amount' => $offlineOrder['tax_amount'] ?? 0.00,
                    'service_charge_amount' => $offlineOrder['charge_amount'] ?? 0.00,
                    'grand_total' => $offlineOrder['total_amount'],
                    'paid_amount' => $offlineOrder['total_amount'],
                    'order_note' => "Synced Offline Order | UUID: {$clientUuid}",
                ]);

                $syncedOrders[] = [
                    'client_uuid' => $clientUuid,
                    'internal_id' => $newOrder->id,
                    'order_number' => $newOrder->order_no,
                ];
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Offline batch sync processed successfully.',
                'data' => [
                    'synced_count' => count($syncedOrders),
                    'duplicate_skipped_count' => count($skippedDuplicates),
                    'failed_count' => count($failedOrders),
                    'synced_orders' => $syncedOrders,
                    'skipped_duplicates' => $skippedDuplicates,
                    'failed_orders' => $failedOrders,
                ]
            ], 200);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('POS Sync Batch failed.', [
                'request_id' => $request->attributes->get('request_id'),
                'exception' => $e,
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Batch sync failed. No orders were synchronized.',
            ], 500);
        }
    }
}
