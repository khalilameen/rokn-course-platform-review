<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use App\Models\WalletTransaction;

final readonly class WalletQueryService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(User $user): array
    {
        $recent = WalletTransaction::query()
            ->where('user_id', $user->id)
            ->latest('occurred_at')
            ->limit(10)
            ->get()
            ->map(fn (WalletTransaction $transaction): array => $this->payload($transaction));

        $freshUser = $user->fresh();
        $setting = Setting::query()->first() ?? new Setting();
        $totalBalance = max(0, (int) $freshUser->wallet_coins);
        $purchasedBalance = min($totalBalance, max(0, (int) $freshUser->wallet_purchased_coins));
        $rewardBalance = $totalBalance - $purchasedBalance;
        $rewardContributionCap = max(0, (int) ($setting->max_reward_contribution_per_course ?? 1200));
        $courseSpendableBalance = $purchasedBalance + min($rewardBalance, $rewardContributionCap);

        return [
            'balance' => $totalBalance,
            'total_balance' => $totalBalance,
            'purchased_balance' => $purchasedBalance,
            'reward_balance' => $rewardBalance,
            'course_spendable_balance' => $courseSpendableBalance,
            'reward_contribution_cap_per_course' => $rewardContributionCap,
            'breakdown' => [
                'total_balance' => $totalBalance,
                'purchased_balance' => $purchasedBalance,
                'reward_balance' => $rewardBalance,
                'course_spendable_balance' => $courseSpendableBalance,
                'reward_contribution_cap_per_course' => $rewardContributionCap,
            ],
            'spend_policy' => 'reward_first_then_paid',
            'coin_rules' => $setting->how_to_use_coins,
            'currency_type' => 'rokn_coins',
            'is_withdrawable' => false,
            'recent_transactions' => $recent,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function transactions(User $user, int $perPage): array
    {
        $transactions = WalletTransaction::query()
            ->where('user_id', $user->id)
            ->latest('occurred_at')
            ->paginate($perPage);

        return [
            'transactions' => $transactions->getCollection()
                ->map(fn (WalletTransaction $item): array => $this->payload($item)),
            'pagination' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(WalletTransaction $transaction): array
    {
        return [
            'id' => $transaction->public_id,
            'direction' => $transaction->direction,
            'category' => $transaction->category,
            'bucket' => $transaction->bucket,
            'amount' => $transaction->amount,
            'paid_coins' => $transaction->paid_amount,
            'reward_coins' => $transaction->reward_amount,
            'balance_after' => $transaction->balance_after,
            'purchased_balance_after' => $transaction->paid_balance_after,
            'reward_balance_after' => $transaction->reward_balance_after,
            'source' => $transaction->source_type ? [
                'type' => class_basename($transaction->source_type),
                'id' => $transaction->source_id,
            ] : null,
            'metadata' => $transaction->metadata,
            'occurred_at' => $transaction->occurred_at?->toIso8601String(),
        ];
    }
}
