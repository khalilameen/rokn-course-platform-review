<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Services\AdminAuthoringCreateIntentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
    public function store(Request $request, AdminAuthoringCreateIntentService $createIntents)
    {
        $validated = $this->validated($request);

        DB::transaction(function () use ($request, $validated, $createIntents): void {
            $package = Package::create($validated);
            $createIntents->completeRedirect(
                $request,
                route('admin.packages.index'),
                302,
                Package::class,
                $package->id
            );
        }, 3);

        return redirect()->route('admin.packages.index')->with('success', 'تم إضافة الباقة بنجاح');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Package $package = null): array
    {
        $request->merge([
            'is_active' => $request->boolean('is_active'),
            'direct_enabled' => $request->boolean('direct_enabled'),
            'google_enabled' => $request->boolean('google_enabled'),
            'apple_enabled' => $request->boolean('apple_enabled'),
        ]);

        $validated = $request->validate([
            'name_ar' => 'required|string|max:255',
            'name_en' => 'required|string|max:255',
            'price' => 'required|numeric|min:0.01',
            'coins' => 'required|integer|min:1',
            'is_active' => 'required|boolean',
            'direct_enabled' => 'required|boolean',
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
            'authoring_request_id' => [$package ? 'nullable' : 'required', 'uuid'],
        ]);
        unset($validated['authoring_request_id']);

        return $validated;
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

        try {
            $package->update($validated);
        } catch (\DomainException $exception) {
            throw ValidationException::withMessages([
                'coins' => [$exception->getMessage()],
            ]);
        }

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
        if (
            $package->orders()->exists()
            || $package->storePurchases()->exists()
            || filled($package->google_product_id)
            || filled($package->apple_product_id)
        ) {
            return redirect()->back()->with(
                'error',
                'لا يمكن حذف باقة دخلت دورة بيع. عطّل قنواتها مع الاحتفاظ بالسجل المالي.'
            );
        }
        $package->delete();
        return redirect()->route('admin.packages.index')->with('success', 'تم حذف الباقة بنجاح');
    }
}
