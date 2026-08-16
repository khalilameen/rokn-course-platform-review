<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class CourseCodeRequest extends FormRequest
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
        $isUpdating = $this->isMethod('put') || $this->isMethod('patch');
        
        $rules = [
            'name' => 'nullable|string|max:255',
            'type' => 'required|in:course,lesson,multiple_lessons',
            'expiry_date' => 'nullable|date|after:start_date',
            'max_uses' => 'required|integer|min:1|max:10000',
            'description' => 'nullable|string|max:1000',
            'allowed_email_domains' => 'nullable|string|max:2000',
            'is_grant' => 'nullable|boolean',
        ];

        // number_of_codes is only required when creating new codes
        if (!$isUpdating) {
            $rules['start_date'] = 'nullable|date|after_or_equal:today';
            $rules['number_of_codes'] = 'required|integer|min:1|max:100';
        }

        // Add is_active for update
        if ($isUpdating) {
            $rules['is_active'] = 'nullable|boolean';
        }

        // Conditional validation based on type
        switch ($this->input('type')) {
            case 'course':
                $rules['course_id'] = 'required|exists:courses,id';
                break;
            case 'lesson':
                $rules['lesson_id'] = 'required|exists:lessons,id';
                break;
            case 'multiple_lessons':
                $rules['course_id'] = 'required|exists:courses,id';
                $rules['lesson_ids'] = 'required|array|min:1';
                $rules['lesson_ids.*'] = 'exists:lessons,id';
                break;
        }

        if ($this->boolean('is_grant')) {
            $rules['type'] = 'required|in:course';
        }

        return $rules;
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages()
    {
        return [
            'type.required' => 'نوع الكود مطلوب',
            'type.in' => 'نوع الكود يجب أن يكون: دورة، درس، أو دروس متعددة',
            'course_id.required' => 'الدورة مطلوبة',
            'course_id.exists' => 'الدورة المحددة غير موجودة',
            'lesson_id.required' => 'الدرس مطلوب',
            'lesson_id.exists' => 'الدرس المحدد غير موجود',
            'lesson_ids.required' => 'يجب اختيار درس واحد على الأقل',
            'lesson_ids.array' => 'يجب اختيار دروس صحيحة',
            'lesson_ids.min' => 'يجب اختيار درس واحد على الأقل',
            'lesson_ids.*.exists' => 'أحد الدروس المحددة غير موجود',
            'start_date.date' => 'تاريخ البداية يجب أن يكون تاريخ صحيح',
            'start_date.after_or_equal' => 'تاريخ البداية يجب أن يكون اليوم أو بعده',
            'expiry_date.date' => 'تاريخ الانتهاء يجب أن يكون تاريخ صحيح',
            'expiry_date.after' => 'تاريخ الانتهاء يجب أن يكون بعد تاريخ البداية',
            'max_uses.required' => 'عدد مرات الاستخدام مطلوب',
            'max_uses.integer' => 'عدد مرات الاستخدام يجب أن يكون رقم صحيح',
            'max_uses.min' => 'عدد مرات الاستخدام يجب أن يكون 1 على الأقل',
            'max_uses.max' => 'عدد مرات الاستخدام يجب أن يكون 10000 كحد أقصى',
            'number_of_codes.required' => 'عدد الأكواد المطلوب إنشاؤها مطلوب',
            'number_of_codes.integer' => 'عدد الأكواد يجب أن يكون رقم صحيح',
            'number_of_codes.min' => 'عدد الأكواد يجب أن يكون 1 على الأقل',
            'number_of_codes.max' => 'عدد الأكواد يجب أن يكون 100 كحد أقصى',
        ];
    }
}

