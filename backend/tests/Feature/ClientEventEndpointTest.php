<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ClientEvent;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClientEventEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('client_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('client_event_id')->unique();
            $table->string('event_name', 48);
            $table->string('severity', 12)->default('info');
            $table->string('app_version', 32)->nullable();
            $table->unsignedInteger('build_number')->nullable();
            $table->string('platform', 12);
            $table->unsignedTinyInteger('os_major')->nullable();
            $table->string('device_tier', 12)->default('unknown');
            $table->string('network_type', 12)->nullable();
            $table->string('screen_key', 64)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->char('error_fingerprint', 64)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('received_at')->useCurrent();
        });

        $this->clearRateLimits('127.0.0.1');
    }

    protected function tearDown(): void
    {
        $this->clearRateLimits('127.0.0.1');
        Schema::dropIfExists('client_events');

        parent::tearDown();
    }

    public function test_valid_event_is_accepted_persisted_and_idempotent(): void
    {
        $eventId = (string) Str::uuid();
        $payload = $this->validPayload($eventId);

        $this->postJson('/api/v1/client-events', $payload)
            ->assertAccepted()
            ->assertJsonPath('status', 202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'تم حفظ الحدث')
            ->assertJsonPath('data.accepted', true)
            ->assertJsonPath('accepted', true);

        $this->assertDatabaseHas('client_events', [
            'client_event_id' => $eventId,
            'event_name' => 'video_failure',
            'error_code' => 'BUNNY_TIMEOUT',
        ]);

        $this->postJson('/api/v1/client-events', [
            ...$payload,
            'error_code' => 'DIFFERENT_RETRY_VALUE',
        ])->assertAccepted();

        self::assertSame(1, ClientEvent::query()->where('client_event_id', $eventId)->count());
        self::assertSame(
            'BUNNY_TIMEOUT',
            ClientEvent::query()->where('client_event_id', $eventId)->value('error_code')
        );
    }

    public function test_unknown_event_and_free_form_fields_are_rejected(): void
    {
        $this->postJson('/api/v1/client-events', [
            ...$this->validPayload((string) Str::uuid()),
            'event_name' => 'student_email_exported',
        ])->assertUnprocessable()->assertJsonValidationErrors('event_name');

        $this->postJson('/api/v1/client-events', [
            ...$this->validPayload((string) Str::uuid()),
            'message' => 'free-form content must never be collected',
            'stack' => 'private implementation detail',
        ])->assertUnprocessable()->assertJsonValidationErrors('payload');

        self::assertSame(0, ClientEvent::query()->count());
    }

    public function test_endpoint_is_rate_limited_per_source(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.77']);
        $this->clearRateLimits('198.51.100.77');

        for ($request = 1; $request <= 30; $request++) {
            $this->postJson(
                '/api/v1/client-events',
                $this->validPayload((string) Str::uuid())
            )->assertAccepted();
        }

        $this->postJson(
            '/api/v1/client-events',
            $this->validPayload((string) Str::uuid())
        )->assertTooManyRequests();

        $this->clearRateLimits('198.51.100.77');
    }

    /** @return array<string, int|string> */
    private function validPayload(string $eventId): array
    {
        return [
            'client_event_id' => $eventId,
            'event_name' => 'video_failure',
            'severity' => 'error',
            'app_version' => '1.0.9',
            'build_number' => 109,
            'platform' => 'android',
            'os_major' => 15,
            'device_tier' => 'mid',
            'network_type' => 'wifi',
            'screen_key' => 'course.player',
            'error_code' => 'BUNNY_TIMEOUT',
            'error_fingerprint' => hash('sha256', 'stable-video-failure'),
            'occurred_at' => now()->subMinute()->toIso8601String(),
        ];
    }

    private function clearRateLimits(string $ip): void
    {
        RateLimiter::clear("client-events:{$ip}:minute");
        RateLimiter::clear("client-events:{$ip}:day");
    }
}
