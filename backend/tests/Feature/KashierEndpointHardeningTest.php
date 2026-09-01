<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class KashierEndpointHardeningTest extends TestCase
{
    public function test_real_kashier_routes_have_distinct_named_limiters(): void
    {
        $callback = Route::getRoutes()->getByName('payment.callback');
        $webhook = Route::getRoutes()->getByName('payment.webhook');
        $reconcile = Route::getRoutes()->getByName('api.payment.reconcile');

        self::assertNotNull($callback);
        self::assertNotNull($webhook);
        self::assertNotNull($reconcile);
        self::assertContains('throttle:kashier-callback', $callback->gatherMiddleware());
        self::assertContains('throttle:kashier-webhook', $webhook->gatherMiddleware());
        self::assertContains('throttle:payment-reconcile', $reconcile->gatherMiddleware());
        self::assertNotContains('throttle:payment-write', $reconcile->gatherMiddleware());
    }

    public function test_webhook_limiter_preserves_signature_input_and_limits_per_order(): void
    {
        config([
            'rate_limits.kashier_webhook_order_per_minute' => 1,
            'rate_limits.kashier_webhook_ip_per_minute' => 10,
            'rate_limits.kashier_webhook_ip_per_hour' => 10,
        ]);
        Route::post('/_kashier-webhook-limit-test', function (Request $request) {
            return response()->json([
                'signature' => $request->input('signature'),
                'orderId' => $request->input('orderId'),
            ]);
        })->middleware('throttle:kashier-webhook');

        $payload = ['orderId' => 'ORDER-ONE', 'signature' => 'signed-payload-is-unchanged'];
        $this->postJson('/_kashier-webhook-limit-test', $payload)
            ->assertOk()
            ->assertJson($payload);
        $this->postJson('/_kashier-webhook-limit-test', $payload)->assertStatus(429);

        $this->postJson('/_kashier-webhook-limit-test', [
            'orderId' => 'ORDER-TWO',
            'signature' => 'second-signature-is-unchanged',
        ])->assertOk()->assertJsonPath('signature', 'second-signature-is-unchanged');
    }

    public function test_callback_limiter_uses_order_and_ip_buckets(): void
    {
        config([
            'rate_limits.kashier_callback_order_per_minute' => 1,
            'rate_limits.kashier_callback_ip_per_minute' => 3,
        ]);
        Route::get('/_kashier-callback-limit-test', fn () => response('ok'))
            ->middleware('throttle:kashier-callback');

        $this->get('/_kashier-callback-limit-test?orderId=ORDER-ONE')->assertOk();
        $this->get('/_kashier-callback-limit-test?orderId=ORDER-ONE')->assertStatus(429);
        $this->get('/_kashier-callback-limit-test?orderId=ORDER-TWO')->assertOk();
    }
}
