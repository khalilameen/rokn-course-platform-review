<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\NotificationService;
use App\Models\Course;
use App\Models\StudentNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Support\Str;

class NotificationsController extends Controller
{
    public function index(): View
    {
        $campaigns = StudentNotification::query()
            ->whereNotNull('delivery_key')
            ->selectRaw('delivery_key')
            ->selectRaw('MAX(notification_type) as notification_type')
            ->selectRaw('MAX(title_ar) as title_ar')
            ->selectRaw('MAX(link) as link')
            ->selectRaw('COUNT(*) as recipients_count')
            ->selectRaw('SUM(CASE WHEN push_attempted_at IS NOT NULL THEN 1 ELSE 0 END) as attempted_count')
            ->selectRaw('SUM(CASE WHEN push_sent_at IS NOT NULL THEN 1 ELSE 0 END) as sent_count')
            ->selectRaw('SUM(CASE WHEN is_read = 1 THEN 1 ELSE 0 END) as read_count')
            ->selectRaw('MAX(created_at) as sent_at')
            ->groupBy('delivery_key')
            ->orderByRaw('MAX(created_at) DESC')
            ->paginate(30);

        return view('admin.notifications.index', compact('campaigns'));
    }

    public function create(): View
    {
        $courses = Course::query()
            ->where('is_coming_soon', false)
            ->orderByDesc('id')
            ->get(['id', 'name_ar']);
        return view('admin.notifications.create', compact('courses'));
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'title_ar'   => 'required_without:title|string|max:255',
            'message_ar' => 'required_without:message|string|max:1000',
            'title_en'   => 'nullable|string|max:255',
            'message_en' => 'nullable|string|max:1000',
            // Backward-compatible aliases for the previous dashboard form.
            'title'   => 'required_without:title_ar|nullable|string|max:255',
            'message' => 'required_without:message_ar|nullable|string|max:1000',
            'course_id' => 'nullable|required_unless:audience,all|integer|exists:courses,id',
            'audience' => 'required|string|in:all,not_enrolled,enrolled',
        ]);

        $titleAr = trim((string) ($request->input('title_ar') ?: $request->input('title')));
        $messageAr = trim((string) ($request->input('message_ar') ?: $request->input('message')));
        $titleEn = trim((string) ($request->input('title_en') ?: $titleAr));
        $messageEn = trim((string) ($request->input('message_en') ?: $messageAr));
        $courseId = $request->integer('course_id') ?: null;
        $audience = (string) $request->input('audience', 'not_enrolled');
        $notificationType = !$courseId
            ? 'admin_broadcast'
            : ($audience === 'enrolled' ? 'continue_course' : 'course_promotion');
        NotificationService::notifyGeneric($notificationType, [], [
            'title_ar'   => $titleAr,
            'title_en'   => $titleEn,
            'message_ar' => $messageAr,
            'message_en' => $messageEn,
            'link' => $courseId
                ? '/courses/' . $courseId . ($audience === 'enrolled' ? '/watch' : '')
                : null,
            'notifiable_type' => $courseId ? Course::class : null,
            'notifiable_id' => $courseId,
            'course_id' => $courseId,
            'audience' => $audience,
            'delivery_key' => 'admin-broadcast:' . Str::uuid(),
        ]);

        return redirect()->route('admin.notifications.index')->with('success', 'تمت إضافة الإشعار إلى قائمة الإرسال');
    }
}
