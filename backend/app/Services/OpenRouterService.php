<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AiProviderUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

final class OpenRouterService
{
    public const CIRCUIT_KEY = 'openrouter:circuit-open';
    private const FAILURE_KEY = 'openrouter:circuit-failures';

    public function chat(
        string $model,
        array $messages,
        float $temperature,
        int $maxTokens,
        ?string $requestIdentity = null,
        ?callable $landImmediately = null,
        ?callable $onPartial = null
    ): array {
        $apiKey = (string) config('openrouter.api_key');
        if ($apiKey === '' || $model === '') {
            throw new AiProviderUnavailableException(
                false,
                'AI service is not configured.'
            );
        }

        $allowed = array_values(array_filter(config('openrouter.allowed_models', [])));
        if ($allowed === [] || !in_array($model, $allowed, true)) {
            throw new AiProviderUnavailableException(
                false,
                'AI model is not in the production allowlist.'
            );
        }

        if ($this->circuitIsOpen()) {
            throw new AiProviderUnavailableException(true);
        }

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'max_completion_tokens' => max(
                80,
                min((int) config('openrouter.max_tokens', 500), $maxTokens)
            ),
            'provider' => [
                'require_parameters' => true,
                'data_collection' => 'deny',
                'zdr' => true,
            ],
        ];
        $reasoningEffort = $this->reasoningEffort($model);
        if ($reasoningEffort !== null) {
            $payload['reasoning'] = [
                'effort' => $reasoningEffort,
                'exclude' => true,
            ];
        }
        // GPT-5 endpoints do not advertise temperature support. Sending it
        // anyway can make an otherwise healthy provider reject the request
        // before generation starts. Keep sampling control for models that
        // support it instead of weakening every model to the same payload.
        if ($this->supportsTemperature($model)) {
            $payload['temperature'] = max(0, min(1.2, $temperature));
        }
        if (trim((string) $requestIdentity) !== '') {
            // The stable external-user value is part of the request body, so
            // an identical recovery attempt cannot accidentally address a
            // different logical learner request.
            $payload['user'] = substr(
                hash('sha256', trim((string) $requestIdentity)),
                0,
                64
            );
        }
        if ($this->containsPdf($messages)) {
            $payload['plugins'] = [[
                'id' => 'file-parser',
                'pdf' => ['engine' => (string) config('openrouter.pdf_parser_engine', 'cloudflare-ai')],
            ]];
        }
        if ($onPartial !== null) {
            $payload['stream'] = true;
            $payload['stream_options'] = ['include_usage' => true];
        }

        try {
            $request = Http::withToken($apiKey)
                ->acceptJson()
                ->withHeaders([
                    'HTTP-Referer' => (string) config('app.url'),
                    'X-Title' => (string) config('app.name', 'Rokn'),
                    'X-OpenRouter-Cache' => 'true',
                    'X-OpenRouter-Cache-TTL' => (string) max(
                        1,
                        min(86400, (int) config(
                            'openrouter.response_recovery_cache_ttl_seconds',
                            900
                        ))
                    ),
                ])
                ->connectTimeout(max(1, (int) config('openrouter.connect_timeout_seconds', 5)))
                ->timeout((int) config('openrouter.timeout_seconds', 20));
            if ($onPartial !== null) {
                // Guzzle returns after the response headers and exposes the
                // provider body as a PSR stream. The API key never crosses the
                // backend boundary and the job keeps one paid request open.
                $request = $request->withOptions(['stream' => true]);
            }
            $response = $request->post((string) config('openrouter.endpoint'), $payload);
        } catch (ConnectionException $exception) {
            $this->recordTransientFailure('connection');
            // A timeout may happen after the provider accepted and billed the
            // request. Do not issue a blind second paid call.
            throw new AiProviderUnavailableException(
                false,
                previous: $exception,
                outcomeUnknown: true
            );
        }

