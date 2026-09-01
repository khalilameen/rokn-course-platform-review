<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\NotificationService;
use App\Services\CoursePublishingService;
use App\Models\Course;
use App\Models\StudentNotification;
use App\Models\NotificationCampaign;
use App\Support\RoknAppLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class NotificationsController extends Controller
{
    public function index(): View
    {
        $campaigns = Schema::hasTable('notification_campaigns')
            ? NotificationCampaign::query()
                ->withCount([
                    'notifications as attempted_count' => fn ($query) => $query->whereNotNull('push_attempted_at'),
                    'notifications as sent_count' => fn ($query) => $query->whereNotNull('push_sent_at'),
                    'notifications as read_count' => fn ($query) => $query->where('is_read', true),
                ])
                ->latest('queued_at')
                ->paginate(30)
            : StudentNotification::query()
                ->whereNotNull('delivery_key')
                ->selectRaw('delivery_key')
                ->selectRaw('MAX(notification_type) as notification_type')
                ->selectRaw('MAX(title_ar) as title_ar')
                ->selectRaw('MAX(message_ar) as message_ar')
                ->selectRaw('MAX(image_url) as image_url')
                ->selectRaw('MAX(link) as link')
                ->selectRaw('COUNT(*) as recipients_count')
                ->selectRaw('SUM(CASE WHEN push_attempted_at IS NOT NULL THEN 1 ELSE 0 END) as attempted_count')
                ->selectRaw('SUM(CASE WHEN push_sent_at IS NOT NULL THEN 1 ELSE 0 END) as sent_count')
                ->selectRaw('SUM(CASE WHEN is_read = 1 THEN 1 ELSE 0 END) as read_count')
                ->selectRaw('MAX(created_at) as queued_at')
                ->groupBy('delivery_key')
                ->orderByRaw('MAX(created_at) DESC')
                ->paginate(30);

        return view('admin.notifications.index', compact('campaigns'));
    }

    public function create(): View
    {
        $courses = Course::query()
            ->whereNull('parent_id')
            ->where('is_coming_soon', false)
            ->orderByDesc('id')
            ->get(['id', 'name_ar']);
        return view('admin.notifications.create', compact('courses'));
    }

    public function store(
        Request $request,
        ?CoursePublishingService $publishing = null
    ): RedirectResponse
    {
        $publishing ??= app(CoursePublishingService::class);
        $request->validate([
            'title_ar'   => 'required_without:title|string|max:80',
            'message_ar' => 'required_without:message|string|max:240',
            'title_en'   => 'nullable|string|max:80',
            'message_en' => 'nullable|string|max:240',
            // Backward-compatible aliases for the previous dashboard form.
            'title'   => 'required_without:title_ar|nullable|string|max:80',
            'message' => 'required_without:message_ar|nullable|string|max:240',
            'course_id' => 'nullable|required_unless:audience,all|integer|exists:courses,id',
            'audience' => 'required|string|in:all,not_enrolled,enrolled',
            'notification_kind' => 'nullable|string|in:marketing,service',
            'image' => 'nullable|image|mimes:jpeg,png,webp|max:4096',
        ]);

        $titleAr = trim((string) ($request->input('title_ar') ?: $request->input('title')));
        $messageAr = trim((string) ($request->input('message_ar') ?: $request->input('message')));
        $titleEn = trim((string) ($request->input('title_en') ?: $titleAr));
        $messageEn = trim((string) ($request->input('message_en') ?: $messageAr));
        $courseId = $request->integer('course_id') ?: null;
        $audience = (string) $request->input('audience', 'not_enrolled');
        if ($courseId) {
            $course = Course::query()->findOrFail($courseId);
            $audit = $publishing->audit($course);
            if ($course->isNestedCourse() || $course->is_coming_soon || !($audit['ready'] ?? false)) {
                throw ValidationException::withMessages([
                    'course_id' => ['لا يمكن إرسال الطالب إلى كورس غير جاهز للنشر'],
                ]);
            }
            if ($audience !== 'enrolled' && !$course->is_catalog_visible) {
                throw ValidationException::withMessages([
                    'course_id' => ['هذا الكورس مخفي من الكتالوج ولا يصلح لإشعار ترويجي'],
                ]);
            }
        }
        $imageUrl = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('student-notifications', 'public');
            if (!is_string($imagePath) || trim($imagePath) === '') {
                throw ValidationException::withMessages(['image' => ['تعذّر حفظ الصورة']]);
            }
            $imageUrl = Storage::disk('public')->url($imagePath);
        }
        $notificationType = !$courseId
            ? ($request->input('notification_kind') === 'service'
                ? 'service_notice'
                : 'admin_broadcast')
            : ($audience === 'enrolled' ? 'continue_course' : 'course_promotion');
        NotificationService::notifyGeneric($notificationType, [], [
            'title_ar'   => $titleAr,
            'title_en'   => $titleEn,
            'message_ar' => $messageAr,
            'message_en' => $messageEn,
            'link' => $courseId
                ? RoknAppLink::course($courseId, $audience === 'enrolled')
                : null,
            'notifiable_type' => $courseId ? Course::class : null,
            'notifiable_id' => $courseId,
            'course_id' => $courseId,
            'audience' => $audience,
            'delivery_key' => 'admin-broadcast:' . Str::uuid(),
            'image_url' => $imageUrl,
            'action_label_ar' => $courseId
                ? ($audience === 'enrolled' ? 'أكمل من مكانك' : 'تفاصيل الكورس')
                : 'افتح ركن',
            'action_label_en' => $courseId
                ? ($audience === 'enrolled' ? 'Continue learning' : 'View course')
                : 'Open Rokn',
        ]);

        return redirect()->route('admin.notifications.index')->with('success', 'تمت إضافة الإشعار إلى قائمة الإرسال');
    }
}
