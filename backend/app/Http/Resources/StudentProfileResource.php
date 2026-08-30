<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class StudentProfileResource extends JsonResource
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

        return [
            'id' => (integer)$this->id,
            'name' => (string)$this->name,
            'phone' => $this->phone !== null ? (string) $this->phone : null,
            'wallet_coins' => (float)$this->wallet_coins,
            'wallet_purchased_coins' => (int) min(max(0, (int) $this->wallet_coins), max(0, (int) $this->wallet_purchased_coins)),
            'wallet_reward_coins' => (int) max(0, (int) $this->wallet_coins - min(max(0, (int) $this->wallet_coins), max(0, (int) $this->wallet_purchased_coins))),
            'image' => $this->image ? $this->image : url('/images/service.jpg'),
            'profile_image' => $this->profile_image_url,
            'job_title' => $this->job_title,
            'profile_deeplink' => $this->profile_deeplink,
            'email' => $this->publicEmail(),
            'gender' => (string)$this->gender,
            'birthday' => (string)$this->birthday,
            'role' => (string)$this->role,
            'device_os' => $this->device_os,
            'notifications_status' => $this->notifications_status,
            'preferred_locale' => $this->preferred_locale ?: 'ar',
            'leaderboard_opt_in' => (bool) $this->leaderboard_opt_in,
            'watch_history_enabled' => (bool) $this->watch_history_enabled,
            'marketing_notifications_enabled' => (bool) $this->marketing_notifications_enabled,
            'autoplay_next_enabled' => (bool) $this->autoplay_next_enabled,
            'video_quality_preference' => (string) ($this->video_quality_preference ?: 'auto'),
            'video_fit_mode' => (string) ($this->video_fit_mode ?: 'cover'),
            'playback_speed' => (float) ($this->playback_speed ?: 1),
            'orders_count' => $orders_count,
            'active' => (bool) $this->active,
            'social_provider' => (string)$this->social_provider,
            'phone_verified' => !is_null($this->phone_verified_at),
            'phone_verified_at' => $this->phone_verified_at,
            'courses' => $this->getAuthorizedCourses(),
            'enrolled_courses' => $this->getEnrolledCourses(),
            'exam_statistics' => [
                'total_attempts' => $this->exam_attempts_count,
                'completed_exams' => $this->completed_exams_count,
                'average_score' => $this->average_exam_score,
                'passed_exams' => $this->exam_statistics['passed_exams'] ?? 0,
                'failed_exams' => $this->exam_statistics['failed_exams'] ?? 0,
                'completion_rate' => $this->exam_statistics['completion_rate'] ?? 0
            ],
            'lesson_progress' => [
                'completed_lessons' => $this->completed_lessons_count,
                'total_lessons_accessed' => $this->lesson_progress_statistics['total_lessons_accessed'] ?? 0,
                'completion_rate' => $this->lesson_progress_statistics['completion_rate'] ?? 0
            ],
            'interests' => $this->getInterestsSafely(),
            'earned_badges' => $this->getEarnedBadgesSafely(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function publicEmail(): ?string
    {
        $email = Str::lower(trim((string) $this->email));
        if (
            $email === ''
            || Str::endsWith($email, '@placeholder.com')
            || Str::endsWith($email, '@accounts.rokn.app')
        ) {
            return null;
        }

        return $email;
    }

    /**
     * Get user interests safely (handling missing tables in test DBs)
     */
    protected function getInterestsSafely()
    {
        try {
            return $this->interests ? $this->interests->map(function($interest) {
                return [
                    'id' => $interest->id,
                    'name_ar' => $interest->name_ar,
                    'name_en' => $interest->name_en,
                ];
            }) : [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get user earned badges safely (handling missing tables in test DBs)
     */
    protected function getEarnedBadgesSafely()
    {
        try {
            $levels = $this->earnedLevels ?: collect();
            $courses = \App\Models\Course::query()
                ->whereIn('id', $levels->pluck('pivot.course_id')->filter()->unique())
                ->get(['id', 'name_ar', 'name_en', 'badge_track'])
                ->keyBy('id');

            return $levels
                ->filter(function($level) use ($courses) {
                    $course = $courses->get($level->pivot->course_id);

                    return $course
                        && in_array($course->badge_track, ['professional', 'freelance'], true);
                })
                ->map(function($level) use ($courses) {
                $course = $courses->get($level->pivot->course_id);
                return [
                    'id' => $level->pivot->id ?: $level->id . '-' . $level->pivot->course_id,
                    'level_id' => $level->id,
                    'name_ar' => $level->name_ar,
                    'name_en' => $level->name_en,
                    'badge_image' => $level->badge_image_url,
                    'course_id' => $level->pivot->course_id,
                    'course_name_ar' => $course?->name_ar,
                    'course_name_en' => $course?->name_en,
                    'track' => $course?->badge_track,
                    'earned_at' => $level->pivot->earned_at,
                ];
            })->values();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get authorized courses for the user
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    protected function getAuthorizedCourses()
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

    /**
     * Get enrolled courses summary for the user
     *
     * @return \Illuminate\Support\Collection
     */
    protected function getEnrolledCourses()
    {
        $enrollments = $this->enrollments()->with('course')->get();
        
        return $enrollments->map(function ($enrollment) {
            return [
                'id' => $enrollment->course->id ?? null,
                'title' => $enrollment->course->title ?? null,
                'enrolled_at' => $enrollment->created_at,
                'status' => $enrollment->status ?? 'active',
            ];
        })->filter(function ($course) {
            return $course['id'] !== null;
        })->values();
    }
}
