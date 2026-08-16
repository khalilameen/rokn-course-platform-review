<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class CouponRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'code' => 'required|unique:coupons|string|min:3|max:50',
            'balance' => 'required|integer|between:1,100',
            'expiry_date' => 'required|date|after:'. date('Y-m-d'),
            
        ];
    }

    /**
     * @return array
     */
    public function attributes()
    {
        return [
            'code' => 'كود الكوبون',
            'balance' => 'نسبة الخصم',
            'expiry_date' => 'تاريخ الانتهاء',
            
        ];
    }
}
