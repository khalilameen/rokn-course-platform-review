<?php

namespace App\Http\Resources;

use App\Services\StudentNotificationPresentationService;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentNotificationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request)
    {
        $locale = $request->header('Accept-Language', app()->getLocale());
        
        // Determine if locale is Arabic
        $isArabic = str_starts_with($locale, 'ar');

        $titleAr = $this->firstText($this->title_ar, $this->title_en, 'إشعار من ركن');
        $titleEn = $this->firstText($this->title_en, $this->title_ar, 'Rokn notification');
        $messageAr = $this->firstText($this->message_ar, $this->message_en);
        $messageEn = $this->firstText($this->message_en, $this->message_ar);
        $presentation = app(StudentNotificationPresentationService::class)->for($this->resource);

        return [
            'id' => $this->id,
            'notification_type' => $this->notification_type,
            'title' => $isArabic ? $titleAr : $titleEn,
            'title_ar' => $titleAr,
            'title_en' => $titleEn,
            'message' => $isArabic ? $messageAr : $messageEn,
            'message_ar' => $messageAr,
            'message_en' => $messageEn,
            'link' => $presentation['link'],
            'course_id' => $presentation['course_id'],
            'image_url' => $presentation['image_url'],
            'action_label_ar' => $presentation['action_label_ar'],
            'action_label_en' => $presentation['action_label_en'],
            'is_read' => $this->is_read,
            'read_at' => $this->read_at ? $this->read_at->toIso8601String() : null,
            'created_at' => $this->created_at->toIso8601String(),
            'created_at_formatted' => $this->created_at->diffForHumans(),
            'notifiable_type' => $this->notifiable_type,
            'notifiable_id' => $this->notifiable_id,
        ];
    }

    private function firstText(mixed ...$values): string
    {
        foreach ($values as $value) {
            $text = trim((string) $value);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }
}

