<?php

namespace App\Http\Controllers;

use App\Http\Requests\WaiterPanel\CreateOrderRequest;
use App\Http\Requests\WaiterPanel\AddItemsRequest;
use App\Http\Requests\WaiterPanel\CancelOrderRequest;
use App\Http\Requests\WaiterPanel\CancelItemRequest;
use App\Http\Requests\WaiterPanel\SplitOrderRequest;
use App\Http\Requests\WaiterPanel\MergeTablesRequest;
use App\Http\Requests\WaiterPanel\TransferTableRequest;
use App\Models\Order;
use App\Services\WaiterPanelService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WaiterPanelController extends Controller
{
    protected $service;

    public function __construct(WaiterPanelService $service)
    {
        $this->service = $service;
    }

    // ---------- General data ----------
    public function createData(Request $request): Response
    {
        $data = $this->service->getCreateData($request->user());
        return response()->json($data);
    }

    // ---------- Table & Item listings ----------
    public function tables(Request $request): Response
    {
        $data = $this->service->listTables($request->user());
        return response()->json($data);
    }

    public function items(Request $request): Response
    {
        $data = $this->service->listItems($request->user());
        return response()->json($data);
    }

    public function orders(Request $request): Response
    {
        $data = $this->service->listOrders($request->user());
        return response()->json($data);
    }

    public function showOrder($id, Request $request): Response
    {
        return $this->show($id, $request);
    }

    // ---------- Order CRUD ----------
    public function storeOrder(CreateOrderRequest $request): Response
    {
        return $this->store($request);
    }

    public function updateOrder(Request $request, $id): Response
    {
        return $this->update($request, $id);
    }

    public function store(CreateOrderRequest $request): Response
    {
        $order = $this->service->createOrder($request->validated(), $request->user());
        return response()->json($order);
    }

    public function update(Request $request, $id): Response
    {
        $order = $this->service->updateOrder($id, $request->all(), $request->user());
        return response()->json($order);
    }

    public function addItems(AddItemsRequest $request, $id): Response
    {
        $order = $this->service->addItems($id, $request->validated(), $request->user());
        return response()->json($order);
    }

    public function cancelOrder(CancelOrderRequest $request, $id): Response
    {
        $order = $this->service->cancelOrder($id, $request->validated(), $request->user());
        return response()->json($order);
    }

    public function cancelItem(CancelItemRequest $request, $orderId, $itemId): Response
    {
        $order = $this->service->cancelItem($orderId, $itemId, $request->validated(), $request->user());
        return response()->json($order);
    }

    // ---------- Additional actions (placeholders) ----------
    public function confirmOrder(Request $request, $id): Response
    {
        // Placeholder implementation
        return response()->json(['message' => 'Order confirmed'], 200);
    }

    public function updateStatus(Request $request, $id): Response
    {
        // Placeholder implementation
        return response()->json(['message' => 'Status updated'], 200);
    }

    public function completePayment(Request $request, $id): Response
    {
        // Placeholder implementation
        return response()->json(['message' => 'Payment completed'], 200);
    }

    public function reprint(Request $request, $id): Response
    {
        // Placeholder implementation
        return response()->json(['message' => 'Reprint triggered'], 200);
    }

    public function updateAdjustments(Request $request, $id): Response
    {
        // Placeholder implementation
        return response()->json(['message' => 'Adjustments updated'], 200);
    }

    public function splitData($id, Request $request): Response
    {
        $data = $this->service->getSplitData($id, $request->user());
        return response()->json($data);
    }

    public function splitOrder(SplitOrderRequest $request, $id): Response
    {
        $result = $this->service->splitOrder($id, $request->validated(), $request->user());
        return response()->json($result);
    }

    public function mergeOptions($id, Request $request): Response
    {
        $data = $this->service->getMergeOptions($id, $request->user());
        return response()->json($data);
    }

    public function mergeTables(MergeTablesRequest $request, $id): Response
    {
        $result = $this->service->mergeTables($id, $request->validated(), $request->user());
        return response()->json($result);
    }

    public function tableActivity($id, Request $request): Response
    {
        $data = $this->service->getTableActivity($id, $request->user());
        return response()->json($data);
    }

    public function swapOptions($id, Request $request): Response
    {
        $data = $this->service->getSwapOptions($id, $request->user());
        return response()->json($data);
    }

    public function swapTable(Request $request, $id): Response
    {
        $result = $this->service->swapTable($id, $request->all(), $request->user());
        return response()->json($result);
    }

    public function unmergeTables($id, Request $request): Response
    {
        $result = $this->service->unmergeTables($id, $request->all(), $request->user());
        return response()->json($result);
    }

    public function showMergeGroup($id, Request $request): Response
    {
        $group = $this->service->getMergeGroup($id, $request->user());
        return response()->json($group);
    }

    public function reservations(Request $request): Response
    {
        $data = $this->service->listReservations($request->user());
        return response()->json($data);
    }

    public function seatReservation(Request $request, $id): Response
    {
        $result = $this->service->seatReservation($id, $request->all(), $request->user());
        return response()->json($result);
    }

    /**
     * GET /waiter-panel/orders/{id}
     */
    public function show($id, Request $request): Response
    {
        $order = $this->service->getOrderDetail($id, $request->user());
        return response()->json($order);
    }
}
?>
