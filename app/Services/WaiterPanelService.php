<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

class WaiterPanelService
{
    /**
     * Get data needed for creating a new order (outlets, floors, tables, items, etc.)
     */
    public function getCreateData($user)
    {
        // Placeholder: fetch outlets, floors, tables, item categories, modifiers, etc.
        return [];
    }

    /**
     * Create a new order (dine-in, take-away, delivery)
     */
    public function createOrder(array $data, $user)
    {
        return DB::transaction(function () use ($data, $user) {
            $order = Order::create([
                'order_no' => $this->generateOrderNumber(),
                'outlet_id' => $data['outlet_id'],
                'order_type' => $data['order_type'],
                'floor_id' => $data['floor_id'] ?? null,
                'table_id' => $data['table_id'] ?? null,
                'pax' => $data['pax'] ?? null,
                'customer_name' => $data['customer_name'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'pickup_time' => $data['pickup_time'] ?? null,
                'delivery_partner' => $data['delivery_partner'] ?? null,
                'delivery_address' => $data['delivery_address'] ?? null,
                'delivery_fee' => $data['delivery_fee'] ?? 0,
                'order_note' => $data['order_note'] ?? null,
                'created_by' => $user->id,
            ]);
            // TODO: Save order items, modifiers, combo components, snapshots, etc.
            return $order->fresh();
        });
    }

    protected function generateOrderNumber()
    {
        return 'ORD-' . time() . '-' . rand(1000, 9999);
    }

    public function updateOrder($orderId, array $data, $user)
    {
        $order = Order::findOrFail($orderId);
        $order->fill($data);
        $order->save();
        return $order->fresh();
    }

    public function addItems($orderId, array $itemsData, $user)
    {
        $order = Order::findOrFail($orderId);
        // TODO: iterate items, validate, snapshot pricing, etc.
        return $order->fresh();
    }

    public function cancelOrder($orderId, array $data, $user)
    {
        $order = Order::findOrFail($orderId);
        $order->order_status = 'cancelled';
        $order->cancelled_by = $user->id;
        $order->cancelled_at = now();
        $order->cancellation_reason = $data['reason'] ?? null;
        $order->save();
        return $order->fresh();
    }

    public function cancelItem($orderId, $itemId, array $data, $user)
    {
        // Placeholder – locate order item and adjust quantity/cancel
        $order = Order::findOrFail($orderId);
        return $order->fresh();
    }

    public function splitOrder($orderId, array $data, $user)
    {
        return DB::transaction(function () use ($orderId, $data, $user) {
            $parentOrder = Order::lockForUpdate()->findOrFail($orderId);
            
            // Validate the order can be split
            if ($parentOrder->payment_state === 'paid') {
                throw new \Exception("Cannot split a fully paid order.");
            }

            // Create split group id if it doesn't exist
            $splitGroupId = $parentOrder->split_group_id ?? uniqid('split_');
            if (!$parentOrder->split_group_id) {
                $parentOrder->split_group_id = $splitGroupId;
                $parentOrder->save();
            }

            $newOrder = $parentOrder->replicate(['order_no', 'split_group_id', 'parent_order_id', 'split_sequence', 'split_from_order_id']);
            $newOrder->order_no = $this->generateOrderNumber();
            $newOrder->parent_order_id = $parentOrder->parent_order_id ?? $parentOrder->id;
            $newOrder->split_group_id = $splitGroupId;
            $newOrder->split_from_order_id = $parentOrder->id;
            $newOrder->save();

            // Here you would move the requested items from $parentOrder to $newOrder
            // and update active_qty, original_qty appropriately.
            // Also log to order_split_histories...

            return [
                'parent' => $parentOrder->fresh(),
                'split' => $newOrder->fresh()
            ];
        });
    }

    public function mergeTables($primaryTableId, array $data, $user)
    {
        return DB::transaction(function () use ($primaryTableId, $data, $user) {
            // Lock the primary table
            $primaryTable = \App\Models\Table::lockForUpdate()->findOrFail($primaryTableId);
            
            $mergeGroup = \App\Models\TableMergeGroup::create([
                'outlet_id' => $primaryTable->outlet_id,
                'floor_id' => $primaryTable->floor_id,
                'primary_table_id' => $primaryTableId,
                'merged_by' => $user->id,
                'status' => 'active',
            ]);

            foreach ($data['secondary_tables'] as $tableId) {
                \App\Models\Table::lockForUpdate()->findOrFail($tableId);
                \App\Models\TableMergeMember::create([
                    'merge_group_id' => $mergeGroup->id,
                    'table_id' => $tableId,
                    'member_type' => 'secondary'
                ]);
            }

            return $mergeGroup->load('members');
        });
    }

    public function transferTable($orderId, array $data, $user)
    {
        return DB::transaction(function () use ($orderId, $data) {
            $order = Order::lockForUpdate()->findOrFail($orderId);
            $order->table_id = $data['new_table_id'];
            $order->save();
            return $order->fresh();
        });
    }

    public function unmergeTables($mergeGroupId, array $data, $user)
    {
        return DB::transaction(function () use ($mergeGroupId, $user) {
            $mergeGroup = \App\Models\TableMergeGroup::lockForUpdate()->findOrFail($mergeGroupId);
            $mergeGroup->status = 'unmerged';
            $mergeGroup->unmerged_by = $user->id;
            $mergeGroup->unmerged_at = now();
            $mergeGroup->save();
            return $mergeGroup;
        });
    }

    public function seatReservation($reservationId, array $data, $user)
    {
        return DB::transaction(function () use ($reservationId, $data) {
            $reservation = \App\Models\Reservation::lockForUpdate()->findOrFail($reservationId);
            $reservation->status = 'seated';
            $reservation->save();

            // Optionally create/link an order here
            return $reservation->fresh();
        });
    }

    public function reprint($orderId, array $data, $user)
    {
        return DB::transaction(function () use ($orderId, $data, $user) {
            $order = Order::lockForUpdate()->findOrFail($orderId);
            
            \App\Models\PrintLog::create([
                'order_id' => $orderId,
                'printer_id' => $data['printer_id'] ?? null,
                'printed_by' => $user->id,
                'print_type' => $data['print_type'] ?? 'reprint',
            ]);

            return ['message' => 'Reprint logged and triggered successfully.'];
        });
    }

    public function getOrderDetail($orderId, $user)
    {
        $order = Order::with(['items', 'table', 'floor', 'outlet', 'payments', 'sale'])
            ->findOrFail($orderId);
        return $order;
    }
}
?>
