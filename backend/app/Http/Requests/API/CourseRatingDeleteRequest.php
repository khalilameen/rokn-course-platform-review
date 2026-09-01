<?php

declare(strict_types=1);

namespace App\Http\Requests\API;

use Illuminate\Foundation\Http\FormRequest;

final class CourseRatingDeleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['version' => 'required|integer|min:1'];
    }

    public function messages(): array
    {
        return [
            'version.required' => 'حدّث الكورس ثم حاول مرة أخرى',
            'version.integer' => 'حدّث الكورس ثم حاول مرة أخرى',
            'version.min' => 'حدّث الكورس ثم حاول مرة أخرى',
        ];
    }
}
