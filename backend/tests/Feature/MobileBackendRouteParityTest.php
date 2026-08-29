<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class MobileBackendRouteParityTest extends TestCase
{
    #[DataProvider('mobileRouteProvider')]
    public function test_every_mobile_route_resolves_in_versioned_and_legacy_contracts(
        string $method,
        string $path,
    ): void {
        foreach (['/api/v1/', '/api/'] as $prefix) {
            $route = $this->app['router']->getRoutes()->match(
                Request::create($prefix.$path, $method),
            );

            self::assertInstanceOf(Route::class, $route);
            self::assertSame($method, $route->methods()[0]);
            self::assertStringStartsWith('api/', $route->uri());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function mobileRouteProvider(): iterable
    {
        yield 'feature flags' => ['GET', 'product-features'];
        yield 'product events' => ['POST', 'product-events'];
        yield 'app version policy' => ['POST', 'app/check-version'];
        yield 'settings' => ['GET', 'settings'];
        yield 'authentication methods' => ['GET', 'auth-methods'];
        yield 'social login' => ['POST', 'social-login'];
        yield 'social auth completion' => ['POST', 'social-auth/complete'];
        yield 'account deletion' => ['POST', 'delete-account'];
        yield 'device token registration' => ['POST', 'user/device-token'];
        yield 'device sessions' => ['GET', 'user/sessions'];
        yield 'device session revocation' => ['DELETE', 'user/sessions/11111111-1111-4111-8111-111111111111'];
        yield 'course list' => ['GET', 'courses/list'];
        yield 'course search' => ['GET', 'search/courses'];
        yield 'course details' => ['GET', 'courses/1/details'];
        yield 'course authorization' => ['POST', 'courses/authorize'];
        yield 'course redemption' => ['POST', 'course-codes/redeem'];
        yield 'course chat' => ['POST', 'courses/1/chat'];
        yield 'full track quote' => ['GET', 'courses/1/full-track-upgrade'];
        yield 'full track purchase' => ['POST', 'courses/1/full-track-upgrade'];
        yield 'learning dashboard' => ['GET', 'learning/courses'];
        yield 'profile read' => ['GET', 'user/profile'];
        yield 'profile update' => ['PUT', 'user/profile'];
        yield 'legacy profile update' => ['POST', 'update_profile'];
        yield 'user paths' => ['GET', 'user/paths'];
        yield 'watch history read' => ['GET', 'user/watch-history'];
        yield 'watch history write' => ['POST', 'user/watch-history'];
        yield 'watch history clear' => ['DELETE', 'user/watch-history'];
        yield 'playback manifest' => ['POST', 'lessons/1/playback-manifest'];
        yield 'section completion' => ['POST', 'courses/1/sections/1/complete'];
        yield 'streaks' => ['GET', 'streaks'];
        yield 'certificates' => ['GET', 'certificates'];
        yield 'certificate recovery' => ['POST', 'certificates/1/issue'];
        yield 'notifications' => ['GET', 'notifications'];
        yield 'notification read' => ['POST', 'notifications/1/mark-read'];
        yield 'notifications read all' => ['POST', 'notifications/mark-all-read'];
        yield 'saved folders read' => ['GET', 'saved-folders'];
        yield 'saved folder create' => ['POST', 'saved-folders'];
        yield 'saved folder delete' => ['DELETE', 'saved-folders/1'];
        yield 'saved lesson folders' => ['GET', 'saved-lessons/1/folders'];
        yield 'saved lesson create' => ['POST', 'saved-folders/1/lessons'];
        yield 'saved lesson delete' => ['DELETE', 'saved-lessons/1'];
        yield 'project submission' => ['POST', 'projects/1/submissions'];
        yield 'project submission status' => ['GET', 'project-submissions/submission-id'];
        yield 'legacy project evaluation' => ['POST', 'projects/1/evaluate'];
        yield 'wallet' => ['GET', 'wallet'];
        yield 'daily reward' => ['POST', 'rewards/daily'];
        yield 'coin packages' => ['GET', 'packages'];
        yield 'coin earning methods' => ['GET', 'coin-earning-methods'];
        yield 'coin task start' => ['POST', 'coin-earning-methods/1/start'];
        yield 'coin task claim' => ['POST', 'claim-coins'];
        yield 'payment initiate' => ['POST', 'payment/initiate'];
        yield 'payment status' => ['GET', 'payment/status/order-reference'];
        yield 'portfolio profile read' => ['GET', 'portfolio-profile'];
        yield 'portfolio profile update' => ['PUT', 'portfolio-profile'];
        yield 'portfolio list' => ['GET', 'portfolio'];
        yield 'portfolio create' => ['POST', 'portfolio'];
        yield 'portfolio delete' => ['DELETE', 'portfolio/1'];
        yield 'portfolio eligible projects' => ['GET', 'portfolio/eligible-projects'];
        yield 'feedback' => ['POST', 'feedback'];
    }
}
