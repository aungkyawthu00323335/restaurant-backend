<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PosSyncController extends Controller
{
    /**
     * Ingest batch of offline transactions from Flutter POS client SQLite queue.
     */
    public function syncBatch(Request $request): JsonResponse
    {
        $request->validate([
            'orders' => 'required|array',
            'orders.*.client_uuid' => 'required|string',
            'orders.*.order_type' => 'required|string',
            'orders.*.total_amount' => 'required|numeric',
        ]);

        $orders = $request->input('orders');
        $syncedOrders = [];
        $skippedDuplicates = [];
        $failedOrders = [];

        DB::beginTransaction();
        try {
            foreach ($orders as $offlineOrder) {
                $clientUuid = $offlineOrder['client_uuid'];

                // Deduplication check via idempotency key / notes
                $existingOrder = Order::where('notes', 'LIKE', "%UUID: {$clientUuid}%")->first();
                if ($existingOrder) {
                    $skippedDuplicates[] = [
                        'client_uuid' => $clientUuid,
                        'internal_id' => $existingOrder->id,
                        'order_number' => $existingOrder->order_number,
                    ];
                    continue;
                }

                // Create Order
                $orderNumber = $offlineOrder['order_number'] ?? ('ORD-OFFLINE-' . date('YmdHis') . '-' . rand(100, 999));
                $newOrder = Order::create([
                    'order_number' => $orderNumber,
                    'location_id' => $request->header('X-Outlet-Id') ?? 1,
                    'user_id' => auth()->id() ?? 1,
                    'order_type' => $offlineOrder['order_type'] ?? 'DINE_IN',
                    'order_status' => 'COMPLETED',
                    'payment_status' => 'PAID',
                    'kitchen_status' => 'SERVED',
                    'subtotal' => $offlineOrder['subtotal'] ?? $offlineOrder['total_amount'],
                    'discount_amount' => $offlineOrder['discount_amount'] ?? 0.00,
                    'tax_amount' => $offlineOrder['tax_amount'] ?? 0.00,
                    'charge_amount' => $offlineOrder['charge_amount'] ?? 0.00,
                    'total_amount' => $offlineOrder['total_amount'],
                    'notes' => "Synced Offline Order | UUID: {$clientUuid}",
                ]);

                $syncedOrders[] = [
                    'client_uuid' => $clientUuid,
                    'internal_id' => $newOrder->id,
                    'order_number' => $newOrder->order_number,
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
                ]
            ], 200);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('POS Sync Batch Error: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Batch sync failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}
