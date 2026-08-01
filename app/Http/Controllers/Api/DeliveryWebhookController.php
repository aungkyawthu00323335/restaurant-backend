<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Order;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class DeliveryWebhookController extends Controller
{
    /**
     * Handle incoming webhooks from delivery aggregators (GrabFood, UberEats, Foodpanda).
     */
    public function handleWebhook(Request $request, string $provider): JsonResponse
    {
        $provider = strtolower(trim($provider));
        if ($failure = $this->verifyWebhook($request, $provider)) {
            return $failure;
        }

        $payload = $request->validate([
            'order_id' => ['nullable', 'string', 'max:120', 'required_without:id'],
            'id' => ['nullable', 'string', 'max:120', 'required_without:order_id'],
            'customer' => ['nullable', 'array'],
            'customer.name' => ['nullable', 'string', 'max:160'],
            'customer.phone' => ['nullable', 'string', 'max:50'],
            'delivery_address' => ['nullable', 'string', 'max:2000'],
            'subtotal' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'tax' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'delivery_fee' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'total' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
        ]);

        $outletHeader = (string) $request->header('X-Outlet-Id', '');
        if (! ctype_digit($outletHeader) || (int) $outletHeader < 1) {
            return response()->json([
                'status' => false,
                'message' => 'A valid X-Outlet-Id header is required.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $outletId = (int) $outletHeader;
        if (! Location::query()->whereKey($outletId)->where('is_active', true)->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'The target outlet is not available.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $externalOrderId = trim((string) ($payload['order_id'] ?? $payload['id'] ?? ''));
        $customer = is_array($payload['customer'] ?? null) ? $payload['customer'] : [];
        $customerName = trim((string) ($customer['name'] ?? '')) ?: "{$provider} Customer";
        $customerPhone = trim((string) ($customer['phone'] ?? '')) ?: null;
        $orderNote = "Delivery webhook UUID: {$externalOrderId}";

        try {
            $order = DB::transaction(function () use ($provider, $outletId, $externalOrderId, $orderNote, $customerName, $customerPhone, $payload): Order {
                $existing = Order::query()
                    ->where('outlet_id', $outletId)
                    ->where('delivery_partner', $provider)
                    ->where('order_note', $orderNote)
                    ->first();

                if ($existing) {
                    return $existing;
                }

                $customer = Customer::firstOrCreate(
                    ['phone' => $customerPhone ?? "EXT-{$provider}-{$externalOrderId}"],
                    [
                        'name' => $customerName,
                        'address' => $payload['delivery_address'] ?? "{$provider} Delivery Order",
                        'is_active' => true,
                    ]
                );

                return Order::create([
                    'order_no' => 'ORD-'.strtoupper($provider).'-'.Str::upper(Str::random(16)),
                    'outlet_id' => $outletId,
                    'order_type' => 'delivery',
                    'customer_name' => $customer->name,
                    'customer_phone' => $customer->phone,
                    'delivery_partner' => $provider,
                    'delivery_address' => $payload['delivery_address'] ?? null,
                    'delivery_fee' => $payload['delivery_fee'] ?? 0,
                    'subtotal' => $payload['subtotal'] ?? $payload['total'],
                    'order_discount_amount' => $payload['discount'] ?? 0,
                    'tax_amount' => $payload['tax'] ?? 0,
                    'grand_total' => $payload['total'],
                    'order_status' => 'pending',
                    'confirmation_status' => 'draft',
                    'payment_state' => 'unpaid',
                    'stock_deduction_status' => 'none',
                    'order_note' => $orderNote,
                ]);
            });

            return response()->json([
                'status' => true,
                'message' => "Successfully ingested order from {$provider}",
                'data' => [
                    'internal_order_id' => $order->id,
                    'order_number' => $order->order_no,
                    'provider' => $provider,
                    'external_order_id' => $externalOrderId,
                ]
            ], $order->wasRecentlyCreated ? Response::HTTP_CREATED : Response::HTTP_OK);

        } catch (\Throwable $e) {
            Log::error("Failed to ingest delivery webhook for {$provider}.", [
                'request_id' => $request->attributes->get('request_id'),
                'exception' => $e,
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to process webhook order.',
            ], 500);
        }
    }

    /**
     * Menu sync webhook for delivery partners.
     */
    public function syncMenu(Request $request, string $provider): JsonResponse
    {
        $provider = strtolower(trim($provider));
        if ($failure = $this->verifyWebhook($request, $provider)) {
            return $failure;
        }

        return response()->json([
            'status' => true,
            'message' => "Menu catalog payload generated for {$provider}",
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    private function verifyWebhook(Request $request, string $provider): ?JsonResponse
    {
        if (! preg_match('/^[a-z0-9_-]{1,40}$/', $provider)) {
            return response()->json(['message' => 'Unknown webhook provider.'], Response::HTTP_NOT_FOUND);
        }

        $secret = (string) config("services.delivery.{$provider}.secret", '');
        $signature = trim((string) ($request->header('X-Webhook-Signature') ?? $request->header('X-Signature') ?? ''));
        $timestamp = (string) $request->header('X-Webhook-Timestamp', '');

        if ($secret === '') {
            return response()->json(['message' => 'Webhook integration is not configured.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
            return response()->json(['message' => 'Webhook request expired.'], Response::HTTP_UNAUTHORIZED);
        }

        if (! preg_match('/^[a-f0-9]{64}$/i', $signature)) {
            return response()->json(['message' => 'Webhook signature is required.'], Response::HTTP_UNAUTHORIZED);
        }

        $computedSignature = hash_hmac('sha256', $request->getContent(), $secret);
        if (! hash_equals($computedSignature, $signature)) {
            Log::warning("Webhook signature mismatch for {$provider}.", [
                'request_id' => $request->attributes->get('request_id'),
            ]);

            return response()->json(['message' => 'Invalid webhook signature.'], Response::HTTP_UNAUTHORIZED);
        }

        return null;
    }
}
