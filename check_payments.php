<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Order;

$orders = Order::query()
    ->where('change_amount', '>', 0)
    ->orWhereRaw('paid_amount > grand_total')
    ->with('payments')
    ->get();

echo "Found " . $orders->count() . " orders with change or paid > grand_total\n";
foreach ($orders as $order) {
    echo "Order ID: {$order->id}, No: {$order->order_no}, Total: {$order->grand_total}, Paid: {$order->paid_amount}, Change: {$order->change_amount}, State: {$order->payment_state}\n";
    echo "Payments:\n";
    foreach ($order->payments as $payment) {
        echo "  - Method: {$payment->payment_method_id}, Amount: {$payment->amount}\n";
    }
    echo "--------------------------------------------------\n";
}
