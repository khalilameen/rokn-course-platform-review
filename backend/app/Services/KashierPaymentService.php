<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order;
use App\Models\Package;
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
    ) {
    }

    /**
     * @return array{order: Order, reused: bool, closed: ?string}
     */
    public function beginCheckout(User $user, Package $package, string $clientRequestKey): array
    {
        return DB::transaction(function () use ($user, $package, $clientRequestKey): array {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

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
                if ($existing->isCheckoutExpired()) {
                    $existing->update([
                        'status' => Order::STATUS_CANCELLED,
                        'financial_status' => Order::FINANCIAL_CANCELLED,
                    ]);

                    return [
                        'order' => $existing->fresh(),
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

            Order::query()
                ->where('user_id', $user->id)
                ->where('package_id', $package->id)
                ->where('status', Order::STATUS_PENDING)
                ->where('payment_method', Order::PAYMENT_METHOD_KASHIER)
                ->update([
                    'status' => Order::STATUS_CANCELLED,
                    'financial_status' => Order::FINANCIAL_CANCELLED,
                ]);

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
                'amount' => $package->price,
                'discount_amount' => 0,
                'final_amount' => $package->price,
                'status' => Order::STATUS_PENDING,
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
                'Authorization' => $configuration['secret_key'],
            ])
                ->connectTimeout(5)
                ->timeout(10)
                ->get("{$apiHost}/payments/orders/" . rawurlencode($orderRef));

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
        if (!$apiResponse) {
            return false;
        }

        $status = $apiResponse['response']['status']
            ?? $apiResponse['status']
            ?? null;

        return strtoupper((string) $status) === 'CAPTURED';
    }

    /**
     * @param array<string, mixed>|null $apiResponse
     */
    public function extractTransactionId(?array $apiResponse): ?string
    {
        if (!$apiResponse) {
            return null;
        }

        return $this->normalizeTransactionId(
            $apiResponse['response']['transactionId']
            ?? $apiResponse['transactionId']
            ?? ($apiResponse['response']['transactions'][0]['transactionId'] ?? null)
        );
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
            User::query()->lockForUpdate()->findOrFail($expectedUserId);
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
            'REFUND', 'REFUNDED', 'FULLY_REFUNDED' => Order::FINANCIAL_REFUNDED,
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
        $reason = 'Kashier reported payment status ' . strtoupper($paymentStatus) . '.';

        if ($order->status !== Order::STATUS_APPROVED) {
            $this->orderLifecycle->cancelPending($order, null, $reason);

            return;
        }

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

        $this->orderLifecycle->registerReversal(
            $order,
            $type,
            $reason,
            'kashier:' . strtolower($paymentStatus) . ':' . hash('sha256', $eventIdentity),
            null,
            'kashier',
            $externalEventId !== '' ? $externalEventId : null,
            $this->sanitizeGatewayResponse($params)
        );
    }

    /**
     * @param array<string, mixed> $gatewayResponse
     */
    public function fulfillOrder(Order $order, ?string $transactionId, array $gatewayResponse): Order
    {
        DB::beginTransaction();

        try {
            $expectedUserId = (int) $order->user_id;
            User::query()->lockForUpdate()->findOrFail($expectedUserId);
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

            if ($order->status !== Order::STATUS_PENDING || $order->isCheckoutExpired()) {
                $wasExpired = $order->status === Order::STATUS_PENDING
                    && $order->isCheckoutExpired();
                $updates = [
                    'financial_status' => Order::FINANCIAL_REVIEW_REQUIRED,
                    'payment_gateway_response' => $this->sanitizeGatewayResponse($gatewayResponse),
                ];
                if ($wasExpired) {
                    $updates['status'] = Order::STATUS_CANCELLED;
                }
                if ($transactionId && !$order->transaction_id) {
                    $updates['transaction_id'] = $transactionId;
                }
                $order->update($updates);

                Log::critical('Kashier capture received for a closed checkout; fulfillment blocked', [
                    'order_ref' => $order->order_ref,
                    'order_id' => $order->id,
                    'order_status' => $order->status,
                    'checkout_expired' => $wasExpired,
                    'transaction_id' => $transactionId,
                ]);

                DB::commit();

                return $order->fresh(['user', 'package']);
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
            $user = $order->user->fresh();

            $user->purchasedPackages()->attach($order->package_id, [
                'order_id' => $order->id,
                'price' => $order->final_amount,
                'coins' => $this->coinAmount($order),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::commit();

            try {
                StudentNotificationService::notifyUser(
                    $user,
                    StudentNotificationService::TYPE_PACKAGE_PURCHASED,
                    'تم شراء الباقة',
                    'Package Purchased',
                    'تم شراء الباقة بنجاح. تم إضافة '
                        . $this->coinAmount($order)
                        . ' عملة إلى محفظتك',
                    'Package purchased successfully. ' . $this->coinAmount($order) . ' coins added to your wallet.',
                    null,
                    Package::class,
                    $order->package_id,
                    'package-purchased:order:' . $order->id
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
            User::query()->lockForUpdate()->findOrFail($expectedUserId);
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
            'authorization', 'api_key', 'apikey',
        ];
        $privateContainers = [
            'card', 'carddata', 'customer', 'customerdata', 'paymentsource',
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
            'data.settlementStatus', 'paymentStatus', 'status',
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
