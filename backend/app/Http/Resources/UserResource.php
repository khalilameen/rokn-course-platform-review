<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use function GuzzleHttp\Psr7\str;

class UserResource extends UsersResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $orders_count = $this->ordersCount();

        return array_merge(parent::toArray($request), [
            'email' => (string)$this->email,
            'gender' => (string)$this->gender,
            'birthday' => (string)$this->birthday,
            'role' => (string)$this->role,
            'wallet_coins' => (float)$this->wallet_coins,
            'wallet_purchased_coins' => (int) min(max(0, (int) $this->wallet_coins), max(0, (int) $this->wallet_purchased_coins)),
            'wallet_reward_coins' => (int) max(0, (int) $this->wallet_coins - min(max(0, (int) $this->wallet_coins), max(0, (int) $this->wallet_purchased_coins))),
            'profile_image' => $this->profile_image_url,
            'job_title' => $this->job_title,
            'profile_deeplink' => $this->profile_deeplink,
            'device_os' => $this->device_os,
            'notifications_status' => $this->notifications_status,
            'watch_history_enabled' => (bool) $this->watch_history_enabled,
            'marketing_notifications_enabled' => (bool) $this->marketing_notifications_enabled,
            'orders_count'=> $orders_count,
          //  'order_delivering_count'=> $order_delivering_count,
            'active' => (bool) $this->active,
            'social_provider' => (string)$this->social_provider,
            'courses' => $this->getAuthorizedCourses(),
            // Exam Statistics
            'exam_statistics' => [
                'total_attempts' => $this->exam_attempts_count,
                'completed_exams' => $this->completed_exams_count,
                'average_score' => $this->average_exam_score,
                'passed_exams' => $this->exam_statistics['passed_exams'],
                'failed_exams' => $this->exam_statistics['failed_exams'],
                'completion_rate' => $this->exam_statistics['completion_rate']
            ],
            // Lesson Progress Statistics
            'lesson_progress' => [
                'completed_lessons' => $this->completed_lessons_count,
                'total_lessons_accessed' => $this->lesson_progress_statistics['total_lessons_accessed'],
                'completion_rate' => $this->lesson_progress_statistics['completion_rate']
            ],
            'interests' => $this->interests ? $this->interests->map(function($interest) {
                return [
                    'id' => $interest->id,
                    'name_ar' => $interest->name_ar,
                    'name_en' => $interest->name_en,
                ];
            }) : [],
        ]);
    }

    /**
     * Get authorized courses for the user
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    private function getAuthorizedCourses()
    {
        // Get active course enrollments for this user
        $enrollments = \App\Models\CourseEnrollment::where('user_id', $this->id)
            ->where('is_active', true)
            ->where(function($query) {
                $query->whereNull('expires_at')
                      ->orWhere('expires_at', '>', now());
            })
            ->with('course')
            ->get();

        // Extract courses from enrollments
        $courses = $enrollments->map(function($enrollment) {
            return $enrollment->course;
        })->filter(); // Remove null courses

        app(\App\Services\CourseDurationService::class)->attachMany($courses);

        return \App\Http\Resources\BaseCourseResource::collection($courses);
    }
}
