<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * This namespace is applied to your controller routes.
     *
     * In addition, it is set as the URL generator's root namespace.
     *
     * @var string
     */
    protected $namespace = 'App\Http\Controllers';

    /**
     * The path to the "home" route for your application.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        $this->configureRateLimiting();

        parent::boot();
    }

    /**
     * Keep ordinary catalogue and learning reads responsive while placing
     * tighter boundaries around authentication and money-moving endpoints.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            $identity = $this->rateLimitIdentity($request);
            $ip = $request->ip() ?: 'unknown';

            if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
                return [
                    Limit::perMinute(max(1, (int) config('rate_limits.api_read_identity_per_minute', 240)))
                        ->by('read:identity:'.$identity),
                    Limit::perMinute(max(1, (int) config('rate_limits.api_read_ip_per_minute', 1200)))
                        ->by('read:ip:'.$ip),
                ];
            }

            return [
                Limit::perMinute(max(1, (int) config('rate_limits.api_write_identity_per_minute', 90)))
                    ->by('write:identity:'.$identity),
                Limit::perMinute(max(1, (int) config('rate_limits.api_write_ip_per_minute', 450)))
                    ->by('write:ip:'.$ip),
            ];
        });

        RateLimiter::for('auth-api', function (Request $request) {
            return Limit::perMinute(12)->by('auth:'.$request->ip());
        });

        RateLimiter::for('admin-login-route', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email')));
            $identity = hash('sha256', $email . '|' . ($request->ip() ?: 'unknown'));

            return Limit::perMinute(12)->by('admin-login-route:' . $identity);
        });

        RateLimiter::for('admin-password-reset', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email')));
            $identity = hash('sha256', $email . '|' . ($request->ip() ?: 'unknown'));

            return Limit::perMinute(5)->by('admin-password-reset:' . $identity);
        });

        RateLimiter::for('admin-mfa', function (Request $request) {
            $userId = optional($request->user())->getAuthIdentifier() ?: 'guest';
            $ip = $request->ip() ?: 'unknown';

            return [
                Limit::perMinute(12)->by('admin-mfa:minute:'.$userId.':'.$ip),
                Limit::perHour(60)->by('admin-mfa:hour:'.$userId),
            ];
        });

        RateLimiter::for('kashier-callback', function (Request $request) {
            $order = $this->kashierOrderIdentity($request);
            $ip = $request->ip() ?: 'unknown';

            return [
                Limit::perMinute(max(1, (int) config('rate_limits.kashier_callback_order_per_minute', 30)))
                    // Couple an untrusted order identifier to its origin so a
                    // third party cannot exhaust a real customer's bucket.
                    ->by('kashier-callback:order:'.$order.':ip:'.$ip),
                Limit::perMinute(max(1, (int) config('rate_limits.kashier_callback_ip_per_minute', 120)))
                    ->by('kashier-callback:ip:'.$ip),
            ];
        });

        RateLimiter::for('kashier-webhook', function (Request $request) {
            $order = $this->kashierOrderIdentity($request);
            $ip = $request->ip() ?: 'unknown';

            return [
                Limit::perMinute(max(1, (int) config('rate_limits.kashier_webhook_order_per_minute', 30)))
                    ->by('kashier-webhook:order:'.$order.':ip:'.$ip),
                Limit::perMinute(max(1, (int) config('rate_limits.kashier_webhook_ip_per_minute', 300)))
                    ->by('kashier-webhook:ip-minute:'.$ip),
                Limit::perHour(max(1, (int) config('rate_limits.kashier_webhook_ip_per_hour', 2000)))
                    ->by('kashier-webhook:ip-hour:'.$ip),
            ];
        });

        RateLimiter::for('payment-write', function (Request $request) {
            $identity = $this->rateLimitIdentity($request);

            return Limit::perMinute(6)->by('payment-write:'.$identity);
        });

        RateLimiter::for('payment-read', function (Request $request) {
            $identity = $this->rateLimitIdentity($request);

            return Limit::perMinute(60)->by('payment-read:'.$identity);
        });

        RateLimiter::for('client-events', function (Request $request) {
            $identity = 'client-events:'.($request->ip() ?: 'unknown');

            return [
                // Distinct buckets are required: sharing one key makes each
                // request increment both limits and halves the minute quota.
                Limit::perMinute(30)->by($identity.':minute'),
                Limit::perDay(500)->by($identity.':day'),
            ];
        });

        RateLimiter::for('product-events', function (Request $request) {
            $session = (string) ($request->input('session_key') ?: $request->input('events.0.session_key'));
            $presentedToken = trim((string) $request->bearerToken());
            $identity = $presentedToken !== ''
                ? 'token:'.hash('sha256', $presentedToken)
                : ($session !== ''
                    ? 'session:'.hash('sha256', $session)
                    : 'ip:'.($request->ip() ?: 'unknown'));
            $ip = $request->ip() ?: 'unknown';

            return [
                Limit::perMinute(60)->by('product-events:minute:'.$identity),
                Limit::perDay(1500)->by('product-events:day:'.$identity),
                Limit::perDay(5000)->by('product-events:ip-day:'.$ip),
            ];
        });

        RateLimiter::for('catalog-search', function (Request $request) {
            return [
                Limit::perMinute(60)->by('catalog-search:minute:'.($request->ip() ?: 'unknown')),
                Limit::perDay(1500)->by('catalog-search:day:'.($request->ip() ?: 'unknown')),
            ];
        });

        RateLimiter::for('feedback', function (Request $request) {
            return [
                Limit::perMinute(5)->by('feedback:minute:'.($request->ip() ?: 'unknown')),
                Limit::perDay(30)->by('feedback:day:'.($request->ip() ?: 'unknown')),
            ];
        });
    }

    private function rateLimitIdentity(Request $request): string
    {
        $userId = optional($request->user())->getAuthIdentifier();
        if ($userId) {
            return 'user:'.$userId;
        }

        // The API group's limiter can execute before auth:api resolves the
        // current user. Hashing the presented bearer token avoids making a
        // whole university/workplace Wi-Fi share one IP bucket.
        $bearerToken = trim((string) $request->bearerToken());
        if ($bearerToken !== '') {
            return 'token:'.hash('sha256', $bearerToken);
        }

        return 'ip:'.($request->ip() ?: 'unknown');
    }

    private function kashierOrderIdentity(Request $request): string
    {
        foreach (['orderId', 'order_id', 'order_ref', 'merchantOrderId'] as $field) {
            $value = $request->input($field);
            if (is_scalar($value)) {
                $value = trim((string) $value);
                if ($value !== '') {
                    return hash('sha256', substr($value, 0, 128));
                }
            }
        }

        return 'missing';
    }

    /**
     * Define the routes for the application.
     *
     * @return void
     */
    public function map()
    {
        $this->mapApiRoutes();

        $this->mapWebRoutes();

        //
    }

    /**
     * Define the "web" routes for the application.
     *
     * These routes all receive session state, CSRF protection, etc.
     *
     * @return void
     */
    protected function mapWebRoutes()
    {
        $locale = request()->segment(1);
        if (! in_array($locale, config('app.locales'))) {
            $locale = '';
        }
        Route::middleware('web')
             ->namespace($this->namespace)
             ->prefix($locale)
             ->group(base_path('routes/web.php'));
    }

    /**
     * Define the "api" routes for the application.
     *
     * These routes are typically stateless.
     *
     * @return void
     */
    protected function mapApiRoutes()
    {
        Route::prefix('api')
             ->middleware('api')
             ->namespace($this->namespace.  '\API')
             ->group(base_path('routes/api.php'));
    }
}
