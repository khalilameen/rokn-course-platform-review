<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BunnyVideoCleanupCandidate;
use App\Models\PortfolioItem;
use App\Models\PortfolioMedia;
use App\Models\PortfolioVideoUpload;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final readonly class PortfolioVideoUploadService
{
    public const MAX_BYTES = 50 * 1024 * 1024;
    public const MIMES = ['video/mp4', 'video/quicktime', 'video/webm'];
    private const MAX_MEDIA_PER_ITEM = 12;

    public function __construct(private BunnyService $bunny) {}

    /** @return array<string,mixed> */
    public function issue(
        User $user,
        PortfolioItem $item,
        string $idempotencyKey,
        int $size,
        string $mime,
        string $originalName,
        string $sha256
    ): array {
        $mime = strtolower(trim($mime));
        $originalName = basename(str_replace('\\', '/', trim($originalName)));
        if ($size < 1 || $size > self::MAX_BYTES || !in_array($mime, self::MIMES, true)) {
            throw ValidationException::withMessages(['file' => 'ملف الفيديو غير صالح']);
        }
        $expectedExtension = match ($mime) {
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
        };
        if (strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION)) !== $expectedExtension) {
            throw ValidationException::withMessages(['file' => 'صيغة الفيديو لا تطابق الملف']);
        }
        if (!Str::isUuid($idempotencyKey) || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            throw ValidationException::withMessages(['file' => 'أعد اختيار ملف الفيديو']);
        }
        $hash = hash('sha256', json_encode([
            'item' => (int) $item->id,
            'size' => $size,
            'mime' => $mime,
            'name' => $originalName,
            'sha256' => $sha256,
        ], JSON_THROW_ON_ERROR));

        return Cache::lock('portfolio-video-upload:' . $user->id . ':' . $idempotencyKey, 180)
            ->block(10, function () use (
                $user, $item, $idempotencyKey, $size, $mime, $originalName, $sha256, $hash
            ): array {
                $session = PortfolioVideoUpload::query()
                    ->where('user_id', $user->id)
                    ->where('portfolio_item_id', $item->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($session) {
                    abort_unless(hash_equals((string) $session->request_hash, $hash), 409);
                    if ($session->status === 'pending' && $session->expires_at->isFuture()) {
                        $session = DB::transaction(function () use ($user, $item, $session): PortfolioVideoUpload {
                            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                            $lockedItem = PortfolioItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
                            abort_unless((int) $lockedItem->user_id === (int) $user->id, 404);
                            abort_if($lockedItem->deletion_started_at, 409);
                            $lockedSession = PortfolioVideoUpload::query()->whereKey($session->id)
                                ->lockForUpdate()->firstOrFail();
                            abort_unless(
                                $lockedSession->status === 'pending'
                                && $lockedSession->expires_at->isFuture(),
                                410
                            );
                            return $lockedSession;
                        }, 3);
                        return $this->payload($session);
                    }
                    if ($session->status === 'attached') {
                        return $this->payload($session);
                    }
                    if ($session->status === 'allocating' && $session->updated_at?->gt(now()->subMinutes(2))) {
                        throw ValidationException::withMessages(['file' => 'جارٍ تجهيز الرفع']);
                    }
                    $this->retireAllocation($session, (int) $user->id);
                }

                $lease = (string) Str::uuid();
                $expiresAt = now()->addHours(24);
                $session = DB::transaction(function () use (
                    $user, $item, $idempotencyKey, $hash, $sha256, $size, $mime,
                    $originalName, $lease, $expiresAt, $session
                ): PortfolioVideoUpload {
                    User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                    $lockedItem = PortfolioItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
                    abort_unless((int) $lockedItem->user_id === (int) $user->id, 404);
                    abort_if($lockedItem->deletion_started_at, 409);
                    $mediaCount = $lockedItem->mediaFiles()->count();
                    $capacity = $this->mediaCapacity($lockedItem);
                    abort_if($mediaCount >= $capacity, 422);
                    if ($lockedItem->mediaFiles()->where('content_sha256', $sha256)->exists()) {
                        throw ValidationException::withMessages(['file' => 'هذا الملف مضاف بالفعل']);
                    }

                    $reserved = PortfolioVideoUpload::query()
                        ->where('portfolio_item_id', $lockedItem->id)
                        ->when($session, fn ($query) => $query->where('id', '!=', $session->id))
                        ->where('expires_at', '>', now())
                        ->whereIn('status', ['allocating', 'pending'])
                        ->count();
                    abort_if($mediaCount + $reserved >= $capacity, 422);

                    $duplicateReservation = PortfolioVideoUpload::query()
                        ->where('portfolio_item_id', $lockedItem->id)
                        ->when($session, fn ($query) => $query->where('id', '!=', $session->id))
                        ->where('content_sha256', $sha256)
                        ->where('expires_at', '>', now())
                        ->whereIn('status', ['allocating', 'pending'])
                        ->exists();
                    if ($duplicateReservation) {
                        throw ValidationException::withMessages(['file' => 'هذا الملف قيد الرفع بالفعل']);
                    }
                    $values = [
                        'user_id' => $user->id,
                        'portfolio_item_id' => $item->id,
                        'idempotency_key' => strtolower($idempotencyKey),
                        'request_hash' => $hash,
                        'content_sha256' => $sha256,
                        'size_bytes' => $size,
                        'mime_type' => $mime,
                        'original_name' => $originalName,
                        'video_guid' => null,
                        'allocation_token' => $lease,
                        'status' => 'allocating',
                        'expires_at' => $expiresAt,
                        'attached_at' => null,
                    ];
                    if ($session) {
                        $session->forceFill($values)->save();
                        return $session;
                    }
                    return PortfolioVideoUpload::query()->create($values);
                }, 3);

                $videoGuid = null;
                try {
                    $marker = '[rokn-portfolio:' . strtolower($idempotencyKey) . ']';
                    $remote = $this->bunny->createVideo(mb_substr((string) $item->title, 0, 190) . ' ' . $marker);
                    $videoGuid = strtolower(trim((string) ($remote['guid'] ?? '')));
                    if (!$this->validGuid($videoGuid)) {
                        throw new RuntimeException('Bunny allocation failed.');
                    }
                    $advanced = PortfolioVideoUpload::query()
                        ->whereKey($session->id)
                        ->where('status', 'allocating')
                        ->where('allocation_token', $lease)
                        ->update(['video_guid' => $videoGuid, 'updated_at' => now()]);
                    if ($advanced !== 1) {
                        throw new RuntimeException('Upload allocation was superseded.');
                    }
                    $candidate = $this->bunny->queueVideoCleanup(
                        $videoGuid, null, 'portfolio_direct_upload_pending', 24, false
                    );
                    if (!$candidate) {
                        throw new RuntimeException('Cleanup allocation failed.');
                    }
                    $candidate->forceFill(['requires_review' => false, 'reviewed_at' => now()])->save();
                    PortfolioVideoUpload::query()
                        ->whereKey($session->id)
                        ->where('allocation_token', $lease)
                        ->update([
                            'status' => 'pending',
                            'allocation_token' => null,
                            'updated_at' => now(),
                        ]);
                    return $this->payload($session->fresh());
                } catch (Throwable $exception) {
                    PortfolioVideoUpload::query()->whereKey($session->id)
                        ->where('allocation_token', $lease)
                        ->update(['status' => 'failed', 'allocation_token' => null]);
                    if ($videoGuid && $this->validGuid($videoGuid)) {
                        $this->queueAbandoned($videoGuid, (int) $user->id, 'portfolio_direct_upload_failed');
                    }
                    throw $exception;
                }
            });
    }

    /** @return array<string,mixed> */
    public function renew(User $user, int $itemId, string $claim): array
    {
        $session = $this->sessionFromClaim($user, $itemId, $claim, false);
        if ($session->status === 'attached') {
            return [
                'video_id' => (string) $session->video_guid,
                'claim_expires_at' => $session->expires_at->toIso8601String(),
                'attached' => true,
            ];
        }

        $session = DB::transaction(function () use ($user, $itemId, $session): PortfolioVideoUpload {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $item = PortfolioItem::query()->whereKey($itemId)->lockForUpdate()->firstOrFail();
            abort_unless((int) $item->user_id === (int) $user->id, 404);
            abort_if($item->deletion_started_at, 409);

            $locked = PortfolioVideoUpload::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'pending' && $locked->expires_at->isFuture(), 410);
            $candidate = BunnyVideoCleanupCandidate::query()
                ->where('video_guid', $locked->video_guid)
                ->whereNull('remote_deleted_at')
                ->whereNull('last_attempt_at')
                ->lockForUpdate()
                ->first();
            abort_unless($candidate, 410);

            $expiresAt = now()->addHours(24);
            $locked->forceFill(['expires_at' => $expiresAt])->save();
            $candidate->forceFill(['eligible_after' => $expiresAt])->save();

            return $locked->fresh();
        }, 3);

        return $this->payload($session);
    }

    public function attach(User $user, int $itemId, string $claim, ?string $caption): PortfolioMedia
    {
        $session = $this->sessionFromClaim($user, $itemId, $claim, false);
        $existing = PortfolioMedia::query()
            ->where('portfolio_item_id', $session->portfolio_item_id)
            ->where('client_request_id', $session->idempotency_key)
            ->first();
        if ($session->status === 'attached' && $existing) {
            return $existing;
        }
        abort_unless($session->status === 'pending', 410);
        if (!$this->bunny->verifyDirectUpload((string) $session->video_guid, (int) $session->size_bytes)) {
            throw ValidationException::withMessages(['claim' => 'لم يكتمل رفع الفيديو بعد']);
        }

        return DB::transaction(function () use ($user, $session, $caption): PortfolioMedia {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $item = PortfolioItem::query()->whereKey($session->portfolio_item_id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $item->user_id === (int) $user->id, 404);
            abort_if($item->deletion_started_at, 409);
            $locked = PortfolioVideoUpload::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $existing = $item->mediaFiles()->where('client_request_id', $locked->idempotency_key)->first();
            if ($existing) {
                $locked->forceFill(['status' => 'attached', 'attached_at' => now()])->save();
                return $existing;
            }
            abort_unless($locked->status === 'pending' && $locked->expires_at->isFuture(), 410);
            abort_if($item->mediaFiles()->count() >= $this->mediaCapacity($item), 422);
            if ($item->mediaFiles()->where('content_sha256', $locked->content_sha256)->exists()) {
                throw ValidationException::withMessages(['file' => 'هذا الملف مضاف بالفعل']);
            }
            $candidate = BunnyVideoCleanupCandidate::query()
                ->where('video_guid', $locked->video_guid)
                ->whereNull('remote_deleted_at')
                ->whereNull('last_attempt_at')
                ->lockForUpdate()
                ->first();
            abort_unless($candidate, 410);
            $media = $item->mediaFiles()->create([
                'client_request_id' => $locked->idempotency_key,
                'file_path' => $locked->video_guid,
                'file_type' => 'video',
                'content_sha256' => $locked->content_sha256,
                'mime_type' => $locked->mime_type,
                'size_bytes' => $locked->size_bytes,
                'original_name' => $locked->original_name,
                'sort_order' => ((int) $item->mediaFiles()->max('sort_order')) + 1,
                'caption' => $caption,
            ]);
            $count = $item->mediaFiles()->count();
            if (!$item->is_public && $count >= max(1, (int) $item->expected_media_count)) {
                $item->forceFill(['is_public' => true])->save();
            } else {
                $item->touch();
            }
            $locked->forceFill(['status' => 'attached', 'attached_at' => now()])->save();
            $candidate->delete();
            return $media;
        }, 3);
    }

    private function sessionFromClaim(
        User $user,
        int $itemId,
        string $claim,
        bool $pendingOnly
    ): PortfolioVideoUpload
    {
        try {
            $payload = json_decode(Crypt::decryptString($claim), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            abort(410);
        }
        abort_unless(is_array($payload) && (int) ($payload['user_id'] ?? 0) === (int) $user->id, 410);
        $session = PortfolioVideoUpload::query()->find((int) ($payload['upload_id'] ?? 0));
        abort_unless(
            $session
            && (int) $session->portfolio_item_id === $itemId
            && hash_equals((string) $session->video_guid, (string) ($payload['video_id'] ?? '')),
            410
        );
        // An attached session is an immutable idempotency receipt. Keep it
        // replayable after the upload lease expires so a lost attach response
        // cannot turn a successful publish into a false failure on recovery.
        abort_if(
            $session->expires_at->isPast() && ($pendingOnly || $session->status !== 'attached'),
            410
        );
        if ($pendingOnly) abort_unless($session->status === 'pending', 410);
        User::query()->whereKey($user->id)->firstOrFail();
        abort_unless(PortfolioItem::query()->whereKey($session->portfolio_item_id)
            ->where('user_id', $user->id)->whereNull('deletion_started_at')->exists(), 404);
        return $session;
    }

    /** @return array<string,mixed> */
    private function payload(PortfolioVideoUpload $session): array
    {
        $claim = Crypt::encryptString(json_encode([
            'v' => 1,
            'upload_id' => (int) $session->id,
            'video_id' => (string) $session->video_guid,
            'user_id' => (int) $session->user_id,
            'expires_at' => $session->expires_at->getTimestamp(),
        ], JSON_THROW_ON_ERROR));
        $base = [
            'upload_endpoint' => 'https://video.bunnycdn.com/tusupload',
            'video_id' => (string) $session->video_guid,
            'claim' => $claim,
            'claim_expires_at' => $session->expires_at->toIso8601String(),
            'attached' => $session->status === 'attached',
        ];
        return $session->status === 'attached'
            ? $base
            : array_merge($base, $this->bunny->directUploadAuthorization((string) $session->video_guid));
    }

    private function retireAllocation(PortfolioVideoUpload $session, int $userId): void
    {
        if ($this->validGuid((string) $session->video_guid)) {
            $this->queueAbandoned((string) $session->video_guid, $userId, 'portfolio_direct_upload_stale');
        } else {
            $marker = '[rokn-portfolio:' . strtolower((string) $session->idempotency_key) . ']';
            foreach ($this->bunny->findVideoGuidsByTitleMarker($marker) as $guid) {
                $this->queueAbandoned($guid, $userId, 'portfolio_direct_upload_interrupted');
            }
        }
    }

    private function queueAbandoned(string $guid, int $userId, string $reason): void
    {
        $candidate = $this->bunny->queueVideoCleanup($guid, null, $reason, 1, false);
        if (!$candidate) throw new RuntimeException('Cleanup allocation failed.');
        $candidate->forceFill(['requires_review' => false, 'reviewed_at' => now(), 'reviewed_by' => $userId])->save();
    }

    private function validGuid(string $value): bool
    {
        return preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/i', trim($value)) === 1;
    }

    private function mediaCapacity(PortfolioItem $item): int
    {
        $expected = max(0, (int) $item->expected_media_count);
        return !$item->is_public && $expected > 0
            ? min(self::MAX_MEDIA_PER_ITEM, $expected)
            : self::MAX_MEDIA_PER_ITEM;
    }
}
