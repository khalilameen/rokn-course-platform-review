<?php

namespace App\Http\Controllers\Admin;

use App\Models\Coupon;
use App\Models\Course;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CouponRequest;
use App\Services\AdminAuthoringCreateIntentService;
use Illuminate\Support\Facades\DB;
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
    public function store(CouponRequest $request, AdminAuthoringCreateIntentService $createIntents)
    {
        $payload = $request->safe()->except('image');
        $payload['starts_at'] = BusinessClock::localInputToUtc($payload['starts_at'] ?? null);
        $requestId = (string) $payload['authoring_request_id'];
        $coupon = Coupon::withTrashed()->where('authoring_request_id', $requestId)->first();
        if (!$coupon) {
            $coupon = DB::transaction(function () use (
                $request,
                $payload,
                $createIntents
            ): Coupon {
                $coupon = Coupon::create($payload);
                $createIntents->checkpointResource($request, Coupon::class, $coupon->id);
                return $coupon;
            }, 3);
        } else {
            DB::transaction(function () use ($request, $coupon, $createIntents): void {
                Coupon::withTrashed()->whereKey($coupon->id)->lockForUpdate()->firstOrFail();
                $createIntents->checkpointResource($request, Coupon::class, $coupon->id);
            }, 3);
        }
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $coupon->storeImage(
                $file,
                'coupons',
                'featured',
                'admin-coupon|'.strtolower($requestId).'|'.hash_file('sha256', $file->getRealPath())
            );
        }

        DB::transaction(function () use ($request, $coupon, $createIntents): void {
            $locked = Coupon::withTrashed()->whereKey($coupon->id)->lockForUpdate()->firstOrFail();
            $createIntents->completeRedirect(
                $request,
                route('admin.coupons.index'),
                302,
                Coupon::class,
                $locked->id
            );
        }, 3);

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
            $payload = $request->safe()->except(['image', 'authoring_request_id']);
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
