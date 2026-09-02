<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InsufficientWalletBalanceException;
use App\Models\Course;
use App\Models\Setting;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class WalletService
{
    /**
     * @return array{cap:int,used:int,remaining:int}
     */
    public function courseRewardContribution(
        int $userId,
        int $courseId,
        int $cap
    ): array {
        $normalizedCap = max(0, $cap);
        $used = max(0, (int) WalletTransaction::query()
            ->where('user_id', $userId)
            ->where('direction', WalletTransaction::DIRECTION_DEBIT)
            ->where('source_type', Course::class)
            ->where('source_id', $courseId)
            ->whereIn('category', [
                'course_purchase',
                'course_chat_upgrade',
                'course_full_track_upgrade',
            ])
            ->sum('reward_amount'));

        return [
            'cap' => $normalizedCap,
            'used' => $used,
            'remaining' => max(0, $normalizedCap - $used),
        ];
    }

    public function coursePaidContribution(int $userId, int $courseId): int
    {
        return max(0, (int) WalletTransaction::query()
            ->where('user_id', $userId)
            ->where('direction', WalletTransaction::DIRECTION_DEBIT)
            ->where('source_type', Course::class)
            ->where('source_id', $courseId)
            ->whereIn('category', [
                'course_purchase',
                'course_chat_upgrade',
                'course_full_track_upgrade',
            ])
            ->sum('paid_amount'));
    }

    public function credit(
        int $userId,
        int $amount,
        string $category,
        string $idempotencyKey,
        ?Model $source = null,
        array $metadata = [],
        string $bucket = WalletTransaction::BUCKET_REWARD
    ): WalletTransaction {
        return $this->recordTransaction(
            $userId,
            $amount,
            WalletTransaction::DIRECTION_CREDIT,
            $category,
            $idempotencyKey,
            $source,
            $metadata,
            $bucket
        );
    }

    public function debit(
        int $userId,
        int $amount,
        string $category,
        string $idempotencyKey,
        ?Model $source = null,
        array $metadata = [],
        ?int $maxRewardAmount = null
    ): WalletTransaction {
        return $this->recordTransaction(
            $userId,
            $amount,
            WalletTransaction::DIRECTION_DEBIT,
            $category,
            $idempotencyKey,
            $source,
            $metadata,
            null,
            null,
            null,
            $maxRewardAmount
        );
    }

    /**
     * Credit free value without ever crossing the configured reward-wallet
     * ceiling. The user aggregate lock makes the room calculation and ledger
     * append one operation across concurrent welcome/task claims.
     */
    public function creditRewardWithinConfiguredCap(
        int $userId,
        int $requestedAmount,
        string $category,
        string $idempotencyKey,
        ?Model $source = null,
        array $metadata = []
    ): ?WalletTransaction {
        if ($requestedAmount < 0) {
            throw new \InvalidArgumentException('Wallet amount must be zero or greater.');
        }

        return DB::transaction(function () use (
            $userId,
            $requestedAmount,
            $category,
            $idempotencyKey,
            $source,
            $metadata
        ): ?WalletTransaction {
            /** @var User $user */
            $user = User::withTrashed()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $existing = WalletTransaction::query()
                ->where('user_id', $userId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                $storedRequested = data_get($existing->metadata, 'requested_amount');
                if (
                    $existing->direction !== WalletTransaction::DIRECTION_CREDIT
                    || $existing->category !== $category
                    || $existing->source_type !== ($source ? get_class($source) : null)
                    || (string) ($existing->source_id ?? '') !== (string) ($source?->getKey() ?? '')
                    || ($storedRequested !== null && (int) $storedRequested !== $requestedAmount)
                    || ($storedRequested === null && (int) $existing->amount !== $requestedAmount)
                ) {
                    throw new \UnexpectedValueException(
                        'Wallet reward idempotency key was reused for a different operation.'
                    );
                }

                return $existing;
            }

            [, $rewardBalance] = $this->normalizedBucketBalances($user);
            $rewardCap = max(0, (int) (Setting::query()->value('reward_balance_cap') ?? 1200));
            $rewardRoom = max(0, $rewardCap - $rewardBalance);
            // A one-time offer is indivisible. Silently granting only the
            // remaining room would consume the task while paying less than
            // the amount shown to the learner.
            if ($requestedAmount <= 0 || $requestedAmount > $rewardRoom) {
                return null;
            }
            $creditedAmount = $requestedAmount;

            return $this->recordTransaction(
                $userId,
                $creditedAmount,
                WalletTransaction::DIRECTION_CREDIT,
                $category,
                $idempotencyKey,
                $source,
                array_merge($metadata, [
                    'requested_amount' => $requestedAmount,
                    'reward_balance_cap' => $rewardCap,
                ]),
                WalletTransaction::BUCKET_REWARD
            );
        }, 3);
    }

    /** Refunds preserve the original paid and reward attribution. */
    public function refundDebit(
        int $userId,
        int $amount,
        string $category,
        string $idempotencyKey,
        WalletTransaction $originalDebit,
        ?Model $source = null,
        array $metadata = []
    ): WalletTransaction {
        if ($amount < 0) {
            throw new \InvalidArgumentException('Invalid wallet debit refund.');
        }

        return DB::transaction(function () use (
            $userId,
            $amount,
            $category,
            $idempotencyKey,
            $originalDebit,
            $source,
            $metadata
        ): WalletTransaction {
            // Every wallet mutation serializes on the user aggregate. Keep the
            // remaining refundable allocation behind the same lock, otherwise
            // two distinct refund requests can both observe the full remainder.
            User::withTrashed()->whereKey($userId)->lockForUpdate()->firstOrFail();

            $lockedDebit = WalletTransaction::query()
                ->whereKey($originalDebit->getKey())
                ->where('user_id', $userId)
                ->where('direction', WalletTransaction::DIRECTION_DEBIT)
                ->lockForUpdate()
                ->first();
            if (!$lockedDebit || $amount > (int) $lockedDebit->amount) {
                throw new \InvalidArgumentException('Invalid wallet debit refund.');
            }
            $refundSource = $source ?? $lockedDebit;

            $existing = WalletTransaction::query()
                ->where('user_id', $userId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                $this->assertRefundReplay(
                    $existing,
                    $amount,
                    $category,
                    $refundSource,
                    (string) $lockedDebit->public_id
                );
                return $existing;
            }

            $previousRefunds = WalletTransaction::query()
                ->where('user_id', $userId)
                ->where('direction', WalletTransaction::DIRECTION_CREDIT)
                ->where('metadata->refunded_transaction_id', $lockedDebit->public_id)
                ->get(['paid_amount', 'reward_amount']);
            $remainingReward = max(
                0,
                (int) $lockedDebit->reward_amount - (int) $previousRefunds->sum('reward_amount')
            );
            $remainingPaid = max(
                0,
                (int) $lockedDebit->paid_amount - (int) $previousRefunds->sum('paid_amount')
            );
            if ($amount > $remainingReward + $remainingPaid) {
                throw new \InvalidArgumentException('Wallet debit refund exceeds the remaining allocation.');
            }

            $rewardAmount = min($amount, $remainingReward);
            $paidAmount = min($amount - $rewardAmount, $remainingPaid);

            // Unattributed legacy value remains reward value.
            $rewardAmount += max(0, $amount - $rewardAmount - $paidAmount);

            return $this->recordTransaction(
                $userId,
                $amount,
                WalletTransaction::DIRECTION_CREDIT,
                $category,
                $idempotencyKey,
                $refundSource,
                $metadata + ['refunded_transaction_id' => $lockedDebit->public_id],
                $paidAmount > 0 && $rewardAmount > 0
                    ? WalletTransaction::BUCKET_MIXED
                    : ($paidAmount > 0 ? WalletTransaction::BUCKET_PAID : WalletTransaction::BUCKET_REWARD),
                $paidAmount,
                $rewardAmount
            );
        }, 3);
    }

    private function assertRefundReplay(
        WalletTransaction $existing,
        int $amount,
        string $category,
        Model $source,
        string $originalPublicId
    ): void {
        if (
            $existing->direction !== WalletTransaction::DIRECTION_CREDIT
            || (int) $existing->amount !== $amount
            || !hash_equals((string) $existing->category, $category)
            || (string) $existing->source_type !== get_class($source)
            || (string) ($existing->source_id ?? '') !== (string) $source->getKey()
            || !hash_equals(
                (string) data_get($existing->metadata, 'refunded_transaction_id', ''),
                $originalPublicId
            )
        ) {
            throw new \UnexpectedValueException(
                'Wallet refund idempotency key was reused for a different operation.'
            );
        }
    }

    private function recordTransaction(
        int $userId,
        int $amount,
        string $direction,
        string $category,
        string $idempotencyKey,
        ?Model $source,
        array $metadata,
        ?string $creditBucket,
        ?int $forcedPaidAmount = null,
        ?int $forcedRewardAmount = null,
        ?int $maxRewardDebitAmount = null
    ): WalletTransaction {
        if ($amount < 0) {
            throw new \InvalidArgumentException('Wallet amount must be zero or greater.');
        }

        $fingerprint = $this->operationFingerprint(
            $amount,
            $direction,
            $category,
            $source,
            $creditBucket,
            $forcedPaidAmount,
            $forcedRewardAmount,
            $maxRewardDebitAmount
        );

        return DB::transaction(function () use (
            $userId,
            $amount,
            $direction,
            $category,
            $idempotencyKey,
            $source,
            $metadata,
            $creditBucket,
            $forcedPaidAmount,
            $forcedRewardAmount,
            $maxRewardDebitAmount,
            $fingerprint
        ): WalletTransaction {
            $existing = WalletTransaction::query()
                ->where('user_id', $userId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                $this->assertIdempotentReplay($existing, $fingerprint, $amount, $direction, $category, $source);
                return $existing;
            }

            /** @var User $user */
            // Retained financial orders can settle or reverse after account deletion.
            // The user row is anonymized and soft-deleted, but its ledger must remain balanced.
            $user = User::withTrashed()->lockForUpdate()->findOrFail($userId);

            // Recheck idempotency after acquiring the aggregate lock.
            $existing = WalletTransaction::query()
                ->where('user_id', $userId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                $this->assertIdempotentReplay($existing, $fingerprint, $amount, $direction, $category, $source);
                return $existing;
            }

            [$paidBalance, $rewardBalance] = $this->normalizedBucketBalances($user);
            $balance = $paidBalance + $rewardBalance;

            if ($direction === WalletTransaction::DIRECTION_DEBIT) {
                $rewardSpendable = $maxRewardDebitAmount === null
                    ? $rewardBalance
                    : min($rewardBalance, max(0, $maxRewardDebitAmount));
                $effectiveSpendable = $paidBalance + $rewardSpendable;
                if ($effectiveSpendable < $amount) {
                    throw new InsufficientWalletBalanceException($amount, $effectiveSpendable);
                }
            }

            if ($direction === WalletTransaction::DIRECTION_CREDIT) {
                if ($forcedPaidAmount !== null || $forcedRewardAmount !== null) {
                    $paidAmount = max(0, (int) $forcedPaidAmount);
                    $rewardAmount = max(0, (int) $forcedRewardAmount);
                    if ($paidAmount + $rewardAmount !== $amount) {
                        throw new \InvalidArgumentException('Wallet credit allocation must equal amount.');
                    }
                } elseif ($creditBucket === WalletTransaction::BUCKET_PAID) {
                    $paidAmount = $amount;
                    $rewardAmount = 0;
                } elseif ($creditBucket === WalletTransaction::BUCKET_REWARD) {
                    $paidAmount = 0;
                    $rewardAmount = $amount;
                } else {
                    throw new \InvalidArgumentException('Wallet credit bucket must be paid or reward.');
                }

                if (
                    $creditBucket === WalletTransaction::BUCKET_REWARD
                    && $forcedPaidAmount === null
                    && $forcedRewardAmount === null
                ) {
                    $rewardCap = max(0, (int) (Setting::query()->value('reward_balance_cap') ?? 1200));
                    if ($rewardBalance + $rewardAmount > $rewardCap) {
                        throw new \DomainException('reward_balance_cap_exceeded');
                    }
                }

                $paidBalance += $paidAmount;
                $rewardBalance += $rewardAmount;
            } else {
                // Debits consume reward value before paid value.
                $rewardLimit = $maxRewardDebitAmount === null
                    ? $amount
                    : max(0, min($amount, $maxRewardDebitAmount));
                $rewardAmount = min($rewardBalance, $amount, $rewardLimit);
                $paidAmount = $amount - $rewardAmount;
                $rewardBalance -= $rewardAmount;
                $paidBalance -= $paidAmount;
            }

            $newBalance = $paidBalance + $rewardBalance;
            $bucket = $paidAmount > 0 && $rewardAmount > 0
                ? WalletTransaction::BUCKET_MIXED
                : ($paidAmount > 0 ? WalletTransaction::BUCKET_PAID : WalletTransaction::BUCKET_REWARD);

            $user->forceFill([
                'wallet_coins' => $newBalance,
                'wallet_purchased_coins' => $paidBalance,
                'wallet_reward_coins' => $rewardBalance,
            ])->save();

            $metadata = array_merge($metadata, [
                'request_fingerprint' => $fingerprint,
                'allocation_policy' => $direction === WalletTransaction::DIRECTION_DEBIT
                    ? 'reward_first_then_paid'
                    : 'source_bucket',
                'paid_coins' => $paidAmount,
                'reward_coins' => $rewardAmount,
            ]);

            return WalletTransaction::create([
                'public_id' => (string) Str::uuid(),
                'user_id' => $userId,
                'direction' => $direction,
                'category' => $category,
                'bucket' => $bucket,
                'amount' => $amount,
                'paid_amount' => $paidAmount,
                'reward_amount' => $rewardAmount,
                'balance_after' => $newBalance,
                'paid_balance_after' => $paidBalance,
                'reward_balance_after' => $rewardBalance,
                'source_type' => $source ? get_class($source) : null,
                'source_id' => $source?->getKey(),
                'idempotency_key' => $idempotencyKey,
                'metadata' => $metadata ?: null,
                'occurred_at' => now(),
            ]);
        });
    }

    private function assertIdempotentReplay(
        WalletTransaction $existing,
        string $fingerprint,
        int $amount,
        string $direction,
        string $category,
        ?Model $source
    ): void {
        $storedFingerprint = (string) data_get($existing->metadata, 'request_fingerprint', '');
        $sameLegacyOperation = (int) $existing->amount === $amount
            && hash_equals((string) $existing->direction, $direction)
            && hash_equals((string) $existing->category, $category)
            && (string) $existing->source_type === ($source ? get_class($source) : '')
            && (string) ($existing->source_id ?? '') === (string) ($source?->getKey() ?? '');

        if (
            ($storedFingerprint !== '' && !hash_equals($storedFingerprint, $fingerprint))
            || ($storedFingerprint === '' && !$sameLegacyOperation)
        ) {
            throw new \UnexpectedValueException(
                'Wallet idempotency key was reused for a different operation.'
            );
        }
    }

    private function operationFingerprint(
        int $amount,
        string $direction,
        string $category,
        ?Model $source,
        ?string $creditBucket,
        ?int $forcedPaidAmount,
        ?int $forcedRewardAmount,
        ?int $maxRewardDebitAmount
    ): string {
        return hash('sha256', json_encode([
            'amount' => $amount,
            'direction' => $direction,
            'category' => $category,
            'source_type' => $source ? get_class($source) : null,
            'source_id' => $source?->getKey(),
            'credit_bucket' => $creditBucket,
            'forced_paid_amount' => $forcedPaidAmount,
            'forced_reward_amount' => $forcedRewardAmount,
            'max_reward_debit_amount' => $maxRewardDebitAmount,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array{0:int,1:int} Paid and reward balances. */
    private function normalizedBucketBalances(User $user): array
    {
        $total = max(0, (int) $user->wallet_coins);
        $paid = max(0, (int) $user->wallet_purchased_coins);
        $reward = max(0, (int) $user->wallet_reward_coins);
        $allocated = $paid + $reward;

        if ($allocated < $total) {
            $reward += $total - $allocated;
        } elseif ($allocated > $total) {
            $overage = $allocated - $total;
            $rewardReduction = min($reward, $overage);
            $reward -= $rewardReduction;
            $paid = max(0, $paid - ($overage - $rewardReduction));
        }

        return [$paid, $reward];
    }
}
