<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\WaiterPanelController;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use ReflectionMethod;

class PrintOrderChangesCommand extends Command
{
    protected $signature = 'print:order-changes {orderId} {cacheKey}';

    protected $description = 'Print kitchen ticket changes asynchronously using cached items';

    public function handle(): int
    {
        $orderId = (int) $this->argument('orderId');
        $cacheKey = $this->argument('cacheKey');

        $order = Order::query()
            ->with(['items.modifiers', 'items.comboComponents', 'floor:id,name', 'table:id,table_no', 'createdBy:id,name', 'outlet'])
            ->find($orderId);

        if (! $order) {
            $this->error("Order #{$orderId} not found.");
            return 1;
        }

        $cached = Cache::get($cacheKey);
        if (! $cached) {
            $this->error("Cached print data not found for key: {$cacheKey}");
            return 1;
        }

        $newItems = $cached['new'] ?? [];
        $cancelledItems = $cached['cancelled'] ?? [];

        $controller = app(WaiterPanelController::class);

        $refMethod = new ReflectionMethod(WaiterPanelController::class, 'printChangedItems');
        $refMethod->setAccessible(true);
        $refMethod->invoke($controller, $order, $newItems, $cancelledItems);

        Cache::forget($cacheKey);

        $this->info("Order changes for #{$orderId} printed successfully.");
        return 0;
    }
}
