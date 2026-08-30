<?php

namespace App\Http\Controllers\Admin;

use App\Models\PaymentMethod;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PaymentMethodController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $paymentMethods = PaymentMethod::get();

        return view('admin.payment-methods.index', compact('paymentMethods'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        return view('admin.payment-methods.create');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'account_details' => 'required|string',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $data = $request->all();
        $data['is_active'] = $request->has('is_active') ? true : false;
        $data['is_default'] = false; // User-created payment methods are not default

        PaymentMethod::create($data);

        return redirect()->route('admin.payment-methods.index')->with('success', 'تمت الإضافة بنجاح');
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\PaymentMethod  $paymentMethod
     * @return \Illuminate\Http\Response
     */
    public function edit(PaymentMethod $paymentMethod)
    {
        return view('admin.payment-methods.edit', compact('paymentMethod'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\PaymentMethod  $paymentMethod
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, PaymentMethod $paymentMethod)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'account_details' => 'required|string',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $isActivating = $request->has('is_active') && !$paymentMethod->is_active;
        $isDefaultMethod = $paymentMethod->is_default;
        $hasDefaultAccountDetails = $paymentMethod->hasDefaultAccountDetails();
        $accountDetailsChanged = $request->input('account_details') !== $paymentMethod->account_details;

        // Validation for default payment methods
        if ($isDefaultMethod && $isActivating && $hasDefaultAccountDetails && !$accountDetailsChanged) {
            return redirect()->back()
                ->withInput()
                ->withErrors(['account_details' => 'يجب تحديث تفاصيل الحساب قبل تفعيل طريقة الدفع الافتراضية']);
        }

        // For default methods, don't allow changing the name
        $data = $request->all();
        if ($isDefaultMethod) {
            $data['name'] = $paymentMethod->name; // Keep the original name
        }
        $data['is_active'] = $request->has('is_active') ? true : false;

        // Check if confirmation is needed for account details change on default methods
        if ($isDefaultMethod && $accountDetailsChanged && !$request->has('confirm_account_details')) {
            return redirect()->back()
                ->withInput()
                ->with('confirm_account_details', true)
                ->with('new_account_details', $request->input('account_details'));
        }

        $paymentMethod->update($data);

        return redirect()->route('admin.payment-methods.index')->with('success', 'تم التعديل بنجاح');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\PaymentMethod  $paymentMethod
     * @return \Illuminate\Http\Response
     */
    public function destroy(PaymentMethod $paymentMethod)
    {
        // Don't allow deleting default payment methods
        if ($paymentMethod->is_default) {
            return redirect()->route('admin.payment-methods.index')
                ->with('error', 'لا يمكن حذف طرق الدفع الافتراضية');
        }

        $paymentMethod->delete();

        return redirect()->route('admin.payment-methods.index')->with('success', 'تم الحذف بنجاح');
    }
}
