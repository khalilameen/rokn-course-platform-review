<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Support\PublicDiskUrl;
use App\Http\Controllers\Controller;
use App\Services\NotificationCampaignService;
use App\Services\NotificationService;
use App\Services\CoursePublishingService;
use App\Services\StoredFileDeletionService;
use App\Services\NotificationDeliveryPolicy;
use App\Services\AdminAuthoringCreateIntentService;
use App\Models\Course;
use App\Models\StudentNotification;
use App\Models\NotificationCampaign;
use App\Support\RoknAppLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\View\View;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Carbon;

class NotificationsController extends Controller
{
    public function index(): View
    {
        $campaignOrder = Schema::hasTable('notification_campaigns')
            && Schema::hasColumn('notification_campaigns', 'scheduled_at')
            ? 'COALESCE(scheduled_at, queued_at, created_at) DESC'
            : 'COALESCE(queued_at, created_at) DESC';
        $campaigns = Schema::hasTable('notification_campaigns')
            ? NotificationCampaign::query()
                ->withCount([
                    'notifications as attempted_count' => fn ($query) => $query->whereNotNull('push_attempted_at'),
                    'notifications as provider_accepted_count' => fn ($query) => $query->whereNotNull('push_sent_at'),
                    'notifications as read_count' => fn ($query) => $query->where('is_read', true),
                    'notifications as push_failed_count' => fn ($query) => $query
                        ->whereNotNull('push_failed_at')->whereNull('push_sent_at'),
                    'notifications as push_partial_count' => fn ($query) => $query
                        ->whereNotNull('push_sent_at')->whereNotNull('push_failed_at'),
                ])
                ->orderByRaw($campaignOrder)
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
                ->selectRaw('SUM(CASE WHEN push_sent_at IS NOT NULL THEN 1 ELSE 0 END) as provider_accepted_count')
                ->selectRaw('SUM(CASE WHEN is_read = 1 THEN 1 ELSE 0 END) as read_count')
                ->selectRaw('SUM(CASE WHEN push_failed_at IS NOT NULL AND push_sent_at IS NULL THEN 1 ELSE 0 END) as push_failed_count')
                ->selectRaw('SUM(CASE WHEN push_failed_at IS NOT NULL AND push_sent_at IS NOT NULL THEN 1 ELSE 0 END) as push_partial_count')
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
        ?CoursePublishingService $publishing = null,
        ?AdminAuthoringCreateIntentService $createIntents = null
    ): RedirectResponse
    {
        $publishing ??= app(CoursePublishingService::class);
        $createIntents ??= app(AdminAuthoringCreateIntentService::class);
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
            'authoring_request_id' => 'required|uuid',
            'send_at' => 'nullable|date_format:Y-m-d\\TH:i',
        ]);

        $titleAr = trim((string) ($request->input('title_ar') ?: $request->input('title')));
        $messageAr = trim((string) ($request->input('message_ar') ?: $request->input('message')));
        $titleEn = trim((string) ($request->input('title_en') ?: $titleAr));
        $messageEn = trim((string) ($request->input('message_en') ?: $messageAr));
        $courseId = $request->integer('course_id') ?: null;
        $audience = (string) $request->input('audience', 'not_enrolled');
        $deliveryKey = 'admin-broadcast:' . auth()->id() . ':'
            . strtolower((string) $request->input('authoring_request_id'));
        $scheduledAt = null;
        if ($request->filled('send_at')) {
            $scheduledAt = Carbon::createFromFormat(
                'Y-m-d\\TH:i',
                (string) $request->input('send_at'),
                'Africa/Cairo'
            )->utc();
            if ($scheduledAt->isAfter(now()->addDays(90))) {
                throw ValidationException::withMessages([
                    'send_at' => ['اختر موعدًا خلال ٩٠ يومًا'],
                ]);
            }
        }
        $notificationType = !$courseId
            ? ($request->input('notification_kind') === 'service'
                ? 'service_notice'
                : 'admin_broadcast')
            : ($audience === 'enrolled' ? 'continue_course' : 'course_promotion');
        $link = $courseId
            ? RoknAppLink::course($courseId, $audience === 'enrolled')
            : null;
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

        $existing = Schema::hasTable('notification_campaigns')
            ? NotificationCampaign::query()->where('delivery_key', $deliveryKey)->first()
            : null;
        if ($existing) {
            $existingHasImage = trim((string) $existing->image_url) !== '';
            $replayHasImage = $request->hasFile('image');
            $sameSchedule = true;
            if ($request->filled('send_at')) {
                $expectedSchedule = NotificationDeliveryPolicy::nextAllowedAt(
                    $notificationType,
                    $scheduledAt
                );
                if (!$expectedSchedule->isAfter(now()->addSeconds(30))) {
                    $expectedSchedule = null;
                }
                $sameSchedule = $expectedSchedule === null
                    ? $existing->scheduled_at === null
                    : $existing->scheduled_at?->equalTo($expectedSchedule) === true;
            }
            $samePayload = hash_equals((string) $existing->notification_type, $notificationType)
                && hash_equals((string) $existing->audience, $audience)
                && (int) ($existing->course_id ?: 0) === (int) ($courseId ?: 0)
                && hash_equals((string) $existing->title_ar, $titleAr)
                && hash_equals((string) $existing->title_en, $titleEn)
                && hash_equals((string) $existing->message_ar, $messageAr)
                && hash_equals((string) $existing->message_en, $messageEn)
                && hash_equals((string) ($existing->link ?: ''), (string) ($link ?: ''))
                && $sameSchedule
                && $existingHasImage === $replayHasImage
                && (!$replayHasImage || $this->notificationImageMatches(
                    (string) $existing->image_url,
                    $request->file('image'),
                    'notification-campaign|' . $deliveryKey
                ));
            if (!$samePayload) {
                throw ValidationException::withMessages([
                    'authoring_request_id' => ['تغيّرت بيانات الإشعار\nأعد فتح النموذج ثم أرسل'],
                ]);
            }

            DB::transaction(function () use ($createIntents, $request, $existing): void {
                $createIntents->completeRedirect(
                    $request,
                    route('admin.notifications.index'),
                    302,
                    NotificationCampaign::class,
                    $existing->id
                );
            }, 3);

            return redirect()->route('admin.notifications.index')->with(
                'success',
                $existing->status === NotificationCampaign::STATUS_SCHEDULED
                    ? 'الإشعار محفوظ في موعده'
                    : 'الإشعار محفوظ في قائمة الإرسال'
            );
        }

        $imageUrl = null;
        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $imagePath = app(StoredFileDeletionService::class)->storeTrackedUpload(
                $image,
                'student-notifications',
                'public',
                60,
                'notification-campaign|' . $deliveryKey . '|' . hash_file('sha256', $image->getRealPath())
            );
            if (!is_string($imagePath) || trim($imagePath) === '') {
                throw ValidationException::withMessages(['image' => ['تعذّر حفظ الصورة']]);
            }
            $imageUrl = PublicDiskUrl::from($imagePath);
        }
        $campaign = null;
        DB::transaction(function () use (
            $notificationType,
            $titleAr,
            $titleEn,
            $messageAr,
            $messageEn,
            $link,
            $courseId,
            $audience,
            $deliveryKey,
            $imageUrl,
            $scheduledAt,
            $createIntents,
            $request,
            &$campaign
        ): void {
            $queued = NotificationService::notifyGeneric($notificationType, [], [
                'title_ar'   => $titleAr,
                'title_en'   => $titleEn,
                'message_ar' => $messageAr,
                'message_en' => $messageEn,
                'link' => $link,
                'notifiable_type' => $courseId ? Course::class : null,
                'notifiable_id' => $courseId,
                'course_id' => $courseId,
                'audience' => $audience,
                'delivery_key' => $deliveryKey,
                'image_url' => $imageUrl,
                'action_label_ar' => $courseId
                    ? ($audience === 'enrolled' ? 'أكمل من مكانك' : 'تفاصيل الكورس')
                    : 'افتح ركن',
                'action_label_en' => $courseId
                    ? ($audience === 'enrolled' ? 'Continue learning' : 'View course')
                    : 'Open Rokn',
                'scheduled_at' => $scheduledAt,
            ]);
            if (!$queued && (!Schema::hasTable('notification_campaigns')
                || !NotificationCampaign::query()->where('delivery_key', $deliveryKey)->exists())) {
                throw ValidationException::withMessages([
                    'notification_kind' => ['هذا النوع متوقف حاليًا من إعدادات الإشعارات'],
                ]);
            }
            $campaign = Schema::hasTable('notification_campaigns')
                ? NotificationCampaign::query()->where('delivery_key', $deliveryKey)->first()
                : null;
            $createIntents->completeRedirect(
                $request,
                route('admin.notifications.index'),
                302,
                $campaign ? NotificationCampaign::class : null,
                $campaign?->id
            );
        }, 3);

        return redirect()->route('admin.notifications.index')->with(
            'success',
            $campaign?->status === NotificationCampaign::STATUS_SCHEDULED
                ? 'تم حفظ الإشعار في موعده'
                : 'تمت إضافة الإشعار إلى قائمة الإرسال'
        );
    }

    private function notificationImageMatches(string $url, UploadedFile $image, string $identityPrefix): bool
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        $storedIdentity = pathinfo($path, PATHINFO_FILENAME);
        $contentHash = hash_file('sha256', $image->getRealPath());

        return $storedIdentity !== '' && hash_equals(
            $storedIdentity,
            hash('sha256', $identityPrefix . '|' . $contentHash)
        );
    }

    public function retry(
        NotificationCampaign $notificationCampaign,
        NotificationCampaignService $campaigns
    ): RedirectResponse {
        if (!$campaigns->retry($notificationCampaign)) {
            return redirect()
                ->route('admin.notifications.index')
                ->with('warning', 'هذه الحملة ليست متوقفة الآن');
        }

        return redirect()
            ->route('admin.notifications.index')
            ->with('success', 'أُعيدت الحملة إلى قائمة الإرسال');
    }
}
