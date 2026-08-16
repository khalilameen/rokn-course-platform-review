<?php

namespace App\Http\Resources;

use App\Http\Resources\LessonResource;
use Illuminate\Http\Resources\Json\JsonResource;
class ShortCourseResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *'title', 'type','description','image', 'created_at','updated_at'
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $course = $this->course;
        $price = $course && $course->price !== null ? (float) $course->price : null;
        $priceBeforeDiscount = $course && $course->price_before_discount !== null
            ? (float) $course->price_before_discount
            : null;
        $lessons = $this->lessons->sortBy('priority');
        $videoCount = $course?->video_count ?: $lessons->count();
        $courseAttributes = $course?->getAttributes() ?? [];
        $ratingsCount = (int) ($courseAttributes['ratings_count'] ?? 0);
        $ratingsAverage = isset($courseAttributes['ratings_avg_rating'])
            ? (float) $courseAttributes['ratings_avg_rating']
            : null;
        $activeStudentsCount = array_key_exists('active_enrollments_count', $courseAttributes)
            ? max(0, (int) $courseAttributes['active_enrollments_count'])
            : null;

        return [
            'id' => (int)$this->id,
            'title' => (string)$this->title,
            'is_opened' => (bool) ($this->is_opened ?? false),
            'is_coming_soon' => (bool) ($course?->is_coming_soon ?? false),
            'type' =>  (string)$this->type,
            'description' =>$this->description ? (string)$this->description: null,
            'image' =>$this->image ? (string)$this->image: null,
            'price' => $price,
            'price_before_discount' => $this->when(
                $priceBeforeDiscount !== null && $priceBeforeDiscount > (float) ($price ?? 0),
                $priceBeforeDiscount
            ),
            'currency' => 'rokn_coins',
            'currency_type' => 'rokn_coins',
            'currency_label' => 'عملة ركن',
            'average_rating' => $this->when($ratingsCount > 0, $ratingsAverage),
            'ratings_count' => $this->when($ratingsCount > 0, $ratingsCount),
            'video_count' => $this->when((int) $videoCount > 0, (int) $videoCount),
            'hours_count' => $this->when((int) ($course?->hours_count ?? 0) > 0, (int) ($course?->hours_count ?? 0)),
            'questions_count' => $this->when((int) ($course?->questions_count ?? 0) > 0, (int) ($course?->questions_count ?? 0)),
            'exam_count' => $this->when((int) ($course?->exam_count ?? 0) > 0, (int) ($course?->exam_count ?? 0)),
            'home_work_count' => $this->when((int) ($course?->home_work_count ?? 0) > 0, (int) ($course?->home_work_count ?? 0)),
            'files_count' => $this->when((int) ($course?->files_count ?? 0) > 0, (int) ($course?->files_count ?? 0)),
            'students_count' => $this->when($activeStudentsCount !== null, $activeStudentsCount),
            'courses' => ShortCourseResource::collection($this->courses),
            'social_groups' => SocialGroupResource::collection($this->socialGroups),
            'lessons'=> ShortLessonResource::collection($lessons),
            // Prompt, model and token settings are deliberately server-only.
            'chat_available' => !empty($course?->ai_model_type) || !empty(config('openrouter.default_model')),
            'created_at' =>  (string)$this->created_at,
            'updated_at' =>  (string)$this->updated_at,
        ];

    }
}
