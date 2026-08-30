<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\StorePurchaseProviderGateway;
use App\Exceptions\StorePurchaseVerificationException;
use App\Models\Order;
use App\Models\Package;
use App\Models\StorePurchase;
use App\Models\User;
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
        $package = $this->packageForProduct($provider, $productId);
        $tokenHash = hash('sha256', $purchaseToken);

        $existing = StorePurchase::query()
            ->where('provider', $provider)
            ->where('purchase_token_hash', $tokenHash)
            ->first();
        if ($existing) {
            return $this->replay($existing, $user, $package, $productId);
        }

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

        $storePurchase = DB::transaction(function () use (
            $user,
            $package,
            $provider,
            $productId,
            $purchaseToken,
            $tokenHash,
            $verified
        ): StorePurchase {
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

            $approved = $this->orders->approve($order);
            $purchase->forceFill([
                'status' => $approved->status === Order::STATUS_APPROVED
                    ? 'credited'
                    : 'review_required',
            ])->save();

            return $purchase->fresh(['order']);
        }, 3);

        return $this->result($storePurchase, $user, $package, false);
    }

    /** @return array<string, mixed> */
    private function replay(
        StorePurchase $purchase,
        User $user,
        Package $package,
        string $productId
    ): array {
        $this->assertReplayMatches($purchase, $user, $package, $productId);
        if ($purchase->order?->status !== Order::STATUS_APPROVED) {
            $this->orders->approve($purchase->order);
            $purchase->forceFill(['status' => 'credited'])->save();
        }

        return $this->result($purchase->fresh(['order']), $user, $package, true);
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
                'تم ربط عملية الشراء بحساب آخر من قبل.',
                409
            );
        }
    }

    private function packageForProduct(string $provider, string $productId): Package
    {
        [$idColumn, $enabledColumn] = match ($provider) {
            StorePurchase::PROVIDER_GOOGLE => ['google_product_id', 'google_enabled'],
            StorePurchase::PROVIDER_APPLE => ['apple_product_id', 'apple_enabled'],
            default => throw new StorePurchaseVerificationException('unsupported_store_provider'),
        };

        $package = Package::query()
            ->where($idColumn, $productId)
            ->where($enabledColumn, true)
            ->where('price', '>', 0)
            ->where('coins', '>', 0)
            ->first();
        if (!$package) {
            throw new StorePurchaseVerificationException(
                'store_product_not_configured',
                'منتج الشحن غير متاح حاليًا.'
            );
        }

        return $package;
    }

    /** @return array<string, mixed> */
    private function result(
        StorePurchase $purchase,
        User $user,
        Package $package,
        bool $alreadyProcessed
    ): array {
        return [
            'purchase_id' => $purchase->public_id,
            'provider' => $purchase->provider,
            'product_id' => $purchase->product_id,
            'environment' => $purchase->environment,
            'coins_added' => (int) $package->coins,
            'already_processed' => $alreadyProcessed,
            'finalize_transaction' => true,
            'wallet' => $this->wallet->summary($user),
        ];
    }
}
