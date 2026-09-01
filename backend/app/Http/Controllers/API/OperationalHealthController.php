<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\ProductionCapabilityService;
use App\Services\AppReleasePolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class OperationalHealthController extends Controller
{
    public function __construct(
        private readonly ProductionCapabilityService $capabilities,
        private readonly AppReleasePolicyService $releasePolicy,
    ) {}

    private const CRITICAL_TABLES = [
        'users',
        'api_tokens',
        'social_accounts',
        'courses',
        'course_modules',
        'course_sections',
        'lessons',
        'course_enrollments',
        'course_access_plans',
        'orders',
        'wallet_transactions',
        'project_submissions',
        'exam_attempts',
        'student_notifications',
        'lesson_media_states',
        'playback_sessions',
        'social_oauth_attempts',
        'store_purchases',
    ];

    private const CRITICAL_COLUMNS = [
        'users' => ['profile_revision'],
        'api_tokens' => ['token', 'user_id', 'expired_at'],
        'social_accounts' => ['user_id', 'provider', 'provider_user_id'],
        'social_oauth_attempts' => [
            'state_hash',
            'completion_hash',
            'code_challenge',
            'encrypted_session_response',
            'completion_processing_at',
        ],
        'packages' => ['is_active', 'direct_enabled'],
        'course_access_plans' => [
            'project_followup_message_limit',
            'project_followup_token_budget',
            'project_followup_budget_usd',
            'project_followup_reserve_usd',
        ],
        'watching_logs' => [
            'playback_session_id',
            'playback_session_started_at',
            'last_playback_sequence',
        ],
        'student_section_progress' => ['completed_at'],
    ];

    private const LAUNCH_TABLES = [
        'ai_entitlement_usages',
        'ai_usage_events',
        'project_feedback_threads',
        'project_feedback_messages',
        'course_chat_turns',
        'notification_campaigns',
        'wallet_credit_lots',
        'wallet_debit_allocations',
        'financial_entitlement_holds',
        'payment_reconciliation_checkpoints',
        'payment_reconciliation_findings',
        'financial_anomalies',
        'coupon_redemptions',
        'store_notification_events',
        'user_whatsapp_connections',
        'whatsapp_link_tokens',
        'product_feature_flags',
        'admin_audit_logs',
        'operational_incidents',
    ];

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
            'critical_schema' => $this->criticalSchemaIsReady(),
            'social_oauth_storage' => $this->tableExists('social_oauth_attempts'),
            'identity_contract' => $this->capabilities->socialHandoffIsReady(),
            'cache' => $this->cacheIsReady(),
        ];

        // Traffic readiness answers one question only: can this instance serve
        // the app now? Cache and OAuth handoff are independently degradable;
        // making either one a load-balancer gate turns a provider incident into
        // a blank guest catalogue. Launch readiness below remains the strict
        // all-capabilities gate used before a release.
        $ready = $checks['database']
            && $checks['critical_schema']
            && $checks['social_oauth_storage'];

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
    public function launchReady(): JsonResponse
    {
        $report = $this->capabilities->report();
        $mobileRelease = $this->releasePolicy->launchReadiness();
        $checks = [
            'database' => $this->databaseIsReady(),
            'critical_schema' => $this->criticalSchemaIsReady(),
            'product_schema' => $this->launchSchemaIsReady(),
            'social_oauth_storage' => $this->tableExists('social_oauth_attempts'),
            'identity_contract' => $this->capabilities->socialHandoffIsReady(),
            'cache' => $this->cacheIsReady(),
            'bunny_stream' => (bool) data_get($report, 'capabilities.bunny.stream.ready'),
            'bunny_upload' => (bool) data_get($report, 'capabilities.bunny.upload.ready'),
            'bunny_playback' => (bool) data_get($report, 'capabilities.bunny.playback.ready'),
            'bunny_signing' => (bool) data_get($report, 'capabilities.bunny.signing.ready'),
            'bunny_assets' => (bool) data_get($report, 'capabilities.bunny.assets.ready'),
            'payment' => (bool) data_get($report, 'capabilities.payment.ready'),
            'payment_kashier' => (bool) data_get($report, 'capabilities.payment.kashier.ready'),
            'payment_google_play' => (bool) data_get($report, 'capabilities.payment.google_play.ready'),
            'payment_app_store' => (bool) data_get($report, 'capabilities.payment.app_store.ready'),
            'ai' => (bool) data_get($report, 'capabilities.ai.ready'),
            'mail' => (bool) data_get($report, 'capabilities.mail.ready'),
            'push' => (bool) data_get($report, 'capabilities.push.ready'),
            'social_callbacks' => (bool) data_get($report, 'capabilities.social.callbacks.ready'),
            'social_handoff' => (bool) data_get($report, 'capabilities.social.handoff.ready'),
            'app_links_android' => (bool) data_get($report, 'capabilities.app_links.android.ready'),
            'app_links_apple' => (bool) data_get($report, 'capabilities.app_links.apple.ready'),
            'queue' => (bool) data_get($report, 'capabilities.queue.ready'),
            'recovery' => (bool) data_get($report, 'capabilities.recovery.ready'),
            'mobile_release' => $mobileRelease['ready'],
        ];
        foreach ((array) data_get($report, 'capabilities.social.declared_providers', []) as $provider) {
            $checks['social_'.$provider] = (bool) data_get($report, "capabilities.social.{$provider}.ready");
        }
        $optionalChecks = collect(['google', 'tiktok', 'apple', 'facebook'])
            ->reject(fn (string $provider): bool => array_key_exists('social_'.$provider, $checks))
            ->mapWithKeys(fn (string $provider): array => [
                'social_'.$provider => (bool) data_get($report, "capabilities.social.{$provider}.ready"),
            ])
            ->all();
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
                'optional_checks' => $optionalChecks,
                'mobile_release' => $mobileRelease,
                'time' => $time,
            ],
            'checks' => $checks,
            'optional_checks' => $optionalChecks,
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
        try {
            return Cache::remember(
                'health:cache-sentinel:v2',
                10,
                static fn (): string => 'ok'
            ) === 'ok';
        } catch (Throwable $exception) {
            return false;
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (Throwable $exception) {
            return false;
        }
    }

    private function criticalSchemaIsReady(): bool
    {
        try {
            return (bool) Cache::remember(
                'health:critical-schema:v3',
                60,
                fn (): bool => $this->scanCriticalSchema()
            );
        } catch (Throwable) {
            return $this->scanCriticalSchema();
        }
    }

    private function scanCriticalSchema(): bool
    {
        foreach (self::CRITICAL_TABLES as $table) {
            if (!$this->tableExists($table)) {
                return false;
            }
        }

        foreach (self::CRITICAL_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                try {
                    if (!Schema::hasColumn($table, $column)) {
                        return false;
                    }
                } catch (Throwable $exception) {
                    return false;
                }
            }
        }

        return true;
    }

    private function launchSchemaIsReady(): bool
    {
        try {
            return (bool) Cache::remember(
                'health:launch-schema:v3',
                60,
                fn (): bool => $this->scanLaunchSchema()
            );
        } catch (Throwable) {
            return $this->scanLaunchSchema();
        }
    }

    private function scanLaunchSchema(): bool
    {
        foreach (self::LAUNCH_TABLES as $table) {
            if (!$this->tableExists($table)) {
                return false;
            }
        }

        try {
            if (
                Schema::hasColumns('exam_attempts', [
                    'quiz_title',
                    'quiz_description',
                    'quiz_image',
                ])
                && DB::table('exam_attempts as attempt')
                    ->join('lists as quiz', 'quiz.id', '=', 'attempt.quiz_id')
                    ->whereNull('attempt.quiz_title')
                    ->exists()
            ) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        return true;
    }

}
