<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class AssignRequestIdentity
{
    public function handle(Request $request, Closure $next): Response
    {
        $candidate = trim((string) $request->header('X-Request-ID'));
        $requestId = Str::isUuid($candidate) ? strtolower($candidate) : (string) Str::uuid();

        $request->headers->set('X-Request-ID', $requestId);
        $request->attributes->set('request_id', $requestId);
        Log::withContext(['request_id' => $requestId]);

        $response = $next($request);
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }
}
