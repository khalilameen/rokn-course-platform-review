<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\GenerateProjectFeedbackReply;
use App\Jobs\GenerateProjectFeedback;
use App\Models\ProjectFeedbackMessage;
use App\Models\ProjectSubmission;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class RecoverStalledAiFeedback extends Command
{
    protected $signature = 'ai:recover-stalled-feedback {--limit=200}';
    protected $description = 'Requeue lost AI feedback jobs and close abandoned typing leases';

    public function handle(): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $queued = 0;
        $reportsQueued = 0;
        $closed = 0;

        $reportIds = ProjectSubmission::query()
            ->where('review_status', ProjectSubmission::STATUS_PASSED)
            ->whereIn('submission_metadata->ai_feedback->status', ['queued', 'processing'])
            ->where('updated_at', '<=', now()->subMinutes(2))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        foreach ($reportIds as $submissionId) {
            try {
                GenerateProjectFeedback::dispatch((int) $submissionId)
                    ->onQueue((string) config('queue.channels.ai_feedback', 'ai-feedback'));
                $reportsQueued++;
            } catch (\Throwable $exception) {
                Log::warning('Stalled initial project report could not be requeued.', [
                    'submission_id' => $submissionId,
                    'exception' => $exception::class,
                ]);
            }
        }

        $queuedMessages = ProjectFeedbackMessage::query()
            ->where('role', 'user')
            ->where('status', ProjectFeedbackMessage::QUEUED)
            ->where('updated_at', '<=', now()->subMinutes(2))
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'updated_at']);
        foreach ($queuedMessages as $message) {
            $claimed = ProjectFeedbackMessage::query()
                ->whereKey($message->id)
                ->where('status', ProjectFeedbackMessage::QUEUED)
                ->where('updated_at', $message->updated_at)
                ->update(['updated_at' => now()]);
            if ($claimed !== 1) continue;
            try {
                GenerateProjectFeedbackReply::dispatch((int) $message->id)
                    ->onQueue((string) config('queue.channels.ai_feedback', 'ai-feedback'));
                $queued++;
            } catch (\Throwable $exception) {
                ProjectFeedbackMessage::query()->whereKey($message->id)->update([
                    'updated_at' => $message->updated_at,
                ]);
                Log::warning('Stalled AI feedback could not be requeued.', [
                    'message_id' => $message->id,
                    'exception' => $exception::class,
                ]);
            }
        }

        $staleSent = ProjectFeedbackMessage::query()
            ->where('role', 'user')
            ->where('status', ProjectFeedbackMessage::SENT)
            ->where('updated_at', '<=', now()->subMinutes(2))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        foreach ($staleSent as $messageId) {
            $closed += DB::transaction(function () use ($messageId): int {
                $message = ProjectFeedbackMessage::query()->lockForUpdate()->find($messageId);
                if (
                    !$message
                    || $message->status !== ProjectFeedbackMessage::SENT
                    || $message->updated_at->gt(now()->subMinutes(2))
                ) return 0;
                $message->forceFill([
                    'status' => ProjectFeedbackMessage::FAILED,
                    'error_code' => 'request_interrupted',
                    'reserved_tokens' => 0,
                    'completed_at' => now(),
                ])->save();
                ProjectFeedbackMessage::query()
                    ->where('thread_id', $message->thread_id)
                    ->where('role', 'assistant')
                    ->where('client_request_id', 'reply:' . $message->public_id)
                    ->whereIn('status', [
                        ProjectFeedbackMessage::QUEUED,
                        ProjectFeedbackMessage::STREAMING,
                    ])
                    ->update([
                        'status' => ProjectFeedbackMessage::FAILED,
                        'error_code' => 'request_interrupted',
                        'completed_at' => now(),
                        'updated_at' => now(),
                    ]);
                return 1;
            }, 3);
        }

        $this->info("Requeued {$reportsQueued} initial report(s) and {$queued} AI message(s); closed {$closed} abandoned lease(s).");
        return self::SUCCESS;
    }
}
