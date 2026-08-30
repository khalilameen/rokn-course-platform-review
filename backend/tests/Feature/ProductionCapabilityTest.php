<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RecordQueueHeartbeat;
use App\Services\ProductionCapabilityService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ProductionCapabilityTest extends TestCase
{
    private const REQUIRED_QUEUES = ['default', 'notifications', 'ai-feedback', 'webhooks'];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('bunny_enabled')->default(false);
            $table->timestamps();
        });

        DB::table('settings')->insert([
            'bunny_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        config([
            'app.env' => 'production',
            'cache.default' => 'array',
            'queue.default' => 'redis',
            'bunny.stream_api_key' => 'stream-secret',
            'bunny.library_id' => '1234',
            'bunny.cdn_hostname' => 'cdn.production.test',
            'bunny.token_auth_key' => 'signing-secret',
            'bunny.storage_zone' => 'production-assets',
            'bunny.storage_password' => 'storage-secret',
            'bunny.storage_cdn_hostname' => 'assets.production.test',
            'bunny.storage_token_auth_key' => 'asset-signing-secret',
            'bunny.connect_timeout_seconds' => 15,
            'bunny.upload_timeout_seconds' => 3600,
            'kashier.mode' => 'live',
            'kashier.live.api_key' => 'payment-secret',
            'kashier.live.secret_key' => 'dashboard-secret',
            'kashier.live.mid' => 'MID-1',
            'kashier.live.base_url' => 'https://checkout.kashier.io',
            'openrouter.api_key' => 'ai-secret',
            'openrouter.default_model' => 'provider/model',
            'openrouter.allowed_models' => ['provider/model'],
            'openrouter.global_daily_request_limit' => 100,
            'openrouter.global_daily_token_budget' => 10000,
            'openrouter.global_monthly_token_budget' => 100000,
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.production.test',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.username' => 'mailer',
            'mail.mailers.smtp.password' => 'mail-secret',
            'mail.from.address' => 'hello@production.test',
            'operations.queue_heartbeat_key' => 'test:queue-heartbeat',
            'operations.queue_heartbeat_required_queues' => self::REQUIRED_QUEUES,
            'operations.queue_heartbeat_ttl_seconds' => 600,
            'operations.queue_heartbeat_max_age_seconds' => 180,
        ]);

        $this->clearQueueHeartbeats();
    }

    protected function tearDown(): void
    {
        $this->clearQueueHeartbeats();
        Schema::dropIfExists('settings');
        parent::tearDown();
    }

    public function test_default_queue_heartbeat_alone_cannot_make_launch_ready(): void
    {
        $heartbeat = new RecordQueueHeartbeat('default');
        self::assertSame('default', $heartbeat->queue);
        $heartbeat->handle();

        $report = app(ProductionCapabilityService::class)->report();

        self::assertFalse($report['ready']);
        self::assertTrue($report['capabilities']['queue']['queues']['default']['ready']);
        self::assertFalse($report['capabilities']['queue']['queues']['notifications']['ready']);
        self::assertFalse($report['capabilities']['queue']['queues']['ai-feedback']['ready']);
        self::assertFalse($report['capabilities']['queue']['queues']['webhooks']['ready']);
        self::assertNotNull(Cache::get('test:queue-heartbeat'));

        $this->getJson('/api/health/launch-ready')
            ->assertStatus(503)
            ->assertJsonPath('checks.queue', false);
    }

    public function test_complete_contract_requires_completed_heartbeat_on_every_queue(): void
    {
        $this->recordAllQueueHeartbeats();

        $report = app(ProductionCapabilityService::class)->report();

        self::assertTrue($report['ready']);
        self::assertTrue($report['capabilities']['bunny']['stream']['ready']);
        self::assertTrue($report['capabilities']['bunny']['upload']['ready']);
        self::assertTrue($report['capabilities']['bunny']['playback']['ready']);
        self::assertTrue($report['capabilities']['bunny']['signing']['ready']);
        self::assertTrue($report['capabilities']['bunny']['assets']['ready']);
        self::assertTrue($report['capabilities']['payment']['ready']);
        self::assertTrue($report['capabilities']['ai']['ready']);
        self::assertTrue($report['capabilities']['mail']['ready']);
        self::assertTrue($report['capabilities']['queue']['ready']);
        self::assertSame(self::REQUIRED_QUEUES, $report['capabilities']['queue']['required_queues']);
        foreach (self::REQUIRED_QUEUES as $queue) {
            self::assertTrue($report['capabilities']['queue']['queues'][$queue]['ready']);
        }

        $response = $this->getJson('/api/health/launch-ready')->assertOk();
        $response->assertJsonPath('status', 'launch_ready')
            ->assertJsonPath('checks.bunny_assets', true)
            ->assertJsonPath('checks.queue', true)
            ->assertJsonPath('checks.mail', true)
            ->assertJsonMissing(['reason'])
            ->assertDontSee('stream-secret')
            ->assertDontSee('payment-secret')
            ->assertDontSee('dashboard-secret')
            ->assertDontSee('ai-secret')
            ->assertDontSee('mail-secret');
    }

    public function test_missing_or_stale_worker_heartbeat_fails_readiness(): void
    {
        Cache::put('test:queue-heartbeat', now()->subMinutes(10)->toIso8601String(), 600);

        $report = app(ProductionCapabilityService::class)->report();
        self::assertFalse($report['ready']);
        self::assertFalse($report['capabilities']['queue']['ready']);

        // Worker failure blocks launch diagnostics, but must not evict every
        // web instance from a load balancer and turn degradation into outage.
        $this->getJson('/api/health/launch-ready')
            ->assertStatus(503)
            ->assertJsonPath('checks.queue', false);
        $this->getJson('/api/health/ready')
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonMissingPath('checks.queue');
    }

    public function test_playback_signing_is_an_independent_required_capability(): void
    {
        $this->recordAllQueueHeartbeats();
        config(['bunny.token_auth_key' => null]);

        $report = app(ProductionCapabilityService::class)->report();

        self::assertTrue($report['capabilities']['bunny']['stream']['ready']);
        self::assertTrue($report['capabilities']['bunny']['playback']['ready']);
        self::assertFalse($report['capabilities']['bunny']['signing']['ready']);
        self::assertFalse($report['ready']);
    }

    public function test_storage_delivery_is_an_independent_required_capability(): void
    {
        $this->recordAllQueueHeartbeats();
        config(['bunny.storage_token_auth_key' => null]);

        $report = app(ProductionCapabilityService::class)->report();

        self::assertTrue($report['capabilities']['bunny']['playback']['ready']);
        self::assertFalse($report['capabilities']['bunny']['assets']['ready']);
        self::assertFalse($report['ready']);

        $this->getJson('/api/health/launch-ready')
            ->assertServiceUnavailable()
            ->assertJsonPath('checks.bunny_assets', false);
    }

    public function test_missing_transactional_mail_credentials_block_launch(): void
    {
        $this->recordAllQueueHeartbeats();
        config(['mail.mailers.smtp.password' => null]);

        $report = app(ProductionCapabilityService::class)->report();

        self::assertFalse($report['capabilities']['mail']['ready']);
        self::assertFalse($report['ready']);
        $this->getJson('/api/health/launch-ready')
            ->assertStatus(503)
            ->assertJsonPath('checks.mail', false);
    }

    public function test_legacy_key_only_falls_back_for_the_default_queue(): void
    {
        Cache::put('test:queue-heartbeat', now()->toIso8601String(), 600);
        foreach (array_slice(self::REQUIRED_QUEUES, 1) as $queue) {
            (new RecordQueueHeartbeat($queue))->handle();
        }

        $report = app(ProductionCapabilityService::class)->report();

        self::assertTrue($report['capabilities']['queue']['ready']);
        foreach (self::REQUIRED_QUEUES as $queue) {
            self::assertTrue($report['capabilities']['queue']['queues'][$queue]['ready']);
        }
    }

    public function test_dispatch_command_targets_every_configured_queue(): void
    {
        Queue::fake();

        $this->artisan('ops:dispatch-queue-heartbeats')->assertSuccessful();

        Queue::assertPushed(RecordQueueHeartbeat::class, count(self::REQUIRED_QUEUES));
        foreach (self::REQUIRED_QUEUES as $queue) {
            Queue::assertPushed(
                RecordQueueHeartbeat::class,
                static fn (RecordQueueHeartbeat $job): bool => $job->heartbeatQueue === $queue
                    && $job->queue === $queue
            );
        }
    }

    public function test_heartbeat_dispatch_is_a_non_blocking_scheduled_command(): void
    {
        $schedule = app(ConsoleKernel::class)->resolveConsoleSchedule();
        $event = collect($schedule->events())->first(
            static fn (Event $candidate): bool => str_contains(
                (string) $candidate->command,
                'ops:dispatch-queue-heartbeats'
            )
        );

        self::assertInstanceOf(Event::class, $event);
        self::assertTrue($event->runInBackground);
        self::assertTrue($event->withoutOverlapping);
        self::assertTrue($event->onOneServer);
    }

    private function recordAllQueueHeartbeats(): void
    {
        foreach (self::REQUIRED_QUEUES as $queue) {
            $heartbeat = new RecordQueueHeartbeat($queue);
            self::assertSame($queue, $heartbeat->queue);
            $heartbeat->handle();
        }
    }

    private function clearQueueHeartbeats(): void
    {
        Cache::forget('test:queue-heartbeat');
        foreach (self::REQUIRED_QUEUES as $queue) {
            Cache::forget(RecordQueueHeartbeat::cacheKey($queue));
        }
    }
}
