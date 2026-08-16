<?php

namespace App\Http\Requests\API;

use App\Http\Requests\API\FormRequest;

class RateRequest extends FormRequest
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
            'rate' => 'required|min:1|max:5',
            'comment' => 'required'
        ];
    }
}
