<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CoinEarningMethod;
use App\Models\User;
use App\Models\UserCoinTaskAttempt;
use App\Services\AcquisitionRewardTombstoneService;
use App\Services\ApiResponseService;
use App\Services\EngagementMessageService;
use Illuminate\Http\JsonResponse;

final class EngagementController extends Controller
{
    public function next(
        EngagementMessageService $messages,
        AcquisitionRewardTombstoneService $tombstones,
        ApiResponseService $responses
    ): JsonResponse {
        /** @var User $user */
        $user = auth('api')->user();
        $methods = CoinEarningMethod::query()
            ->active()
            ->where(function ($query): void {
                $query->whereNull('action_key')->orWhere('action_key', '!=', 'register');
            })
            // Lead with the current social follow task, matching the familiar
            // short-drama-app loop: leave, return, then claim.
            ->orderByDesc('requires_external_visit')
            ->orderBy('id')
            ->get();

        $method = $methods->first(function (CoinEarningMethod $method) use ($user, $tombstones): bool {
            if (!$method->hasUsableDestination() || $tombstones->userHasConsumedMethod($user, $method)) {
                return false;
            }
            if ($user->coinEarnings()->where('coin_earning_method_id', $method->id)->exists()) {
                return false;
            }

            return !UserCoinTaskAttempt::query()
                ->where('user_id', $user->id)
                ->where('coin_earning_method_id', $method->id)
                ->where('status', UserCoinTaskAttempt::STATUS_CLAIMED)
                ->exists();
        });

        if (!$method) {
            return $responses->success(null, 'لا توجد رسالة الآن');
        }

        $message = $messages->publicMessage('coin_offer', [
            'task' => (string) ($method->title_ar ?: $method->title_en),
            'coins' => (int) $method->coins_amount,
        ]);
        if (!$message) {
            return $responses->success(null, 'رسائل العملات متوقفة الآن');
        }

        return $responses->success($message + [
            'campaign_key' => 'coin-offer:' . $method->id,
            'task_id' => (string) $method->id,
            'action_key' => (string) $method->action_key,
            'link' => '/wallet',
        ], 'تم تحميل الرسالة المناسبة');
    }
}
