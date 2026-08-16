<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CoinEarningMethod;
use App\Models\Setting;
use Illuminate\Http\Request;

class CoinEarningMethodController extends Controller
{
    public function index()
    {
        $methods  = CoinEarningMethod::latest()->paginate(10);
        $setting  = Setting::first();
        return view('admin.coin_earning_methods.index', compact('methods', 'setting'));
    }

    public function updateSettings(Request $request)
    {
        $request->validate([
            'how_to_use_coins_ar' => 'nullable|string',
            'how_to_use_coins_en' => 'nullable|string',
        ]);

        $setting = Setting::first();
        if ($setting) {
            $setting->update($request->only('how_to_use_coins_ar', 'how_to_use_coins_en'));
        }

        return redirect()->route('admin.coin-earning-methods.index')
            ->with('success', 'تم تحديث نص كيفية استخدام العملات بنجاح');
    }

    public function create()
    {
        return view('admin.coin_earning_methods.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'title_ar' => 'required|string|max:255',
            'title_en' => 'required|string|max:255',
            'coins_amount' => 'required|integer|min:0',
            'action_key' => 'nullable|string|max:255|unique:coin_earning_methods,action_key',
            'action_url' => 'nullable|required_if:requires_external_visit,1|url|max:2000',
            'requires_external_visit' => 'nullable|boolean',
            'verification_delay_seconds' => 'nullable|integer|min:0|max:300',
            'is_active' => 'boolean',
            'is_repeatable' => 'boolean',
        ]);

        CoinEarningMethod::create($request->only([
            'title_ar', 'title_en', 'coins_amount', 'action_key', 'action_url',
            'requires_external_visit', 'verification_delay_seconds', 'is_active',
        ]) + ['is_repeatable' => false]);

        return redirect()->route('admin.coin-earning-methods.index')
            ->with('success', 'تم إضافة طريقة ربح العملات بنجاح');
    }

    public function edit(CoinEarningMethod $coinEarningMethod)
    {
        return view('admin.coin_earning_methods.edit', compact('coinEarningMethod'));
    }

    public function update(Request $request, CoinEarningMethod $coinEarningMethod)
    {
        $request->validate([
            'title_ar' => 'required|string|max:255',
            'title_en' => 'required|string|max:255',
            'coins_amount' => 'required|integer|min:0',
            'action_key' => 'nullable|string|max:255|unique:coin_earning_methods,action_key,' . $coinEarningMethod->id,
            'action_url' => 'nullable|required_if:requires_external_visit,1|url|max:2000',
            'requires_external_visit' => 'nullable|boolean',
            'verification_delay_seconds' => 'nullable|integer|min:0|max:300',
            'is_active' => 'boolean',
            'is_repeatable' => 'boolean',
        ]);

        $coinEarningMethod->update($request->only([
            'title_ar', 'title_en', 'coins_amount', 'action_key', 'action_url',
            'requires_external_visit', 'verification_delay_seconds', 'is_active',
        ]) + ['is_repeatable' => false]);

        return redirect()->route('admin.coin-earning-methods.index')
            ->with('success', 'تم تحديث طريقة ربح العملات بنجاح');
    }

    public function destroy(CoinEarningMethod $coinEarningMethod)
    {
        $coinEarningMethod->delete();

        return redirect()->route('admin.coin-earning-methods.index')
            ->with('success', 'تم حذف طريقة ربح العملات بنجاح');
    }

    public function toggleStatus(CoinEarningMethod $coinEarningMethod)
    {
        $coinEarningMethod->update(['is_active' => !$coinEarningMethod->is_active]);
        return response()->json(['status' => true, 'is_active' => $coinEarningMethod->is_active]);
    }
}
