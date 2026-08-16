<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\CourseAccessPlan;
use LogicException;

/**
 * Runtime guard for the immutable commercial terms copied onto an order and
 * its current enrollment. MySQL also requires a non-null snapshot whenever a
 * plan is present; this class verifies the versioned payload on every write so
 * SQLite tests and application-created records enforce the same contract.
 */
final class CourseAccessPlanSnapshot
{
    public const CURRENT_VERSION = 2;
    public const SUPPORTED_VERSIONS = [1, self::CURRENT_VERSION];

    /** @var list<string> */
    private const REQUIRED_KEYS = [
        'version',
        'plan_id',
        'code',
        'name_ar',
        'price_coins',
        'chat_enabled',
        'chat_message_limit',
        'chat_token_budget',
        'ai_budget_usd',
        'request_reserve_usd',
        'project_feedback_token_budget',
        'project_feedback_budget_usd',
        'project_feedback_reserve_usd',
        'max_output_tokens',
        'model_override',
        'project_feedback_level',
        'project_output_enabled',
        'certificate_enabled',
        'purchased_at',
    ];

    /**
     * A null plan is the explicit legacy-access state. A plan-backed write,
     * however, must always carry the complete immutable receipt it promises.
     */
    public static function assertValidForPlan(?int $accessPlanId, mixed $snapshot): void
    {
        if ($accessPlanId === null) {
            return;
        }

        if ($accessPlanId <= 0 || !is_array($snapshot)) {
            throw new LogicException('A plan-backed course entitlement requires a valid snapshot.');
        }

        foreach (self::REQUIRED_KEYS as $key) {
            if (!array_key_exists($key, $snapshot)) {
                throw new LogicException("The access-plan snapshot is missing [{$key}].");
            }
        }

        $version = (int) $snapshot['version'];
        if (!in_array($version, self::SUPPORTED_VERSIONS, true)) {
            throw new LogicException('Unsupported access-plan snapshot version.');
        }
        if ($version >= 2) {
            if (!array_key_exists('sort_order', $snapshot) || (int) $snapshot['sort_order'] < 0) {
                throw new LogicException('The access-plan snapshot requires a valid sort order.');
            }
            foreach ([
                'ai_budget_usd',
                'request_reserve_usd',
                'project_feedback_budget_usd',
                'project_feedback_reserve_usd',
            ] as $moneyKey) {
                if (
                    !is_string($snapshot[$moneyKey])
                    || preg_match('/^\d+\.\d{6}$/D', $snapshot[$moneyKey]) !== 1
                ) {
                    throw new LogicException(
                        "Version 2 access-plan money [{$moneyKey}] must use a fixed six-decimal string."
                    );
                }
            }
        }
        if ((int) $snapshot['plan_id'] !== $accessPlanId) {
            throw new LogicException('The access-plan snapshot does not match its plan.');
        }
        if (!in_array((string) $snapshot['code'], CourseAccessPlan::CODES, true)) {
            throw new LogicException('The access-plan snapshot contains an unknown plan code.');
        }
        if (trim((string) $snapshot['name_ar']) === '') {
            throw new LogicException('The access-plan snapshot requires a display name.');
        }
        if (!is_numeric($snapshot['price_coins']) || (int) $snapshot['price_coins'] < 0) {
            throw new LogicException('The access-plan snapshot contains an invalid price.');
        }
        if (!in_array(
            (string) $snapshot['project_feedback_level'],
            CourseAccessPlan::PROJECT_FEEDBACK_LEVELS,
            true
        )) {
            throw new LogicException('The access-plan snapshot contains an invalid feedback level.');
        }
        if (trim((string) $snapshot['purchased_at']) === '') {
            throw new LogicException('The access-plan snapshot requires its purchase timestamp.');
        }

        foreach ([
            'chat_message_limit',
            'chat_token_budget',
            'ai_budget_usd',
            'request_reserve_usd',
            'project_feedback_token_budget',
            'project_feedback_budget_usd',
            'project_feedback_reserve_usd',
            'max_output_tokens',
        ] as $numericKey) {
            if (!is_numeric($snapshot[$numericKey]) || (float) $snapshot[$numericKey] < 0) {
                throw new LogicException(
                    "The access-plan snapshot contains an invalid [{$numericKey}] value."
                );
            }
        }
    }

    private function __construct()
    {
    }
}
