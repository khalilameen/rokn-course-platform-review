<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Attachment;
use App\Models\CourseModule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class MigratePublicCourseAttachments extends Command
{
    protected $signature = 'attachments:privatize
        {--execute : Copy, verify and update records; otherwise audit only}
        {--delete-public : Delete the public source only after a verified private copy}
        {--limit=0 : Maximum rows to inspect; zero means all}';

    protected $description = 'Audit or safely move legacy module attachments from public storage to the private course disk';

    public function handle(): int
    {
        $targetName = (string) config('course_attachments.disk', 'module-attachments');
        if ($targetName === '' || $targetName === 'public') {
            $this->error('COURSE_ATTACHMENT_DISK must name a private disk.');
            return self::FAILURE;
        }

        $query = Attachment::query()
            ->where('attachable_type', CourseModule::class)
            ->where(function ($query): void {
                $query->whereNull('storage_disk')->orWhere('storage_disk', 'public');
            })
            ->orderBy('id');
        $limit = max(0, (int) $this->option('limit'));
        if ($limit > 0) {
            $query->limit($limit);
        }

        $attachments = $query->get();
        if ($attachments->isEmpty()) {
            $this->info('No legacy public module attachments found.');
            return self::SUCCESS;
        }

        if (!$this->option('execute')) {
            $this->warn("{$attachments->count()} public module attachment(s) require migration.");
            $this->line('Run with --execute after confirming shared private storage, then optionally --delete-public.');
            return self::SUCCESS;
        }

        $source = Storage::disk('public');
        $target = Storage::disk($targetName);
        $migrated = 0;
        $failed = 0;

        foreach ($attachments as $attachment) {
            $path = ltrim((string) $attachment->file_path, '/');
            try {
                if ($path === '' || !$source->exists($path)) {
                    throw new \RuntimeException('public source is missing');
                }

                if (!$target->exists($path)) {
                    $stream = $source->readStream($path);
                    if (!is_resource($stream)) {
                        throw new \RuntimeException('could not open source stream');
                    }
                    try {
                        if (!$target->put($path, $stream)) {
                            throw new \RuntimeException('private write failed');
                        }
                    } finally {
                        fclose($stream);
                    }
                }

                if (!$target->exists($path) || $target->size($path) !== $source->size($path)) {
                    throw new \RuntimeException('private copy verification failed');
                }

                Attachment::query()
                    ->whereKey($attachment->id)
                    ->where(function ($query): void {
                        $query->whereNull('storage_disk')->orWhere('storage_disk', 'public');
                    })
                    ->update(['storage_disk' => $targetName]);

                if ($this->option('delete-public')) {
                    $source->delete($path);
                }
                $migrated++;
            } catch (Throwable $exception) {
                $failed++;
                $this->error("#{$attachment->id}: {$exception->getMessage()}");
            }
        }

        $this->info("Migrated {$migrated}; failed {$failed}.");
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
