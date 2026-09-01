<?php

namespace App\Http\Requests\Admin;

use App\Models\AdminNotification;
use App\Support\RoknAppLink;
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
            'title_ar' => ['required', 'string', 'min:3', 'max:80', $this->knownPlaceholders()],
            'title_en' => ['nullable', 'string', 'min:3', 'max:80', $this->knownPlaceholders()],
            'description_ar' => ['required', 'string', 'min:3', 'max:240', $this->knownPlaceholders()],
            'description_en' => ['nullable', 'string', 'min:3', 'max:240', $this->knownPlaceholders()],
            'action_label_ar' => 'nullable|string|max:80',
            'action_label_en' => 'nullable|string|max:80',
            'secondary_action_label_ar' => 'nullable|string|max:80',
            'secondary_action_label_en' => 'nullable|string|max:80',
            'link' => [
                'nullable',
                'string',
                'max:2000',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    $link = trim((string) $value);
                    if ($link === '') {
                        return;
                    }
                    if (RoknAppLink::normalize($link) === null) {
                        $fail('اختر وجهة صحيحة داخل ركن');
                    }
                },
            ],
            'is_active' => 'nullable|boolean',
            'is_dismissible' => 'nullable|boolean',
            'priority' => 'required|integer|min:0|max:1000',
            'cooldown_hours' => 'required|integer|min:0|max:8760',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after:starts_at',
            'image' => 'nullable|image|max:4096',
            'remove_image' => 'nullable|boolean',
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

    private function knownPlaceholders(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            preg_match_all('/\{([^{}\r\n]+)\}/', (string) $value, $matches);
            $unknown = array_diff(array_unique($matches[1] ?? []), [
                'coins',
                'course',
                'task',
                'lesson',
                'quiz',
            ]);
            if ($unknown !== []) {
                $fail('يوجد متغير غير معروف في النص');
            }
        };
    }
}
