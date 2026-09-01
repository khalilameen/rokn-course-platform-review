<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FeedbackAttachment;
use App\Models\FeedbackReport;
use App\Models\SupportCaseEvent;
use App\Models\SupportCaseMessage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;

final class SupportCaseService
{
    public const CUSTOMER_STATUSES = ['new', 'reviewing', 'waiting_for_user', 'resolved', 'closed', 'dismissed'];

    public function createGuestCredential(string $clientRequestId): array
    {
        $secret = (string) config('app.key');
        abort_if($secret === '', 503, 'تعذّر فتح المتابعة الآن');
        $bytes = hash_hmac('sha256', 'support-case|'.$clientRequestId, $secret, true);
        $token = rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        return ['token' => $token, 'hash' => hash('sha256', $token)];
    }

    public function authorizeViewer(FeedbackReport $report, ?User $user, ?string $accessToken): void
    {
        if ($user && (int) $report->user_id === (int) $user->id) {
            return;
        }
        $digest = trim((string) $report->guest_access_hash);
        $candidate = trim((string) $accessToken);
        if ($digest !== '' && $candidate !== '' && hash_equals($digest, hash('sha256', $candidate))) {
            return;
        }
        abort(404);
    }

    public function accessTokenFromRequest(\Illuminate\Http\Request $request): ?string
    {
        $token = trim((string) $request->header('X-Support-Access'));
        return $token !== '' && strlen($token) <= 128 ? $token : null;
    }

