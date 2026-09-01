<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AiUsageEvent;
use App\Models\CourseChatTurn;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use UnexpectedValueException;

final class CourseChatTurnService
{
    private ?bool $schemaAvailable = null;

    public function available(): bool
    {
        return $this->schemaAvailable ??= Schema::hasTable('course_chat_turns');
    }

    public function begin(
        int $userId,
        int $courseId,
        ?int $enrollmentId,
        ?int $lessonId,
        string $clientRequestId,
        string $question,
        string $language,
        string $promptVersion
    ): ?CourseChatTurn {
        if (!$this->available()) {
            return null;
        }

        $fingerprint = hash('sha256', json_encode([
            'course_id' => $courseId,
            'lesson_id' => $lessonId,
            'question' => $question,
            'language' => $language,
            'prompt_version' => $promptVersion,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return DB::transaction(function () use (
            $userId,
            $courseId,
            $enrollmentId,
            $lessonId,
            $clientRequestId,
            $question,
            $language,
            $promptVersion,
            $fingerprint
        ): CourseChatTurn {
            $existing = CourseChatTurn::query()
                ->where('user_id', $userId)
                ->where('client_request_id', $clientRequestId)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                if (!hash_equals((string) $existing->request_fingerprint, $fingerprint)) {
                    throw new UnexpectedValueException('Course chat request identity conflict.');
                }

                return $existing;
            }

            $turn = CourseChatTurn::query()->createOrFirst(
                [
                    'user_id' => $userId,
                    'client_request_id' => $clientRequestId,
                ],
                [
                    'public_id' => (string) Str::uuid(),
                    'course_id' => $courseId,
                    'enrollment_id' => $enrollmentId,
                    'lesson_id' => $lessonId,
                    'request_fingerprint' => $fingerprint,
                    'prompt_version' => $promptVersion,
                    'language' => substr($language, 0, 12),
                    'status' => CourseChatTurn::QUEUED,
                    'question' => $question,
                    'expires_at' => now()->addDays(max(7, (int) config('openrouter.chat_history_days', 90))),
                ]
            );
            if (!hash_equals((string) $turn->request_fingerprint, $fingerprint)) {
                throw new UnexpectedValueException('Course chat request identity conflict.');
            }

            return $turn;
        }, 3);
    }

    /** @return list<array{role:string,content:string}> */
    public function context(
        int $userId,
        int $courseId,
        ?int $lessonId,
        string $language,
        string $promptVersion,
        int $excludeTurnId
    ): array {
        if (!$this->available()) {
            return [];
        }

        return CourseChatTurn::query()
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->where('language', $language)
            ->where('prompt_version', $promptVersion)
            ->where('status', CourseChatTurn::COMPLETED)
            ->where('id', '<>', $excludeTurnId)
            ->where('expires_at', '>', now())
            ->when(
                $lessonId === null,
                fn ($query) => $query->whereNull('lesson_id'),
                fn ($query) => $query->where('lesson_id', $lessonId)
            )
            ->orderByDesc('id')
            ->limit(4)
            ->get(['question', 'answer'])
            ->reverse()
            ->flatMap(fn (CourseChatTurn $turn): array => [
                ['role' => 'user', 'content' => (string) $turn->question],
                ['role' => 'assistant', 'content' => (string) $turn->answer],
            ])
            ->filter(fn (array $message): bool => trim($message['content']) !== '')
            ->values()
            ->all();
    }

    public function markStreaming(?CourseChatTurn $turn): void
    {
        $this->transition($turn, CourseChatTurn::STREAMING, null, null);
    }

    public function complete(?CourseChatTurn $turn, string $answer, ?AiUsageEvent $usage = null): void
    {
        if (!$turn || !$this->available()) {
            return;
        }

        DB::transaction(function () use ($turn, $answer, $usage): void {
            $locked = CourseChatTurn::query()->lockForUpdate()->find($turn->id);
            if (!$locked || $locked->status === CourseChatTurn::COMPLETED) {
                return;
            }
            if (!$usage && Schema::hasTable('ai_usage_events')) {
                $usage = AiUsageEvent::query()
                    ->where('request_id', $locked->client_request_id)
                    ->where('user_id', $locked->user_id)
                    ->first();
            }
            $locked->forceFill([
                'status' => CourseChatTurn::COMPLETED,
                'answer' => mb_substr(trim($answer), 0, 12000),
                'error_code' => null,
                'usage_event_id' => $usage?->id,
                'completed_at' => now(),
            ])->save();
        }, 3);
    }

    public function fail(?CourseChatTurn $turn, string $code): void
    {
        $this->transition($turn, CourseChatTurn::FAILED, $code, now());
    }

    public function page(
        int $userId,
        int $courseId,
        ?int $lessonId,
        int $perPage = 20
    ): CursorPaginator {
        return CourseChatTurn::query()
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->where('expires_at', '>', now())
            ->when(
                $lessonId === null,
                fn ($query) => $query->whereNull('lesson_id'),
                fn ($query) => $query->where('lesson_id', $lessonId)
            )
            ->orderByDesc('id')
            ->cursorPaginate(max(1, min(50, $perPage)));
    }

    public function prune(int $limit = 1000): int
    {
        if (!$this->available()) {
            return 0;
        }

        $ids = CourseChatTurn::query()
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->limit(max(1, min(5000, $limit)))
            ->pluck('id');

        return $ids->isEmpty() ? 0 : CourseChatTurn::query()->whereIn('id', $ids)->delete();
    }

    private function transition(
        ?CourseChatTurn $turn,
        string $status,
        ?string $code,
        mixed $completedAt
    ): void {
        if (!$turn || !$this->available()) {
            return;
        }
        CourseChatTurn::query()
            ->whereKey($turn->id)
            ->where('status', '<>', CourseChatTurn::COMPLETED)
            ->update([
                'status' => $status,
                'error_code' => $code ? substr($code, 0, 64) : null,
                'completed_at' => $completedAt,
                'updated_at' => now(),
            ]);
    }
}
