<?php

namespace App\Services;

use App\Models\StudentSectionProgress;
use Carbon\Carbon;

class StreakService
{
    /**
     * Get distinct activity dates (Y-m-d) when user completed at least one section.
     * Uses app timezone for date extraction.
     *
     * @param int $userId
     * @param Carbon|null $from
     * @param Carbon|null $to
     * @return array Array of Y-m-d date strings
     */
    public static function getActivityDatesForUser(int $userId, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $query = StudentSectionProgress::where('user_id', $userId)
            ->where('is_completed', true);

        if ($from !== null) {
            $query->where('updated_at', '>=', $from);
        }
        if ($to !== null) {
            $query->where('updated_at', '<=', $to);
        }

        $timezone = config('app.timezone', 'Asia/Riyadh');

        return $query->get()
            ->map(function ($progress) use ($timezone) {
                return Carbon::parse($progress->updated_at)->timezone($timezone)->format('Y-m-d');
            })
            ->unique()
            ->values()
            ->toArray();
    }

    /**
     * Get streak data for the authenticated user: week view, current streak, status.
     *
     * @param int $userId
     * @return array
     */
    public static function getStreakDataForUser(int $userId): array
    {
        $timezone = config('app.timezone', 'Asia/Riyadh');
        $now = Carbon::now($timezone);

        // Week: Sunday to Saturday
        $weekStart = $now->copy()->startOfWeek(Carbon::SUNDAY);
        $weekEnd = $now->copy()->endOfWeek(Carbon::SATURDAY);

        // Fetch activity dates for the week plus a few days before (for streak calculation)
        $lookbackStart = $weekStart->copy()->subDays(14);
        $activityDates = self::getActivityDatesForUser($userId, $lookbackStart, $weekEnd);
        $activitySet = array_flip($activityDates);

        $today = $now->format('Y-m-d');

        // Build week days
        $days = [];
        $current = $weekStart->copy();
        while ($current->lte($weekEnd)) {
            $dateStr = $current->format('Y-m-d');
            $days[] = [
                'date' => $dateStr,
                'day_name' => $current->format('D'),
                'has_streak' => isset($activitySet[$dateStr]),
            ];
            $current->addDay();
        }

        // Current streak: consecutive days from today backwards (or from most recent activity)
        $currentStreak = 0;
        if (isset($activitySet[$today])) {
            $check = $now->copy();
            while (isset($activitySet[$check->format('Y-m-d')])) {
                $currentStreak++;
                $check->subDay();
            }
        }

        // Last streak before gap: if today has no activity, count backwards from yesterday
        $lastStreakBeforeGap = 0;
        if (!isset($activitySet[$today])) {
            $check = $now->copy()->subDay();
            while (isset($activitySet[$check->format('Y-m-d')])) {
                $lastStreakBeforeGap++;
                $check->subDay();
            }
        }

        // Status
        $status = 'no_streak';
        if ($currentStreak >= 1) {
            $status = 'active';
        } elseif ($lastStreakBeforeGap >= 1) {
            $status = 'broken';
        }

        // Status messages
        $statusMessageAr = self::getStatusMessageAr($status, $currentStreak, $lastStreakBeforeGap);
        $statusMessageEn = self::getStatusMessageEn($status, $currentStreak, $lastStreakBeforeGap);

        return [
            'week' => [
                'start' => $weekStart->format('Y-m-d'),
                'end' => $weekEnd->format('Y-m-d'),
                'days' => $days,
            ],
            'current_streak' => $currentStreak,
            'last_streak_before_gap' => $lastStreakBeforeGap,
            'status' => $status,
            'status_message_ar' => $statusMessageAr,
            'status_message_en' => $statusMessageEn,
        ];
    }

    private static function getStatusMessageAr(string $status, int $currentStreak, int $lastStreakBeforeGap): string
    {
        if ($status === 'active') {
            return $currentStreak === 1
                ? 'يوم واحد متتالي بنشاط'
                : "آخر {$currentStreak} أيام متتالية بها نشاط";
        }
        if ($status === 'broken') {
            return $lastStreakBeforeGap === 1
                ? 'كان لديك يوم واحد بنشاط قبل التوقف'
                : "كان لديك {$lastStreakBeforeGap} أيام متتالية بنشاط قبل التوقف";
        }
        return 'لا يوجد سلسلة نشاط حالياً';
    }

    private static function getStatusMessageEn(string $status, int $currentStreak, int $lastStreakBeforeGap): string
    {
        if ($status === 'active') {
            return $currentStreak === 1
                ? '1 consecutive day with activity'
                : "Last {$currentStreak} consecutive days with activity";
        }
        if ($status === 'broken') {
            return $lastStreakBeforeGap === 1
                ? 'You had 1 day with activity before the gap'
                : "You had {$lastStreakBeforeGap} consecutive days with activity before the gap";
        }
        return 'No current streak';
    }
}
