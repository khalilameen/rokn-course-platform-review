<?php

namespace App\Http\Controllers\API;

use App\Models\Coupon;
use App\Http\Controllers\Controller;
use App\Http\Resources\CouponResource;
use Illuminate\Http\Request;
class CouponController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return CouponResource::collection(Coupon::all());
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function validCoupon($code)
    {
        //
        $coupon = Coupon::where('code',$code)->first();
        if(!$coupon){
            return response()->json([
                'valid'=>false,
                'data' => 'غير موجود'], 200);
        }        
        if(!$coupon->active){
            return response()->json([
                'valid'=>false,
                'data' => 'الكوبون غير مفعل '], 200);
        }

        if($coupon->expiry_date < \Carbon\Carbon::now()){
            return response()->json([
                'valid'=>false,
                'data' => 'انتهى تاريخ الكوبون'], 200);
        }  
        return response()->json([
                'valid'=>true,
                'data' => new CouponResource($coupon)], 200);
        //return ;                   
    }
    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
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
        //
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
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Coupon  $coupon
     * @return \Illuminate\Http\Response
     */
    public function destroy(Coupon $coupon)
    {
        //
    }
}
