<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class KDSController extends Controller
{
    /**
     * Get active kitchen tickets for the KDS display screen.
     */
    public function getTickets(Request $request): JsonResponse
    {
        $locationId = $this->resolveOutletId($request);

        $tickets = Order::with(['items.modifiers', 'floor', 'table', 'outlet'])
            ->where('outlet_id', $locationId)
            ->whereIn('order_status', ['pending', 'preparing', 'ready'])
            ->where('confirmation_status', 'confirmed')
            ->where('stock_deduction_status', 'deducted')
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn (Order $order): array => $this->ticketPayload($order))
            ->values();

        return response()->json([
            'status' => true,
            'data' => $tickets,
            'meta' => [
                'total_tickets' => $tickets->count(),
                'pending_count' => $tickets->where('kitchen_status', 'PENDING')->count(),
                'preparing_count' => $tickets->where('kitchen_status', 'PREPARING')->count(),
                'ready_count' => $tickets->where('kitchen_status', 'READY')->count(),
            ]
        ]);
    }

    /**
     * Update kitchen status for a specific ticket (PENDING -> PREPARING -> READY -> SERVED).
     */
    public function updateTicketStatus(Request $request, int $id): JsonResponse
    {
        $payload = $request->validate([
            'kitchen_status' => 'required|string|in:PENDING,PREPARING,READY,SERVED',
        ]);

        $locationId = $this->resolveOutletId($request);
        $order = Order::query()
            ->where('outlet_id', $locationId)
            ->findOrFail($id);

        $previousStatus = $this->kitchenStatus($order->order_status);
        $order->order_status = $this->orderStatus($payload['kitchen_status'], $order);
        $order->save();

        return response()->json([
            'status' => true,
            'message' => "Kitchen ticket #{$order->order_no} status updated to ".$this->kitchenStatus($order->order_status),
            'data' => array_merge($this->ticketPayload($order->load(['items.modifiers', 'floor', 'table', 'outlet'])), [
                'previous_status' => $previousStatus,
                'current_status' => $this->kitchenStatus($order->order_status),
            ]),
        ]);
    }

    /**
     * Bump ticket to next workflow state automatically.
     */
    public function bumpTicket(Request $request, int $id): JsonResponse
    {
        $locationId = $this->resolveOutletId($request);
        $order = Order::query()
            ->where('outlet_id', $locationId)
            ->findOrFail($id);

        $nextStatus = match ($order->order_status) {
            'pending' => 'preparing',
            'preparing' => 'ready',
            'ready' => 'ready',
            default => 'ready',
        };

        $order->order_status = $nextStatus;
        $order->save();

        return response()->json([
            'status' => true,
            'message' => "Bumped ticket #{$order->order_no} to ".$this->kitchenStatus($order->order_status),
            'data' => $this->ticketPayload($order->load(['items.modifiers', 'floor', 'table', 'outlet'])),
        ]);
    }

    private function resolveOutletId(Request $request): int
    {
        $outletId = $request->input('outlet_id')
            ?? $request->input('location_id')
            ?? $request->header('X-Outlet-Id')
            ?? (app()->bound('current_outlet_id') ? app('current_outlet_id') : null);

        if (! $outletId || (int) $outletId <= 0) {
            throw ValidationException::withMessages([
                'outlet_id' => ['An outlet context is required for KDS tickets.'],
            ]);
        }

        return (int) $outletId;
    }

    private function ticketPayload(Order $order): array
    {
        $elapsedMinutes = $order->created_at
            ? max(0, $order->created_at->diffInMinutes(now()))
            : 0;

        return [
            'id' => $order->id,
            'order_number' => $order->order_no,
            'table_name' => $this->tableLabel($order),
            'order_type' => strtoupper((string) $order->order_type),
            'kitchen_status' => $this->kitchenStatus((string) $order->order_status),
            'elapsed_minutes' => $elapsedMinutes,
            'updated_at' => $order->updated_at?->toIso8601String(),
            'items' => $order->items->map(fn ($item): array => [
                'name' => $item->item_name_snapshot,
                'qty' => (float) ($item->active_qty ?? $item->qty),
                'note' => $item->item_note,
                'modifiers' => $item->modifiers->map(fn ($modifier): string => (string) $modifier->modifier_item_name_snapshot)->values(),
            ])->values(),
        ];
    }

    private function tableLabel(Order $order): string
    {
        if ($order->order_type === 'delivery') {
            return $order->delivery_partner ?: 'Delivery';
        }

        if ($order->order_type === 'takeaway') {
            return 'Takeaway Counter';
        }

        $floor = $order->floor?->name;
        $table = $order->table?->table_no;

        return trim(($floor ? "{$floor} - " : '').($table ?: 'Dine In')) ?: 'Dine In';
    }

    private function kitchenStatus(string $orderStatus): string
    {
        return match ($orderStatus) {
            'preparing' => 'PREPARING',
            'ready' => 'READY',
            'completed' => 'SERVED',
            default => 'PENDING',
        };
    }

    private function orderStatus(string $kitchenStatus, Order $order): string
    {
        return match ($kitchenStatus) {
            'PREPARING' => 'preparing',
            'READY' => 'ready',
            'SERVED' => $order->order_status === 'completed' ? 'completed' : 'ready',
            default => 'pending',
        };
    }
}
