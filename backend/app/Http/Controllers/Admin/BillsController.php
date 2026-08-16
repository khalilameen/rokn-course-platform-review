<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use App\Services\OrderLifecycleService;

class BillsController extends Controller
{
    /**
     * Display a listing of bills with filtering and pagination.
     *
     * @param Request $request
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function index(Request $request)
    {
        $query = Bill::with(['user', 'course', 'order.paymentMethod']);

        // Apply payment status filter
        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->payment_status);
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
            $query->where('total_amount', '>=', $request->amount_min);
        }
        if ($request->filled('amount_max')) {
            $query->where('total_amount', '<=', $request->amount_max);
        }

        // Apply due date filter
        if ($request->filled('due_date_from')) {
            $query->whereDate('due_date', '>=', $request->due_date_from);
        }
        if ($request->filled('due_date_to')) {
            $query->whereDate('due_date', '<=', $request->due_date_to);
        }

        // Sort by latest
        $query->latest();

        $bills = $query->paginate(15);

        // Preserve query parameters for pagination
        $bills->appends($request->all());

        // Get statistics
        $stats = [
            'total' => Bill::count(),
            'pending' => Bill::where('payment_status', Bill::PAYMENT_STATUS_PENDING)->count(),
            'paid' => Bill::where('payment_status', Bill::PAYMENT_STATUS_PAID)->count(),
            'overdue' => Bill::where('payment_status', Bill::PAYMENT_STATUS_OVERDUE)->count(),
            'cancelled' => Bill::where('payment_status', Bill::PAYMENT_STATUS_CANCELLED)->count(),
            'total_amount' => Bill::where('payment_status', Bill::PAYMENT_STATUS_PAID)->sum('total_amount'),
            'pending_amount' => Bill::where('payment_status', Bill::PAYMENT_STATUS_PENDING)->sum('total_amount'),
        ];

        // Get payment methods for filter dropdown
        $paymentMethods = PaymentMethod::get();

        return view('admin.bills.index', compact('bills', 'stats', 'paymentMethods'));
    }

    /**
     * Display the specified bill.
     *
     * @param Bill $bill
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function show(Bill $bill)
    {
        $bill->load(['user', 'course', 'order.course', 'order.coupon', 'order.paymentMethod']);

        return view('admin.bills.show', compact('bill'));
    }

    /**
     * Update the bill payment status.
     *
     * @param Request $request
     * @param Bill $bill
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updatePaymentStatus(Request $request, Bill $bill, OrderLifecycleService $lifecycle)
    {
        $request->validate([
            'payment_status' => 'required|in:pending,paid,overdue,cancelled',
            'notes' => 'nullable|string|max:1000'
        ]);

        $oldStatus = $bill->payment_status;

        $expected = $lifecycle->expectedBillStatus($bill->order);
        if ($request->payment_status !== $expected) {
            return redirect()->back()->with(
                'error',
                'Bill status is derived from its order. Update the order or register a financial reversal.'
            );
        }
        $bill = $lifecycle->reconcileBill($bill);

        $statusNames = [
            'pending' => 'في الانتظار',
            'paid' => 'مدفوع',
            'overdue' => 'متأخر',
            'cancelled' => 'ملغي'
        ];

        return redirect()->back()->with('success',
            'تم تحديث حالة الفاتورة من "' . $statusNames[$oldStatus] . '" إلى "' . $statusNames[$request->payment_status] . '" بنجاح'
        );
    }

    /**
     * Bulk update bills payment status.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function bulkUpdatePaymentStatus(Request $request, OrderLifecycleService $lifecycle)
    {
        $request->validate([
            'bill_ids' => 'required|array',
            'bill_ids.*' => 'exists:bills,id',
            'payment_status' => 'required|in:pending,paid,overdue,cancelled'
        ]);

        $bills = Bill::whereIn('id', $request->bill_ids)->get();
        $count = 0;

        foreach ($bills as $bill) {
            if (!$bill->order) {
                return redirect()->back()->with('error', 'One or more bills have no source order.');
            }
            $expected = $lifecycle->expectedBillStatus($bill->order);
            if ($request->payment_status !== $expected) {
                return redirect()->back()->with(
                    'error',
                    'One or more bill statuses conflict with their source orders.'
                );
            }
        }

        \DB::transaction(function () use ($bills, $lifecycle, &$count): void {
            foreach ($bills as $bill) {
                $lifecycle->reconcileBill($bill);
                $count++;
            }
        }, 3);

        $statusNames = [
            'pending' => 'في الانتظار',
            'paid' => 'مدفوع',
            'overdue' => 'متأخر',
            'cancelled' => 'ملغي'
        ];

        return redirect()->back()->with('success',
            'تم تحديث حالة ' . $count . ' فاتورة إلى "' . $statusNames[$request->payment_status] . '" بنجاح'
        );
    }
}
