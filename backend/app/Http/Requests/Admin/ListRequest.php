<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name_ar' => 'required|string|min:3|max:50',
            'name_en' => 'required|string|min:3|max:50',
        ];
    }

    public function attributes(): array
    {
        return [
            'name_ar' => 'اسم القائمة',
            'name_en' => 'اسم القائمة بالإنجليزية',
        ];
    }
}
