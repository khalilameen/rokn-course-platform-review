<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CourseEnrollment;
use App\Models\User;
use App\Services\StudentNotificationService;
use App\Services\EngagementMessageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class SendLearningNudges extends Command
{
    protected $signature = 'learning:send-nudges {--limit=500 : Maximum students to nudge in this run}';

    protected $description = 'Send one opt-in learning reminder to inactive enrolled students';

    public function handle(): int
    {
        $today = now(config('app.timezone', 'Africa/Cairo'))->startOfDay();
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $sent = 0;

        $students = User::query()
            ->where('role', 'client')
            ->where('active', true)
            ->where('notifications_status', true)
            ->whereHas('enrollments', function ($query): void {
                $query->where('is_active', true)
                    ->where(function ($expires): void {
                        $expires->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    });
            })
            ->whereDoesntHave('sectionProgress', function ($query) use ($today): void {
                $query->where('is_completed', true)->where('updated_at', '>=', $today);
            })
            ->whereDoesntHave('studentNotifications', function ($query) use ($today): void {
                $query->where('notification_type', 'learning_nudge')
                    ->where('created_at', '>=', $today);
            })
            ->with(['enrollments' => function ($query): void {
                $query->where('is_active', true)
                    ->where(function ($expires): void {
                        $expires->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    })
                    ->with('course')
                    ->orderByDesc('access_granted_at')
                    ->orderByDesc('enrolled_at');
            }])
            // Least-recently-notified first prevents the same low IDs from
            // consuming the daily limit forever as the audience grows.
            ->orderByRaw('CASE WHEN last_learning_nudge_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('last_learning_nudge_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($students as $student) {
            /** @var CourseEnrollment|null $enrollment */
            $enrollment = $student->enrollments->first();
            $course = $enrollment?->course;
            if (!$course) {
                continue;
            }

            $courseName = (string) ($course->name_ar ?: $course->name_en ?: 'كورس ركن');
            try {
                $copy = app(EngagementMessageService::class)->copy(
                    'learning_nudge',
                    ['course' => $courseName],
                    [
                        'title_ar' => 'خطوتك الجاية مستنياك',
                        'title_en' => 'Your next step is ready',
                        'message_ar' => "ارجع لـ {$courseName}. مقطع واحد كفاية ترجع للمود.",
                        'message_en' => "Continue {$courseName}. One short clip is enough to get back into it.",
                    ]
                );
                StudentNotificationService::notifyUser(
                    $student,
                    'learning_nudge',
                    $copy['title_ar'],
                    $copy['title_en'],
                    $copy['message_ar'],
                    $copy['message_en'],
                    '/courses/' . $course->id,
                    $course::class,
                    (int) $course->id,
                    'learning-nudge:' . $student->id . ':' . $course->id . ':' . now()->toDateString(),
                );
                $student->forceFill(['last_learning_nudge_at' => now()])->save();
                $sent++;
            } catch (\Throwable $exception) {
                Log::warning('Learning nudge could not be queued', [
                    'user_id' => $student->id,
                    'course_id' => $course->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $this->info("Sent {$sent} learning nudge(s).");

        return self::SUCCESS;
    }
}
