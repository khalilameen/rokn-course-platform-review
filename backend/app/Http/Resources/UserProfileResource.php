<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class UserProfileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $levels = $this->earnedLevels ?: collect();
        $badgeCourses = \App\Models\Course::query()
            ->whereIn('id', $levels->pluck('pivot.course_id')->filter()->unique())
            ->get(['id', 'name_ar', 'name_en', 'badge_track'])
            ->keyBy('id');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'wallet_coins' => (float)$this->wallet_coins,
            'wallet_purchased_coins' => (int) min(max(0, (int) $this->wallet_coins), max(0, (int) $this->wallet_purchased_coins)),
            'wallet_reward_coins' => (int) max(0, (int) $this->wallet_coins - min(max(0, (int) $this->wallet_coins), max(0, (int) $this->wallet_purchased_coins))),
            'profile_image' => $this->profile_image_url,
            'job_title' => $this->job_title,
            'profile_deeplink' => $this->profile_deeplink,
            'email' => $this->publicEmail(),
            'phone_verified' => !is_null($this->phone_verified_at),
            'phone_verified_at' => $this->phone_verified_at,
            'watch_history_enabled' => (bool) $this->watch_history_enabled,
            'marketing_notifications_enabled' => (bool) $this->marketing_notifications_enabled,
            'preferred_locale' => $this->preferred_locale ?: 'ar',
            'leaderboard_opt_in' => (bool) $this->leaderboard_opt_in,
            'enrolled_courses' => $this->getEnrolledCourses(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'interests' => $this->interests ? $this->interests->map(function($interest) {
                return [
                    'id' => $interest->id,
                    'name_ar' => $interest->name_ar,
                    'name_en' => $interest->name_en,
                ];
            }) : [],
            'earned_badges' => $levels
                ->filter(function($level) use ($badgeCourses) {
                    $course = $badgeCourses->get($level->pivot->course_id);

                    return $course
                        && in_array($course->badge_track, ['professional', 'freelance'], true);
                })
                ->map(function($level) use ($badgeCourses) {
                $course = $badgeCourses->get($level->pivot->course_id);
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
            })->values(),
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
     * Get enrolled courses for the user
     *
     * @return array
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
