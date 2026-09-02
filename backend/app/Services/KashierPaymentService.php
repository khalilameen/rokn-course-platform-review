<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order;
use App\Models\Package;
use App\Models\PaymentReconciliationFinding;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final readonly class KashierPaymentService
{
    private const CHECKOUT_TTL_MINUTES = 30;

    public function __construct(
        private readonly OrderLifecycleService $orderLifecycle,
        private readonly WalletService $wallet,
        private readonly FinancialProvenanceService $financialProvenance,
        private readonly PackageChannelPricingService $pricing,
    ) {
    }

    /**
     * @return array{order: Order, reused: bool, closed: ?string}
     */
    public function beginCheckout(
        User $user,
        Package $package,
        string $clientRequestKey,
        ?float $expectedAmount = null,
        ?int $expectedCoins = null
    ): array
    {
        return DB::transaction(function () use (
            $user,
            $package,
            $clientRequestKey,
            $expectedAmount,
            $expectedCoins
        ): array {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            // The package is catalogue input, not the learner's financial
            // aggregate. Locking it serialized every buyer of the same popular
            // package. Published store coin terms are immutable and direct
            // price/coin facts are copied into the order below, so a consistent
            // transaction read is sufficient here.
            $package = Package::query()->findOrFail($package->id);

            $existing = null;
            if ($clientRequestKey !== '') {
                $existing = Order::query()
                    ->where('user_id', $user->id)
                    ->where('checkout_request_key', $clientRequestKey)
                    ->first();

                if (
                    $existing
                    && (
                        (int) $existing->package_id !== (int) $package->id
                        || $existing->payment_method !== Order::PAYMENT_METHOD_KASHIER
                    )
                ) {
                    throw new \UnexpectedValueException(
                        'Checkout idempotency key was reused for another package.'
                    );
                }
            } else {
                $existing = Order::query()
                    ->where('user_id', $user->id)
                    ->where('package_id', $package->id)
                    ->where('payment_method', Order::PAYMENT_METHOD_KASHIER)
                    ->where('status', Order::STATUS_PENDING)
                    ->where('created_at', '>=', now()->subMinutes(10))
                    ->where(function ($query): void {
                        $query->whereNull('checkout_expires_at')
                            ->orWhere('checkout_expires_at', '>', now());
                    })
                    ->latest('id')
                    ->first();
            }

            if ($existing) {
                if (
                    ($expectedAmount !== null
                        && (int) round((float) $existing->final_amount * 100)
                            !== (int) round($expectedAmount * 100))
                    || ($expectedCoins !== null
                        && (int) $existing->package_coins !== $expectedCoins)
                ) {
                    throw new \UnexpectedValueException(
                        'Checkout idempotency key was replayed with different package terms.'
                    );
                }
                if ($existing->isCheckoutExpired()) {
                    return [
                        'order' => $existing,
                        'reused' => true,
                        'closed' => 'expired',
                    ];
                }

                if ($existing->status !== Order::STATUS_PENDING) {
                    return [
                        'order' => $existing,
                        'reused' => true,
                        'closed' => 'closed',
                    ];
                }

                return ['order' => $existing, 'reused' => true, 'closed' => null];
            }

            if (
                !$package->is_active
                || !$package->direct_enabled
                || (float) $package->price <= 0
                || (int) $package->coins <= 0
            ) {
                throw new \UnexpectedValueException(
                    'This package is not available for checkout.'
                );
            }

            $otherPendingCheckout = Order::query()
                ->where('user_id', $user->id)
                ->where('package_id', $package->id)
                ->where('payment_method', Order::PAYMENT_METHOD_KASHIER)
                ->where('status', Order::STATUS_PENDING)
                ->where(function ($query): void {
                    $query->where('checkout_expires_at', '>', now())
                        ->orWhere(function ($legacy): void {
                            $legacy->whereNull('checkout_expires_at')
                                ->where('created_at', '>', now()->subMinutes(self::CHECKOUT_TTL_MINUTES));
                        });
                })
                ->lockForUpdate()
                ->latest('id')
                ->first();
            if ($otherPendingCheckout) {
                throw new \UnexpectedValueException(
                    'A previous payment is still pending confirmation.'
                );
            }

            $baseAmount = (float) $package->price;
            $finalAmount = $this->pricing->directPrice($package);
            if (
                ($expectedAmount !== null
                    && (int) round($finalAmount * 100) !== (int) round($expectedAmount * 100))
                || ($expectedCoins !== null && (int) $package->coins !== $expectedCoins)
            ) {
                throw new \UnexpectedValueException(
                    'Package terms changed before checkout.'
                );
            }
            $order = Order::create([
                'user_id' => $user->id,
                'package_id' => $package->id,
                'package_coins' => (int) $package->coins,
                'payment_method' => Order::PAYMENT_METHOD_KASHIER,
                'order_ref' => 'PKG-' . strtoupper(str_replace('-', '', (string) Str::uuid())),
                'checkout_request_key' => $clientRequestKey !== ''
                    ? $clientRequestKey
                    : 'server-' . (string) Str::uuid(),
                'checkout_expires_at' => now()->addMinutes(self::CHECKOUT_TTL_MINUTES),
                'amount' => $baseAmount,
                'discount_amount' => round($baseAmount - $finalAmount, 2),
                'final_amount' => $finalAmount,
                'status' => Order::STATUS_PENDING,
                'financial_status' => Order::FINANCIAL_PENDING,
                'is_premium_user' => $user->isPremiumUser(),
            ]);

            return ['order' => $order, 'reused' => false, 'closed' => null];
        });
    }

    /**
     * @param array<string, mixed> $params
     */
    public function parseAndValidateSignature(
        Request $request,
        KashierService $kashier,
        array &$params
    ): bool {
        try {
            $configuration = $this->configuration();
        } catch (\RuntimeException $exception) {
            Log::error('Kashier signature verification is not configured', [
                'exception' => $exception::class,
            ]);

            return false;
        }

        $secret = $configuration['api_key'];
        $signatureSource = isset($params['data']) && is_array($params['data'])
            ? $params['data']
            : $params;

        if (isset($params['data']) && is_array($params['data'])) {
            $params = array_merge($params, $params['data']);
        }

        $candidates = array_values(array_filter([
            $request->header('x-kashier-signature'),
            $request->header('kashier-signature'),
            $request->header('signature'),
            $params['kashierSignature'] ?? null,
            $params['signature'] ?? null,
            $params['hash'] ?? null,
            $signatureSource['kashierSignature'] ?? null,
            $signatureSource['hash'] ?? null,
            $signatureSource['signature'] ?? null,
        ], fn ($candidate): bool => is_scalar($candidate) && (string) $candidate !== ''));

        if (!empty($params['signatureKeys']) && is_array($params['signatureKeys'])) {
            $queryString = $this->buildSignatureKeysQuery($params['signatureKeys'], $signatureSource);
            if ($queryString !== null) {
                $expectedSignature = hash_hmac('sha256', $queryString, $secret, false);

                foreach ($candidates as $candidate) {
                    if (hash_equals($expectedSignature, (string) $candidate)) {
                        return true;
                    }
                }
            }

            Log::warning('Kashier webhook signature mismatch (signatureKeys check failed)', [
                'mode' => config('kashier.mode'),
            ]);
        }

        $rawBody = $request->getContent();
        foreach ($candidates as $candidate) {
            if (
                $rawBody !== ''
                && hash_equals(hash_hmac('sha256', $rawBody, $secret, false), (string) $candidate)
            ) {
                return true;
            }
        }

        if (!empty($params['signature'])) {
            return $this->validateFlatCallbackSignature($params, $secret);
        }

        return $kashier->validateSignature($this->flattenCallbackParams($params));
    }

    public function isValidOrderReference(mixed $orderRef): bool
    {
        return is_string($orderRef)
            && preg_match('/^PKG-[A-Z0-9-]{8,64}$/i', $orderRef) === 1;
    }

    public function normalizeTransactionId(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/-]{0,190}\z/D', $value) === 1
            ? $value
            : null;
    }

    /**
     * @return array{mode: string, api_key: string, secret_key: string, mid: string, base_url: string}
     */
    public function configuration(): array
    {
        $mode = strtolower(trim((string) config('kashier.mode')));
        if (!in_array($mode, ['live', 'test'], true)) {
            throw new \RuntimeException('KASHIER_MODE must be explicitly set to live or test.');
        }

        $prefix = $mode === 'live' ? 'KASHIER_LIVE' : 'KASHIER_TEST';
        $apiKey = trim((string) config("kashier.{$mode}.api_key"));
        $secretKey = trim((string) config("kashier.{$mode}.secret_key"));
        $mid = trim((string) config("kashier.{$mode}.mid"));
        $baseUrl = trim((string) config("kashier.{$mode}.base_url"));
        $missing = [];

        if ($apiKey === '') {
            $missing[] = $prefix . '_API_KEY';
        }
        if ($secretKey === '') {
            $missing[] = $prefix . '_SECRET_KEY';
        }
        if ($mid === '') {
            $missing[] = $prefix . '_MID';
        }
        if ($baseUrl === '') {
            $missing[] = 'Kashier checkout base URL';
        }
        if ($missing !== []) {
            throw new \RuntimeException('Missing Kashier configuration: ' . implode(', ', $missing));
        }

        return [
            'mode' => $mode,
            'api_key' => $apiKey,
            'secret_key' => $secretKey,
            'mid' => $mid,
            'base_url' => $baseUrl,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function verifyOrderViaApi(string $orderRef): ?array
    {
        if (!$this->isValidOrderReference($orderRef)) {
            Log::warning('Kashier order verification rejected an invalid reference', [
                'order_ref_fingerprint' => hash('sha256', $orderRef),
            ]);

            return null;
        }

        try {
            $configuration = $this->configuration();
        } catch (\RuntimeException $exception) {
            Log::error('Kashier order verification is not configured', [
                'order_ref' => $orderRef,
                'exception' => $exception::class,
            ]);

            return null;
        }

        $apiHost = $configuration['mode'] === 'live'
            ? 'https://api.kashier.io'
            : 'https://test-api.kashier.io';

        try {
            $response = Http::withHeaders([
                // Kashier's reconciliation API does not accept either
                // credential on its own. Its documented merchant
                // authorization value is "API key$secret key".
                'Authorization' => $configuration['api_key'] . '$' . $configuration['secret_key'],
            ])
                ->connectTimeout(5)
                ->timeout(10)
                ->get("{$apiHost}/payments/orders/" . rawurlencode($orderRef));

            if ($response->status() === 404) {
                // An HPP link can be opened and abandoned before Kashier
                // creates a provider-side order. Preserve that distinction so
                // an expired local checkout can close without pretending the
                // provider was unavailable.
                return [
                    'response' => [
                        'status' => 'NOT_FOUND',
                        'merchantOrderId' => $orderRef,
                    ],
                ];
            }

            if (!$response->successful()) {
                Log::warning('Kashier order verification API failed', [
                    'order_ref' => $orderRef,
                    'status' => $response->status(),
                ]);

                return null;
            }

            return $response->json();
        } catch (\Throwable $exception) {
            Log::error('Kashier order verification API error', [
                'order_ref' => $orderRef,
                'exception' => $exception::class,
            ]);

            return null;
        }
    }

    /**
     * @param array<string, mixed>|null $apiResponse
     */
    public function isOrderCaptured(?array $apiResponse): bool
    {
        return $this->isCaptureNotificationStatus(
            $this->providerOrderStatus($apiResponse)
        );
    }

    public function isCaptureNotificationStatus(?string $status): bool
    {
        return in_array(strtoupper(trim((string) $status)), [
            'SUCCESS', 'CAPTURED', 'PAID',
        ], true);
    }

    /**
     * @param array<string, mixed>|null $apiResponse
     */
    public function providerOrderStatus(?array $apiResponse): ?string
    {
        if (!$apiResponse) {
            return null;
        }

        $status = $apiResponse['response']['status']
            ?? $apiResponse['response']['paymentStatus']
            ?? $apiResponse['data']['status']
            ?? $apiResponse['status']
            ?? null;
        $status = strtoupper(trim((string) $status));

        if ($status === '') {
            $transactions = $apiResponse['response']['transactions']
                ?? $apiResponse['data']['transactions']
                ?? $apiResponse['transactions']
                ?? [];
            if (is_array($transactions)) {
                foreach (array_reverse($transactions) as $transaction) {
                    if (!is_array($transaction)) continue;
                    $transactionStatus = strtoupper(trim((string) (
                        $transaction['status'] ?? $transaction['paymentStatus'] ?? ''
                    )));
                    $operation = strtoupper(trim((string) (
                        $transaction['operation'] ?? $transaction['type'] ?? ''
                    )));
                    if (in_array($transactionStatus, ['SUCCESS', 'CAPTURED', 'PAID'], true)) {
                        if (str_contains($operation, 'REFUND')) return 'REFUNDED';
                        if (str_contains($operation, 'CHARGEBACK') || str_contains($operation, 'DISPUTE')) {
                            return 'CHARGEBACK';
                        }
                        if (str_contains($operation, 'REVERS') || str_contains($operation, 'VOID')) {
                            return 'REVERSED';
                        }
                        if (in_array($operation, ['PAY', 'CAPTURE', 'SALE', 'PURCHASE'], true)) {
                            return 'CAPTURED';
                        }
                    }
                    if (preg_match('/\A[A-Z0-9_-]{1,32}\z/D', $transactionStatus) === 1) {
                        return $transactionStatus;
                    }
                }
            }
        }

        return $status !== '' && preg_match('/\A[A-Z0-9_-]{1,32}\z/D', $status) === 1
            ? $status
            : null;
    }

    public function isProviderPendingStatus(?string $status): bool
    {
        return in_array($status, ['PENDING', 'INITIATED', 'AUTHORIZED', 'PROCESSING'], true);
    }

    /**
     * An authorization/processing state may still turn into a charge without
     * another learner action. A merely opened HPP order may be abandoned and
     * replaced; a late authenticated capture is still recovered by
     * fulfillOrder() exactly once.
     */
    public function providerStatusMayCaptureWithoutLearner(?string $status): bool
    {
        $normalized = strtoupper(trim((string) $status));
        if ($normalized === '') {
            // A successful HTTP response with an unknown body is not evidence
            // that the payment can be safely replaced.
            return true;
        }

        if (in_array($normalized, [
            'NOT_FOUND', 'PENDING', 'INITIATED', 'FAILED', 'FAILURE', 'DECLINED',
            'CANCELLED', 'CANCELED', 'VOIDED', 'EXPIRED',
        ], true)) {
            return false;
        }

        // AUTHORIZED/PROCESSING and new provider states stay recoverable until
        // reconciliation can classify them. Unknown must fail closed here: a
        // second payable checkout is more harmful than a short pending state.
        return true;
    }

    public function isProviderFailureStatus(?string $status): bool
    {
        return in_array($status, [
            'NOT_FOUND', 'FAILED', 'FAILURE', 'DECLINED', 'CANCELLED', 'CANCELED',
            'VOIDED', 'EXPIRED',
        ], true);
    }

    /**
     * @param array<string, mixed>|null $apiResponse
     */
    public function extractTransactionId(?array $apiResponse): ?string
    {
        if (!$apiResponse) {
            return null;
        }

        $direct = $this->normalizeTransactionId(
            $apiResponse['response']['transactionId']
            ?? $apiResponse['transactionId']
            ?? ($apiResponse['data']['transactionId'] ?? null)
        );
        if ($direct !== null) {
            return $direct;
        }

        $transactions = $apiResponse['response']['transactions']
            ?? $apiResponse['data']['transactions']
            ?? $apiResponse['transactions']
            ?? [];
        if (!is_array($transactions)) {
            return null;
        }

        $successful = array_values(array_filter(
            $transactions,
            static fn (mixed $transaction): bool => is_array($transaction)
                && in_array(strtoupper(trim((string) ($transaction['status'] ?? ''))), [
                    'SUCCESS',
                    'CAPTURED',
                ], true)
        ));
        foreach (array_reverse($successful) as $transaction) {
            $operation = strtolower(trim((string) ($transaction['operation'] ?? '')));
            if (!in_array($operation, ['pay', 'capture', 'sale', 'purchase'], true)) {
                continue;
            }
            $transactionId = $this->normalizeTransactionId(
                $transaction['transactionId'] ?? $transaction['transaction_id'] ?? null
            );
            if ($transactionId !== null) {
                return $transactionId;
            }
        }

        foreach (array_reverse($successful) as $transaction) {
            $transactionId = $this->normalizeTransactionId(
                $transaction['transactionId'] ?? $transaction['transaction_id'] ?? null
            );
            if ($transactionId !== null) {
                return $transactionId;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $gatewayResponse
     * @return array{0: ?string, 1: array<string, mixed>}
     */
    public function captureEvidenceWithTransactionId(
        string $orderRef,
        ?string $transactionId,
        array $gatewayResponse
    ): array {
        if ($transactionId !== null) {
            return [$transactionId, $gatewayResponse];
        }

        $apiResponse = $this->verifyOrderViaApi($orderRef);
        if (!$this->isOrderCaptured($apiResponse)) {
            return [null, $gatewayResponse];
        }

        return [
            $this->extractTransactionId($apiResponse),
            array_merge($gatewayResponse, [
                'verified_via' => 'kashier_api_missing_transaction_id',
                'kashier_api_response' => $apiResponse,
            ]),
        ];
    }

    public function transactionIdConflicts(Order $order, ?string $transactionId): bool
    {
        return $transactionId !== null
            && is_string($order->transaction_id)
            && $order->transaction_id !== ''
            && !hash_equals($order->transaction_id, $transactionId);
    }

    private function buildSignatureKeysQuery(array $signatureKeys, array $source): ?string
    {
        $pairs = [];
        foreach ($signatureKeys as $key) {
            if (
                !is_string($key)
                || $key === ''
                || !array_key_exists($key, $source)
                || (!is_scalar($source[$key]) && $source[$key] !== null)
            ) {
                return null;
            }

            $value = $source[$key];
            $pairs[] = rawurlencode($key) . '=' . rawurlencode($value === null ? '' : (string) $value);
        }

        return implode('&', $pairs);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, scalar|null>
     */
    private function flattenCallbackParams(array $params): array
    {
        $flat = [];
        foreach ($params as $key => $value) {
            if (is_array($value) || is_object($value)) {
                continue;
            }
            $flat[$key] = $value;
        }

        return $flat;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function validateFlatCallbackSignature(array $params, string $secret): bool
    {
        $providedSignature = $params['signature'] ?? '';
        if ($providedSignature === '' || $providedSignature === null) {
            return false;
        }

        $excludeKeys = ['signature', 'mode', 'hash', 'event', 'data', 'signatureKeys'];
        $queryString = '';

        foreach ($params as $key => $value) {
            if (in_array($key, $excludeKeys, true) || is_array($value) || is_object($value)) {
                continue;
            }
            $queryString .= "&{$key}={$value}";
        }

        $expectedSignature = hash_hmac('sha256', ltrim($queryString, '&'), $secret, false);

        return hash_equals($expectedSignature, (string) $providedSignature);
    }

    /**
     * @param array<string, mixed> $gatewayResponse
     */
    public function flagApprovedTransactionConflict(
        Order $order,
        ?string $transactionId,
        array $gatewayResponse
    ): bool {
        if ($order->status !== Order::STATUS_APPROVED || !$this->transactionIdConflicts($order, $transactionId)) {
            return false;
        }

        return DB::transaction(function () use ($order, $transactionId, $gatewayResponse): bool {
            $expectedUserId = (int) $order->user_id;
            User::withTrashed()->lockForUpdate()->findOrFail($expectedUserId);
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if (
                $locked->status !== Order::STATUS_APPROVED
                || !$this->transactionIdConflicts($locked, $transactionId)
            ) {
                return false;
            }

            $locked->update([
                'financial_status' => Order::FINANCIAL_REVIEW_REQUIRED,
                'payment_gateway_response' => $this->sanitizeGatewayResponse($gatewayResponse),
            ]);

            Log::critical('Kashier replay carried a different transaction identifier', [
                'order_ref' => $locked->order_ref,
                'order_id' => $locked->id,
                'stored_transaction_id' => $locked->transaction_id,
                'incoming_transaction_id' => $transactionId,
            ]);

            return true;
        });
    }

    public function financialReversalType(string $paymentStatus): ?string
    {
        return match (strtoupper(trim($paymentStatus))) {
            'REFUND', 'REFUNDED', 'FULLY_REFUNDED',
            'PARTIAL_REFUND', 'PARTIALLY_REFUNDED' => Order::FINANCIAL_REFUNDED,
            'CHARGEBACK', 'DISPUTED' => Order::FINANCIAL_CHARGEBACK,
            'REVERSED', 'REVERSAL' => Order::FINANCIAL_REVERSED,
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $params
     */
    public function recordFinancialReversal(
        Order $order,
        string $type,
        string $paymentStatus,
        ?string $transactionId,
        array $params
    ): void {
        $normalizedStatus = strtoupper(trim($paymentStatus));
        $reason = 'Kashier reported payment status ' . $normalizedStatus . '.';
        $externalEventId = (string) (
            $params['eventId']
            ?? $params['event_id']
            ?? $params['id']
            ?? $transactionId
            ?? ''
        );
        $eventIdentity = $externalEventId !== ''
            ? $externalEventId
            : (string) $order->order_ref;
        $eventKey = 'kashier:' . strtolower($normalizedStatus) . ':'
            . hash('sha256', $eventIdentity);

        if (in_array($normalizedStatus, ['PARTIAL_REFUND', 'PARTIALLY_REFUNDED'], true)) {
            $this->orderLifecycle->flagExternalFinancialReview(
                $order,
                'partial_refund_reported',
                $reason,
                $eventKey,
                'kashier',
                $externalEventId !== '' ? $externalEventId : null,
                $this->sanitizeGatewayResponse($params)
            );

            return;
        }

        DB::transaction(function () use (
            $order,
            $type,
            $reason,
            $normalizedStatus,
            $transactionId,
            $params,
            $externalEventId,
            $eventKey
        ): void {
            $expectedUserId = (int) $order->user_id;
            User::withTrashed()->lockForUpdate()->findOrFail($expectedUserId);
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ((int) $locked->user_id !== $expectedUserId) {
                throw new \RuntimeException('Kashier order ownership changed during reversal.');
            }

            // Keep the identity check and the reversal under the same locks.
            // Otherwise a capture can settle between both operations and a
            // stale reversal can reclaim a different transaction's value.
            if ($this->flagApprovedTransactionConflict($locked, $transactionId, $params)) {
                return;
            }

            $this->orderLifecycle->registerReversal(
                $locked,
                $type,
                $reason,
                $eventKey,
                null,
                'kashier',
                $externalEventId !== '' ? $externalEventId : null,
                $this->sanitizeGatewayResponse($params)
            );
        }, 3);
    }

    /**
     * @param array<string, mixed> $gatewayResponse
     */
    public function fulfillOrder(Order $order, ?string $transactionId, array $gatewayResponse): Order
    {
        DB::beginTransaction();

        try {
            $expectedUserId = (int) $order->user_id;
            User::withTrashed()->lockForUpdate()->findOrFail($expectedUserId);
            $order = Order::with(['user', 'package'])->lockForUpdate()->findOrFail($order->id);
            if ((int) $order->user_id !== $expectedUserId) {
                throw new \RuntimeException('Kashier order ownership changed during fulfillment.');
            }

            if ($order->status === Order::STATUS_APPROVED) {
                if ($this->transactionIdConflicts($order, $transactionId)) {
                    $order->update([
                        'financial_status' => Order::FINANCIAL_REVIEW_REQUIRED,
                        'payment_gateway_response' => $this->sanitizeGatewayResponse($gatewayResponse),
                    ]);
                    Log::critical('Concurrent Kashier fulfillment carried a different transaction identifier', [
                        'order_ref' => $order->order_ref,
                        'order_id' => $order->id,
                        'stored_transaction_id' => $order->transaction_id,
                        'incoming_transaction_id' => $transactionId,
                    ]);
                } else {
                    $this->assertGatewayPaymentMatchesOrder($order, $gatewayResponse);
                    $settlementFacts = $this->gatewaySettlementFacts($order, $gatewayResponse);
                    if ($settlementFacts !== []) {
                        $order->update($settlementFacts);
                    }
                }

                DB::commit();

                return $order->fresh(['user', 'package']);
            }

            if (
                $order->payment_method !== Order::PAYMENT_METHOD_KASHIER
                || !$order->package_id
                || !$order->package
                || !$order->user
                || $this->coinAmount($order) <= 0
                || (float) $order->final_amount <= 0
            ) {
                throw new \RuntimeException('Invalid Kashier package order.');
            }

            if ($order->reversed_at || in_array($order->financial_status, [
                Order::FINANCIAL_REFUNDED,
                Order::FINANCIAL_CHARGEBACK,
                Order::FINANCIAL_REVERSED,
                Order::FINANCIAL_PARTIALLY_RECOVERED,
            ], true)) {
                Log::warning('Kashier capture ignored because a reversal arrived first', [
                    'order_ref' => $order->order_ref,
                    'order_id' => $order->id,
                    'financial_status' => $order->financial_status,
                    'transaction_id' => $transactionId,
                ]);
                DB::commit();

                return $order->fresh(['user', 'package']);
            }

            $this->assertGatewayPaymentMatchesOrder($order, $gatewayResponse);

            if (
                $transactionId
                && Order::query()
                    ->where('transaction_id', $transactionId)
                    ->whereKeyNot($order->id)
                    ->exists()
            ) {
                throw new \RuntimeException('Kashier transaction was already assigned to another order.');
            }

            $lateCapture = $order->status !== Order::STATUS_PENDING
                || $order->isCheckoutExpired();
            if (!in_array($order->status, [
                Order::STATUS_PENDING,
                Order::STATUS_CANCELLED,
                Order::STATUS_REJECTED,
            ], true)) {
                throw new \RuntimeException('Kashier capture targets an unsupported order state.');
            }

            if ($transactionId === null) {
                $order->update([
                    'financial_status' => Order::FINANCIAL_REVIEW_REQUIRED,
                    'payment_gateway_response' => $this->sanitizeGatewayResponse($gatewayResponse),
                ]);

                Log::critical('Kashier capture is missing a valid transaction identifier', [
                    'order_ref' => $order->order_ref,
                    'order_id' => $order->id,
                ]);

                DB::commit();

                return $order->fresh(['user', 'package']);
            }

            $order->update(array_merge([
                'status' => Order::STATUS_APPROVED,
                'financial_status' => Order::FINANCIAL_SETTLED,
                'transaction_id' => $transactionId,
                'approved_at' => now(),
                'payment_gateway_response' => $this->sanitizeGatewayResponse($gatewayResponse),
            ], $this->gatewaySettlementFacts($order, $gatewayResponse)));

            if ($lateCapture) {
                // Redirects, webhooks and reconciliation can arrive out of
                // order. A provider-authenticated capture is still real money:
                // credit it exactly once instead of leaving a charged learner
                // waiting for manual review merely because a failure/timeout
                // notification won the race.
                Log::warning('Late Kashier capture recovered after checkout closure', [
                    'order_ref' => $order->order_ref,
                    'order_id' => $order->id,
                    'transaction_id' => $transactionId,
                ]);
            }

            $paidCredit = $this->wallet->credit(
                $order->user_id,
                $this->coinAmount($order),
                'package_purchase',
                "kashier-order:{$order->id}",
                $order,
                [
                    'package_id' => $order->package_id,
                    'transaction_id' => $transactionId,
                ],
                WalletTransaction::BUCKET_PAID
            );
            $this->financialProvenance->recordPaidPackageCredit($order, $paidCredit);
            /** @var User $user */
            $user = User::withTrashed()->findOrFail($order->user_id);

            $user->purchasedPackages()->attach($order->package_id, [
                'order_id' => $order->id,
                'price' => $order->final_amount,
                'coins' => $this->coinAmount($order),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::commit();

            if ($lateCapture && Schema::hasTable('payment_reconciliation_findings')) {
                try {
                    $overlappingOrder = Order::query()
                        ->whereKeyNot($order->id)
                        ->where('user_id', $order->user_id)
                        ->where('package_id', $order->package_id)
                        ->where('payment_method', Order::PAYMENT_METHOD_KASHIER)
                        ->financiallyEffective()
                        ->where('created_at', '>=', $order->created_at)
                        ->oldest('id')
                        ->first(['id', 'order_ref']);
                    if ($overlappingOrder) {
                        $fingerprint = hash('sha256', implode('|', [
                            'kashier',
                            (string) $order->id,
                            'late_capture_overlap',
                            (string) $overlappingOrder->id,
                        ]));
                        PaymentReconciliationFinding::query()->firstOrCreate(
                            ['fingerprint' => $fingerprint],
                            [
                                'provider' => 'kashier',
                                'order_id' => $order->id,
                                'order_ref' => (string) $order->order_ref,
                                'kind' => 'late_capture_overlaps_newer_payment',
                                'local_status' => (string) $order->status,
                                'local_financial_status' => (string) $order->financial_status,
                                'provider_status' => 'CAPTURED',
                                'provider_transaction_id' => $transactionId,
                                'state' => PaymentReconciliationFinding::STATE_OPEN,
                                'attempts' => 1,
                                'first_seen_at' => now(),
                                'last_seen_at' => now(),
                                'evidence' => [
                                    'overlapping_order_id' => (int) $overlappingOrder->id,
                                    'overlapping_order_ref' => (string) $overlappingOrder->order_ref,
                                ],
                            ]
                        );
                    }
                } catch (\Throwable $findingException) {
                    report($findingException);
                }
            }

            try {
                if ($user->trashed()) {
                    return $order->fresh(['user', 'package']);
                }
                StudentNotificationService::notifyUser(
                    $user,
                    StudentNotificationService::TYPE_PACKAGE_PURCHASED,
                    'تم شحن رصيدك',
                    'Package Purchased',
                    'أضفنا ' . $this->coinAmount($order)
                        . " عملة إلى محفظتك\nالرصيد جاهز للاستخدام",
                    'Package purchased successfully. ' . $this->coinAmount($order) . ' coins added to your wallet.',
                    null,
                    Package::class,
                    $order->package_id,
                    'package-purchased:order:' . $order->id,
                    ['coins' => $this->coinAmount($order)]
                );
            } catch (\Throwable $notificationException) {
                report($notificationException);
            }

            return $order->fresh(['user', 'package']);
        } catch (\Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }
    }

    /**
     * Record a signed failure without overwriting a terminal successful state.
     *
     * @param array<string, mixed>|null $gatewayResponse
     */
    public function cancelPendingOrder(Order $order, ?array $gatewayResponse = null): Order
    {
        return DB::transaction(function () use ($order, $gatewayResponse): Order {
            $expectedUserId = (int) $order->user_id;
            User::withTrashed()->lockForUpdate()->findOrFail($expectedUserId);
            $locked = Order::with(['user', 'package'])->lockForUpdate()->findOrFail($order->id);
            if ((int) $locked->user_id !== $expectedUserId) {
                throw new \RuntimeException('Kashier order ownership changed while recording failure.');
            }

            if ($locked->status === Order::STATUS_PENDING) {
                $updates = [
                    'status' => Order::STATUS_CANCELLED,
                    'financial_status' => Order::FINANCIAL_CANCELLED,
                ];
                if ($gatewayResponse !== null) {
                    $updates['payment_gateway_response'] = $this->sanitizeGatewayResponse($gatewayResponse);
                }
                $locked->update($updates);
            }

            return $locked->fresh(['user', 'package']);
        });
    }

    public function coinAmount(Order $order): int
    {
        return max(0, (int) ($order->package_coins ?? $order->package?->coins ?? 0));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sanitizeGatewayResponse(array $payload): array
    {
        $secretKeys = [
            'signature', 'hash', 'signaturekeys', 'token', 'cardtoken',
            'ccvtoken', 'carddatatoken', 'accesstoken', 'refreshtoken',
            'authorization', 'api_key', 'apikey', 'apipassword', 'password',
            'secret', 'secretkey', 'securitycode',
        ];
        $privateContainers = [
            'card', 'cardinfo', 'carddata', 'customer', 'customerdata',
            'paymentsource', 'sourceoffunds', 'requestcredentials',
            'credentials', 'auth',
        ];

        foreach ($payload as $key => $value) {
            $normalizedKey = strtolower((string) $key);
            if (in_array($normalizedKey, $secretKeys, true)) {
                unset($payload[$key]);

                continue;
            }
            if (in_array($normalizedKey, $privateContainers, true)) {
                $payload[$key] = '[redacted]';

                continue;
            }
            if (is_array($value)) {
                $payload[$key] = $this->sanitizeGatewayResponse($value);
            }
        }

        return $payload;
    }

    /**
     * Preserve provider settlement facts separately from the sanitized raw
     * payload so reports never present captured gross revenue as net payout.
     * Existing non-settlement values are intentionally write-once.
     *
     * @param array<string, mixed> $gatewayResponse
     * @return array<string, mixed>
     */
    private function gatewaySettlementFacts(Order $order, array $gatewayResponse): array
    {
        if (!Schema::hasColumn('orders', 'gateway_gross_amount')) {
            return [];
        }

        $payload = isset($gatewayResponse['kashier_api_response'])
            && is_array($gatewayResponse['kashier_api_response'])
                ? $gatewayResponse['kashier_api_response']
                : $gatewayResponse;
        $fee = $this->normalizeGatewayAmount($this->firstGatewayValue($payload, [
            'fee', 'fees', 'feeAmount', 'fee_amount', 'processingFee',
            'response.fee', 'response.fees', 'response.feeAmount',
            'response.settlement.fee', 'data.fee', 'data.feeAmount',
        ]));
        $net = $this->normalizeGatewayAmount($this->firstGatewayValue($payload, [
            'netAmount', 'net_amount', 'settlementAmount', 'settlement_amount',
            'response.netAmount', 'response.net_amount',
            'response.settlement.netAmount', 'response.settlement.amount',
            'data.netAmount', 'data.settlementAmount',
        ]));
        $gross = (float) $order->final_amount;
        if ($net === null && $fee !== null) {
            $net = max(0, $gross - $fee);
        }
        $currency = strtoupper((string) ($this->firstGatewayValue($payload, [
            'currency', 'response.currency', 'response.settlement.currency', 'data.currency',
        ]) ?? 'EGP'));
        $providerStatus = strtolower(trim((string) ($this->firstGatewayValue($payload, [
            'settlementStatus', 'settlement_status', 'response.settlement.status',
            'data.settlementStatus', 'response.status', 'paymentStatus', 'status',
        ]) ?? 'captured')));

        $facts = [];
        foreach ([
            'gateway_gross_amount' => number_format($gross, 2, '.', ''),
            'gateway_currency' => substr($currency, 0, 3),
            'gateway_settlement_status' => $net !== null ? 'settled' : substr($providerStatus, 0, 32),
            'gateway_fee_amount' => $fee !== null ? number_format($fee, 2, '.', '') : null,
            'gateway_net_amount' => $net !== null ? number_format($net, 2, '.', '') : null,
            'gateway_settled_at' => $net !== null ? now() : null,
        ] as $field => $value) {
            if ($order->{$field} === null && $value !== null) {
                $facts[$field] = $value;
            }
        }

        return $facts;
    }

    /**
     * @param array<string, mixed> $gatewayResponse
     */
    public function assertGatewayPaymentMatchesOrder(Order $order, array $gatewayResponse): void
    {
        $payload = isset($gatewayResponse['kashier_api_response'])
            && is_array($gatewayResponse['kashier_api_response'])
                ? $gatewayResponse['kashier_api_response']
                : $gatewayResponse;

        $gatewayOrderRef = $this->firstGatewayValue($payload, [
            'merchantOrderId',
            'merchant_order_id',
            'order_ref',
            'response.merchantOrderId',
            'response.merchant_order_id',
            'response.order_ref',
            'response.order.merchantOrderId',
            'response.order.merchant_order_id',
            'response.order.order_ref',
            'response.transactions.0.merchantOrderId',
            'data.merchantOrderId',
            'data.merchant_order_id',
            'data.order_ref',
        ]);
        if (
            $gatewayOrderRef !== null
            && !hash_equals((string) $order->order_ref, trim((string) $gatewayOrderRef))
        ) {
            throw new \RuntimeException('Kashier merchant order reference mismatch.');
        }

        $gatewayAmount = $this->firstGatewayValue($payload, [
            'amount',
            'amount.value',
            'response.amount',
            'response.amount.value',
            'response.paymentAmount',
            'response.totalAmount',
            'response.order.amount',
            'response.order.amount.value',
            'response.transactions.0.amount',
            'data.amount',
            'data.amount.value',
        ]);
        if ($gatewayAmount !== null) {
            $normalizedAmount = $this->normalizeGatewayAmount($gatewayAmount);
            if (
                $normalizedAmount === null
                || abs($normalizedAmount - (float) $order->final_amount) > 0.009
            ) {
                throw new \RuntimeException('Kashier payment amount mismatch.');
            }
        }

        $gatewayCurrency = $this->firstGatewayValue($payload, [
            'currency',
            'amount.currency',
            'response.currency',
            'response.amount.currency',
            'response.order.currency',
            'response.transactions.0.currency',
            'data.currency',
            'data.amount.currency',
        ]);
        if (
            $gatewayCurrency !== null
            && strtoupper(trim((string) $gatewayCurrency)) !== 'EGP'
        ) {
            throw new \RuntimeException('Kashier payment currency mismatch.');
        }
    }

    private function firstGatewayValue(array $payload, array $paths): mixed
    {
        foreach ($paths as $path) {
            $value = data_get($payload, $path);
            if ($value !== null && $value !== '' && !is_array($value) && !is_object($value)) {
                return $value;
            }
        }

        return null;
    }

    private function normalizeGatewayAmount(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $normalized = str_replace([',', ' '], '', trim($value));

        return $normalized !== '' && is_numeric($normalized)
            ? (float) $normalized
            : null;
    }
}
