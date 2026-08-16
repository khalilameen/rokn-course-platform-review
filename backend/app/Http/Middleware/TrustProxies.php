<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * @var array|string
     */
    protected $proxies;

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;

    public function handle(Request $request, Closure $next)
    {
        // Trust only explicitly configured edge addresses. With an empty or
        // invalid list, Laravel safely ignores forwarded client IP/proto data.
        $this->proxies = array_values(array_filter(
            (array) config('trusted_proxies.proxies', []),
            fn ($proxy) => $this->isSafeProxyDefinition((string) $proxy)
        ));

        return parent::handle($request, $next);
    }

    private function isSafeProxyDefinition(string $proxy): bool
    {
        $proxy = trim($proxy);
        if ($proxy === '' || in_array($proxy, ['*', '0.0.0.0/0', '::/0'], true)) {
            return false;
        }
        if (!str_contains($proxy, '/')) {
            return filter_var($proxy, FILTER_VALIDATE_IP) !== false;
        }

        [$network, $prefix] = array_pad(explode('/', $proxy, 2), 2, null);
        if (filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return ctype_digit((string) $prefix) && (int) $prefix >= 8 && (int) $prefix <= 32;
        }
        if (filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return ctype_digit((string) $prefix) && (int) $prefix >= 32 && (int) $prefix <= 128;
        }

        return false;
    }
}
