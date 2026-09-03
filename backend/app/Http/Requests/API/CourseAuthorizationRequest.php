<?php

namespace App\Http\Requests\API;

use Illuminate\Foundation\Http\FormRequest;

class CourseAuthorizationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (!$this->filled('idempotency_key') && $this->hasHeader('Idempotency-Key')) {
            $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
        }
    }

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
            'course_id' => 'required|exists:courses,id',
            'access_plan_code' => 'nullable|string|in:basic,guided,mentor',
            'coupon_code' => 'nullable|string|min:3|max:50',
            'expected_price' => 'nullable|integer|min:0|max:100000000',
            'expected_course_revision' => 'nullable|integer|min:1',
            'idempotency_key' => [
                'nullable',
                'string',
                'min:16',
                'max:140',
                'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]{15,139}$/',
            ],
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
            'course_id.required' => 'Course ID is required',
            'course_id.exists' => 'Course not found',
        ];
    }
}
