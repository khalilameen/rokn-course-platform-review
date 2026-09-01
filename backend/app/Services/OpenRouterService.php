<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AiProviderUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class OpenRouterService
{
    public const CIRCUIT_KEY = 'openrouter:circuit-open';
    private const FAILURE_KEY = 'openrouter:circuit-failures';

    public function chat(string $model, array $messages, float $temperature, int $maxTokens): array
    {
        $apiKey = (string) config('openrouter.api_key');
        if ($apiKey === '' || $model === '') {
            throw new \RuntimeException('AI service is not configured.');
        }

        $allowed = array_values(array_filter(config('openrouter.allowed_models', [])));
        if ($allowed === [] || !in_array($model, $allowed, true)) {
            throw new \RuntimeException('AI model is not in the production allowlist.');
        }

        if ($this->circuitIsOpen()) {
            throw new AiProviderUnavailableException(true);
        }

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->withHeaders([
                    'HTTP-Referer' => (string) config('app.url'),
                    'X-Title' => (string) config('app.name', 'Rokn'),
                ])
                ->connectTimeout(max(1, (int) config('openrouter.connect_timeout_seconds', 5)))
                ->timeout((int) config('openrouter.timeout_seconds', 20))
                ->post((string) config('openrouter.endpoint'), [
                    'model' => $model,
                    'messages' => $messages,
                    'temperature' => max(0, min(1.2, $temperature)),
                    'max_completion_tokens' => max(
                        80,
                        min((int) config('openrouter.max_tokens', 500), $maxTokens)
                    ),
                    'reasoning' => [
                        'effort' => $this->reasoningEffort(),
                        'exclude' => true,
                    ],
                    'provider' => [
                        'data_collection' => 'deny',
                        'zdr' => true,
                    ],
                ]);
        } catch (ConnectionException $exception) {
            $this->recordTransientFailure('connection');
            // A timeout may happen after the provider accepted and billed the
            // request. Do not issue a blind second paid call.
            throw new AiProviderUnavailableException(false, previous: $exception);
        }

        if (!$response->successful()) {
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
                $response->status() === 429 || $response->serverError()
            );
        }

        $this->recordSuccess();

        $body = $response->json();
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
            throw new \RuntimeException('AI provider returned an empty response.');
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
            throw new \RuntimeException('AI provider returned an unusable response.');
        }

        $providerCost = data_get($body, 'usage.cost');

        return [
            'message' => $content,
            'provider_request_id' => data_get($body, 'id'),
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
        ];
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

    private function reasoningEffort(): string
    {
        $effort = strtolower(trim((string) config('openrouter.reasoning_effort', 'none')));

        return in_array(
            $effort,
            ['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'],
            true
        ) ? $effort : 'none';
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
