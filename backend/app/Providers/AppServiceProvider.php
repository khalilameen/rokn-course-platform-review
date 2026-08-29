<?php

namespace App\Providers;

use App\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Factory as FirebaseFactory;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // We use only FCM from the Firebase Admin SDK. Binding the contract
        // directly keeps the integration small and avoids coupling the whole
        // application to a Laravel wrapper that cannot run on PHP 8.2 with
        // Laravel 12. Resolution is lazy, so CLI commands that do not send a
        // notification never require production credentials.
        $this->app->singleton(Messaging::class, static function (): Messaging {
            $encodedCredentials = trim((string) config('firebase.credentials.base64'));
            if ($encodedCredentials !== '') {
                $decodedCredentials = base64_decode($encodedCredentials, true);
                $credentials = is_string($decodedCredentials)
                    ? json_decode($decodedCredentials, true)
                    : null;

                if (!is_array($credentials)) {
                    throw new \RuntimeException('Firebase credentials are malformed.');
                }
            } else {
                $credentials = trim((string) config('firebase.credentials.file'));
                if ($credentials === '' || !is_file($credentials) || !is_readable($credentials)) {
                    throw new \RuntimeException('Firebase credentials are missing or unreadable.');
                }
            }

            return (new FirebaseFactory())
                ->withServiceAccount($credentials)
                ->createMessaging();
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // Cloud terminates TLS before the request reaches PHP. Generate
        // redirects and signed URLs from the configured public origin without
        // broadening the trusted-proxy allow-list.
        if (parse_url((string) config('app.url'), PHP_URL_SCHEME) === 'https') {
            URL::forceScheme('https');
        }

        // Symfony can resolve the host while constructing a request, before
        // the HTTP middleware stack runs. Install the allow-list during app
        // boot as well as in the middleware so long-lived workers never parse
        // a new request using the previous request's host policy.
        $hosts = $this->app->make(TrustHosts::class)->hosts();
        if ($hosts !== []) {
            Request::setTrustedHosts(array_filter($hosts));
        }

        Paginator::useBootstrap();
    }
}
