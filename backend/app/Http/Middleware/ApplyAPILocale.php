<?php

namespace App\Http\Middleware;

use Closure;

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
        $locale = $request->header('locale') 
            ?? $request->header('Accept-Language') 
            ?? 'ar';

        if (str_starts_with(strtolower((string)$locale), 'en')) {
            $locale = 'en';
        } else {
            $locale = 'ar';
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
