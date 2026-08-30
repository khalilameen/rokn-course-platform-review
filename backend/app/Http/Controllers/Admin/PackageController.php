<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Package;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PackageController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $packages = Package::latest()->get();
        return view('admin.packages.index', compact('packages'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        return view('admin.packages.create');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $validated = $this->validated($request);

        Package::create($validated);

        return redirect()->route('admin.packages.index')->with('success', 'تم إضافة الباقة بنجاح');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Package $package = null): array
    {
        $request->merge([
            'google_enabled' => $request->boolean('google_enabled'),
            'apple_enabled' => $request->boolean('apple_enabled'),
        ]);

        return $request->validate([
            'name_ar' => 'required|string|max:255',
            'name_en' => 'required|string|max:255',
            'price' => 'required|numeric|min:0.01',
            'coins' => 'required|integer|min:1',
            'google_product_id' => [
                'nullable', 'required_if:google_enabled,1', 'string', 'max:191',
                'regex:/^[a-z0-9._]+$/',
                Rule::unique('packages', 'google_product_id')->ignore($package?->id),
            ],
            'apple_product_id' => [
                'nullable', 'required_if:apple_enabled,1', 'string', 'max:191',
                'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('packages', 'apple_product_id')->ignore($package?->id),
            ],
            'google_enabled' => 'required|boolean',
            'apple_enabled' => 'required|boolean',
        ]);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Package  $package
     * @return \Illuminate\Http\Response
     */
    public function show(Package $package)
    {
        $package->load(['purchases' => function($q) {
            $q->latest()->limit(50);
        }]);
        return view('admin.packages.show', compact('package'));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Package  $package
     * @return \Illuminate\Http\Response
     */
    public function edit(Package $package)
    {
        return view('admin.packages.edit', compact('package'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Package  $package
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Package $package)
    {
        $validated = $this->validated($request, $package);

        $package->update($validated);

        return redirect()->route('admin.packages.index')->with('success', 'تم تحديث الباقة بنجاح');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Package  $package
     * @return \Illuminate\Http\Response
     */
    public function destroy(Package $package)
    {
        if ($package->storePurchases()->exists()) {
            return redirect()->back()->with(
                'error',
                'لا يمكن حذف باقة لها مشتريات متجر. عطّلها مع الاحتفاظ بالسجل المالي.'
            );
        }
        $package->delete();
        return redirect()->route('admin.packages.index')->with('success', 'تم حذف الباقة بنجاح');
    }
}
