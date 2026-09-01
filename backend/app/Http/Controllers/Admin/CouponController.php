<?php

namespace App\Http\Controllers\Admin;

use App\Models\Coupon;
use App\Models\Course;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CouponRequest;
use Illuminate\Validation\ValidationException;
use App\Support\BusinessClock;

class CouponController extends Controller
{
    /**
     * Display a couponing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $coupons = Coupon::query()
            ->with('course:id,name_ar')
            ->withCount('redemptions')
            ->latest()
            ->get();

        return view('admin.coupons.index', compact('coupons'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $courses = Course::query()->orderBy('name_ar')->get(['id', 'name_ar']);
        return view('admin.coupons.create', compact('courses'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(CouponRequest $request)
    {
        $payload = $request->safe()->except('image');
        $payload['starts_at'] = BusinessClock::localInputToUtc($payload['starts_at'] ?? null);
        $coupon = Coupon::create($payload);
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $coupon->storeImage($file, 'coupons', 'featured');
        }

        return redirect()->route('admin.coupons.index')->with('success', 'تمت الإضافة بنجاح ');
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Coupon  $coupon
     * @return \Illuminate\Http\Response
     */
    public function edit(Coupon $coupon)
    {
         $courses = Course::query()->orderBy('name_ar')->get(['id', 'name_ar']);
         return view('admin.coupons.edit', compact('coupon', 'courses'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Coupon  $coupon
     * @return \Illuminate\Http\Response
     */
    public function update(CouponRequest $request, Coupon $coupon)
    {
        try {
            $payload = $request->safe()->except('image');
            $payload['starts_at'] = BusinessClock::localInputToUtc($payload['starts_at'] ?? null);
            $coupon->update($payload);
        } catch (\DomainException $exception) {
            throw ValidationException::withMessages([
                'code' => [$exception->getMessage()],
            ]);
        }
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $coupon->replaceImage($file, 'coupons', 'featured');
        }

        return redirect()->route('admin.coupons.index')->with('success', 'تم التعديل بنجاح');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Coupon  $coupon
     * @return \Illuminate\Http\Response
     */
    public function destroy(Coupon $coupon)
    {
        $coupon->delete();

        return redirect()->route('admin.coupons.index')->with('success', 'تم الحذف بنجاح ');
    }
}
