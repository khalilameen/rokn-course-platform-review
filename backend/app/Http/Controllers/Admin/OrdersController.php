<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\DesignSetting;
use App\Models\PaymentMethod;
use App\Services\OrderLifecycleService;
use App\Services\PaymentChannelReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
    public function index(Request $request, PaymentChannelReportService $paymentChannels)
    {
        $query = Order::with(['user', 'course', 'package', 'coupon', 'courseCode', 'approvedBy', 'paymentMethod', 'storePurchase']);

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
        $paymentChannelReport = $paymentChannels->summary();
        $stats = [
            'total' => Order::count(),
            'pending' => Order::where('status', Order::STATUS_PENDING)->count(),
            'approved' => Order::where('status', Order::STATUS_APPROVED)->count(),
            'rejected' => Order::where('status', Order::STATUS_REJECTED)->count(),
            'cancelled' => Order::where('status', Order::STATUS_CANCELLED)->count(),
            'total_amount' => $paymentChannelReport['egp']['gross_amount'],
        ];

        $designSettings = $this->getDesignSettings();

        // Get payment methods for filter dropdown
        $paymentMethods = PaymentMethod::get();
        $paymentMethodOptions = collect($paymentChannels->labels())
            ->merge($paymentMethods->pluck('name', 'name'));
        $paymentMethodLabels = $paymentChannelReport['rows']
            ->pluck('label', 'method')
            ->all();

        return view('admin.orders.index', compact(
            'orders',
            'stats',
            'designSettings',
            'paymentMethods',
            'paymentMethodOptions',
            'paymentMethodLabels',
            'paymentChannelReport'
        ));
    }

    /**
     * Display the specified order.
     *
     * @param Order $order
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function show(Order $order)
    {
        $order->load(['user', 'course', 'package', 'coupon', 'courseCode', 'approvedBy', 'bill', 'paymentMethod', 'storePurchase']);
        $designSettings = $this->getDesignSettings();
        $paymentMethodLabels = app(PaymentChannelReportService::class)->labels();
        return view('admin.orders.show', compact('order', 'designSettings', 'paymentMethodLabels'));
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

    public function recordSettlement(Request $request, Order $order)
    {
        if (
            !in_array($order->payment_method, [
                Order::PAYMENT_METHOD_KASHIER,
                Order::PAYMENT_METHOD_GOOGLE_PLAY,
                Order::PAYMENT_METHOD_APP_STORE,
            ], true)
            || $order->package_id === null
            || $order->status !== Order::STATUS_APPROVED
        ) {
            return back()->with('error', 'التسوية متاحة فقط لطلب شحن مدفوع ومُعتمد.');
        }
        if ($order->gateway_settlement_status === 'test_purchase') {
            return back()->with('error', 'عمليات الاختبار لا تتحول إلى إيراد حقيقي.');
        }
        if ($order->gateway_net_amount !== null) {
            return back()->with('error', 'تم توثيق صافي هذه العملية من قبل ولا يمكن استبداله.');
        }

        $validated = $request->validate([
            'gross_amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'fee_amount' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'net_amount' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'settled_at' => ['required', 'date', 'before_or_equal:now'],
            'provider_reference' => ['required', 'string', 'min:3', 'max:191'],
        ]);
        $gross = round((float) $validated['gross_amount'], 2);
        $fee = round((float) $validated['fee_amount'], 2);
        $net = round((float) $validated['net_amount'], 2);
        $currency = strtoupper($validated['currency']);

        if (abs(($gross - $fee) - $net) > 0.02) {
            return back()->withInput()->with(
                'error',
                'يجب أن يساوي الصافي الإجمالي ناقص جميع رسوم واستقطاعات المزود.'
            );
        }
        if (
            $order->gateway_gross_amount !== null
            && abs((float) $order->gateway_gross_amount - $gross) > 0.02
        ) {
            return back()->withInput()->with(
                'error',
                'إجمالي كشف التسوية لا يطابق إجمالي العملية المسجل.'
            );
        }
        if (
            filled($order->gateway_currency)
            && strtoupper((string) $order->gateway_currency) !== $currency
        ) {
            return back()->withInput()->with('error', 'عملة كشف التسوية لا تطابق عملة العملية.');
        }

        try {
            DB::transaction(function () use ($order, $validated, $gross, $fee, $net, $currency): void {
                /** @var Order $locked */
                $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
                if ($locked->gateway_net_amount !== null) {
                    throw new \DomainException('تم توثيق صافي هذه العملية بالتزامن من قبل.');
                }

                $response = is_array($locked->payment_gateway_response)
                    ? $locked->payment_gateway_response
                    : [];
                $response['settlement'] = [
                    'source' => 'dashboard_statement',
                    'provider_reference' => trim($validated['provider_reference']),
                    'recorded_by' => auth()->id(),
                    'recorded_at' => now()->toIso8601String(),
                ];
                $locked->forceFill([
                    'gateway_gross_amount' => $locked->gateway_gross_amount ?? number_format($gross, 2, '.', ''),
                    'gateway_fee_amount' => number_format($fee, 2, '.', ''),
                    'gateway_net_amount' => number_format($net, 2, '.', ''),
                    'gateway_currency' => $currency,
                    'gateway_settlement_status' => 'settled',
                    'gateway_settled_at' => $validated['settled_at'],
                    'payment_gateway_response' => $response,
                ])->save();
            }, 3);
        } catch (\DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'تم توثيق كشف التسوية وإظهار الصافي المؤكد في التقارير.');
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
