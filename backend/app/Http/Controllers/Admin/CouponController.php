<?php

namespace App\Http\Controllers\Admin;

use App\Models\Coupon;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CouponRequest;

use Illuminate\Http\Request;

class CouponController extends Controller
{
    /**
     * Display a couponing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $coupons  = Coupon::get();

        return view('admin.coupons.index', compact('coupons'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        return view('admin.coupons.create');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(CouponRequest $request)
    {
        $coupon = Coupon::create($request->input());
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $coupon->storeImage($file, 'coupons', 'featured');
        }

        return redirect()->route('admin.coupons.index')->with('success', 'تمت الإضافة بنجاح ');
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Coupon  $coupon
     * @return \Illuminate\Http\Response
     */
    public function show(Coupon $coupon)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Coupon  $coupon
     * @return \Illuminate\Http\Response
     */
    public function edit(Coupon $coupon)
    {
         return view('admin.coupons.edit', compact('coupon'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Coupon  $coupon
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Coupon $coupon)
    {
        $coupon->update($request->input());
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
