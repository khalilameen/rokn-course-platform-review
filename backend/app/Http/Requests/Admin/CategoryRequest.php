<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class CategoryRequest extends FormRequest
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
            'name_ar' => 'required|string|min:2|max:100',
            'name_en' => 'nullable|string|max:100',
            'description_ar' => 'nullable|string|max:1000',
            'description_en' => 'nullable|string|max:1000',
            'type' => 'nullable|string|max:50',
            'image' => 'nullable|image|max:4096',
        ];
    }

    /**
     * @return array
     */
    public function attributes()
    {
        return [
            'name_ar' => 'أسم القسم ',
            'name_en' => 'أسم القسم باللإنجليزية',
            'type' => 'نوع القسم',
            'image' => 'صوره القسم'
        ];
    }
}
