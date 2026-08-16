<?php

namespace App\Http\Middleware;

use App\Models\AdminAuditLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class AuditAdminMutation
{
    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    private const SENSITIVE_FIELDS = [
        'password', 'password_confirmation', 'current_password',
        'token', 'api_token', 'access_token', 'secret',
        'code', 'one_time_code', 'recovery_code',
        'bunny_api_key', 'bunny_storage_password', 'bunny_security_key',
    ];

    public function handle(Request $request, Closure $next)
    {
        $requestId = $this->requestId($request);
        $request->headers->set('X-Request-ID', $requestId);

        $response = $next($request);
        $response->headers->set('X-Request-ID', $requestId);

        if (!in_array(strtoupper($request->method()), self::MUTATING_METHODS, true)) {
            return $response;
        }

        try {
            if (!Schema::hasTable('admin_audit_logs') || !$request->user()) {
                return $response;
            }

            $fields = collect(array_keys($request->except(self::SENSITIVE_FIELDS)))
                ->filter(fn ($field) => is_string($field) && strlen($field) <= 100)
                ->values()
                ->all();
            $parameters = collect($request->route()?->parameters() ?? [])
                ->map(function ($value) {
                    if (is_object($value) && method_exists($value, 'getKey')) {
                        return (string) $value->getKey();
                    }
                    return is_scalar($value) ? (string) $value : get_debug_type($value);
                })
                ->all();

            AdminAuditLog::query()->create([
                'request_id' => $requestId,
                'actor_id' => $request->user()->id,
                'actor_role' => (string) $request->user()->role,
                'route_name' => $request->route()?->getName(),
                'http_method' => strtoupper($request->method()),
                'path' => Str::limit($request->path(), 500, ''),
                'route_parameters' => $parameters,
                // Field names are enough for traceability. Never duplicate
                // credentials or personal values into the audit trail.
                'request_fields' => $fields,
                'response_status' => $response->getStatusCode(),
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            // A logging outage is reported, but must not make a successful
            // browser mutation retry and execute twice.
            report($exception);
        }

        return $response;
    }

    private function requestId(Request $request): string
    {
        $candidate = trim((string) $request->header('X-Request-ID'));
        if ($candidate !== '' && preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $candidate) === 1) {
            return $candidate;
        }

        return (string) Str::uuid();
    }
}
