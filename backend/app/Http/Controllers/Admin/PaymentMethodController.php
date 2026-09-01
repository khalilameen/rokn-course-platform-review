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
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'account_details' => 'required|string',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $data = $validated;
        $data['is_active'] = $request->boolean('is_active');
        $data['is_default'] = false; // User-created payment methods are not default

        if (
            $data['is_active']
            && trim((string) $data['account_details']) === PaymentMethod::DEFAULT_ACCOUNT_DETAILS
        ) {
            return redirect()->back()
                ->withInput()
                ->withErrors(['account_details' => 'أضف بيانات الدفع قبل التفعيل']);
        }

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
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'account_details' => 'required|string',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $isDefaultMethod = $paymentMethod->is_default;
        $accountDetailsChanged = $request->input('account_details') !== $paymentMethod->account_details;

        if (
            $request->boolean('is_active')
            && trim((string) $request->input('account_details')) === PaymentMethod::DEFAULT_ACCOUNT_DETAILS
        ) {
            return redirect()->back()
                ->withInput()
                ->withErrors(['account_details' => 'أضف بيانات الدفع قبل التفعيل']);
        }

        // For default methods, don't allow changing the name
        $data = $validated;
        if ($isDefaultMethod) {
            $data['name'] = $paymentMethod->name; // Keep the original name
        }
        $data['is_active'] = $request->boolean('is_active');

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
