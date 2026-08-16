<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrdersResource;
use App\Models\Order;
use Illuminate\Http\Request;

class OrdersController extends Controller
{
    /**
     * List authenticated user's course orders.
     */
    public function index()
    {
        $orders = Order::where('user_id', auth()->user()->id)
            ->latest()
            ->get();

        return OrdersResource::collection($orders);
    }

    /**
     * Show a single course order.
     */
    public function show(Order $order)
    {
        if ($order->user_id !== auth()->user()->id) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        return new OrdersResource($order);
    }

    /**
     * Cancel a pending course order.
     */
    public function cancel(Request $request, Order $order)
    {
        if ($order->user_id !== auth()->user()->id) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        if ($order->status !== Order::STATUS_PENDING) {
            return response()->json(['message' => 'لا يمكن إلغاء هذا الطلب'], 422);
        }

        $order->update(['status' => Order::STATUS_CANCELLED]);

        return response()->json(['message' => 'تم إلغاء الطلب بنجاح'], 200);
    }
}
