<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\OpenRouterService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class OpenRouterCostAccountingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('openrouter:circuit-open');
        config()->set('openrouter.api_key', 'test-key');
        config()->set('openrouter.allowed_models', ['test/model']);
        config()->set('openrouter.endpoint', 'https://openrouter.test/chat');
        config()->set('openrouter.timeout_seconds', 5);
        config()->set('openrouter.max_tokens', 500);
        config()->set('openrouter.reasoning_effort', 'none');
    }

    public function test_zero_provider_cost_is_preserved_as_a_real_cost_fact(): void
    {
        Http::fake(['openrouter.test/*' => Http::response([
            'id' => 'generation-free',
            'choices' => [['message' => ['content' => 'answer']]],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
                'total_tokens' => 15,
                'cost' => 0,
            ],
        ])]);

        $result = app(OpenRouterService::class)->chat(
            'test/model',
            [['role' => 'user', 'content' => 'question']],
            0.2,
            100
        );

        self::assertEquals(0.0, $result['usage']['cost']);
        self::assertTrue($result['usage']['cost_reported']);
    }

    public function test_missing_provider_cost_is_marked_for_reservation_fallback(): void
    {
        Http::fake(['openrouter.test/*' => Http::response([
            'id' => 'generation-without-cost',
            'choices' => [['message' => ['content' => 'answer']]],
            'usage' => ['total_tokens' => 15],
        ])]);

        $result = app(OpenRouterService::class)->chat(
            'test/model',
            [['role' => 'user', 'content' => 'question']],
            0.2,
            100
        );

        self::assertFalse($result['usage']['cost_reported']);
    }

    public function test_course_chat_reserves_the_budget_for_visible_output(): void
    {
        Http::fake(['openrouter.test/*' => Http::response([
            'id' => 'generation-visible-output',
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => ['content' => 'answer'],
            ]],
            'usage' => ['total_tokens' => 15, 'cost' => 0.001],
        ])]);

        app(OpenRouterService::class)->chat(
            'test/model',
            [['role' => 'user', 'content' => 'question']],
            0.2,
            100
        );

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $request->url() === 'https://openrouter.test/chat'
                && ($payload['max_completion_tokens'] ?? null) === 100
                && !array_key_exists('max_tokens', $payload)
                && ($payload['reasoning'] ?? null) === [
                    'effort' => 'none',
                    'exclude' => true,
                ];
        });
    }

    public function test_text_parts_are_joined_without_exposing_reasoning_parts(): void
    {
        Http::fake(['openrouter.test/*' => Http::response([
            'id' => 'generation-content-parts',
            'choices' => [['message' => ['content' => [
                ['type' => 'reasoning', 'text' => 'private chain of thought'],
                ['type' => 'text', 'text' => 'السطر الأول'],
                ['type' => 'output_text', 'text' => 'السطر الثاني'],
            ]]]],
            'usage' => ['total_tokens' => 15, 'cost' => 0.001],
        ])]);

        $result = app(OpenRouterService::class)->chat(
            'test/model',
            [['role' => 'user', 'content' => 'question']],
            0.2,
            100
        );

        self::assertSame("السطر الأول\nالسطر الثاني", $result['message']);
        self::assertStringNotContainsString('private chain of thought', $result['message']);
    }
}
