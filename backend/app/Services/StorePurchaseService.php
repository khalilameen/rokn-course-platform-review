<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\StorePurchaseProviderGateway;
use App\Exceptions\StorePurchaseVerificationException;
use App\Models\Order;
use App\Models\Package;
use App\Models\StoreNotificationEvent;
use App\Models\StorePurchase;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class StorePurchaseService
{
    public function __construct(
        private StorePurchaseProviderGateway $gateway,
        private StoreBillingAccountIdentity $identities,
        private OrderLifecycleService $orders,
        private WalletQueryService $wallet
    ) {
    }

    /** @return array<string, mixed> */
    public function verifyAndCredit(
        User $user,
        string $provider,
        string $productId,
        string $purchaseToken,
        ?string $transactionId
    ): array {
        $tokenHash = hash('sha256', $purchaseToken);

        $existing = StorePurchase::query()
            ->with('package')
            ->where('provider', $provider)
            ->where('purchase_token_hash', $tokenHash)
            ->first();
        if ($existing) {
            $this->assertEnvironmentMayCredit((string) $existing->environment);
            if (!$existing->package) {
                throw new StorePurchaseVerificationException(
                    'store_purchase_receipt_incomplete',
                    'تعذّر مطابقة عملية الشراء بالباقة',
                    409
                );
            }

            // Restore/finalization belongs to the immutable issued receipt.
            // A package may be retired after payment without making that paid
            // receipt impossible to recover on a new device.
            return $this->replay($existing, $user, $existing->package, $productId);
        }

        // Product ids and their coin value form the immutable fulfilment
        // contract. Availability flags only control whether a new sheet may
        // be opened; they must not invalidate a receipt already paid in the
        // provider UI.
        $package = $this->packageContractForProduct($provider, $productId);
        $contractCoins = (int) $package->coins;

        $binding = $provider === StorePurchase::PROVIDER_GOOGLE
            ? $this->identities->google($user)
            : $this->identities->apple($user);
        $verified = $this->gateway->verify(
            $provider,
            $productId,
            $purchaseToken,
            $transactionId,
            $binding
        );
        if (
            !hash_equals($provider, $verified->provider)
            || !hash_equals($productId, $verified->productId)
        ) {
            throw new StorePurchaseVerificationException('store_verification_contract_mismatch');
        }
        $this->assertEnvironmentMayCredit($verified->environment);

        $alreadyProcessed = false;
        try {
            $storePurchase = DB::transaction(function () use (
                $user,
                $package,
                $provider,
                $productId,
                $purchaseToken,
                $tokenHash,
                $verified,
                $contractCoins
            ): StorePurchase {
                /** @var Package $lockedPackage */
                $lockedPackage = Package::query()->lockForUpdate()->findOrFail($package->id);
                $providerProductColumn = $provider === StorePurchase::PROVIDER_GOOGLE
                    ? 'google_product_id'
                    : 'apple_product_id';
                if (
                    !hash_equals((string) $lockedPackage->{$providerProductColumn}, $productId)
                    || $contractCoins <= 0
                    || (int) $lockedPackage->coins !== $contractCoins
                ) {
                    throw new StorePurchaseVerificationException(
                        'store_product_catalog_changed',
                        "تغيّرت تفاصيل الباقة\nراجعها ثم حاول مرة أخرى",
                        409
                    );
                }
                $package = $lockedPackage;

                $existing = StorePurchase::query()
                    ->where('provider', $provider)
                    ->where(function ($query) use ($tokenHash, $verified): void {
                        $query->where('purchase_token_hash', $tokenHash)
                            ->orWhere('external_transaction_id', $verified->externalTransactionId);
                    })
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    $this->assertReplayMatches($existing, $user, $package, $productId);
                    return $existing;
                }

                $catalogAmount = (float) $package->price;
                $isTest = in_array(strtolower($verified->environment), ['test', 'sandbox', 'xcode'], true);
                $gatewayGross = $isTest
                    ? 0.0
                    : ($verified->grossAmount ?? $catalogAmount);
                $gatewayCurrency = $verified->currency ?? 'EGP';
                $transactionKey = $provider . ':' . $verified->externalTransactionId;

                $order = Order::query()->create([
                    'user_id' => $user->id,
                    'course_id' => null,
                    'package_id' => $package->id,
                    'package_coins' => (int) $package->coins,
                    'payment_method' => $provider === StorePurchase::PROVIDER_GOOGLE
                        ? Order::PAYMENT_METHOD_GOOGLE_PLAY
                        : Order::PAYMENT_METHOD_APP_STORE,
                    'order_ref' => strtoupper($provider) . '-' . Str::orderedUuid(),
                    'transaction_id' => $transactionKey,
                    'amount' => $catalogAmount,
                    'discount_amount' => 0,
                    'final_amount' => $catalogAmount,
                    'gateway_gross_amount' => $gatewayGross,
                    'gateway_currency' => $gatewayCurrency,
                    'gateway_settlement_status' => $isTest
                        ? 'test_purchase'
                        : ($verified->grossAmount === null ? 'catalog_estimate' : 'provider_verified'),
                    'total_coins' => (int) $package->coins,
                    'status' => Order::STATUS_PENDING,
                    'financial_status' => Order::FINANCIAL_PENDING,
                    'is_premium_user' => false,
                    'payment_gateway_response' => [
                        'provider' => $provider,
                        'product_id' => $productId,
                        'environment' => $verified->environment,
                        'verification' => $verified->auditPayload,
                    ],
                ]);

                $purchase = StorePurchase::query()->create([
                    'public_id' => (string) Str::uuid(),
                    'user_id' => $user->id,
                    'package_id' => $package->id,
                    'order_id' => $order->id,
                    'provider' => $provider,
                    'product_id' => $productId,
                    'external_transaction_id' => $verified->externalTransactionId,
                    'purchase_token_hash' => $tokenHash,
                    'purchase_token' => $purchaseToken,
                    'environment' => $verified->environment,
                    'status' => 'verified',
                    'provider_payload' => $verified->auditPayload,
                    'verified_at' => now(),
                ]);

                $approved = $this->orders->approve($order, null, null, true);
                $purchase->forceFill([
                    'status' => $approved->status === Order::STATUS_APPROVED
                        ? 'credited'
                        : 'review_required',
                ])->save();

                return $purchase->fresh(['order']);
            }, 3);
        } catch (QueryException $exception) {
            // Two devices can submit the same store receipt before either sees
            // the other's row. The database uniqueness constraint is the final
            // arbiter; the losing request becomes a normal idempotent replay.
            if (!in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw $exception;
            }
            $storePurchase = StorePurchase::query()
                ->where('provider', $provider)
                ->where(function ($query) use ($tokenHash, $verified): void {
                    $query->where('purchase_token_hash', $tokenHash)
                        ->orWhere('external_transaction_id', $verified->externalTransactionId);
                })
                ->with('order')
                ->first();
            if (!$storePurchase) throw $exception;
            $this->assertReplayMatches($storePurchase, $user, $package, $productId);
            $alreadyProcessed = true;
        }

        $this->reconcilePendingStoreNotifications($storePurchase);

        return $this->result(
            $storePurchase->fresh(['order']),
            $user,
            $alreadyProcessed
        );
    }

    /** @return array<string, mixed> */
    private function replay(
        StorePurchase $purchase,
        User $user,
        Package $package,
        string $productId
    ): array {
        $this->assertReplayMatches($purchase, $user, $package, $productId);
        $order = $purchase->order;
        if (!$order) {
            throw new StorePurchaseVerificationException(
                'store_purchase_receipt_incomplete',
                'تعذّر مطابقة عملية الشراء بالطلب',
                409
            );
        }

        // A replay may finish the one pending fulfilment created by the same
        // verified receipt. It must never turn a cancelled/rejected purchase,
        // or a refund/chargeback under review, back into settled money.
        if (
            $order->status === Order::STATUS_PENDING
            && $order->financial_status === Order::FINANCIAL_PENDING
            && !$order->reversed_at
        ) {
            $this->orders->approve($purchase->order, null, null, true);
            $purchase->forceFill(['status' => 'credited'])->save();
        }
        $this->reconcilePendingStoreNotifications($purchase);

        return $this->result($purchase->fresh(['order']), $user, true);
    }

    private function assertEnvironmentMayCredit(string $environment): void
    {
        $isTestReceipt = in_array(
            strtolower(trim($environment)),
            ['test', 'sandbox', 'xcode'],
            true
        );
        if ($isTestReceipt && app()->environment('production')) {
            throw new StorePurchaseVerificationException(
                'store_test_purchase_not_allowed',
                'عملية الاختبار غير متاحة على هذا الإصدار',
                422
            );
        }
    }

    /**
     * Refund notifications can beat the device receipt to our database. They
     * were already authenticated at ingestion, so once the matching purchase
     * exists we apply them in financial order instead of leaving a brief
     * credit-without-charge window for manual cleanup.
     */
    private function reconcilePendingStoreNotifications(StorePurchase $purchase): void
    {
        $query = StoreNotificationEvent::query()
            ->where('provider', $purchase->provider)
            ->where('status', StoreNotificationEvent::STATUS_REVIEW_REQUIRED)
            ->where('error_code', 'store_purchase_not_found');

        if ($purchase->provider === StorePurchase::PROVIDER_GOOGLE) {
            $query->where(function ($events) use ($purchase): void {
                $events->where('payload->purchase_token_sha256', $purchase->purchase_token_hash)
                    ->orWhere('payload->order_id', $purchase->external_transaction_id);
            });
        } else {
            $query->where(function ($events) use ($purchase): void {
                $events->where('payload->transaction_id', $purchase->external_transaction_id)
                    ->orWhere('payload->original_transaction_id', $purchase->external_transaction_id);
            });
        }

        $events = $query->get()->sortBy(function (StoreNotificationEvent $event): int {
            return strtolower((string) $event->event_type) === 'refund_reversed' ? 1 : 0;
        });
        foreach ($events as $event) {
            $payload = is_array($event->payload) ? $event->payload : [];
            $eventType = strtolower((string) $event->event_type);
            if ($purchase->provider === StorePurchase::PROVIDER_GOOGLE) {
                if ($eventType !== 'voided_purchase') continue;
                $this->orders->registerReversal(
                    $purchase->order,
                    Order::FINANCIAL_REFUNDED,
                    (int) ($payload['refund_type'] ?? 1) === 2
                        ? 'Google Play quantity-based refund'
                        : 'Google Play voided purchase',
                    'store-notification:google:' . $event->event_id,
                    null,
                    Order::PAYMENT_METHOD_GOOGLE_PLAY,
                    trim((string) ($payload['order_id'] ?? '')) ?: $event->event_id,
                    $payload
                );
                $purchase->forceFill(['status' => 'refunded'])->save();
            } elseif ($eventType === 'refund') {
                $this->orders->registerReversal(
                    $purchase->order,
                    Order::FINANCIAL_REFUNDED,
                    'App Store refund',
                    'store-notification:apple:' . $event->event_id,
                    null,
                    Order::PAYMENT_METHOD_APP_STORE,
                    (string) $purchase->external_transaction_id,
                    $payload
                );
                $purchase->forceFill(['status' => 'refunded'])->save();
            } elseif (
                $eventType === 'refund_reversed'
                && $purchase->order->fresh()->financial_status === Order::FINANCIAL_REVIEW_REQUIRED
            ) {
                $this->orders->resolveFinancialReview(
                    $purchase->order,
                    'repaid',
                    'store-notification:apple:' . $event->event_id,
                    null,
                    'App Store reversed a prior refund.'
                );
                $purchase->forceFill(['status' => 'credited'])->save();
            } else {
                continue;
            }

            $event->forceFill([
                'status' => StoreNotificationEvent::STATUS_PROCESSED,
                'error_code' => null,
                'processed_at' => now(),
            ])->save();
        }
    }

    private function assertReplayMatches(
        StorePurchase $purchase,
        User $user,
        Package $package,
        string $productId
    ): void {
        if (
            (int) $purchase->user_id !== (int) $user->id
            || (int) $purchase->package_id !== (int) $package->id
            || !hash_equals((string) $purchase->product_id, $productId)
        ) {
            throw new StorePurchaseVerificationException(
                'store_purchase_already_claimed',
                'عملية الشراء مرتبطة بحساب آخر',
                409
            );
        }
    }

    private function packageContractForProduct(string $provider, string $productId): Package
    {
        $idColumn = match ($provider) {
            StorePurchase::PROVIDER_GOOGLE => 'google_product_id',
            StorePurchase::PROVIDER_APPLE => 'apple_product_id',
            default => throw new StorePurchaseVerificationException('unsupported_store_provider'),
        };
        $package = Package::query()
            ->where($idColumn, $productId)
            ->where('coins', '>', 0)
            ->first();
        if (!$package) {
            throw new StorePurchaseVerificationException(
                'store_product_not_configured',
                'باقة الشحن غير متاحة الآن'
            );
        }

        return $package;
    }

    /** @return array<string, mixed> */
    private function result(
        StorePurchase $purchase,
        User $user,
        bool $alreadyProcessed
    ): array {
        $order = $purchase->relationLoaded('order')
            ? $purchase->order
            : $purchase->order()->first();
        $isSettled = $order
            && $order->status === Order::STATUS_APPROVED
            && $order->financial_status === Order::FINANCIAL_SETTLED
            && !$order->reversed_at;
        $creditedCoins = $isSettled
            ? max(0, (int) ($order->package_coins ?? 0))
            : 0;
        if ($creditedCoins === 0 && $isSettled) {
            $creditedCoins = max(0, (int) $order->paidCreditLot()->value('original_amount'));
        }

        return [
            'purchase_id' => $purchase->public_id,
            'provider' => $purchase->provider,
            'product_id' => $purchase->product_id,
            'environment' => $purchase->environment,
            // The issued order is the purchase receipt. Package rows remain
            // editable catalogue data and must never rewrite a past credit.
            'coins_added' => $creditedCoins,
            'already_processed' => $alreadyProcessed,
            'finalize_transaction' => true,
            'wallet' => $this->wallet->summary($user),
        ];
    }
}
