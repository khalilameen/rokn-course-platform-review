<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

final class OpenRouterService
{
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

        if (Cache::has('openrouter:circuit-open')) {
            throw new \RuntimeException('AI provider is temporarily unavailable.');
        }

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->withHeaders([
                'HTTP-Referer' => (string) config('app.url'),
                'X-Title' => (string) config('app.name', 'Rokn'),
            ])
            ->timeout((int) config('openrouter.timeout_seconds', 20))
            ->post((string) config('openrouter.endpoint'), [
                'model' => $model,
                'messages' => $messages,
                'temperature' => max(0, min(1.2, $temperature)),
                'max_tokens' => max(80, min((int) config('openrouter.max_tokens', 500), $maxTokens)),
                'provider' => [
                    'data_collection' => 'deny',
                    'zdr' => true,
                ],
            ]);

        if (!$response->successful()) {
            if ($response->status() === 402 || $response->status() === 429 || $response->serverError()) {
                Cache::put('openrouter:circuit-open', true, now()->addSeconds(30));
            }
            throw new \RuntimeException('AI provider is temporarily unavailable.');
        }

        $body = $response->json();
        $content = data_get($body, 'choices.0.message.content');
        if (!is_string($content) || trim($content) === '') {
            throw new \RuntimeException('AI provider returned an empty response.');
        }

        return [
            'message' => trim($content),
            'provider_request_id' => data_get($body, 'id'),
            // OpenRouter includes normalized token and cost accounting in the
            // response. Persist the real amount; never infer margin from a
            // model name that can change price later.
            'usage' => [
                'prompt_tokens' => max(0, (int) data_get($body, 'usage.prompt_tokens', 0)),
                'completion_tokens' => max(0, (int) data_get($body, 'usage.completion_tokens', 0)),
                'total_tokens' => max(0, (int) data_get($body, 'usage.total_tokens', 0)),
                'cost' => max(0, (float) data_get($body, 'usage.cost', 0)),
            ],
        ];
    }
}
