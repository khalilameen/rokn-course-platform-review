<?php

declare(strict_types=1);

namespace App\Http\Requests\API;

use Illuminate\Foundation\Http\FormRequest;

final class StartExamRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'quiz_id' => 'required|integer|exists:lists,id',
            'course_id' => 'nullable|integer|exists:courses,id',
            'section_id' => 'nullable|integer|exists:course_sections,id',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'quiz_id.required' => 'Quiz ID is required',
            'quiz_id.exists' => 'Quiz not found',
            'course_id.exists' => 'Course not found',
            'section_id.exists' => 'Section not found',
        ];
    }
}
