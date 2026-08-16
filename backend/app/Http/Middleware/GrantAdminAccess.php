<?php

namespace App\Http\Middleware;

use App\Auth\AdminPermissionMatrix;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class GrantAdminAccess
{
    public function __construct(private readonly AdminPermissionMatrix $permissions)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (!$user) {
            return redirect()->route('login');
        }
        if (!(bool) $user->active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        abort_unless(
            $this->permissions->allows(
                (string) $user->role,
                $request->route()?->getName(),
                $request->method()
            ),
            403
        );

        return $next($request);
    }
}
