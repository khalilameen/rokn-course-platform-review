<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Traits\NotifyUsers;
use App\Traits\SendMessage;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class NotifyUsersTest extends TestCase
{
    public function test_empty_token_list_does_not_resolve_the_messaging_client(): void
    {
        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldNotReceive('send');
        $this->app->instance(Messaging::class, $messaging);

        NotifyUsers::sendAndroid([], ['message' => 'Ignored']);

        self::assertTrue(true);
    }

    public function test_failed_delivery_logs_only_a_token_fingerprint(): void
    {
        $token = 'private-device-token';
        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldReceive('send')
            ->once()
            ->andThrow(new RuntimeException('provider unavailable'));
        $this->app->instance(Messaging::class, $messaging);
        Log::spy();

        NotifyUsers::sendIos([$token], [
            'title' => 'Rokn',
            'message' => 'New lesson',
        ]);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(static function (string $message, array $context) use ($token): bool {
                return $message === 'FCM send failed in NotifyUsers'
                    && ($context['token_fingerprint'] ?? null)
                        === substr(hash('sha256', $token), 0, 12)
                    && ! array_key_exists('token', $context)
                    && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), $token);
            });
    }

    public function test_message_delivery_also_redacts_the_device_token(): void
    {
        $token = 'private-provider-device-token';
        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldReceive('send')
            ->once()
            ->andThrow(new RuntimeException('provider unavailable'));
        $this->app->instance(Messaging::class, $messaging);
        Log::spy();

        SendMessage::sendAndroid([$token], [
            'title' => 'Rokn',
            'message' => 'Update',
            'user' => ['id' => 42],
        ]);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(static function (string $message, array $context) use ($token): bool {
                return $message === 'FCM send failed in SendMessage'
                    && ($context['token_fingerprint'] ?? null)
                        === substr(hash('sha256', $token), 0, 12)
                    && ! array_key_exists('token', $context)
                    && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), $token);
            });
    }
}
