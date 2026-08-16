<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Bill;
use App\Models\CourseEnrollment;
use App\Models\Order;
use App\Models\OrderFinancialEvent;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

final readonly class OrderLifecycleService
{
    public function __construct(
        private WalletService $wallet,
        private FinancialProvenanceService $provenance
    ) {
    }

    /** Approval side effects use order-scoped idempotency keys. */
    public function approve(Order $order, ?int $actorId = null, ?string $notes = null): Order
    {
        return DB::transaction(function () use ($order, $actorId, $notes): Order {
            User::query()->lockForUpdate()->findOrFail($order->user_id);
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->assertOrderShape($locked);

            if ($locked->reversed_at || in_array($locked->financial_status, [
                Order::FINANCIAL_REFUNDED,
                Order::FINANCIAL_CHARGEBACK,
                Order::FINANCIAL_REVERSED,
                Order::FINANCIAL_PARTIALLY_RECOVERED,
            ], true)) {
                throw new \DomainException('A financially reversed order cannot be approved again.');
            }

            if ($locked->status !== Order::STATUS_APPROVED) {
                if (
                    $locked->course_id
                    && $locked->payment_method === Order::PAYMENT_METHOD_WALLET_COINS
                ) {
                    throw new \DomainException(
                        'Wallet course orders can only be created by the wallet purchase flow.'
                    );
                }

                $locked->forceFill([
                    'status' => Order::STATUS_APPROVED,
                    'financial_status' => Order::FINANCIAL_SETTLED,
                    'approved_at' => now(),
                    'approved_by' => $actorId,
                    'notes' => $notes ?? $locked->notes,
                ])->save();
            } else {
                $locked->forceFill(['financial_status' => Order::FINANCIAL_SETTLED])->save();
            }

            if ($locked->package_id) {
                $this->fulfillPackage($locked);
            } else {
                $this->fulfillCourse($locked);
            }
            if ($locked->course_id) {
                $this->syncBill($locked, Bill::PAYMENT_STATUS_PAID);
            }
            $this->recordEvent($locked, 'approved', 'approval', $actorId, $notes);

            return $locked->fresh(['bill', 'course', 'package', 'user']);
        }, 3);
    }

    /** Only pending orders can be rejected. */
    public function rejectPending(Order $order, ?int $actorId = null, ?string $reason = null): Order
    {
        return DB::transaction(function () use ($order, $actorId, $reason): Order {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->status === Order::STATUS_APPROVED) {
                throw new \DomainException(
                    'A settled order cannot be rejected. Register a refund or chargeback for finance review.'
                );
            }

            $locked->forceFill([
                'status' => Order::STATUS_REJECTED,
                'financial_status' => Order::FINANCIAL_REJECTED,
                'approved_at' => null,
                'approved_by' => null,
                'notes' => $reason ?? $locked->notes,
            ])->save();
            if ($locked->course_id) {
                $this->syncBill($locked, Bill::PAYMENT_STATUS_CANCELLED);
            }
            $this->recordEvent($locked, 'rejected', 'rejection', $actorId, $reason);

            return $locked->fresh(['bill']);
        }, 3);
    }

    public function cancelPending(Order $order, ?int $actorId = null, ?string $reason = null): Order
    {
        return DB::transaction(function () use ($order, $actorId, $reason): Order {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->status === Order::STATUS_APPROVED) {
                throw new \DomainException(
                    'A settled order cannot be cancelled. Register a refund or chargeback for finance review.'
                );
            }
            $locked->forceFill([
                'status' => Order::STATUS_CANCELLED,
                'financial_status' => Order::FINANCIAL_CANCELLED,
                'notes' => $reason ?? $locked->notes,
            ])->save();
            if ($locked->course_id) {
                $this->syncBill($locked, Bill::PAYMENT_STATUS_CANCELLED);
            }
            $this->recordEvent($locked, 'cancelled', 'cancellation', $actorId, $reason);

            return $locked->fresh(['bill']);
        }, 3);
    }

    /** Record an external reversal exactly once with paid-source attribution. */
    public function registerReversal(
        Order $order,
        string $type,
        string $reason,
        string $eventKey,
        ?int $actorId = null,
        ?string $provider = null,
        ?string $externalEventId = null,
        array $payload = []
    ): Order {
        $allowed = [
            Order::FINANCIAL_REFUNDED,
            Order::FINANCIAL_CHARGEBACK,
            Order::FINANCIAL_REVERSED,
        ];
        if (!in_array($type, $allowed, true) || trim($eventKey) === '') {
            throw new \InvalidArgumentException('Invalid financial reversal event.');
        }

        return DB::transaction(function () use (
            $order,
            $type,
            $reason,
            $eventKey,
            $actorId,
            $provider,
            $externalEventId,
            $payload
        ): Order {
            User::query()->lockForUpdate()->findOrFail($order->user_id);
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $existing = OrderFinancialEvent::query()
                ->where('order_id', $locked->id)
                ->where('event_key', $eventKey)
                ->first();
            if ($existing) {
                return $locked->fresh(['bill']);
            }

            $locked->loadMissing('package');
            $atRisk = $locked->package_id
                ? max(0, (int) ($locked->package_coins ?? $locked->package?->coins ?? 0))
                : max(0, (int) $locked->total_coins);
            $result = $locked->package_id
                ? $this->provenance->applyPackageReversal($locked, $reason)
                : ['recovered' => 0, 'unrecovered' => $atRisk, 'holds' => 0];
            $locked->forceFill([
                'financial_status' => Order::FINANCIAL_REVIEW_REQUIRED,
                'reversed_at' => $locked->reversed_at ?: now(),
                'reversal_reason' => $reason,
                'recovered_coins' => (int) $result['recovered'],
                'unrecovered_coins' => (int) $result['unrecovered'],
            ])->save();
            $this->recordEvent(
                $locked,
                $type,
                $eventKey,
                $actorId,
                $reason,
                $provider,
                $externalEventId,
                $payload,
                (int) $result['recovered'],
                (int) $result['unrecovered']
            );

            return $locked->fresh(['bill']);
        }, 3);
    }

    /** Resolve a reviewed reversal without rewriting its financial history. */
    public function resolveFinancialReview(
        Order $order,
        string $resolution,
        string $eventKey,
        ?int $actorId = null,
        ?string $note = null
    ): Order {
        if (!in_array($resolution, ['repaid', 'waived'], true) || trim($eventKey) === '') {
            throw new \InvalidArgumentException('Invalid financial review resolution.');
        }

        return DB::transaction(function () use (
            $order,
            $resolution,
            $eventKey,
            $actorId,
            $note
        ): Order {
            User::query()->lockForUpdate()->findOrFail($order->user_id);
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $existing = OrderFinancialEvent::query()
                ->where('order_id', $locked->id)
                ->where('event_key', $eventKey)
                ->first();
            if ($existing) {
                if ($existing->event_type !== 'resolution_' . $resolution) {
                    throw new \UnexpectedValueException(
                        'Financial resolution event key was reused for another decision.'
                    );
                }

                return $locked->fresh(['bill']);
            }
            if (
                !$locked->package_id
                || $locked->financial_status !== Order::FINANCIAL_REVIEW_REQUIRED
            ) {
                throw new \DomainException('Only a package under financial review can be resolved.');
            }

            $result = $this->provenance->resolvePackageReversal(
                $locked,
                $resolution,
                $actorId,
                $note
            );
            if ($resolution === 'repaid') {
                $locked->forceFill([
                    'financial_status' => Order::FINANCIAL_SETTLED,
                    'reversed_at' => null,
                    'reversal_reason' => null,
                    'recovered_coins' => 0,
                    'unrecovered_coins' => 0,
                ])->save();
            } else {
                $locked->forceFill([
                    'financial_status' => Order::FINANCIAL_REVERSED,
                    'reversal_reason' => $note ?: $locked->reversal_reason,
                ])->save();
            }

            $this->recordEvent(
                $locked,
                'resolution_' . $resolution,
                $eventKey,
                $actorId,
                $note,
                null,
                null,
                $result,
                $resolution === 'repaid' ? (int) $result['restored_coins'] : 0,
                $resolution === 'waived' ? (int) $locked->unrecovered_coins : 0
            );

            return $locked->fresh(['bill']);
        }, 3);
    }

    public function reconcile(Order $order): Order
    {
        $fresh = $order->fresh();
        if ($fresh->status === Order::STATUS_APPROVED && !$fresh->reversed_at) {
            return $this->approve($fresh, $fresh->approved_by, $fresh->notes);
        }
        if ($fresh->status === Order::STATUS_REJECTED) {
            return $this->rejectPending($fresh, null, $fresh->notes);
        }
        if ($fresh->status === Order::STATUS_CANCELLED) {
            return $this->cancelPending($fresh, null, $fresh->notes);
        }

        return $fresh;
    }

    public function expectedBillStatus(Order $order): string
    {
        if ($order->status === Order::STATUS_APPROVED && !$order->reversed_at) {
            return Bill::PAYMENT_STATUS_PAID;
        }

        return $order->status === Order::STATUS_PENDING
            ? Bill::PAYMENT_STATUS_PENDING
            : Bill::PAYMENT_STATUS_CANCELLED;
    }

    public function reconcileBill(Bill $bill): Bill
    {
        return DB::transaction(function () use ($bill): Bill {
            /** @var Bill $locked */
            $locked = Bill::query()->lockForUpdate()->findOrFail($bill->id);
            $order = Order::query()->lockForUpdate()->findOrFail($locked->order_id);

            return $this->syncBill($order, $this->expectedBillStatus($order));
        }, 3);
    }

    private function assertOrderShape(Order $order): void
    {
        if ((bool) $order->course_id === (bool) $order->package_id) {
            throw new \DomainException('An order must reference exactly one course or coin package.');
        }
        if (!$order->user_id) {
            throw new \DomainException('An order must belong to a learner.');
        }
    }

    private function fulfillPackage(Order $order): void
    {
        $order->loadMissing(['package', 'user']);
        $coins = max(0, (int) ($order->package_coins ?? $order->package?->coins ?? 0));
        if (!$order->package || !$order->user || $coins <= 0 || (float) $order->final_amount <= 0) {
            throw new \DomainException('Coin package order is incomplete and cannot be approved.');
        }

        $existingCredit = WalletTransaction::query()
            ->where('user_id', $order->user_id)
            ->where('direction', WalletTransaction::DIRECTION_CREDIT)
            ->where('category', 'package_purchase')
            ->where('source_type', Order::class)
            ->where('source_id', $order->id)
            ->first();
        if (!$existingCredit) {
            $existingCredit = $this->wallet->credit(
                (int) $order->user_id,
                $coins,
                'package_purchase',
                'order-lifecycle:package-credit:' . $order->id,
                $order,
                [
                    'package_id' => $order->package_id,
                    'transaction_id' => $order->transaction_id,
                ],
                WalletTransaction::BUCKET_PAID
            );
        }
        $this->provenance->recordPaidPackageCredit($order, $existingCredit);
        if ($order->package_coins === null) {
            $order->forceFill(['package_coins' => $coins])->save();
        }

        DB::table('package_user')->updateOrInsert(
            ['order_id' => $order->id],
            [
                'user_id' => $order->user_id,
                'package_id' => $order->package_id,
                'price' => $order->final_amount,
                'coins' => $coins,
                'created_at' => $order->approved_at ?: now(),
                'updated_at' => now(),
            ]
        );
    }

    private function fulfillCourse(Order $order): void
    {
        $order->loadMissing(['course', 'user']);
        if (!$order->course || !$order->user) {
            throw new \DomainException('Course order is incomplete and cannot be approved.');
        }

        $enrollment = CourseEnrollment::query()
            ->where('user_id', $order->user_id)
            ->where('course_id', $order->course_id)
            ->lockForUpdate()
            ->first() ?: new CourseEnrollment([
                'user_id' => $order->user_id,
                'course_id' => $order->course_id,
                'enrolled_at' => now(),
            ]);
        $enrollment->forceFill([
            'order_id' => $order->id,
            'is_active' => true,
            'access_granted_at' => $enrollment->access_granted_at ?: now(),
            'expires_at' => null,
        ])->save();
    }

    private function syncBill(Order $order, string $status): Bill
    {
        /** @var Bill $bill */
        $bill = Bill::withTrashed()->where('order_id', $order->id)->lockForUpdate()->first()
            ?: new Bill(['order_id' => $order->id]);
        if ($bill->trashed()) {
            $bill->restore();
        }
        $bill->forceFill([
            'user_id' => $order->user_id,
            'course_id' => $order->course_id,
            'bill_number' => $bill->bill_number ?: 'BILL-ORDER-' . $order->id,
            'amount' => $order->amount,
            'tax_amount' => 0,
            'total_amount' => $order->final_amount,
            'payment_status' => $status,
            'payment_method' => $order->payment_method,
            'due_date' => $order->created_at ?: now(),
            'paid_at' => $status === Bill::PAYMENT_STATUS_PAID
                ? ($bill->paid_at ?: $order->approved_at ?: now())
                : null,
            'notes' => $order->reversal_reason ?: $order->notes,
        ])->save();

        return $bill;
    }

    private function recordEvent(
        Order $order,
        string $type,
        string $key,
        ?int $actorId,
        ?string $reason,
        ?string $provider = null,
        ?string $externalEventId = null,
        array $payload = [],
        int $recovered = 0,
        int $unrecovered = 0
    ): OrderFinancialEvent {
        return OrderFinancialEvent::query()->firstOrCreate(
            ['order_id' => $order->id, 'event_key' => $key],
            [
                'actor_id' => $actorId,
                'event_type' => $type,
                'provider' => $provider,
                'external_event_id' => $externalEventId,
                'recovered_coins' => $recovered,
                'unrecovered_coins' => $unrecovered,
                'reason' => $reason,
                'payload' => $payload ?: null,
                'occurred_at' => now(),
            ]
        );
    }
}
