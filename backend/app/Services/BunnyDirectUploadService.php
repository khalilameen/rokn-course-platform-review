<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BunnyVideoCleanupCandidate;
use App\Models\BunnyDirectUpload;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final readonly class BunnyDirectUploadService
{
    public const MAX_BYTES = 5 * 1024 * 1024 * 1024;
    public const MIMES = [
        'video/mp4',
        'video/quicktime',
        'video/x-msvideo',
        'video/webm',
    ];

    public function __construct(private BunnyService $bunny)
    {
    }

    /** @return array<string, mixed> */
    public function issue(
        Course $course,
        User $admin,
        string $title,
        int $size,
        string $mime,
        string $originalName,
        string $idempotencyKey,
        ?CourseSection $section = null
    ): array {
        $this->assertAuthoringContext($course, $admin, $section);
        $title = trim($title);
        $mime = strtolower(trim($mime));
        $originalName = basename(str_replace('\\', '/', trim($originalName)));
        if ($title === '' || mb_strlen($title) > 255) {
            throw ValidationException::withMessages(['title' => 'أضف عنوان المقطع أولًا']);
        }
        if ($size < 1 || $size > self::MAX_BYTES) {
            throw ValidationException::withMessages(['size' => 'حجم الفيديو يجب ألا يتجاوز 5GB']);
        }
        if (!in_array($mime, self::MIMES, true)) {
            throw ValidationException::withMessages(['mime' => 'صيغة الفيديو غير مدعومة']);
        }
        $expectedExtension = match ($mime) {
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/x-msvideo' => 'avi',
            'video/webm' => 'webm',
        };
        if (strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION)) !== $expectedExtension) {
            throw ValidationException::withMessages([
                'original_name' => 'صيغة الملف لا تطابق محتواه المعلن',
            ]);
        }
        if (preg_match('/^[a-f0-9-]{36}$/i', $idempotencyKey) !== 1) {
            throw ValidationException::withMessages(['idempotency_key' => 'أعد اختيار ملف الفيديو']);
        }

        $requestHash = hash('sha256', json_encode([
            'course' => (int) $course->id,
            'section' => $section ? (int) $section->id : null,
            'title' => $title,
            'size' => $size,
            'mime' => $mime,
            'original_name' => $originalName,
        ], JSON_THROW_ON_ERROR));
        $legacyRequestHash = hash('sha256', json_encode([
            'course' => (int) $course->id,
            'section' => $section ? (int) $section->id : null,
            'title' => $title,
            'size' => $size,
            'mime' => $mime,
        ], JSON_THROW_ON_ERROR));
        $lockName = sprintf('bunny-direct-upload:%d:%d:%s', $admin->id, $course->id, $idempotencyKey);

        return Cache::lock($lockName, 30)->block(10, function () use (
            $course,
            $admin,
            $section,
            $title,
            $size,
            $mime,
            $idempotencyKey,
            $requestHash,
            $legacyRequestHash
        ): array {
            $session = BunnyDirectUpload::query()
                ->where('user_id', $admin->id)
                ->where('course_id', $course->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($session) {
                if (!hash_equals((string) $session->request_hash, $requestHash)
                    && !hash_equals((string) $session->request_hash, $legacyRequestHash)) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => 'تغيرت بيانات الملف
اختره مرة أخرى',
                    ]);
                }
                if ($session->status === 'pending'
                    && $session->expires_at->isFuture()
                    && $this->validGuid((string) $session->video_guid)) {
                    return $this->payload($session, $course, $admin, $title, $size, $mime, $section);
                }
                if ($session->expires_at->isPast()) {
                    $session->forceFill(['status' => 'failed'])->save();
                    throw ValidationException::withMessages([
                        'idempotency_key' => 'انتهت عملية الرفع
اختر الملف مرة أخرى',
                    ]);
                }

                throw ValidationException::withMessages([
                    'idempotency_key' => $session->status === 'allocating'
                        ? 'ما زال تجهيز الرفع جاريًا
حاول بعد لحظات'
                        : 'انتهت عملية الرفع
اختر الملف مرة أخرى',
                ]);
            }

            $expiresAt = now()->addHours(max(2, (int) config('bunny.direct_upload_claim_ttl_hours', 24)));
            $session = BunnyDirectUpload::query()->create([
                'user_id' => $admin->id,
                'course_id' => $course->id,
                'section_id' => $section?->id,
                'idempotency_key' => strtolower($idempotencyKey),
                'request_hash' => $requestHash,
                'status' => 'allocating',
                'expires_at' => $expiresAt,
            ]);

            $videoId = null;
            try {
                $remoteTitle = mb_substr($title, 0, 205) . ' [rokn:' . strtolower($idempotencyKey) . ']';
                $video = $this->bunny->createVideo($remoteTitle);
                $videoId = strtolower(trim((string) ($video['guid'] ?? '')));
                if (!$this->validGuid($videoId)) {
                    throw new RuntimeException('تعذر تجهيز مساحة رفع الفيديو');
                }
                $candidate = $this->bunny->queueVideoCleanup(
                    $videoId,
                    $section?->sectionable instanceof Lesson ? $section->sectionable : null,
                    'direct_upload_pending',
                    24
                );
                if (!$candidate) {
                    $this->bunny->deleteVideo($videoId);
                    throw new RuntimeException('تعذر تسجيل عملية الرفع بأمان');
                }
                // An unattached direct upload is always safe for automatic
                // retirement: the cleanup worker rechecks live lesson pointers.
                $candidate->forceFill([
                    'requires_review' => false,
                    'reviewed_at' => now(),
                    'reviewed_by' => $admin->id,
                ])->save();
                $session->forceFill(['video_guid' => $videoId, 'status' => 'pending'])->save();

                return $this->payload($session, $course, $admin, $title, $size, $mime, $section);
            } catch (\Throwable $exception) {
                $session->forceFill(['status' => 'failed'])->save();
                if ($videoId && $this->validGuid($videoId)) {
                    $failedCandidate = $this->bunny->queueVideoCleanup(
                        $videoId,
                        null,
                        'direct_upload_allocation_failed',
                        1
                    );
                    $failedCandidate?->forceFill([
                        'requires_review' => false,
                        'reviewed_at' => now(),
                        'reviewed_by' => $admin->id,
                    ])->save();
                }
                throw $exception;
            }
        });
    }

    /** @return array<string, mixed> */
    public function authorization(Course $course, User $admin, string $claim): array
    {
        $payload = $this->claim($course, $admin, $claim);
        $this->assertPendingSession($payload);
        if (!BunnyVideoCleanupCandidate::query()
            ->where('video_guid', $payload['video_id'])
            ->whereNull('remote_deleted_at')
            ->whereNull('last_attempt_at')
            ->exists()) {
            throw ValidationException::withMessages([
                'claim' => 'انتهت عملية الرفع أو تم استخدامها من قبل',
            ]);
        }

        return array_merge([
            'video_id' => $payload['video_id'],
        ], $this->bunny->directUploadAuthorization((string) $payload['video_id']));
    }

    /** @return array<string, mixed> */
    public function verifyForAttach(
        Course $course,
        User $admin,
        string $claim,
        ?CourseSection $section
    ): array {
        $payload = $this->claim($course, $admin, $claim);
        $this->assertPendingSession($payload);
        $claimedSectionId = $payload['section_id'] ?? null;
        $actualSectionId = $section?->id;
        if (($claimedSectionId === null) !== ($actualSectionId === null)
            || ($actualSectionId !== null && (int) $claimedSectionId !== (int) $actualSectionId)) {
            throw ValidationException::withMessages([
                'bunny_video_claim' => 'هذا الرفع لا يخص المقطع الحالي',
            ]);
        }
        if (!BunnyVideoCleanupCandidate::query()
            ->where('video_guid', $payload['video_id'])
            ->whereNull('remote_deleted_at')
            ->whereNull('last_attempt_at')
            ->exists()) {
            throw ValidationException::withMessages([
                'bunny_video_claim' => 'تم استخدام هذا الرفع من قبل أو انتهت صلاحيته',
            ]);
        }
        if (!$this->bunny->verifyDirectUpload((string) $payload['video_id'], (int) $payload['size'])) {
            throw ValidationException::withMessages([
                'bunny_video_claim' => 'لم يكتمل رفع الفيديو بعد\nحاول الحفظ مرة أخرى بعد لحظات',
            ]);
        }

        return $payload;
    }

    /** Consume inside the same transaction that attaches the lesson pointer. */
    public function consume(string $videoId): void
    {
        $candidate = BunnyVideoCleanupCandidate::query()
            ->where('video_guid', $videoId)
            ->whereNull('remote_deleted_at')
            // Once cleanup has claimed the remote GUID, attaching it would
            // race a deletion already in flight. The moderator must allocate
            // a new upload instead of publishing a video that may disappear.
            ->whereNull('last_attempt_at')
            ->lockForUpdate()
            ->first();
        if (!$candidate) {
            throw ValidationException::withMessages([
                'bunny_video_claim' => 'تم استخدام هذا الرفع من قبل',
            ]);
        }
        BunnyDirectUpload::query()
            ->where('video_guid', $videoId)
            ->where('status', 'pending')
            ->lockForUpdate()
            ->update(['status' => 'attached', 'attached_at' => now(), 'updated_at' => now()]);
        $candidate->delete();
    }

    /** @return array<string, mixed> */
    private function claim(Course $course, User $admin, string $claim): array
    {
        $this->assertAuthoringContext($course, $admin, null);
        try {
            $payload = json_decode(Crypt::decryptString($claim), true, 16, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw ValidationException::withMessages(['bunny_video_claim' => 'بيانات الرفع غير صالحة']);
        }
        if (!is_array($payload)
            || !in_array((int) ($payload['v'] ?? 0), [1, 2], true)
            || (int) ($payload['course_id'] ?? 0) !== (int) $course->id
            || (int) ($payload['admin_id'] ?? 0) !== (int) $admin->id
            || (int) ($payload['expires_at'] ?? 0) < time()
            || !$this->validGuid((string) ($payload['video_id'] ?? ''))
            || (int) ($payload['size'] ?? 0) < 1
            || (int) ($payload['size'] ?? 0) > self::MAX_BYTES
            || !in_array((string) ($payload['mime'] ?? ''), self::MIMES, true)
            || trim((string) ($payload['title'] ?? '')) === '') {
            throw ValidationException::withMessages(['bunny_video_claim' => 'انتهت صلاحية الرفع أو لا يخص هذا الكورس']);
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function payload(
        BunnyDirectUpload $session,
        Course $course,
        User $admin,
        string $title,
        int $size,
        string $mime,
        ?CourseSection $section
    ): array {
        $claim = Crypt::encryptString((string) json_encode([
            'v' => 2,
            'upload_id' => (int) $session->id,
            'video_id' => (string) $session->video_guid,
            'course_id' => (int) $course->id,
            'section_id' => $section ? (int) $section->id : null,
            'admin_id' => (int) $admin->id,
            'size' => $size,
            'mime' => $mime,
            'title' => $title,
            'expires_at' => $session->expires_at->getTimestamp(),
        ], JSON_THROW_ON_ERROR));

        return array_merge([
            'upload_endpoint' => 'https://video.bunnycdn.com/tusupload',
            'video_id' => (string) $session->video_guid,
            'claim' => $claim,
            'claim_expires_at' => $session->expires_at->toIso8601String(),
        ], $this->bunny->directUploadAuthorization((string) $session->video_guid));
    }

    /** @param array<string, mixed> $payload */
    private function assertPendingSession(array $payload): void
    {
        if ((int) ($payload['v'] ?? 1) === 1) {
            return;
        }
        $session = BunnyDirectUpload::query()->find((int) ($payload['upload_id'] ?? 0));
        if (!$session
            || $session->status !== 'pending'
            || $session->expires_at->isPast()
            || !hash_equals((string) $session->video_guid, (string) $payload['video_id'])) {
            throw ValidationException::withMessages([
                'bunny_video_claim' => 'تم استخدام هذا الرفع من قبل أو انتهت صلاحيته',
            ]);
        }
    }

    private function assertAuthoringContext(Course $course, User $admin, ?CourseSection $section): void
    {
        if (!in_array(strtolower((string) $admin->role), ['admin', 'moderator'], true)) {
            abort(403);
        }
        if (!$course->is_coming_soon) {
            throw ValidationException::withMessages([
                'course' => 'حوّل الكورس إلى مسودة قبل استبدال الفيديو',
            ]);
        }
        if ($section && (int) $section->course_id !== (int) $course->id) {
            abort(404);
        }
    }

    private function validGuid(string $value): bool
    {
        return preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/i', $value) === 1;
    }
}
