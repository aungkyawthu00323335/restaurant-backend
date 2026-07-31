<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\WaiterPanelController;
use App\Models\Order;
use Illuminate\Console\Command;
use ReflectionMethod;

class PrintOrderCommand extends Command
{
    protected $signature = 'print:order {orderId} {--reprint}';

    protected $description = 'Print kitchen ticket for an order asynchronously';

    public function handle(): int
    {
        $orderId = (int) $this->argument('orderId');
        $isReprint = (bool) $this->option('reprint');

        $order = Order::query()
            ->with(['items.modifiers', 'items.comboComponents', 'floor:id,name', 'table:id,table_no', 'createdBy:id,name', 'outlet'])
            ->find($orderId);

        if (! $order) {
            $this->error("Order #{$orderId} not found.");
            return 1;
        }

        $controller = app(WaiterPanelController::class);

        $refMethod = new ReflectionMethod(WaiterPanelController::class, 'printOrder');
        $refMethod->setAccessible(true);
        $refMethod->invoke($controller, $order, $isReprint);

        $this->info("Order #{$orderId} printed successfully.");
        return 0;
    }
}
