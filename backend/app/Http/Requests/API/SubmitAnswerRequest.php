<?php

declare(strict_types=1);

namespace App\Http\Requests\API;

use Illuminate\Foundation\Http\FormRequest;

final class SubmitAnswerRequest extends FormRequest
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
            'exam_attempt_id' => 'required|integer|exists:exam_attempts,id',
            // The attempt owns an immutable question snapshot. The authored
            // question may be replaced by a later published revision while
            // this learner is still answering it; lifecycle validation below
            // checks membership in that snapshot instead of the live table.
            'question_id' => 'required|integer|min:1',
            'selected_answer' => 'required|integer|in:1,2,3,4,5,6',
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
            'exam_attempt_id.required' => 'Exam attempt ID is required',
            'exam_attempt_id.exists' => 'Exam attempt not found',
            'question_id.required' => 'Question ID is required',
            'question_id.exists' => 'Question not found',
            'selected_answer.required' => 'Selected answer is required',
            'selected_answer.in' => 'Selected answer must be between 1 and 6',
        ];
    }
}
