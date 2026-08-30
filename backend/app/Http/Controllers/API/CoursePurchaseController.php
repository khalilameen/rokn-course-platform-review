<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Exceptions\FinancialProvenanceException;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\CourseAuthorizationRequest;
use App\Models\Bill;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Order;
use App\Models\Package;
use App\Models\Setting;
use App\Services\AiEntitlementBudgetService;
use App\Services\CourseAccessPlanService;
use App\Services\FinancialAnomalyService;
use App\Services\FinancialProvenanceService;
use App\Services\StudentNotificationService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CoursePurchaseController extends Controller
{
    public function authorizeCourse(
        CourseAuthorizationRequest $request,
        WalletService $walletService,
        FinancialProvenanceService $provenance,
        FinancialAnomalyService $financialRisk,
        CourseAccessPlanService $planService,
        AiEntitlementBudgetService $aiBudget
    ): JsonResponse
    {
        $user = auth('api')->user();
        $course = Course::findOrFail($request->course_id);
        $requestedPlanCode = $request->filled('access_plan_code')
            ? strtolower(trim((string) $request->input('access_plan_code')))
            : null;
        $clientIdempotencyKey = $request->filled('idempotency_key')
            ? (string) $request->input('idempotency_key')
            : null;
        try {
            $result = DB::transaction(function () use (
                $user,
                $course,
                $walletService,
                $provenance,
                $planService,
                $aiBudget,
                $requestedPlanCode,
                $clientIdempotencyKey
            ): array {
                // Money paths acquire learner and course locks in this order.
                \App\Models\User::query()->lockForUpdate()->findOrFail($user->id);
                $lockedCourse = Course::query()->lockForUpdate()->findOrFail($course->id);

                $existingEnrollment = CourseEnrollment::query()
                    ->where('user_id', $user->id)
                    ->where('course_id', $lockedCourse->id)
                    ->lockForUpdate()
                    ->first();

                if ($clientIdempotencyKey !== null) {
                    $replayedOrder = Order::query()
                        ->with(['bill', 'accessPlan'])
                        ->where('user_id', $user->id)
                        ->where('checkout_request_key', $clientIdempotencyKey)
                        ->lockForUpdate()
                        ->first();
                    if ($replayedOrder) {
                        if (!$this->isSamePurchaseReplay(
                            $replayedOrder,
                            (int) $lockedCourse->id,
                            $requestedPlanCode
                        )) {
                            throw new \DomainException('checkout_idempotency_conflict');
                        }
                        if (!$existingEnrollment) {
                            throw new \LogicException('Committed course order has no enrollment.');
                        }

                        return [
                            'enrollment' => $existingEnrollment,
                            'order' => $replayedOrder,
                            'bill' => $replayedOrder->bill,
                            'amount' => 0,
                            'already_enrolled' => true,
                            'idempotent_replay' => true,
                            'plan_terms' => is_array($replayedOrder->access_plan_snapshot)
                                ? $replayedOrder->access_plan_snapshot
                                : null,
                        ];
                    }
                }

                if ($existingEnrollment && $existingEnrollment->isActive()) {
                    return [
                        'enrollment' => $existingEnrollment,
                        'order' => $existingEnrollment->order,
                        'bill' => $existingEnrollment->order?->bill,
                        'amount' => 0,
                        'already_enrolled' => true,
                        'idempotent_replay' => false,
                        'plan_terms' => $planService->termsForEnrollment($existingEnrollment),
                    ];
                }

                $selectedPlan = $planService->selectedPlan(
                    $lockedCourse,
                    $requestedPlanCode,
                    true
                );
                $amount = $selectedPlan
                    ? max(0, (int) $selectedPlan->price_coins)
                    : max(0, (int) ($lockedCourse->price ?? 0));
                if (
                    $lockedCourse->is_coming_soon
                    || (!$selectedPlan && ($lockedCourse->price === null || (float) $lockedCourse->price < 0))
                    || !$lockedCourse->sections()->exists()
                ) {
                    throw new \DomainException('course_not_available');
                }

                $checkoutKey = $clientIdempotencyKey ?: sprintf(
                    'server:course-purchase:%d:%d:%s',
                    $user->id,
                    $lockedCourse->id,
                    Str::orderedUuid()->toString()
                );
                $walletIdempotencyKey = 'course-purchase:' . hash(
                    'sha256',
                    $user->id . '|' . $checkoutKey
                );
                $planSnapshot = $selectedPlan
                    ? $planService->snapshot($selectedPlan, now())
                    : null;

                $order = Order::create([
                    'user_id' => $user->id,
                    'course_id' => $lockedCourse->id,
                    'access_plan_id' => $selectedPlan?->id,
                    'access_plan_snapshot' => $planSnapshot,
                    'checkout_request_key' => $checkoutKey,
                    'payment_method' => Order::PAYMENT_METHOD_WALLET_COINS,
                    'amount' => $amount,
                    'discount_amount' => 0,
                    'final_amount' => $amount,
                    'status' => Order::STATUS_APPROVED,
                    'financial_status' => Order::FINANCIAL_SETTLED,
                    'approved_at' => now(),
                    'approved_by' => null,
                    'is_premium_user' => $user->isPremiumUser(),
                    'notes' => 'Idempotency: ' . $walletIdempotencyKey,
                ]);

                // The user and course rows are both locked above. Derive the
                // remaining course-wide allowance from the immutable wallet
                // ledger so base purchases and every plan upgrade share one
                // cumulative reward cap.
                $rewardContribution = $this->rewardContribution(
                    $walletService,
                    (int) $user->id,
                    (int) $lockedCourse->id
                );
                $minimumPaidCoins = max(0, (int) ($planSnapshot['minimum_paid_coins'] ?? 0));
                $paidFloorRemaining = max(
                    0,
                    $minimumPaidCoins - $walletService->coursePaidContribution(
                        (int) $user->id,
                        (int) $lockedCourse->id
                    )
                );
                $maximumRewardForPurchase = min(
                    $rewardContribution['remaining'],
                    max(0, $amount - min($amount, $paidFloorRemaining))
                );
                $walletTransaction = $walletService->debit(
                    $user->id,
                    $amount,
                    'course_purchase',
                    $walletIdempotencyKey,
                    $lockedCourse,
                    [
                        'course_title' => $lockedCourse->name_ar,
                        'minimum_paid_coins' => $minimumPaidCoins,
                        'paid_floor_remaining_before_purchase' => $paidFloorRemaining,
                    ],
                    $maximumRewardForPurchase
                );

                // Course orders preserve the paid/reward coin attribution.
                $order->forceFill([
                    'total_coins' => $amount,
                    'paid_coins' => (int) $walletTransaction->paid_amount,
                    'reward_coins' => (int) $walletTransaction->reward_amount,
                ])->save();
                $provenance->allocateCourseDebit($order, $walletTransaction);

                $bill = Bill::create([
                    'order_id' => $order->id,
                    'user_id' => $user->id,
                    'course_id' => $lockedCourse->id,
                    'bill_number' => Bill::generateBillNumber(),
                    'amount' => $amount,
                    'tax_amount' => 0,
                    'total_amount' => $amount,
                    'payment_status' => Bill::PAYMENT_STATUS_PAID,
                    'payment_method' => Order::PAYMENT_METHOD_WALLET_COINS,
                    'due_date' => now(),
                    'paid_at' => now(),
                    'notes' => 'Paid via Rokn coins',
                ]);

                $enrollment = $existingEnrollment ?: new CourseEnrollment([
                    'user_id' => $user->id,
                    'course_id' => $lockedCourse->id,
                ]);
                if ($existingEnrollment) {
                    // A repurchase starts a fresh AI entitlement cycle.
                    $aiBudget->resetForNewPurchase($existingEnrollment);
                }
                $enrollment->fill([
                    'order_id' => $order->id,
                    'access_plan_order_id' => $order->id,
                    'access_plan_id' => $selectedPlan?->id,
                    'access_plan_snapshot' => $planSnapshot,
                    'enrolled_at' => $enrollment->enrolled_at ?: now(),
                    'expires_at' => null,
                    'is_active' => true,
                    'access_granted_at' => now(),
                ])->save();

                return [
                    'enrollment' => $enrollment,
                    'order' => $order,
                    'bill' => $bill,
                    'amount' => $amount,
                    'remaining_balance' => $walletTransaction->balance_after,
                    'paid_coins' => (int) $walletTransaction->paid_amount,
                    'reward_coins' => (int) $walletTransaction->reward_amount,
                    'already_enrolled' => false,
                    'idempotent_replay' => false,
                    'plan_terms' => $planSnapshot,
                ];
            }, 3);
        } catch (InsufficientWalletBalanceException $exception) {
            $deficit = max(0, $exception->required - $exception->balance);
            $freshUser = $user->fresh();
            $totalBalance = max(0, (int) $freshUser->wallet_coins);
            $purchasedBalance = min(
                $totalBalance,
                max(0, (int) $freshUser->wallet_purchased_coins)
            );
            $rewardBalance = $totalBalance - $purchasedBalance;
            $rewardContribution = $this->rewardContribution(
                $walletService,
                (int) $user->id,
                (int) $course->id
            );
            $selectedPlan = $planService->selectedPlan($course, $requestedPlanCode);
            $minimumPaidCoins = max(0, (int) ($selectedPlan?->minimum_paid_coins ?? 0));
            $paidFloorRemaining = max(
                0,
                $minimumPaidCoins - $walletService->coursePaidContribution(
                    (int) $user->id,
                    (int) $course->id
                )
            );
            $recommendedPackages = Package::query()
                ->where('coins', '>=', $deficit)
                ->where('coins', '>', 0)
                ->where('price', '>', 0)
                ->orderBy('coins')
                ->limit(3)
                ->get(['id', 'name_ar', 'name_en', 'price', 'coins']);

            return response()->json([
                'status' => 400,
                'success' => false,
                'code' => 'insufficient_coins',
                'message' => 'Insufficient wallet balance',
                'data' => [
                    'required_coins' => $exception->required,
                    // Legacy clients read the real wallet total from current_coins.
                    'current_coins' => $totalBalance,
                    'total_balance' => $totalBalance,
                    'purchased_balance' => $purchasedBalance,
                    'reward_balance' => $rewardBalance,
                    'spendable_balance' => $exception->balance,
                    'reward_contribution_cap_per_course' => $rewardContribution['cap'],
                    'reward_contribution_used_for_course' => $rewardContribution['used'],
                    'reward_contribution_remaining_for_course' => $rewardContribution['remaining'],
                    'minimum_paid_coins_required' => $minimumPaidCoins,
                    'paid_coin_floor_remaining' => $paidFloorRemaining,
                    'deficit' => $deficit,
                    'recommended_packages' => $recommendedPackages,
                    // Mobile resumes the purchase after the embedded checkout.
                    'resume_action' => [
                        'type' => 'purchase_course',
                        'course_id' => $course->id,
                        'access_plan_code' => $requestedPlanCode,
                    ],
                ],
            ], 400);
        } catch (FinancialProvenanceException $exception) {
            report($exception);
            return response()->json([
                'status' => 409,
                'success' => false,
                'code' => 'financial_provenance_unavailable',
                'message' => 'Course purchase is temporarily unavailable. Your balance was not changed.',
                'data' => null,
            ], 409);
        } catch (\DomainException $exception) {
            $code = $exception->getMessage();

            return response()->json([
                'status' => 409,
                'success' => false,
                'code' => $code,
                'message' => $code === 'checkout_idempotency_conflict'
                    ? 'This idempotency key was already used for a different purchase.'
                    : 'This course is not available to unlock yet.',
                'data' => null,
            ], 409);
        } catch (\Throwable $exception) {
            report($exception);
            return response()->json([
                'status' => 500,
                'success' => false,
                'message' => 'An error occurred while processing your request',
                'data' => null,
            ], 500);
        }

        if (!$result['already_enrolled']) {
            try {
                StudentNotificationService::notifyUser(
                    $user->fresh(),
                    StudentNotificationService::TYPE_COURSE_ENROLLED,
                    'تم فتح الكورس',
                    'Course unlocked',
                    'يمكنك الآن بدء كورس: ' . $course->name_ar,
                    'You can now start: ' . $course->name_en,
                    '/courses/' . $course->id,
                    Course::class,
                    $course->id,
                    'course-enrolled:order:' . ($result['order']?->id ?? $result['enrollment']->id)
                );
            } catch (\Throwable $exception) {
                // A push outage must never turn a completed purchase into an apparent failure.
                report($exception);
            }
        }

        $freshUser = $user->fresh();
        $totalBalance = max(0, (int) $freshUser->wallet_coins);
        $purchasedBalance = min(
            $totalBalance,
            max(0, (int) $freshUser->wallet_purchased_coins)
        );
        $rewardBalance = $totalBalance - $purchasedBalance;
        $rewardContribution = $this->rewardContribution(
            $walletService,
            (int) $user->id,
            (int) $course->id
        );
        $minimumPaidCoins = max(0, (int) data_get($result, 'plan_terms.minimum_paid_coins', 0));
        $paidContributionForCourse = $walletService->coursePaidContribution(
            (int) $user->id,
            (int) $course->id
        );
        $financialReviewRequired = !$financialRisk->allowsVariableCostFeatures(
            $result['enrollment']->fresh()
        );

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => $result['already_enrolled']
                ? 'Course access was already granted'
                : 'Course access granted successfully',
            'data' => [
                'order_id' => $result['order']?->id,
                'bill_id' => $result['bill']?->id,
                'enrollment_id' => $result['enrollment']->id,
                'amount_deducted' => $result['amount'],
                'remaining_balance' => $totalBalance,
                'total_balance' => $totalBalance,
                'purchased_balance' => $purchasedBalance,
                'reward_balance' => $rewardBalance,
                'spendable_balance' => $purchasedBalance + min($rewardBalance, $rewardContribution['remaining']),
                'reward_contribution_cap_per_course' => $rewardContribution['cap'],
                'reward_contribution_used_for_course' => $rewardContribution['used'],
                'reward_contribution_remaining_for_course' => $rewardContribution['remaining'],
                'minimum_paid_coins_required' => $minimumPaidCoins,
                'paid_coin_floor_remaining' => max(0, $minimumPaidCoins - $paidContributionForCourse),
                'financial_review_required' => $financialReviewRequired,
                'allocation' => [
                    'total_coins' => (int) ($result['order']?->total_coins ?? 0),
                    'paid_coins' => (int) ($result['order']?->paid_coins ?? 0),
                    'reward_coins' => (int) ($result['order']?->reward_coins ?? 0),
                    'spend_policy' => 'reward_first_then_paid',
                ],
                'access_plan' => $result['plan_terms']
                    ? $planService->publicPayloadFromTerms($result['plan_terms'])
                    : null,
                'already_enrolled' => $result['already_enrolled'],
                'idempotent_replay' => (bool) ($result['idempotent_replay'] ?? false),
            ],
        ]);
    }

    /** @return array{cap:int,used:int,remaining:int} */
    private function rewardContribution(WalletService $wallet, int $userId, int $courseId): array
    {
        $cap = max(
            0,
            (int) (Setting::query()->value('max_reward_contribution_per_course') ?? 1200)
        );

        return $wallet->courseRewardContribution($userId, $courseId, $cap);
    }

    private function isSamePurchaseReplay(
        Order $order,
        int $courseId,
        ?string $requestedPlanCode
    ): bool
    {
        if (
            (int) $order->course_id !== $courseId
            || $order->package_id !== null
            || $order->payment_method !== Order::PAYMENT_METHOD_WALLET_COINS
            || $order->status !== Order::STATUS_APPROVED
            || !str_starts_with((string) $order->notes, 'Idempotency: ')
        ) {
            return false;
        }

        if ($requestedPlanCode === null) {
            return true;
        }

        return (string) data_get($order->access_plan_snapshot, 'code') === $requestedPlanCode;
    }
}
