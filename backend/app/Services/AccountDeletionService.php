<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\ProjectSubmission;
use App\Jobs\CleanupDeletedAccountPortfolioMedia;
use App\Jobs\DeleteAccountFile;
use App\Models\AccountFileDeletion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class AccountDeletionService
{
    public function __construct(
        private readonly AcquisitionRewardTombstoneService $rewardTombstones
    ) {
    }

    /**
     * Remove account identity while retaining the relational shell required by
     * payment, wallet and enrolment records.
     *
     * @return array{local_cleanup_pending: bool, remote_portfolio_cleanup_pending: bool}
     */
    public function delete(User $user): array
    {
        $publicFiles = [];
        $localFiles = [];
        $storedFiles = [];
        $remotePortfolioCleanupPending = false;
        $cleanupOutboxIds = [];

        DB::transaction(function () use (
            $user,
            &$publicFiles,
            &$localFiles,
            &$storedFiles,
            &$remotePortfolioCleanupPending,
            &$cleanupOutboxIds
        ): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $userId = (int) $locked->id;
            $originalPhone = trim((string) $locked->getRawOriginal('phone'));
            $profileImage = trim((string) $locked->getRawOriginal('profile_image'));

            if ($profileImage !== '' && !filter_var($profileImage, FILTER_VALIDATE_URL)) {
                $publicFiles[] = ltrim($profileImage, '/');
            }

            if (Schema::hasTable('project_submissions')) {
                ProjectSubmission::query()
                    ->where('user_id', $userId)
                    ->whereNotNull('submission_file')
                    ->get(['id', 'submission_file', 'submission_metadata'])
                    ->each(function (ProjectSubmission $submission) use (&$storedFiles): void {
                        foreach ($submission->submissionDiskCandidates() as $disk) {
                            $storedFiles[] = [
                                'disk' => $disk,
                                'path' => (string) $submission->submission_file,
                            ];
                        }
                    });

                DB::table('project_submissions')->where('user_id', $userId)->update([
                    'submission_text' => null,
                    'submission_file' => null,
                    'original_file_name' => null,
                    'mime_type' => null,
                    'file_size' => null,
                    'submission_metadata' => null,
                    'updated_at' => now(),
                ]);
            }

            if (Schema::hasTable('user_project_evaluations')) {
                $legacyFiles = DB::table('user_project_evaluations')
                    ->where('user_id', $userId)
                    ->whereNotNull('submission_file')
                    ->pluck('submission_file')
                    ->filter()
                    ->all();
                $localFiles = array_merge($localFiles, $legacyFiles);
                $publicFiles = array_merge($publicFiles, $legacyFiles);

                DB::table('user_project_evaluations')->where('user_id', $userId)->update([
                    'submission_text' => null,
                    'submission_file' => null,
                    'updated_at' => now(),
                ]);
            }

            if (Schema::hasTable('certificates') && Schema::hasColumn('certificates', 'image_path')) {
                $certificateFiles = DB::table('certificates')
                        ->where('user_id', $userId)
                        ->whereNotNull('image_path')
                        ->where('image_path', '!=', 'pending')
                        ->pluck('image_path')
                        ->filter()
                        ->all();
                foreach ($certificateFiles as $certificatePath) {
                    foreach (array_unique([(string) config('certificate.disk', 'public'), 'public']) as $disk) {
                        $storedFiles[] = ['disk' => $disk, 'path' => (string) $certificatePath];
                    }
                }

                $certificateUpdate = ['image_path' => 'pending', 'updated_at' => now()];
                if (Schema::hasColumn('certificates', 'status')) {
                    $certificateUpdate['status'] = 'revoked';
                }
                if (Schema::hasColumn('certificates', 'revoked_at')) {
                    $certificateUpdate['revoked_at'] = now();
                }
                DB::table('certificates')->where('user_id', $userId)->update($certificateUpdate);
            }

            if (Schema::hasTable('portfolio_items')) {
                $portfolioItemIds = DB::table('portfolio_items')->where('user_id', $userId)->pluck('id');
                if ($portfolioItemIds->isNotEmpty() && Schema::hasTable('portfolio_media')) {
                    // Bunny deletions are external and cannot be atomic with the DB
                    // transaction. Keep private references for a retriable cleanup.
                    $remotePortfolioCleanupPending = DB::table('portfolio_media')
                        ->whereIn('portfolio_item_id', $portfolioItemIds)
                        ->exists();
                    $mediaUpdate = [];
                    if (Schema::hasColumn('portfolio_media', 'caption')) {
                        $mediaUpdate['caption'] = null;
                    }
                    if (Schema::hasColumn('portfolio_media', 'updated_at')) {
                        $mediaUpdate['updated_at'] = now();
                    }
                    if ($mediaUpdate !== []) {
                        DB::table('portfolio_media')
                            ->whereIn('portfolio_item_id', $portfolioItemIds)
                            ->update($mediaUpdate);
                    }
                }

                $portfolioUpdate = $this->onlyExistingColumns('portfolio_items', [
                    'title' => null,
                    'description' => null,
                    'slug' => null,
                    'role' => null,
                    'tools' => null,
                    'external_url' => null,
                    'is_public' => false,
                    'is_featured' => false,
                    'updated_at' => now(),
                ]);
                if ($portfolioUpdate !== []) {
                    DB::table('portfolio_items')->where('user_id', $userId)->update($portfolioUpdate);
                }
            }

            // HasPhoto historically deleted these files synchronously from a
            // model event. Capture them in the durable outbox instead, then
            // remove only the database references inside this transaction.
            if (Schema::hasTable('photos')) {
                $legacyPhotoQuery = DB::table('photos')
                    ->where('photoable_type', User::class)
                    ->where('photoable_id', $userId);
                $legacyPhotoPaths = (clone $legacyPhotoQuery)
                    ->whereNotNull('path')
                    ->pluck('path')
                    ->filter()
                    ->map(static fn ($path): string => (string) $path)
                    ->all();
                $publicFiles = array_merge($publicFiles, $legacyPhotoPaths);
                $legacyPhotoQuery->delete();
            }

            // Keep one-time acquisition rewards one-time even if the learner
            // later signs up again with the same provider identity. The
            // tombstone contains only a keyed HMAC and consumed reward keys;
            // this must happen before social_accounts is erased.
            $this->rewardTombstones->rememberConsumedRewards($locked);

            $this->deleteByUserIdIfPresent('user_device_tokens', $userId);
            $this->deleteByUserIdIfPresent('social_accounts', $userId);
            $this->deleteByUserIdIfPresent('sessions', $userId);
            $this->deleteByUserIdIfPresent('watching_logs', $userId);
            // Academic evidence is deliberately not part of "clear watch
            // history", but full account deletion must remove it as personal
            // learning data.
            $this->deleteByUserIdIfPresent('lesson_watch_evidence', $userId);
            $this->deleteByUserIdIfPresent('login_logs', $userId);
            $this->deleteByUserIdIfPresent('payment_infos', $userId);
            // These are user-controlled or communication records, not the
            // financial/learning evidence retained for legal disputes.
            $this->deleteByUserIdIfPresent('saved_folders', $userId);
            $this->deleteByUserIdIfPresent('student_notifications', $userId);
            $this->deleteByUserIdIfPresent('messages', $userId);
            $this->deleteByUserIdIfPresent('user_notes', $userId);
            $this->deleteByUserIdIfPresent('course_ratings', $userId);
            $this->deleteByUserIdIfPresent('rates', $userId);

            $tokenTable = (string) config('multiple-tokens-auth.table', 'api_tokens');
            $this->deleteByUserIdIfPresent($tokenTable, $userId);

            if ($originalPhone !== '' && Schema::hasTable('verification_codes')) {
                DB::table('verification_codes')->where('phone', $originalPhone)->delete();
            }

            $suffix = $userId . '-' . Str::lower(Str::random(12));
            $anonymized = [
                'name' => 'حساب محذوف',
                'name_ar' => null,
                'name_en' => null,
                'email' => 'deleted-' . $suffix . '@deleted.rokn.local',
                'email_verified_at' => null,
                'phone' => 'deleted-' . $suffix,
                'phone_verified_at' => null,
                'password' => Hash::make(Str::random(64)),
                'social_provider' => null,
                'social_id' => null,
                'api_token' => null,
                'access_token' => null,
                'remember_token' => null,
                'device_os' => null,
                'locked_device_id' => null,
                'active' => false,
                'is_online' => false,
                'provider_request' => false,
                'notifications_status' => false,
                'watch_history_enabled' => false,
                'marketing_notifications_enabled' => false,
                'profile_image' => null,
                'job_title' => null,
                'bio' => null,
                'bio_ar' => null,
                'bio_en' => null,
                'birthday' => null,
                'gender' => 'other',
                'first_name' => null,
                'second_name' => null,
                'last_name' => null,
                'parent_phone' => null,
                'parent_job' => null,
                'type' => null,
                'governorate' => null,
                'car_model' => null,
                'car_year' => null,
                'bank_account_name' => null,
                'bank_account_id' => null,
                'portfolio_slug' => null,
                'portfolio_is_public' => false,
                'portfolio_headline' => null,
                'portfolio_location' => null,
                'portfolio_skills' => null,
                'portfolio_links' => null,
            ];

            $cleanupOutboxIds = $this->enqueueFileCleanup(
                $userId,
                $publicFiles,
                $localFiles,
                $storedFiles
            );

            // Remains safe during rolling deploys with slightly different legacy schemas.
            $userColumns = array_flip(Schema::getColumnListing('users'));
            $locked->forceFill(array_intersect_key($anonymized, $userColumns))->save();
            // The legacy HasPhoto deleting hook performs immediate filesystem
            // I/O. It has already been replaced above with transactional,
            // retriable outbox work, so suppress that hook here.
            $locked->deleteQuietly();
        });

        foreach ($cleanupOutboxIds as $deletionId) {
            try {
                DeleteAccountFile::dispatch((int) $deletionId)->onQueue('default');
            } catch (\Throwable $exception) {
                Log::warning('Unable to dispatch account-file cleanup.', [
                    'deletion_id' => $deletionId,
                    'exception' => get_class($exception),
                ]);
            }
        }
        $cleanupPending = AccountFileDeletion::query()
            ->whereIn('id', $cleanupOutboxIds)
            ->where('status', '<>', AccountFileDeletion::STATUS_COMPLETED)
            ->exists();

        if ($remotePortfolioCleanupPending) {
            try {
                CleanupDeletedAccountPortfolioMedia::dispatch((int) $user->id)
                    ->onQueue('default');
            } catch (\Throwable $exception) {
                // Account deletion has already committed. Keep the private DB
                // references so the scheduled recovery command can retry later.
                Log::warning('Unable to dispatch deleted portfolio cleanup.', [
                    'deleted_user_id' => $user->id,
                    'exception' => get_class($exception),
                ]);
            }
        }

        return [
            'local_cleanup_pending' => $cleanupPending,
            'remote_portfolio_cleanup_pending' => $remotePortfolioCleanupPending,
        ];
    }

    private function deleteByUserIdIfPresent(string $table, int $userId): void
    {
        if ($table !== '' && Schema::hasTable($table) && Schema::hasColumn($table, 'user_id')) {
            DB::table($table)->where('user_id', $userId)->delete();
        }
    }

    /**
     * Keep anonymisation compatible with rolling deployments where the app
     * process and a just-running migration may briefly see adjacent schemas.
     */
    private function onlyExistingColumns(string $table, array $values): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        $columns = array_flip(Schema::getColumnListing($table));

        return array_intersect_key($values, $columns);
    }

    private function enqueueFileCleanup(int $userId, array $publicFiles, array $localFiles, array $storedFiles): array
    {
        $candidates = [];
        foreach ($publicFiles as $path) {
            $candidates[] = ['disk' => 'public', 'path' => $path];
        }
        foreach ($localFiles as $path) {
            $candidates[] = ['disk' => 'local', 'path' => $path];
        }
        $candidates = array_merge($candidates, $storedFiles);

        $ids = [];
        foreach ($candidates as $candidate) {
            $disk = trim((string) ($candidate['disk'] ?? ''));
            $path = ltrim(trim((string) ($candidate['path'] ?? '')), '/');
            if ($disk === '' || $path === '' || filter_var($path, FILTER_VALIDATE_URL)) {
                continue;
            }
            $row = AccountFileDeletion::query()->firstOrCreate(
                ['disk' => $disk, 'path_hash' => hash('sha256', $path)],
                [
                    'user_id' => $userId,
                    'path' => $path,
                    'status' => AccountFileDeletion::STATUS_PENDING,
                    'available_at' => now(),
                ]
            );
            if ($row->status !== AccountFileDeletion::STATUS_COMPLETED) {
                $ids[] = (int) $row->id;
            }
        }

        return array_values(array_unique($ids));
    }
}
