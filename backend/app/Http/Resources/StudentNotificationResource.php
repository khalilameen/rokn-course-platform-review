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

        $presentation = app(StudentNotificationPresentationService::class)->for($this->resource);

        return [
            'id' => $this->id,
            'notification_type' => $this->notification_type,
            'title' => $isArabic ? $this->title_ar : $this->title_en,
            'title_ar' => $this->title_ar,
            'title_en' => $this->title_en,
            'message' => $isArabic ? $this->message_ar : $this->message_en,
            'message_ar' => $this->message_ar,
            'message_en' => $this->message_en,
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
}

