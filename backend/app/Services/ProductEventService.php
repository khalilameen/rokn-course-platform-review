<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ProductEventConflictException;
use App\Models\ProductEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ProductEventService
{
    public function __construct(private OutboxService $outbox)
    {
    }

    public function record(array $data, ?User $user = null): ProductEvent
    {
        return DB::transaction(function () use ($data, $user) {
            $sessionKey = $this->keyedIdentity('session:'.(string) $data['session_key']);
            $attributes = [
                'user_id' => $user?->id,
                'actor_key' => $this->keyedIdentity(
                    $user ? 'user:'.$user->id : 'guest-session:'.(string) $data['session_key']
                ),
                'session_key' => $sessionKey,
                'event_name' => (string) $data['event_name'],
                'source' => (string) ($data['source'] ?? 'app'),
                'screen_key' => $data['screen_key'] ?? null,
                'campaign_key' => $data['campaign_key'] ?? null,
                'course_id' => isset($data['course_id']) ? (int) $data['course_id'] : null,
                'module_id' => isset($data['module_id']) ? (int) $data['module_id'] : null,
                'lesson_id' => isset($data['lesson_id']) ? (int) $data['lesson_id'] : null,
                'project_id' => isset($data['project_id']) ? (int) $data['project_id'] : null,
                'milestone' => isset($data['milestone']) ? (int) $data['milestone'] : null,
                'value' => isset($data['value']) ? (int) $data['value'] : null,
                // SQL timestamp columns are second-precision in the supported
                // production schema. Normalize before the idempotency compare
                // so a normal JavaScript ISO timestamp with milliseconds does
                // not look like a conflicting retry.
                'occurred_at' => CarbonImmutable::parse((string) $data['occurred_at'])
                    ->utc()
                    ->setMicrosecond(0),
                'received_at' => now(),
            ];

            $event = ProductEvent::query()->firstOrCreate(
                ['event_id' => $data['event_id']],
                $attributes
            );

            if (!$event->wasRecentlyCreated && !$this->sameEvent($event, $attributes)) {
                throw new ProductEventConflictException('event_id payload mismatch');
            }

            if ($event->wasRecentlyCreated) {
                $this->outbox->record(
                    'product.' . $event->event_name,
                    [
                        'event_id' => $event->event_id,
                        'event_name' => $event->event_name,
                        'source' => $event->source,
                        'screen_key' => $event->screen_key,
                        'campaign_key' => $event->campaign_key,
                        'course_id' => $event->course_id,
                        'module_id' => $event->module_id,
                        'lesson_id' => $event->lesson_id,
                        'project_id' => $event->project_id,
                        'milestone' => $event->milestone,
                        'value' => $event->value,
                        'occurred_at' => $event->occurred_at?->toIso8601String(),
                    ],
                    'product_event',
                    $event->id,
                    $event->event_id
                );
            }

            return $event;
        });
    }

    private function keyedIdentity(string $value): string
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            $key = $decoded === false ? $key : $decoded;
        }

        if ($key === '') {
            throw new \RuntimeException('APP_KEY is required for product-event pseudonyms.');
        }

        return hash_hmac('sha256', $value, $key);
    }

    private function sameEvent(ProductEvent $event, array $attributes): bool
    {
        foreach (['event_name', 'source', 'screen_key', 'campaign_key'] as $field) {
            $actual = $event->{$field};
            $expected = $attributes[$field];
            if (($actual === null ? null : (string) $actual)
                !== ($expected === null ? null : (string) $expected)) {
                return false;
            }
        }

        foreach (['course_id', 'module_id', 'lesson_id', 'project_id', 'milestone', 'value'] as $field) {
            $actual = $event->{$field};
            $expected = $attributes[$field];
            if (($actual === null ? null : (int) $actual)
                !== ($expected === null ? null : (int) $expected)) {
                return false;
            }
        }

        // SQL timestamp columns do not retain a timezone. Comparing Carbon
        // instances would reinterpret the stored UTC wall time in APP_TIMEZONE
        // and reject an otherwise identical retry. Compare the canonical value
        // that was actually persisted instead.
        return (string) $event->getRawOriginal('occurred_at')
            === $attributes['occurred_at']->format($event->getDateFormat());
    }
}
