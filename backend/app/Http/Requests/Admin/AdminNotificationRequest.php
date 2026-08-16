<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AdminNotificationRequest extends FormRequest
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
            'title_ar' => 'required|string|min:3|max:50',
            'title_en' => 'required|string|min:3|max:50',
            'description_ar' => 'required|string|min:3',
            'description_en' => 'required|string|min:3',
            'link' => 'string|min:3',
            'image' => ($this->method() === 'POST' ? 'required|image' : '')
        ];
    }

    /**
     * @return array
     */
    public function attributes()
    {
        return [
            'title_ar' => 'أسم الخدمه',
            'title_en' => 'أسم الخدمة باللإنجليزية',
            'description_ar' => 'محتوى الاشعار',
            'description_en' => 'محتوى الاشعار باللغة الانجليزية',
            'link' => 'الرابط الداخلي في الاشعار',            
            'image' => 'صوره الخدمة'
        ];
    }
}
