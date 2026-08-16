<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Exceptions\ProductEventConflictException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\ProductEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ProductEventController extends Controller
{
    private const EVENT_FIELDS = [
        'event_id', 'session_key', 'event_name', 'source', 'screen_key',
        'campaign_key', 'course_id', 'module_id', 'lesson_id', 'project_id',
        'milestone', 'value', 'occurred_at',
    ];

    public function store(
        Request $request,
        ProductEventService $events,
        ApiResponseService $responses
    ): JsonResponse {
        $payload = $request->all();
        $isBatch = array_key_exists('events', $payload);

        if ($isBatch) {
            $unknown = array_values(array_diff(array_keys($payload), ['events']));
            if ($unknown !== []) {
                throw ValidationException::withMessages([
                    'payload' => ['Unsupported product-event fields: '.implode(', ', $unknown)],
                ]);
            }

            $batchValidator = Validator::make($payload, [
                'events' => ['required', 'array', 'min:1', 'max:12'],
            ]);
            $batchValidator->validate();
            $rawEvents = $payload['events'];
        } else {
            $rawEvents = [$payload];
        }

        $validatedEvents = [];
        foreach ($rawEvents as $index => $rawEvent) {
            if (!is_array($rawEvent)) {
                throw ValidationException::withMessages([
                    $this->field($isBatch, $index, 'event') => ['Each product event must be an object.'],
                ]);
            }

            $unknown = array_values(array_diff(array_keys($rawEvent), self::EVENT_FIELDS));
            if ($unknown !== []) {
                throw ValidationException::withMessages([
                    $this->field($isBatch, $index, 'payload') => [
                        'Unsupported product-event fields: '.implode(', ', $unknown),
                    ],
                ]);
            }

            $validator = Validator::make($rawEvent, [
                'event_id' => ['required', 'uuid'],
                'session_key' => ['required', 'uuid'],
                'event_name' => ['required', Rule::in((array) config('product_events.allowed', []))],
                // Public clients may not impersonate trusted dashboard/system producers.
                'source' => ['sometimes', Rule::in(['app', 'web', 'notification'])],
                'screen_key' => ['nullable', Rule::in((array) config('product_events.screens', []))],
                'campaign_key' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:-]+$/'],
                'course_id' => ['nullable', 'integer', 'min:1'],
                'module_id' => ['nullable', 'integer', 'min:1'],
                'lesson_id' => ['nullable', 'integer', 'min:1'],
                'project_id' => ['nullable', 'integer', 'min:1'],
                'milestone' => ['nullable', 'integer', Rule::in((array) config('product_events.lesson_milestones', []))],
                'value' => ['nullable', 'integer', 'between:-1000000,1000000'],
                'occurred_at' => ['required', 'date', 'after_or_equal:-7 days', 'before_or_equal:+10 minutes'],
            ]);

            $validator->after(static function ($validator) use ($rawEvent): void {
                $isMilestone = ($rawEvent['event_name'] ?? null) === 'lesson_milestone';
                $hasMilestone = array_key_exists('milestone', $rawEvent) && $rawEvent['milestone'] !== null;
                if ($isMilestone && !$hasMilestone) {
                    $validator->errors()->add('milestone', 'A lesson milestone value is required.');
                } elseif (!$isMilestone && $hasMilestone) {
                    $validator->errors()->add('milestone', 'Milestone is only valid for lesson_milestone events.');
                }
            });

            try {
                $validatedEvents[] = $validator->validate();
            } catch (ValidationException $exception) {
                if (!$isBatch) {
                    throw $exception;
                }

                $messages = [];
                foreach ($exception->errors() as $field => $errorsForField) {
                    $messages['events.'.$index.'.'.$field] = $errorsForField;
                }
                throw ValidationException::withMessages($messages);
            }
        }

        /** @var User|null $user */
        $user = $request->user('api');
        $accepted = 0;
        $duplicates = 0;

        try {
            foreach ($validatedEvents as $event) {
                $record = $events->record($event, $user);
                $record->wasRecentlyCreated ? $accepted++ : $duplicates++;
            }
        } catch (ProductEventConflictException) {
            return $responses->error(
                'event_id was already used for a different product event.',
                409,
                ['accepted' => false],
                ['accepted' => false]
            );
        }

        $result = [
            'accepted' => true,
            'accepted_count' => $accepted,
            'duplicate_count' => $duplicates,
        ];

        return $responses->success(
            $result,
            'Product events accepted',
            202,
            $result
        );
    }

    private function field(bool $isBatch, int $index, string $field): string
    {
        return $isBatch ? 'events.'.$index.'.'.$field : $field;
    }
}