    public function appendLearnerMessage(
        FeedbackReport $report,
        ?User $user,
        string $body,
        string $clientRequestId,
        ?UploadedFile $screenshot = null
    ): SupportCaseMessage {
        $body = trim($body);
        $fingerprint = hash('sha256', json_encode([
            'body' => $body,
            'attachment' => $screenshot ? $this->uploadFingerprint($screenshot) : null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $existing = SupportCaseMessage::query()
            ->where('feedback_report_id', $report->id)
            ->where('client_request_id', $clientRequestId)
            ->first();
        if ($existing) {
            abort_unless(hash_equals((string) $existing->request_fingerprint, $fingerprint), 409);
            return $existing;
        }

        $storedPath = null;
        try {
            return DB::transaction(function () use (
                $report,
                $user,
                $body,
                $clientRequestId,
                $fingerprint,
                $screenshot,
                &$storedPath
            ): SupportCaseMessage {
                $locked = FeedbackReport::query()->lockForUpdate()->findOrFail($report->id);
                $existing = SupportCaseMessage::query()
                    ->where('feedback_report_id', $locked->id)
                    ->where('client_request_id', $clientRequestId)
                    ->first();
                if ($existing) {
                    abort_unless(hash_equals((string) $existing->request_fingerprint, $fingerprint), 409);
                    return $existing;
                }

                $fromStatus = (string) $locked->status;
                $reopened = in_array($fromStatus, ['resolved', 'closed', 'dismissed'], true);
                $message = SupportCaseMessage::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'feedback_report_id' => $locked->id,
                    'author_id' => $user?->id,
                    'author_type' => SupportCaseMessage::AUTHOR_LEARNER,
                    'visibility' => SupportCaseMessage::VISIBILITY_CUSTOMER,
                    'body' => $body,
                    'client_request_id' => $clientRequestId,
                    'request_fingerprint' => $fingerprint,
                ]);

                if ($screenshot) {
                    $attachment = $this->storeSanitizedImage($locked, $message, $screenshot);
                    $storedPath = $attachment->path;
                }

                $updates = [
                    'last_user_message_at' => now(),
                    'version' => (int) $locked->version + 1,
                    'updated_at' => now(),
                ];
                if ($reopened) {
                    $updates += [
                        'status' => 'reviewing',
                        'resolved_at' => null,
                        'closed_at' => null,
                        'reopened_at' => now(),
                    ];
                }
                $locked->update($updates);
                $this->event($locked, $user?->id, $reopened ? 'reopened' : 'learner_replied', $fromStatus, $updates['status'] ?? $fromStatus);

                return $message;
            }, 3);
        } catch (\Throwable $exception) {
            if ($storedPath) {
                app(StoredFileDeletionService::class)->deleteOrQueue('feedback', $storedPath);
            }
            throw $exception;
        }
    }

    public function appendStaffMessage(
        FeedbackReport $report,
        User $staff,
        string $body,
        string $visibility,
        string $clientRequestId,
        int $expectedVersion
    ): SupportCaseMessage {
        $body = trim($body);
        $visibility = $visibility === SupportCaseMessage::VISIBILITY_INTERNAL
            ? SupportCaseMessage::VISIBILITY_INTERNAL
            : SupportCaseMessage::VISIBILITY_CUSTOMER;
        $fingerprint = hash('sha256', $visibility.'|'.$body);

        $message = DB::transaction(function () use (
            $report,
            $staff,
            $body,
            $visibility,
            $clientRequestId,
            $expectedVersion,
            $fingerprint
        ): SupportCaseMessage {
            $locked = FeedbackReport::query()->lockForUpdate()->findOrFail($report->id);
            $existing = SupportCaseMessage::query()
                ->where('feedback_report_id', $locked->id)
                ->where('client_request_id', $clientRequestId)
                ->first();
            if ($existing) {
                abort_unless(hash_equals((string) $existing->request_fingerprint, $fingerprint), 409);
                return $existing;
            }
            abort_if((int) $locked->version !== $expectedVersion, 409, 'عدّل شخص آخر هذه الحالة\nحدّث الصفحة ثم أعد المحاولة');

            $message = SupportCaseMessage::query()->create([
                'public_id' => (string) Str::ulid(),
                'feedback_report_id' => $locked->id,
                'author_id' => $staff->id,
                'author_type' => SupportCaseMessage::AUTHOR_STAFF,
                'visibility' => $visibility,
                'body' => $body,
                'client_request_id' => $clientRequestId,
                'request_fingerprint' => $fingerprint,
            ]);
            $updates = ['version' => (int) $locked->version + 1, 'updated_at' => now()];
            if ($visibility === SupportCaseMessage::VISIBILITY_CUSTOMER) {
                $updates['last_staff_message_at'] = now();
                if ($locked->status === 'new') $updates['status'] = 'reviewing';
            }
            $locked->update($updates);
            $this->event($locked, $staff->id, $visibility === 'internal' ? 'internal_note' : 'staff_replied');

            return $message;
        }, 3);

        if ($visibility === SupportCaseMessage::VISIBILITY_CUSTOMER && $report->user_id) {
            $this->notifyCustomer($report->fresh(), $message);
        }
        return $message;
    }

    public function customerPayload(FeedbackReport $report): array
    {
        $report->loadMissing(['course:id,name_ar,name_en', 'messages' => fn ($query) => $query
            ->where('visibility', SupportCaseMessage::VISIBILITY_CUSTOMER)
            ->withCount('attachments')
            ->orderBy('id')]);

        return [
            'public_id' => $report->public_id,
            'case_number' => strtoupper(substr((string) $report->public_id, -8)),
            'category' => $report->category,
            'status' => $this->customerStatus((string) $report->status),
            'message' => $report->message,
            'course' => $report->course ? ['id' => (int) $report->course->id, 'title' => $report->course->title] : null,
            'created_at' => $report->created_at?->toIso8601String(),
            'updated_at' => $report->updated_at?->toIso8601String(),
            'messages' => $report->messages->map(fn (SupportCaseMessage $message): array => [
                'public_id' => $message->public_id,
                'author' => $message->author_type === SupportCaseMessage::AUTHOR_LEARNER ? 'learner' : 'support',
                'text' => $message->body,
                'has_attachment' => (int) $message->attachments_count > 0,
                'created_at' => $message->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    public function firstResponseDueAt(string $priority = 'normal'): \Carbon\CarbonInterface
    {
        $hours = ['urgent' => 2, 'high' => 8, 'normal' => 24, 'low' => 72][$priority] ?? 24;
        return now()->addHours($hours);
    }

    public function notifyStatus(FeedbackReport $report, string $status, string $deliveryKey): void
    {
        $user = $report->user;
        if (!$user) return;
        [$titleAr, $messageAr] = match ($status) {
            'resolved' => ['تم حل البلاغ', 'راجع رد فريق الدعم على البلاغ '.strtoupper(substr((string) $report->public_id, -8))],
            'waiting_for_user' => ['ينتظر الدعم ردك', 'أرسل التفاصيل المطلوبة في البلاغ '.strtoupper(substr((string) $report->public_id, -8))],
            default => ['تحديث على بلاغك', 'راجع آخر تحديث على البلاغ '.strtoupper(substr((string) $report->public_id, -8))],
        };
        StudentNotificationService::notifyUser(
            $user,
            StudentNotificationService::TYPE_SUPPORT_CASE_UPDATE,
            $titleAr,
            'Support case updated',
            $messageAr,
            'Your support case was updated',
            'rokn://support/'.$report->public_id,
            FeedbackReport::class,
            (int) $report->id,
            $deliveryKey
        );
    }

    public function event(
        FeedbackReport $report,
        ?int $actorId,
        string $type,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        array $metadata = []
    ): SupportCaseEvent {
        return SupportCaseEvent::query()->create([
            'feedback_report_id' => $report->id,
            'actor_id' => $actorId,
            'event_type' => $type,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'metadata' => $this->safeEventMetadata($metadata),
        ]);
    }

    private function storeSanitizedImage(
        FeedbackReport $report,
        SupportCaseMessage $message,
        UploadedFile $upload
    ): FeedbackAttachment {
        try {
            $image = Image::make($upload->getRealPath());
        } catch (\Throwable) {
            abort(422, 'تعذّرت قراءة الصورة\nاختر صورة أخرى');
        }
        if (function_exists('exif_read_data')) $image->orientate();
        $image->resize(2048, 2048, static function ($constraint): void {
            $constraint->aspectRatio();
            $constraint->upsize();
        });
        $encoded = (string) $image->encode('jpg', 86);
        abort_if($encoded === '', 422, 'تعذّرت قراءة الصورة\nاختر صورة أخرى');
        $path = now()->format('Y/m').'/'.$report->public_id.'/'.$message->public_id.'.jpg';
        abort_unless(Storage::disk('feedback')->put($path, $encoded), 503, 'تعذّر حفظ الصورة الآن');

        return $report->attachments()->create([
            'support_case_message_id' => $message->id,
            'disk' => 'feedback',
            'path' => $path,
            'mime_type' => 'image/jpeg',
            'size_bytes' => strlen($encoded),
            'width' => $image->width(),
            'height' => $image->height(),
            'sha256' => hash('sha256', $encoded),
            'scan_status' => 'sanitized',
        ]);
    }

    private function uploadFingerprint(UploadedFile $file): array
    {
        $hash = hash_file('sha256', $file->getRealPath());
        abort_unless($hash && $file->getSize() > 0, 422, 'تعذّرت قراءة الصورة\nاختر صورة أخرى');
        return ['sha256' => $hash, 'size' => (int) $file->getSize()];
    }

    private function notifyCustomer(FeedbackReport $report, SupportCaseMessage $message): void
    {
        $user = $report->user;
        if (!$user) return;
        StudentNotificationService::notifyUser(
            $user,
            StudentNotificationService::TYPE_SUPPORT_CASE_UPDATE,
            'رد فريق الدعم',
            'Support replied',
            'لديك رد جديد على البلاغ '.strtoupper(substr((string) $report->public_id, -8)),
            'You have a new support reply',
            'rokn://support/'.$report->public_id,
            FeedbackReport::class,
            (int) $report->id,
            'support-case:'.$report->id.':message:'.$message->id
        );
    }

    private function customerStatus(string $status): string
    {
        return match ($status) {
            'new' => 'received',
            'reviewing' => 'in_progress',
            'waiting_for_user' => 'waiting_for_you',
            'resolved' => 'resolved',
            'closed', 'dismissed' => 'closed',
            default => 'in_progress',
        };
    }

    private function safeEventMetadata(array $metadata): array
    {
        return array_intersect_key($metadata, array_flip([
            'assigned_to', 'priority', 'resolution_kind', 'order_id', 'compensation_event_key',
        ]));
    }
}
