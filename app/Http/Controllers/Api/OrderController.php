<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(private OrderService $orders) {}

    /**
     * List the orders belonging to the authenticated user.
     */
    public function index(Request $request)
    {
        $orders = $request->user()
            ->orders()
            ->with('items.product')
            ->latest()
            ->paginate(15);

        return OrderResource::collection($orders);
    }

    /**
     * Place a new order.
     */
    public function store(StoreOrderRequest $request)
    {
        $order = $this->orders->place($request->user(), $request->validated('items'));

        return (new OrderResource($order))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display a single order.
     */
    public function show(Request $request, Order $order)
    {
        abort_unless(
            $order->user_id === $request->user()->id,
            403
        );

        return new OrderResource($order->load('items.product'));
    }
}
