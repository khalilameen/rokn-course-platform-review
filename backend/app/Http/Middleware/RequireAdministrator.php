<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class RequireAdministrator
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        abort_unless(
            $user
                && (bool) $user->active
                && hash_equals('admin', strtolower(trim((string) $user->role))),
            403
        );

        return $next($request);
    }
}
