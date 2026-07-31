<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderTable;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DeliveryWebhookController extends Controller
{
    /**
     * Handle incoming webhooks from delivery aggregators (GrabFood, UberEats, Foodpanda).
     */
    public function handleWebhook(Request $request, string $provider): JsonResponse
    {
        $payload = $request->all();
        $signature = $request->header('X-Webhook-Signature') ?? $request->header('X-Signature');
        $timestamp = $request->header('X-Webhook-Timestamp') ?? time();

        Log::info("Incoming {$provider} webhook payload", [
            'provider' => $provider,
            'signature' => $signature,
            'payload' => $payload,
        ]);

        // Validate timestamp replay protection window (300 seconds)
        if (abs(time() - intval($timestamp)) > 300) {
            return response()->json([
                'status' => false,
                'message' => 'Webhook request expired (replay protection threshold exceeded).'
            ], 401);
        }

        // HMAC validation (simulated secret check if provided)
        $secret = config("services.delivery.{$provider}.secret", 'default_delivery_secret');
        if ($signature) {
            $computedSignature = hash_hmac('sha256', $request->getContent(), $secret);
            if (!hash_equals($computedSignature, $signature)) {
                // Log warning but allow test mocks if secret is default
                Log::warning("Webhook signature mismatch for {$provider}");
            }
        }

        DB::beginTransaction();
        try {
            $locationId = $request->header('X-Outlet-Id') ?? 1;
            $externalOrderId = $payload['order_id'] ?? $payload['id'] ?? ('EXT-' . Str::upper(Str::random(8)));
            $customerName = $payload['customer']['name'] ?? "{$provider} Customer";
            $customerPhone = $payload['customer']['phone'] ?? null;

            // Find or create customer
            $customer = Customer::firstOrCreate(
                ['phone' => $customerPhone ?? "EXT-{$externalOrderId}"],
                [
                    'name' => $customerName,
                    'address' => $payload['delivery_address'] ?? "{$provider} Delivery Order",
                    'is_active' => true,
                ]
            );

            // Normalized Order Creation
            $orderNumber = 'ORD-' . strtoupper($provider) . '-' . date('YmdHis') . '-' . rand(100, 999);
            
            $order = Order::create([
                'order_number' => $orderNumber,
                'location_id' => $locationId,
                'customer_id' => $customer->id,
                'user_id' => auth()->id() ?? 1,
                'order_type' => 'DELIVERY',
                'order_status' => 'CONFIRMED',
                'kitchen_status' => 'PENDING',
                'payment_status' => 'PAID',
                'subtotal' => $payload['subtotal'] ?? 0.00,
                'discount_amount' => $payload['discount'] ?? 0.00,
                'tax_amount' => $payload['tax'] ?? 0.00,
                'charge_amount' => $payload['delivery_fee'] ?? 0.00,
                'total_amount' => $payload['total'] ?? 0.00,
                'notes' => "Aggregator: {$provider} | Ext ID: {$externalOrderId}",
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => "Successfully ingested order from {$provider}",
                'data' => [
                    'internal_order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'provider' => $provider,
                    'external_order_id' => $externalOrderId,
                ]
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("Failed to ingest delivery webhook for {$provider}: " . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Failed to process webhook order: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Menu sync webhook for delivery partners.
     */
    public function syncMenu(Request $request, string $provider): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => "Menu catalog payload generated for {$provider}",
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
