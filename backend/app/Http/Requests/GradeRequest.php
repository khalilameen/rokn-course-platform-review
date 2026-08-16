<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GradeRequest extends FormRequest
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
        $gradeId = $this->route('grade')?->id;
        
        return [
            'name_ar' => 'required|string|max:255',
            'name_en' => 'nullable|string|max:255',
            'type' => 'required|in:preparatory,secondary,primary,university,general',
            'description_ar' => 'nullable|string',
            'description_en' => 'nullable|string',
            'country' => 'required|string|max:100'
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages()
    {
        return [
            'name_ar.required' => 'Grade name is required',
            'name_ar.max' => 'Grade name cannot exceed 255 characters',
            'name_en.max' => 'English grade name cannot exceed 255 characters',
            'type.required' => 'Grade type is required',
            'type.in' => 'Grade type must be either primary, preparatory, secondary, university, or general',
            'country.required' => 'Country is required',
            'country.max' => 'Country name cannot exceed 100 characters'
        ];
    }

    /**
     * Prepare the data for validation.
     *
     * @return void
     */
    protected function prepareForValidation()
    {
        // Set default values for English fields if not provided
        if ($this->has('name_ar') && !$this->has('name_en')) {
            $this->merge([
                'name_en' => $this->name_ar
            ]);
        }

        if ($this->has('description_ar') && !$this->has('description_en')) {
            $this->merge([
                'description_en' => $this->description_ar
            ]);
        }
    }
}
