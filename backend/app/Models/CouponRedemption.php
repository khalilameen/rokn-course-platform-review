<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class CouponRedemption extends Model
{
    protected $fillable = [
        'coupon_id',
        'user_id',
        'course_id',
        'order_id',
        'coupon_code',
        'discount_percentage',
        'discount_coins',
        'redeemed_at',
    ];

    protected $casts = [
        'discount_percentage' => 'integer',
        'discount_coins' => 'integer',
        'redeemed_at' => 'datetime',
    ];

    public function coupon()
    {
        return $this->belongsTo(Coupon::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
