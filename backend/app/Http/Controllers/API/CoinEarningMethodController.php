<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\CoinEarningMethodResource;
use App\Models\CoinEarningMethod;
use App\Models\Setting;
use App\Models\UserCoinTaskAttempt;
use App\Models\WalletTransaction;
use App\Services\AcquisitionRewardTombstoneService;
use App\Services\StudentNotificationService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CoinEarningMethodController extends Controller
{
    public function __construct(
        private readonly AcquisitionRewardTombstoneService $tombstones
    ) {
    }

    public function index(): JsonResponse
    {
        // Registration credit is granted automatically during verified social
        // login. It must never appear as a second, manually claimable task.
        $methods = CoinEarningMethod::active()
            ->where(function ($query): void {
                $query->whereNull('action_key')
                    ->orWhere('action_key', '!=', 'register');
            })
            ->latest()
            ->get()
            ->filter(fn (CoinEarningMethod $method): bool => $method->hasUsableDestination())
            ->values();
        $setting = Setting::first() ?? new Setting();
        $user = auth('api')->user();

        $earnings = collect();
        $attempts = collect();
        if ($user) {
            $earnings = $user->coinEarnings()
                ->whereIn('coin_earning_method_id', $methods->pluck('id'))
                ->get()
                ->keyBy('coin_earning_method_id');
            $attempts = $user->coinTaskAttempts()
                ->whereIn('coin_earning_method_id', $methods->pluck('id'))
                ->get()
                ->keyBy('coin_earning_method_id');
        }

        $tombstones = $this->tombstones;
        $consumedRewardKeys = $user ? $tombstones->consumedRewardKeys($user) : [];
        $methods->each(function (CoinEarningMethod $method) use (
            $earnings,
            $attempts,
            $tombstones,
            $consumedRewardKeys
        ): void {
            $attempt = $attempts->get($method->id);
            $tombstoneKey = $tombstones->rewardKeyForMethod($method);
            $claimed = $earnings->has($method->id)
                || $attempt?->status === UserCoinTaskAttempt::STATUS_CLAIMED
                || ($tombstoneKey !== null && in_array($tombstoneKey, $consumedRewardKeys, true));
            $state = 'available';
            if ($claimed) {
                $state = 'claimed';
            } elseif ($attempt && $attempt->claim_available_at?->isFuture()) {
                $state = 'started';
            } elseif ($attempt) {
                $state = 'ready_to_claim';
            }

            $method->is_consumed = $claimed;
            $method->task_state = $state;
            $method->claim_available_at = $attempt?->claim_available_at;
        });

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'Coin earning methods retrieved successfully',
            'how_to_use_coins' => $setting->how_to_use_coins,
            'coin_rules' => $setting->how_to_use_coins,
            'data' => CoinEarningMethodResource::collection($methods),
        ]);
    }

    /**
     * Record the external visit before the learner leaves the app. Returning to
     * claim is a separate action, matching the intended two-tap UX.
     */
    public function start(CoinEarningMethod $method): JsonResponse
    {
        $user = auth('api')->user();
        $actionUrl = $method->resolvedActionUrl();
        if (
            !$method->is_active
            || $method->action_key === 'register'
            || ($method->requires_external_visit && $actionUrl === null)
        ) {
            return $this->error('Task is not available', 404, 'task_unavailable');
        }
        if ($this->tombstones->userHasConsumedMethod($user, $method)) {
            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'تم استلام مكافأة هذه المهمة سابقًا.',
                'data' => ['task_state' => 'claimed', 'action_url' => $actionUrl],
            ]);
        }

        [$attempt, $alreadyClaimed] = DB::transaction(function () use ($user, $method): array {
            // Two fast taps must resolve to one immutable attempt on every DB driver.
            \App\Models\User::query()->lockForUpdate()->findOrFail($user->id);

            $attempt = UserCoinTaskAttempt::firstOrCreate(
                ['user_id' => $user->id, 'coin_earning_method_id' => $method->id],
                [
                    'public_id' => (string) Str::uuid(),
                    'status' => UserCoinTaskAttempt::STATUS_STARTED,
                    'started_at' => now(),
                    'claim_available_at' => now()->addSeconds(max(0, (int) $method->verification_delay_seconds)),
                ]
            );

            $alreadyClaimed = $attempt->status === UserCoinTaskAttempt::STATUS_CLAIMED
                || $user->coinEarnings()
                    ->where('coin_earning_method_id', $method->id)
                    ->exists();

            return [$attempt, $alreadyClaimed];
        });

        if ($alreadyClaimed) {
            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'تم استلام مكافأة هذه المهمة سابقًا.',
                'data' => [
                    'task_state' => 'claimed',
                    'action_url' => $actionUrl,
                ],
            ]);
        }

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم بدء المهمة. عد إلى التطبيق للمطالبة بالمكافأة.',
            'data' => [
                'attempt_id' => $attempt->public_id,
                'task_state' => $attempt->claim_available_at?->isFuture() ? 'started' : 'ready_to_claim',
                'action_url' => $actionUrl,
                'claim_available_at' => $attempt->claim_available_at?->toIso8601String(),
            ],
        ]);
    }

    public function claim(Request $request, WalletService $walletService): JsonResponse
    {
        $request->validate([
            'method_id' => 'required|integer|exists:coin_earning_methods,id',
        ]);

        $user = auth('api')->user();
        $method = CoinEarningMethod::active()->findOrFail($request->integer('method_id'));
        if ($method->action_key === 'register') {
            return $this->error('Task is not available', 404, 'task_unavailable');
        }
        if ($this->tombstones->userHasConsumedMethod($user, $method)) {
            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'تم استلام مكافأة هذه المهمة سابقًا.',
                'data' => [
                    'already_claimed' => true,
                    'earned_amount' => 0,
                    'new_balance' => (int) $user->wallet_coins,
                    'task_state' => 'claimed',
                ],
            ]);
        }

        try {
            $result = DB::transaction(function () use ($user, $method, $walletService): array {
                // Serializes two rapid claim taps even before an attempt row exists.
                \App\Models\User::query()->lockForUpdate()->findOrFail($user->id);

                $attempt = UserCoinTaskAttempt::query()
                    ->where('user_id', $user->id)
                    ->where('coin_earning_method_id', $method->id)
                    ->lockForUpdate()
                    ->first();

                $earning = $user->coinEarnings()
                    ->where('coin_earning_method_id', $method->id)
                    ->first();
                if ($earning || $attempt?->status === UserCoinTaskAttempt::STATUS_CLAIMED) {
                    return [
                        'already_claimed' => true,
                        'earned_amount' => (int) ($earning?->amount ?? $method->coins_amount),
                        'new_balance' => (int) $user->fresh()->wallet_coins,
                    ];
                }

                if ($method->requires_external_visit && !$attempt) {
                    throw new \DomainException('task_not_started');
                }
                if ($attempt?->claim_available_at?->isFuture()) {
                    throw new \DomainException('claim_not_ready');
                }

                if (!$attempt) {
                    $attempt = UserCoinTaskAttempt::create([
                        'public_id' => (string) Str::uuid(),
                        'user_id' => $user->id,
                        'coin_earning_method_id' => $method->id,
                        'status' => UserCoinTaskAttempt::STATUS_STARTED,
                        'started_at' => now(),
                        'claim_available_at' => now(),
                    ]);
                }

                $transaction = $walletService->credit(
                    $user->id,
                    (int) $method->coins_amount,
                    'task_reward',
                    "coin-task:{$user->id}:{$method->id}",
                    $method,
                    ['action_key' => $method->action_key],
                    WalletTransaction::BUCKET_REWARD
                );

                $user->coinEarnings()->firstOrCreate(
                    ['coin_earning_method_id' => $method->id],
                    ['amount' => $method->coins_amount]
                );
                $attempt->update([
                    'status' => UserCoinTaskAttempt::STATUS_CLAIMED,
                    'claimed_at' => now(),
                ]);

                return [
                    'already_claimed' => false,
                    'earned_amount' => (int) $method->coins_amount,
                    'new_balance' => $transaction->balance_after,
                ];
            });
        } catch (\DomainException $exception) {
            $code = $exception->getMessage();
            return $this->error(
                $code === 'task_not_started'
                    ? 'ابدأ المهمة أولًا ثم عد للمطالبة بالمكافأة.'
                    : 'عد بعد إتمام المهمة للمطالبة بالمكافأة.',
                409,
                $code
            );
        }

        if (!$result['already_claimed']) {
            try {
                StudentNotificationService::notifyUser(
                    $user->fresh(),
                    StudentNotificationService::TYPE_COINS_CLAIMED,
                    'تم استلام العملات',
                    'Coins Claimed',
                    'تمت إضافة ' . $method->coins_amount . ' عملة إلى محفظتك',
                    $method->coins_amount . ' coins have been added to your wallet',
                    null,
                    CoinEarningMethod::class,
                    $method->id,
                    'coins-claimed:' . $user->id . ':' . $method->id
                );
            } catch (\Throwable $exception) {
                // Reward delivery is complete even if the optional push service is down.
                report($exception);
            }
        }

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => $result['already_claimed']
                ? 'تم استلام مكافأة هذه المهمة سابقًا.'
                : 'تم الحصول على العملات بنجاح.',
            'data' => $result + ['task_state' => 'claimed'],
        ]);
    }

    private function error(string $message, int $status, string $code): JsonResponse
    {
        return response()->json([
            'status' => $status,
            'success' => false,
            'code' => $code,
            'message' => $message,
            'data' => null,
        ], $status);
    }
}
