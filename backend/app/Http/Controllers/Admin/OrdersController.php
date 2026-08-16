<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\DesignSetting;
use App\Models\PaymentMethod;
use App\Services\OrderLifecycleService;
use Illuminate\Http\Request;

class OrdersController extends Controller
{
    /**
     * Get design settings for the views
     */
    private function getDesignSettings()
    {
        return DesignSetting::getDefaultSettings();
    }

    /**
     * Display a listing of orders with filtering and pagination.
     *
     * @param Request $request
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function index(Request $request)
    {
        $query = Order::with(['user', 'course', 'coupon', 'courseCode', 'approvedBy', 'paymentMethod']);

        // Apply status filter
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Apply payment method filter
        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }

        // Apply user search
        if ($request->filled('user_search')) {
            $query->whereHas('user', function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->user_search . '%')
                  ->orWhere('email', 'like', '%' . $request->user_search . '%')
                  ->orWhere('phone', 'like', '%' . $request->user_search . '%');
            });
        }

        // Apply course search
        if ($request->filled('course_search')) {
            $query->whereHas('course', function ($q) use ($request) {
                $q->where('name_ar', 'like', '%' . $request->course_search . '%');
                $q->orWhere('name_en', 'like', '%' . $request->course_search . '%');
            });
        }

        // Apply date range filter
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Apply amount range filter
        if ($request->filled('amount_min')) {
            $query->where('final_amount', '>=', $request->amount_min);
        }
        if ($request->filled('amount_max')) {
            $query->where('final_amount', '<=', $request->amount_max);
        }

        // Sort by latest
        $query->latest();

        $orders = $query->paginate(10);

        // Preserve query parameters for pagination
        $orders->appends($request->all());

        // Get statistics
        $stats = [
            'total' => Order::count(),
            'pending' => Order::where('status', Order::STATUS_PENDING)->count(),
            'approved' => Order::where('status', Order::STATUS_APPROVED)->count(),
            'rejected' => Order::where('status', Order::STATUS_REJECTED)->count(),
            'cancelled' => Order::where('status', Order::STATUS_CANCELLED)->count(),
            'total_amount' => Order::where('status', Order::STATUS_APPROVED)->sum('final_amount'),
        ];

        $designSettings = $this->getDesignSettings();

        // Get payment methods for filter dropdown
        $paymentMethods = PaymentMethod::get();

        return view('admin.orders.index', compact('orders', 'stats', 'designSettings', 'paymentMethods'));
    }

    /**
     * Display the specified order.
     *
     * @param Order $order
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function show(Order $order)
    {
        $order->load(['user', 'course', 'coupon', 'courseCode', 'approvedBy', 'bill', 'paymentMethod']);
        $designSettings = $this->getDesignSettings();
        return view('admin.orders.show', compact('order', 'designSettings'));
    }

    /**
     * Update the order status.
     *
     * @param Request $request
     * @param Order $order
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateStatus(Request $request, Order $order, OrderLifecycleService $lifecycle)
    {
        $request->validate([
            'status' => 'required|in:pending,approved,rejected,cancelled',
            'notes' => 'nullable|string|max:1000'
        ]);

        $oldStatus = $order->status;

        try {
            if ($request->status === Order::STATUS_APPROVED) {
                $lifecycle->approve($order, auth()->id(), $request->notes);
            } elseif ($request->status === Order::STATUS_REJECTED) {
                $lifecycle->rejectPending($order, auth()->id(), $request->notes);
            } elseif ($request->status === Order::STATUS_CANCELLED) {
                $lifecycle->cancelPending($order, auth()->id(), $request->notes);
            } elseif ($order->status !== Order::STATUS_PENDING) {
                throw new \DomainException('Only an already-pending order can remain pending.');
            }
        } catch (\DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        $statusNames = [
            'pending' => 'في الانتظار',
            'approved' => 'مُعتمد',
            'rejected' => 'مرفوض',
            'cancelled' => 'ملغي'
        ];

        return redirect()->back()->with('success',
            'تم تحديث حالة الطلب من "' . $statusNames[$oldStatus] . '" إلى "' . $statusNames[$request->status] . '" بنجاح'
        );
    }

    public function resolveFinancialReview(
        Request $request,
        Order $order,
        OrderLifecycleService $lifecycle
    ) {
        $validated = $request->validate([
            'resolution' => 'required|in:repaid,waived',
            'note' => 'required|string|min:5|max:1000',
        ]);
        $eventKey = sprintf(
            'admin-resolution:%d:%s:%s',
            $order->id,
            $validated['resolution'],
            hash('sha256', trim($validated['note']))
        );

        try {
            $lifecycle->resolveFinancialReview(
                $order,
                $validated['resolution'],
                $eventKey,
                auth()->id(),
                trim($validated['note'])
            );
        } catch (\DomainException|\InvalidArgumentException|\UnexpectedValueException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with(
            'success',
            $validated['resolution'] === 'repaid'
                ? 'تم توثيق السداد وإعادة الاستحقاقات المرتبطة.'
                : 'تم توثيق الإعفاء وإغلاق المراجعة المالية.'
        );
    }

    /**
     * Bulk update orders status.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function bulkUpdateStatus(Request $request, OrderLifecycleService $lifecycle)
    {
        $request->validate([
            'order_ids' => 'required|array',
            'order_ids.*' => 'exists:orders,id',
            'status' => 'required|in:pending,approved,rejected,cancelled'
        ]);

        $orders = Order::whereIn('id', $request->order_ids)->get();
        $count = 0;

        try {
            \DB::transaction(function () use ($orders, $request, $lifecycle, &$count): void {
                foreach ($orders as $order) {
                    if ($request->status === Order::STATUS_APPROVED) {
                        $lifecycle->approve($order, auth()->id());
                    } elseif ($request->status === Order::STATUS_REJECTED) {
                        $lifecycle->rejectPending($order, auth()->id());
                    } elseif ($request->status === Order::STATUS_CANCELLED) {
                        $lifecycle->cancelPending($order, auth()->id());
                    } elseif ($order->status !== Order::STATUS_PENDING) {
                        throw new \DomainException('Only pending orders can remain pending.');
                    }
                    $count++;
                }
            }, 3);
        } catch (\DomainException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        $statusNames = [
            'pending' => 'في الانتظار',
            'approved' => 'مُعتمد',
            'rejected' => 'مرفوض',
            'cancelled' => 'ملغي'
        ];

        return redirect()->back()->with('success',
            'تم تحديث حالة ' . $count . ' طلب إلى "' . $statusNames[$request->status] . '" بنجاح'
        );
    }
}
