<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\ProductionCapabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class OperationalHealthController extends Controller
{
    public function live(): JsonResponse
    {
        $time = now()->toIso8601String();

        return response()->json([
            'status' => 'ok',
            'success' => true,
            'message' => 'Service is live',
            'data' => [
                'health_status' => 'ok',
                'time' => $time,
            ],
            'time' => $time,
        ]);
    }

    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->databaseIsReady(),
            'cache' => $this->cacheIsReady(),
        ];
        $ready = !in_array(false, $checks, true);

        $status = $ready ? 'ready' : 'unavailable';
        $time = now()->toIso8601String();

        return response()->json([
            'status' => $status,
            'success' => $ready,
            'message' => $ready ? 'Service is ready' : 'Service is unavailable',
            'data' => [
                'health_status' => $status,
                'checks' => $checks,
                'time' => $time,
            ],
            'checks' => $checks,
            'time' => $time,
        ], $ready ? 200 : 503);
    }

    /**
     * Deployment/launch gate. Unlike traffic readiness, this deliberately
     * fails when a configured product capability is incomplete. Load
     * balancers must use /health/ready, not this endpoint.
     */
    public function launchReady(ProductionCapabilityService $capabilities): JsonResponse
    {
        $report = $capabilities->report();
        $checks = [
            'database' => $this->databaseIsReady(),
            'cache' => $this->cacheIsReady(),
            'bunny_stream' => (bool) data_get($report, 'capabilities.bunny.stream.ready'),
            'bunny_upload' => (bool) data_get($report, 'capabilities.bunny.upload.ready'),
            'bunny_playback' => (bool) data_get($report, 'capabilities.bunny.playback.ready'),
            'bunny_signing' => (bool) data_get($report, 'capabilities.bunny.signing.ready'),
            'payment' => (bool) data_get($report, 'capabilities.payment.ready'),
            'ai' => (bool) data_get($report, 'capabilities.ai.ready'),
            'queue' => (bool) data_get($report, 'capabilities.queue.ready'),
        ];
        $ready = !in_array(false, $checks, true);

        $status = $ready ? 'launch_ready' : 'launch_blocked';
        $time = now()->toIso8601String();

        return response()->json([
            'status' => $status,
            'success' => $ready,
            'message' => $ready ? 'Launch checks passed' : 'Launch checks failed',
            'data' => [
                'health_status' => $status,
                'checks' => $checks,
                'time' => $time,
            ],
            'checks' => $checks,
            'time' => $time,
        ], $ready ? 200 : 503);
    }

    private function databaseIsReady(): bool
    {
        try {
            DB::select('SELECT 1');

            return true;
        } catch (Throwable $exception) {
            return false;
        }
    }

    private function cacheIsReady(): bool
    {
        $key = 'health:'.bin2hex(random_bytes(8));
        try {
            Cache::put($key, 'ok', 10);
            $ready = Cache::get($key) === 'ok';
            Cache::forget($key);

            return $ready;
        } catch (Throwable $exception) {
            return false;
        }
    }
}