        if (!$response->successful()) {
            $failureAnnotations = $response->json('error.metadata.file_annotations');
            if (!is_array($failureAnnotations)) $failureAnnotations = [];
            $providerCode = trim((string) $response->json('error.code'));
            $providerCode = $providerCode !== ''
                ? substr($providerCode, 0, 80)
                : null;
            Log::warning('OpenRouter rejected a generation request.', [
                'status' => $response->status(),
                'provider_code' => $providerCode,
                'model' => $model,
            ]);
            if ($response->status() === 402) {
                $this->openCircuit(
                    'billing',
                    max(60, (int) config('openrouter.billing_circuit_open_seconds', 900))
                );
            } elseif (in_array($response->status(), [401, 403], true)) {
                $this->openCircuit(
                    'authentication',
                    max(60, (int) config('openrouter.billing_circuit_open_seconds', 900))
                );
            } elseif ($response->status() === 429 || $response->serverError()) {
                $this->recordTransientFailure('http_' . $response->status());
            }
            throw new AiProviderUnavailableException(
                $response->status() === 429,
                fileAnnotations: $failureAnnotations,
                outcomeUnknown: $response->serverError(),
                providerStatus: $response->status(),
                providerCode: $providerCode
            );
        }

        $this->recordSuccess();

        $isEventStream = str_contains(
            strtolower((string) $response->header('Content-Type')),
            'text/event-stream'
        );
        $body = $onPartial !== null && $isEventStream
            ? $this->consumeEventStream($response, $onPartial, $model)
            : $response->json();
        $content = $this->learnerVisibleContent(
            data_get($body, 'choices.0.message.content')
        );
        if ($content === '') {
            Log::warning('OpenRouter returned no learner-visible answer.', [
                'provider_request_id' => data_get($body, 'id'),
                'model' => data_get($body, 'model', $model),
                'finish_reason' => data_get($body, 'choices.0.finish_reason'),
                'native_finish_reason' => data_get($body, 'choices.0.native_finish_reason'),
                'completion_tokens' => max(
                    0,
                    (int) data_get($body, 'usage.completion_tokens', 0)
                ),
                'reasoning_returned' => filled(
                    data_get($body, 'choices.0.message.reasoning')
                ) || filled(data_get($body, 'choices.0.message.reasoning_details')),
            ]);
            throw new AiProviderUnavailableException(
                false,
                'AI provider returned an empty response.',
                outcomeUnknown: true
            );
        }
        $content = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $content));
        if (
            $content === ''
            || mb_strlen($content) > 12000
            || preg_match(
                '/(?:sqlstate|stack\s+trace|uncaught\s+exception|provider\s+error|tool[_\s-]?calls?|<html\b)/iu',
                $content
            )
        ) {
            throw new AiProviderUnavailableException(
                false,
                'AI provider returned an unusable response.',
                outcomeUnknown: true
            );
        }

        $providerCost = data_get($body, 'usage.cost');

        $result = [
            'message' => $content,
            'provider_request_id' => data_get($body, 'id')
                ?: $response->header('X-Generation-Id'),
            // OpenRouter includes normalized token and cost accounting in the
            // response. Persist the real amount; never infer margin from a
            // model name that can change price later.
            'usage' => [
                'prompt_tokens' => max(0, (int) data_get($body, 'usage.prompt_tokens', 0)),
                'completion_tokens' => max(0, (int) data_get($body, 'usage.completion_tokens', 0)),
                'total_tokens' => max(0, (int) data_get($body, 'usage.total_tokens', 0)),
                'cost' => is_numeric($providerCost) ? max(0, (float) $providerCost) : 0,
                // Zero is a valid provider-reported cost (for example a free
                // model). Keep it distinct from an omitted usage cost.
                'cost_reported' => is_numeric($providerCost),
            ],
            'file_annotations' => is_array(data_get($body, 'choices.0.message.annotations'))
                ? data_get($body, 'choices.0.message.annotations')
                : [],
            'provider_transport' => [
                'generation_id' => substr(
                    (string) ($response->header('X-Generation-Id') ?: data_get($body, 'id', '')),
                    0,
                    255
                ),
                'response_cache_status' => substr(
                    (string) $response->header('X-OpenRouter-Cache-Status'),
                    0,
                    16
                ),
            ],
        ];

        // Land at the provider boundary before returning through formatting,
        // settlement and learner-facing layers. The caller repeats the same
        // idempotent landing as a defensive check.
        if ($landImmediately !== null) {
            $landImmediately($result);
        }

        return $result;
    }

    /**
     * Convert OpenRouter's SSE frames into the same result envelope used by
     * non-streaming calls. Partial delivery is presentation only: the caller
     * still lands and settles the final envelope exactly once.
     *
     * @return array<string,mixed>
     */
    private function consumeEventStream(
        Response $response,
        callable $onPartial,
        string $fallbackModel
    ): array {
        $stream = $response->toPsrResponse()->getBody();
        $buffer = '';
        $content = '';
        $providerRequestId = (string) ($response->header('X-Generation-Id') ?: '');
        $model = $fallbackModel;
        $finishReason = null;
        $nativeFinishReason = null;
        $usage = [];
        $annotations = [];
        $lastEmittedLength = 0;
        $lastEmittedAt = hrtime(true);
        $callback = $onPartial;
        $streamCompleted = false;

        try {
            while (!$stream->eof()) {
                $chunk = $stream->read(8192);
                if ($chunk === '') {
                    continue;
                }
                $buffer .= $chunk;
                foreach ($this->takeCompleteSseEvents($buffer) as $event) {
                    if ($this->consumeSseEvent(
                        $event,
                        $content,
                        $providerRequestId,
                        $model,
                        $finishReason,
                        $nativeFinishReason,
                        $usage,
                        $annotations
                    )) {
                        $streamCompleted = true;
                        break 2;
                    }
                    $this->emitPartial(
                        $callback,
                        $content,
                        $lastEmittedLength,
                        $lastEmittedAt
                    );
                }
            }
            if (trim($buffer) !== '') {
                $streamCompleted = $this->consumeSseEvent(
                    $buffer,
                    $content,
                    $providerRequestId,
                    $model,
                    $finishReason,
                    $nativeFinishReason,
                    $usage,
                    $annotations
                ) || $streamCompleted;
            }
            if (!$streamCompleted && !filled($finishReason)) {
                throw new AiProviderUnavailableException(
                    false,
                    'AI provider stream ended before completion.',
                    outcomeUnknown: true
                );
            }
        } catch (AiProviderUnavailableException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new AiProviderUnavailableException(
                false,
                'AI provider stream was interrupted.',
                previous: $exception,
                outcomeUnknown: true
            );
        } finally {
            $stream->close();
        }

        $this->emitPartial(
            $callback,
            $content,
            $lastEmittedLength,
            $lastEmittedAt,
            true
        );

        return [
            'id' => $providerRequestId,
            'model' => $model,
            'choices' => [[
                'message' => [
                    'content' => $content,
                    'annotations' => $annotations,
                ],
                'finish_reason' => $finishReason,
                'native_finish_reason' => $nativeFinishReason,
            ]],
            'usage' => $usage,
        ];
    }

    /** @return list<string> */
    private function takeCompleteSseEvents(string &$buffer): array
    {
        $events = [];
        while (preg_match('/\r\n\r\n|\n\n|\r\r/', $buffer, $match, PREG_OFFSET_CAPTURE)) {
            $delimiter = (string) $match[0][0];
            $offset = (int) $match[0][1];
            $events[] = substr($buffer, 0, $offset);
            $buffer = substr($buffer, $offset + strlen($delimiter));
        }

        return $events;
    }

    /**
     * @param array<string,mixed> $usage
     * @param list<array<string,mixed>> $annotations
     */
    private function consumeSseEvent(
        string $event,
        string &$content,
        string &$providerRequestId,
        string &$model,
        mixed &$finishReason,
        mixed &$nativeFinishReason,
        array &$usage,
        array &$annotations
    ): bool {
        $dataLines = [];
        foreach (preg_split('/\r\n|\n|\r/', $event) ?: [] as $line) {
            if (!str_starts_with($line, 'data:')) {
                continue;
            }
            $dataLines[] = ltrim(substr($line, 5), ' ');
        }
        if ($dataLines === []) {
            return false;
        }

        $payload = implode("\n", $dataLines);
        if (trim($payload) === '[DONE]') {
            return true;
        }

        try {
            $frame = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new AiProviderUnavailableException(
                false,
                'AI provider returned a malformed stream.',
                previous: $exception,
                outcomeUnknown: true
            );
        }
        if (!is_array($frame)) {
            return false;
        }
        if (isset($frame['error'])) {
            throw new AiProviderUnavailableException(
                false,
                'AI provider stream returned an error.',
                fileAnnotations: is_array(data_get($frame, 'error.metadata.file_annotations'))
                    ? data_get($frame, 'error.metadata.file_annotations') : [],
                outcomeUnknown: $content !== ''
            );
        }

        $providerRequestId = (string) ($frame['id'] ?? $providerRequestId);
        $model = (string) ($frame['model'] ?? $model);
        $delta = data_get($frame, 'choices.0.delta.content');
        $content .= $this->streamVisibleContent($delta);
        if (mb_strlen($content) > 12000) {
            throw new AiProviderUnavailableException(
                false,
                'AI provider stream exceeded the answer limit.',
                outcomeUnknown: true
            );
        }
        $finishReason = data_get($frame, 'choices.0.finish_reason', $finishReason);
        $nativeFinishReason = data_get(
            $frame,
            'choices.0.native_finish_reason',
            $nativeFinishReason
        );
        if (is_array($frame['usage'] ?? null)) {
            $usage = $frame['usage'];
        }
        $frameAnnotations = data_get($frame, 'choices.0.delta.annotations');
        if (!is_array($frameAnnotations)) {
            $frameAnnotations = data_get($frame, 'choices.0.message.annotations');
        }
        if (is_array($frameAnnotations) && $frameAnnotations !== []) {
            $annotations = array_values(array_merge($annotations, $frameAnnotations));
        }

        return false;
    }

    private function streamVisibleContent(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }
        if (!is_array($content)) {
            return '';
        }

        $text = '';
        foreach ($content as $part) {
            if (is_string($part)) {
                $text .= $part;
                continue;
            }
            if (!is_array($part)) {
                continue;
            }
            $type = strtolower(trim((string) ($part['type'] ?? '')));
            if ($type !== '' && !in_array($type, ['text', 'output_text'], true)) {
                continue;
            }
            if (is_string($part['text'] ?? null)) {
                $text .= $part['text'];
            }
        }

        return $text;
    }

    private function emitPartial(
        ?callable &$callback,
        string $content,
        int &$lastEmittedLength,
        int &$lastEmittedAt,
        bool $force = false
    ): void {
        if ($callback === null || $content === '') {
            return;
        }
        $length = mb_strlen($content);
        $now = hrtime(true);
        if (
            !$force
            && $length - $lastEmittedLength < 48
            && $now - $lastEmittedAt < 250_000_000
        ) {
            return;
        }
        $partial = (string) preg_replace(
            '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',
            '',
            $content
        );
        if ($partial === '' || preg_match(
            '/(?:sqlstate|stack\s+trace|uncaught\s+exception|provider\s+error|tool[_\s-]?calls?|<html\b)/iu',
            $partial
        )) {
            return;
        }
        try {
            $callback($partial);
            $lastEmittedLength = $length;
            $lastEmittedAt = $now;
        } catch (Throwable $exception) {
            // A progress checkpoint is deliberately non-authoritative. Losing
            // it must not abort a paid provider call whose final result can
            // still be landed and settled safely.
            Log::warning('AI partial response checkpoint failed.', [
                'exception' => $exception::class,
            ]);
            $callback = null;
        }
    }

    private function containsPdf(array $messages): bool
    {
        foreach ($messages as $message) {
            $content = is_array($message) ? ($message['content'] ?? null) : null;
            if (!is_array($content)) continue;
            foreach ($content as $part) {
                if (!is_array($part) || ($part['type'] ?? null) !== 'file') continue;
                if (str_starts_with((string) data_get($part, 'file.file_data'), 'data:application/pdf;')) {
                    return true;
                }
            }
        }
        return false;
    }

    private function learnerVisibleContent(mixed $content): string
    {
        if (is_string($content)) {
            return trim($content);
        }
        if (!is_array($content)) {
            return '';
        }

        $lines = [];
        foreach ($content as $part) {
            if (is_string($part) && trim($part) !== '') {
                $lines[] = trim($part);
                continue;
            }
            if (!is_array($part)) {
                continue;
            }

            $type = strtolower(trim((string) ($part['type'] ?? '')));
            if ($type !== '' && !in_array($type, ['text', 'output_text'], true)) {
                continue;
            }
            if (is_string($part['text'] ?? null) && trim($part['text']) !== '') {
                $lines[] = trim($part['text']);
            }
        }

        return implode("\n", $lines);
    }

    private function reasoningEffort(string $model): ?string
    {
        $effort = strtolower(trim((string) config('openrouter.reasoning_effort', 'none')));
        $effort = in_array(
            $effort,
            ['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'],
            true
        ) ? $effort : 'none';

        // The original GPT-5 family requires reasoning and advertises
        // minimal/low/medium/high only. OpenRouter may map an unsupported
        // "none" to a larger default effort, consuming the small course-chat
        // completion budget before any learner-visible text is produced.
        if (
            $effort === 'none'
            && preg_match(
                '/^openai\/gpt-5(?:-(?:mini|nano|pro))?(?:-\d{4}-\d{2}-\d{2})?$/',
                strtolower(trim($model))
            )
        ) {
            return 'minimal';
        }

        // `none` is an OpenRouter reasoning control, not a universal model
        // parameter. Omitting it lets ordinary chat models remain eligible
        // when strict parameter support is enabled.
        return $effort === 'none' ? null : $effort;
    }

    private function supportsTemperature(string $model): bool
    {
        $normalized = strtolower(trim($model));

        return !str_starts_with($normalized, 'openai/gpt-5')
            && !preg_match('/^openai\/(?:o1|o3|o4)(?:-|$)/', $normalized);
    }

    private function circuitIsOpen(): bool
    {
        try {
            return Cache::has(self::CIRCUIT_KEY);
        } catch (\Throwable) {
            // Cache failure is already visible in operational health. It must
            // not turn an otherwise usable AI provider into a false outage.
            return false;
        }
    }

    private function recordTransientFailure(string $reason): void
    {
        try {
            Cache::add(self::FAILURE_KEY, 0, now()->addMinute());
            $failures = (int) Cache::increment(self::FAILURE_KEY);
            if ($failures >= max(2, (int) config('openrouter.circuit_failure_threshold', 3))) {
                $this->openCircuit($reason);
            }
        } catch (\Throwable $exception) {
            Log::warning('OpenRouter circuit state could not be recorded.', [
                'reason' => $reason,
                'exception' => $exception::class,
            ]);
        }
    }

    private function openCircuit(string $reason, ?int $seconds = null): void
    {
        try {
            Cache::put(
                self::CIRCUIT_KEY,
                ['reason' => $reason, 'opened_at' => now()->toIso8601String()],
                now()->addSeconds($seconds ?? max(
                    10,
                    (int) config('openrouter.circuit_open_seconds', 30)
                ))
            );
        } catch (\Throwable $exception) {
            Log::warning('OpenRouter circuit could not be opened.', [
                'reason' => $reason,
                'exception' => $exception::class,
            ]);
        }
    }

    private function recordSuccess(): void
    {
        try {
            Cache::forget(self::FAILURE_KEY);
            Cache::forget(self::CIRCUIT_KEY);
        } catch (\Throwable) {
            // Successful student output is never failed by monitoring state.
        }
    }
}
