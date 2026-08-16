<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\Order;
use App\Models\WalletDebitAllocation;
use App\Models\WalletTransaction;
use App\Services\FinancialProvenanceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class BackfillFinancialProvenance extends Command
{
    protected $signature = 'finance:backfill-provenance
        {--apply : Persist deterministic lots and allocations}
        {--user= : Restrict the audit/backfill to one learner ID}';

    protected $description = 'Audit and deterministically backfill paid coin sources and course allocations';

    public function handle(FinancialProvenanceService $provenance): int
    {
        if (!$provenance->schemaAvailable()) {
            $this->error('Financial provenance tables are missing. Run migrations first.');
            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $userId = $this->option('user') !== null ? (int) $this->option('user') : null;
        if ($userId !== null && $userId <= 0) {
            $this->error('--user must be a positive learner ID.');
            return self::INVALID;
        }

        $query = WalletTransaction::query()
            ->where(function ($query): void {
                $query->where(function ($credits): void {
                    $credits->where('direction', WalletTransaction::DIRECTION_CREDIT)
                        ->where('category', 'package_purchase')
                        ->where('paid_amount', '>', 0);
                })->orWhere(function ($debits): void {
                    $debits->where('direction', WalletTransaction::DIRECTION_DEBIT)
                        ->whereIn('category', ['course_purchase', 'course_chat_upgrade', 'course_full_track_upgrade'])
                        ->where('paid_amount', '>', 0);
                });
            })
            ->when($userId, fn ($builder) => $builder->where('user_id', $userId))
            ->orderBy('user_id')
            ->orderBy('occurred_at')
            ->orderBy('id');

        $stats = [
            'package_credits' => 0,
            'course_debits' => 0,
            'lots_written' => 0,
            'allocations_written' => 0,
            'unresolved' => 0,
        ];

        // Stream in ledger chronology. chunkById would reorder by primary key
        // and could let a later package fund an earlier historical debit.
        foreach ($query->cursor() as $transaction) {
                try {
                    if ($transaction->direction === WalletTransaction::DIRECTION_CREDIT) {
                        $stats['package_credits']++;
                        $order = $this->packageOrderForCredit($transaction);
                        if (!$order) {
                            $stats['unresolved']++;
                            $this->warn("Credit #{$transaction->id}: source package order is missing or inconsistent.");
                            continue;
                        }
                        if ($apply) {
                            $before = $order->paidCreditLot()->exists();
                            $provenance->recordPaidPackageCredit($order, $transaction);
                            $stats['lots_written'] += $before ? 0 : 1;
                        }
                        continue;
                    }

                    $stats['course_debits']++;
                    $order = $this->courseOrderForDebit($transaction);
                    if (!$order) {
                        $stats['unresolved']++;
                        $this->warn("Debit #{$transaction->id}: no unique immutable course order match.");
                        continue;
                    }
                    if ($apply) {
                        $before = WalletDebitAllocation::query()
                            ->where('wallet_transaction_id', $transaction->id)
                            ->count();
                        $provenance->allocateCourseDebit($order, $transaction);
                        $after = WalletDebitAllocation::query()
                            ->where('wallet_transaction_id', $transaction->id)
                            ->count();
                        $stats['allocations_written'] += max(0, $after - $before);
                    }
                } catch (Throwable $exception) {
                    $stats['unresolved']++;
                    $this->warn(
                        "Transaction #{$transaction->id}: {$exception->getMessage()}"
                    );
                }
        }

        $remaining = $this->remainingAuditFailures($userId);
        $this->table(['Check', 'Count'], [
            ['Package paid credits', $stats['package_credits']],
            ['Course paid debits', $stats['course_debits']],
            ['Lots written', $stats['lots_written']],
            ['Allocation rows written', $stats['allocations_written']],
            ['Unresolved during scan', $stats['unresolved']],
            ['Package credits without lot', $remaining['missing_lots']],
            ['Paid debits with incomplete allocation', $remaining['incomplete_debits']],
            ['Paid course orders with incomplete allocation', $remaining['incomplete_orders']],
            ['Historical reversals needing finance review', $remaining['unreconciled_reversals']],
            ['Learners whose paid balance does not match active lots', $remaining['balance_mismatches']],
        ]);

        if (!$apply) {
            $this->comment('Dry run only. Re-run with --apply, then repeat without flags to verify zero failures.');
        }

        return $stats['unresolved'] === 0 && array_sum($remaining) === 0
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function packageOrderForCredit(WalletTransaction $credit): ?Order
    {
        if ($credit->source_type !== Order::class || !$credit->source_id) {
            return null;
        }

        return Order::query()
            ->whereKey($credit->source_id)
            ->where('user_id', $credit->user_id)
            ->whereNotNull('package_id')
            ->where('status', Order::STATUS_APPROVED)
            ->whereIn('financial_status', [
                Order::FINANCIAL_SETTLED,
                Order::FINANCIAL_REFUNDED,
                Order::FINANCIAL_CHARGEBACK,
                Order::FINANCIAL_REVIEW_REQUIRED,
                Order::FINANCIAL_REVERSED,
                Order::FINANCIAL_PARTIALLY_RECOVERED,
            ])
            ->first();
    }

    private function courseOrderForDebit(WalletTransaction $debit): ?Order
    {
        if ($debit->source_type !== Course::class || !$debit->source_id) {
            return null;
        }

        $query = Order::query()
            ->where('user_id', $debit->user_id)
            ->where('course_id', $debit->source_id)
            ->where('status', Order::STATUS_APPROVED)
            ->where('payment_method', Order::PAYMENT_METHOD_WALLET_COINS)
            ->where('paid_coins', $debit->paid_amount)
            ->where('reward_coins', $debit->reward_amount);

        if ($debit->category === 'course_purchase') {
            $query->where('notes', 'Idempotency: ' . $debit->idempotency_key);
        } else {
            $parentOrderId = (int) (
                data_get($debit->metadata, 'parent_order_id')
                ?: data_get($debit->metadata, 'grant_order_id', 0)
            );
            if ($parentOrderId <= 0) {
                return null;
            }
            if (
                $debit->category === 'course_full_track_upgrade'
                && Schema::hasColumn('orders', 'parent_order_id')
            ) {
                $query->where('parent_order_id', $parentOrderId);
            } else {
                $query->where(function ($notes) use ($parentOrderId): void {
                    $notes->where(
                        'notes',
                        'Rokn AI/full-access upgrade from grant order #' . $parentOrderId
                    )->orWhere(
                        'notes',
                        'Course access-plan upgrade from order #' . $parentOrderId
                    );
                });
            }
        }

        $matches = $query->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /** @return array{missing_lots:int,incomplete_debits:int,incomplete_orders:int,unreconciled_reversals:int,balance_mismatches:int} */
    private function remainingAuditFailures(?int $userId): array
    {
        $creditQuery = DB::table('wallet_transactions as wt')
            ->leftJoin('wallet_credit_lots as lot', 'lot.credit_transaction_id', '=', 'wt.id')
            ->where('wt.direction', WalletTransaction::DIRECTION_CREDIT)
            ->where('wt.category', 'package_purchase')
            ->where('wt.paid_amount', '>', 0)
            ->whereNull('lot.id')
            ->when($userId, fn ($query) => $query->where('wt.user_id', $userId));

        $debitRows = DB::table('wallet_transactions as wt')
            ->leftJoin('wallet_debit_allocations as allocation', 'allocation.wallet_transaction_id', '=', 'wt.id')
            ->where('wt.direction', WalletTransaction::DIRECTION_DEBIT)
            ->whereIn('wt.category', ['course_purchase', 'course_chat_upgrade', 'course_full_track_upgrade'])
            ->where('wt.paid_amount', '>', 0)
            ->when($userId, fn ($query) => $query->where('wt.user_id', $userId))
            ->groupBy('wt.id', 'wt.paid_amount')
            ->havingRaw('COALESCE(SUM(allocation.amount), 0) <> wt.paid_amount');

        $orderRows = DB::table('orders as orders')
            ->leftJoin('wallet_debit_allocations as allocation', 'allocation.course_order_id', '=', 'orders.id')
            ->whereNotNull('orders.course_id')
            ->where('orders.payment_method', Order::PAYMENT_METHOD_WALLET_COINS)
            ->where('orders.status', Order::STATUS_APPROVED)
            ->where('orders.paid_coins', '>', 0)
            ->when($userId, fn ($query) => $query->where('orders.user_id', $userId))
            ->groupBy('orders.id', 'orders.paid_coins')
            ->havingRaw('COALESCE(SUM(allocation.amount), 0) <> orders.paid_coins');

        $balanceRows = DB::table('users as users')
            ->leftJoin('wallet_credit_lots as lot', function ($join): void {
                $join->on('lot.user_id', '=', 'users.id')
                    ->where('lot.status', '=', 'active');
            })
            ->when($userId, fn ($query) => $query->where('users.id', $userId))
            ->groupBy('users.id', 'users.wallet_purchased_coins')
            ->havingRaw('COALESCE(SUM(lot.remaining_amount), 0) <> users.wallet_purchased_coins');

        // Never mutate a historical reversal in a bulk migration. Flag it so
        // finance can reconcile the exact provider event/order deliberately.
        $unreconciledReversals = DB::table('orders as orders')
            ->leftJoin('wallet_credit_lots as lot', 'lot.source_order_id', '=', 'orders.id')
            ->whereNotNull('orders.package_id')
            ->where('orders.status', Order::STATUS_APPROVED)
            ->where(function ($query): void {
                $query->whereNotNull('orders.reversed_at')
                    ->orWhereIn('orders.financial_status', [
                        Order::FINANCIAL_REFUNDED,
                        Order::FINANCIAL_CHARGEBACK,
                        Order::FINANCIAL_REVERSED,
                        Order::FINANCIAL_PARTIALLY_RECOVERED,
                        Order::FINANCIAL_REVIEW_REQUIRED,
                    ]);
            })
            ->where(function ($query): void {
                $query->whereNull('lot.id')
                    ->orWhere('lot.status', 'active');
            })
            ->when($userId, fn ($query) => $query->where('orders.user_id', $userId))
            ->count();

        return [
            'missing_lots' => $creditQuery->count(),
            'incomplete_debits' => DB::query()->fromSub($debitRows->select('wt.id'), 'x')->count(),
            'incomplete_orders' => DB::query()->fromSub($orderRows->select('orders.id'), 'x')->count(),
            'unreconciled_reversals' => $unreconciledReversals,
            'balance_mismatches' => DB::query()->fromSub($balanceRows->select('users.id'), 'x')->count(),
        ];
    }
}
