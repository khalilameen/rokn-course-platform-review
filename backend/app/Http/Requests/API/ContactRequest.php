<?php

namespace App\Http\Requests\API;

use App\Http\Requests\API\FormRequest;

class ContactRequest extends FormRequest
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
            'name' => 'required|string|min:2|max:120',
            'phone' => 'nullable|string|max:32',
            'email' => 'required|email|max:255',
            'message' => 'required|string|min:10|max:2000'
        ];
    }
}
