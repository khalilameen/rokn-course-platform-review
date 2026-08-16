<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    //
    protected $fillable = ['name_ar', 'name_en', 'code', 'balance', 'expiry_date','active'];
     protected $dates = [
        'expiry_date',
    ];
    public function customers()
    {

        return $this->belongsToMany('App\Models\User');
    } 

}
