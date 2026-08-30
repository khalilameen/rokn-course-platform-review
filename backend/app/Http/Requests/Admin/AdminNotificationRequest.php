<?php

namespace App\Http\Requests\Admin;

use App\Models\AdminNotification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        $notification = $this->route('admin_notification');

        return [
            'system_key' => [
                'nullable',
                'string',
                'max:80',
                'regex:/^[a-z0-9_]+$/',
                Rule::unique('admin_notifications', 'system_key')->ignore($notification?->id),
            ],
            'surface' => ['required', Rule::in(array_keys(AdminNotification::SURFACES))],
            'title_ar' => 'required|string|min:3|max:255',
            'title_en' => 'required|string|min:3|max:255',
            'description_ar' => 'required|string|min:3|max:255',
            'description_en' => 'required|string|min:3|max:255',
            'action_label_ar' => 'nullable|string|max:80',
            'action_label_en' => 'nullable|string|max:80',
            'secondary_action_label_ar' => 'nullable|string|max:80',
            'secondary_action_label_en' => 'nullable|string|max:80',
            'link' => 'nullable|string|max:2000',
            'is_active' => 'nullable|boolean',
            'is_dismissible' => 'nullable|boolean',
            'priority' => 'required|integer|min:0|max:1000',
            'cooldown_hours' => 'required|integer|min:0|max:8760',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after:starts_at',
            'image' => 'nullable|image|max:4096',
        ];
    }

    /**
     * @return array
     */
    public function attributes()
    {
        return [
            'title_ar' => 'عنوان الإشعار',
            'title_en' => 'عنوان الإشعار بالإنجليزية',
            'description_ar' => 'نص الإشعار',
            'description_en' => 'نص الإشعار بالإنجليزية',
            'link' => 'وجهة زر الإشعار',
            'image' => 'صورة الإشعار',
        ];
    }
}
