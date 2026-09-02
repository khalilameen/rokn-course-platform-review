<?php

namespace App\Http\Controllers\Admin;

use App\Models\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Services\AdminAuthoringCreateIntentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Support\AdminEditorVersion;

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
        $editorVersions = $paymentMethods->mapWithKeys(fn (PaymentMethod $method): array => [
            $method->id => $this->editorVersion($method),
        ]);

        return view('admin.payment-methods.index', compact('paymentMethods', 'editorVersions'));
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
    public function store(Request $request, AdminAuthoringCreateIntentService $createIntents)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'account_details' => 'required|string',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'authoring_request_id' => 'required|uuid',
        ]);

        $data = $validated;
        unset($data['authoring_request_id']);
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

        DB::transaction(function () use ($request, $data, $createIntents): void {
            $paymentMethod = PaymentMethod::create($data);
            $createIntents->completeRedirect(
                $request,
                route('admin.payment-methods.index'),
                302,
                PaymentMethod::class,
                $paymentMethod->id
            );
        }, 3);

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
        $editorVersion = $this->editorVersion($paymentMethod);
        return view('admin.payment-methods.edit', compact('paymentMethod', 'editorVersion'));
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
            'editor_version' => 'required|string|size:64',
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
        unset($data['editor_version']);
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

        DB::transaction(function () use ($paymentMethod, $data, $request): void {
            $locked = PaymentMethod::query()->whereKey($paymentMethod->id)
                ->lockForUpdate()->firstOrFail();
            if (!hash_equals($this->editorVersion($locked), (string) $request->input('editor_version'))) {
                throw ValidationException::withMessages([
                    'editor_version' => 'تغيّرت طريقة الدفع منذ فتح الصفحة\nأعد تحميلها قبل الحفظ',
                ]);
            }
            if ($locked->is_default) {
                $data['name'] = $locked->name;
            }
            $locked->update($data);
        }, 3);

        return redirect()->route('admin.payment-methods.index')->with('success', 'تم التعديل بنجاح');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\PaymentMethod  $paymentMethod
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request, PaymentMethod $paymentMethod)
    {
        $validated = $request->validate(['editor_version' => 'required|string|size:64']);
        $blocked = DB::transaction(function () use ($paymentMethod, $validated): bool {
            $locked = PaymentMethod::query()->whereKey($paymentMethod->id)
                ->lockForUpdate()->firstOrFail();
            if (!hash_equals($this->editorVersion($locked), (string) $validated['editor_version'])) {
                throw ValidationException::withMessages([
                    'editor_version' => 'تغيّرت طريقة الدفع منذ فتح الصفحة\nأعد تحميلها قبل الحذف',
                ]);
            }
            if ($locked->is_default) return true;
            $locked->delete();
            return false;
        }, 3);
        if ($blocked) {
            return redirect()->route('admin.payment-methods.index')
                ->with('error', 'لا يمكن حذف طرق الدفع الافتراضية');
        }

        return redirect()->route('admin.payment-methods.index')->with('success', 'تم الحذف بنجاح');
    }

    private function editorVersion(PaymentMethod $method): string
    {
        return AdminEditorVersion::for($method, [
            'name', 'account_details', 'description', 'is_active', 'is_default',
        ]);
    }
}
