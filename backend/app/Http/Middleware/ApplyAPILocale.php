<?php

namespace App\Http\Middleware;

use App\Support\RoknLocale;
use App\Support\BusinessClock;
use Closure;
use Illuminate\Http\JsonResponse;

class ApplyAPILocale
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $locale = RoknLocale::fromRequest($request);
        app()->setLocale($locale);

        $response = $next($request);
        if ($response instanceof JsonResponse && !$response->headers->has('ETag')) {
            $body = $response->getData(true);
            if (is_array($body) && !array_key_exists('server_time', $body)) {
                // Non-versioned manual and legacy controllers share the same
                // clock contract as ApiResponseService. ETagged bodies stay
                // byte-stable and clients use their HTTP Date header instead.
                $body['server_time'] = BusinessClock::utcNow()->toIso8601String();
                $response->setData($body);
            }
        }
        $response->headers->set('Content-Language', $locale);
        $vary = array_filter(array_map('trim', explode(',', (string) $response->headers->get('Vary'))));
        $normalizedVary = array_map('strtolower', $vary);
        if (!in_array('*', $vary, true) && !in_array('accept-language', $normalizedVary, true)) {
            $vary[] = 'Accept-Language';
        }
        $response->headers->set('Vary', implode(', ', $vary));

        return $response;
    }
}
