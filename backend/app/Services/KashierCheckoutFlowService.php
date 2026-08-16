<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final readonly class KashierCheckoutFlowService
{
    public function __construct(
        private KashierService $kashier,
        private KashierPaymentService $payments,
        private PaymentApiResponseService $responses
    ) {
    }

    public function initiate(Request $request): JsonResponse
    {
        $bodyHasKey = array_key_exists('idempotency_key', $request->all());
        $headerHasKey = $request->hasHeader('Idempotency-Key');
        $bodyKey = $bodyHasKey ? $request->input('idempotency_key') : null;
        $headerKey = $headerHasKey ? $request->header('Idempotency-Key') : null;

        if (
            $bodyHasKey
            && $headerHasKey
            && (
                !is_string($bodyKey)
                || !is_string($headerKey)
                || !hash_equals($bodyKey, $headerKey)
            )
        ) {
            return $this->responses->make(
                false,
                'Idempotency-Key header and request body must match exactly.',
                [],
                422,
                'checkout_idempotency_mismatch',
                [
                    'idempotency_key' => [
                        'Idempotency-Key header and request body must match exactly.',
                    ],
                ]
            );
        }

        if (!$bodyHasKey && $headerHasKey) {
            $request->merge(['idempotency_key' => $headerKey]);
        }

        try {
            $validated = $request->validate([
                'package_id' => 'required|integer|exists:packages,id',
                'idempotency_key' => [
                    'nullable',
                    'string',
                    'min:16',
                    'max:140',
                    'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]{15,139}\z/D',
                ],
            ]);
        } catch (ValidationException $exception) {
            return $this->responses->make(
                false,
                'The supplied payment details are invalid.',
                [],
                422,
                'validation_error',
                $exception->errors()
            );
        }

        /** @var User $user */
        $user = auth('api')->user();
        $package = Package::findOrFail($request->package_id);
        if ((float) $package->price <= 0 || (int) $package->coins <= 0) {
            return $this->responses->make(
                false,
                'This package is not available for checkout.',
                [],
                409,
                'package_not_available'
            );
        }

        try {
            $this->payments->configuration();
        } catch (\RuntimeException $exception) {
            Log::critical('Kashier payment initiation blocked by configuration', [
                'exception' => $exception::class,
            ]);

            return $this->responses->make(
                false,
                'Payment is temporarily unavailable. Please try again later.',
                [],
                503,
                'payment_configuration_unavailable'
            );
        }

        Log::info('Kashier payment initiation started', [
            'user_id' => $user->id,
            'package_id' => $package->id,
            'amount' => $package->price,
        ]);

        $clientRequestKey = (string) ($validated['idempotency_key'] ?? '');
        $orderRef = null;
        try {
            $checkout = $this->payments->beginCheckout($user, $package, $clientRequestKey);

            /** @var Order $order */
            $order = $checkout['order'];
            $orderRef = (string) $order->order_ref;

            if ($checkout['closed'] !== null) {
                $expired = $checkout['closed'] === 'expired';

                return $this->responses->make(
                    false,
                    $expired
                        ? 'This checkout attempt has expired. Start a new checkout attempt.'
                        : 'This checkout attempt is already closed. Start a new checkout attempt.',
                    [
                        'order_ref' => $orderRef,
                        'status' => $order->status,
                    ],
                    409,
                    $expired ? 'checkout_attempt_expired' : 'checkout_attempt_closed'
                );
            }

            Log::info('Kashier order created', [
                'order_ref' => $orderRef,
                'order_id' => $order->id,
                'user_id' => $user->id,
                'package_id' => $package->id,
                'amount' => $package->price,
                'is_premium_user' => $order->is_premium_user,
                'idempotent_replay' => $checkout['reused'],
            ]);
        } catch (\UnexpectedValueException $exception) {
            return $this->responses->make(
                false,
                $exception->getMessage(),
                [],
                409,
                'checkout_idempotency_conflict'
            );
        } catch (\Throwable $exception) {
            Log::error('Kashier order creation failed', [
                'user_id' => $user->id,
                'package_id' => $package->id,
                'order_ref' => $orderRef,
                'exception' => $exception::class,
                'error_fingerprint' => hash('sha256', $exception->getMessage()),
            ]);

            return $this->responses->make(false, 'Failed to create payment order.', [], 500);
        }

        try {
            $hppUrl = $this->kashier->getHppUrl(
                $orderRef,
                number_format((float) $order->final_amount, 2, '.', ''),
                'EGP',
                route('payment.callback')
            );
        } catch (\Throwable $exception) {
            report($exception);

            return $this->responses->make(
                false,
                'Payment is temporarily unavailable. Retry this checkout in a moment.',
                [],
                503,
                'checkout_temporarily_unavailable'
            );
        }

        Log::info('Kashier HPP URL generated', [
            'order_ref' => $orderRef,
            'user_id' => $user->id,
        ]);

        return $this->responses->make(true, 'Payment checkout created.', [
            'payment_url' => $hppUrl,
            'order_ref' => $orderRef,
            'idempotency_key' => $order->checkout_request_key,
            'checkout_expires_at' => $order->checkout_expires_at?->toIso8601String(),
            'amount' => $order->final_amount,
            'package' => [
                'id' => $package->id,
                'name_ar' => $package->name_ar,
                'name_en' => $package->name_en,
                'coins' => $this->payments->coinAmount($order),
            ],
        ]);
    }

    public function status(Request $request, string $orderRef): JsonResponse
    {
        /** @var User $user */
        $user = auth('api')->user();
        $order = Order::byOrderRef($orderRef)
            ->where('user_id', $user->id)
            ->with('package')
            ->first();

        if (!$order) {
            Log::info('Kashier status poll: order not found', [
                'order_ref' => $orderRef,
                'user_id' => $user->id,
            ]);

            return $this->responses->make(
                false,
                'Order not found.',
                [],
                404,
                'order_not_found'
            );
        }

        if ($order->status === Order::STATUS_PENDING) {
            $checkoutExpired = $order->isCheckoutExpired();
            $apiResponse = $this->payments->verifyOrderViaApi((string) $order->order_ref);
            if ($this->payments->isOrderCaptured($apiResponse)) {
                try {
                    $transactionId = $this->payments->extractTransactionId($apiResponse);
                    $order = $this->payments->fulfillOrder($order, $transactionId, [
                        'verified_via' => 'kashier_api_status_poll',
                        'kashier_api_response' => $apiResponse,
                    ]);

                    if ($order->status === Order::STATUS_APPROVED) {
                        Log::info('Kashier status poll reconciled captured payment', [
                            'order_ref' => $order->order_ref,
                            'order_id' => $order->id,
                            'user_id' => $user->id,
                            'transaction_id' => $order->transaction_id,
                        ]);
                    }
                } catch (\Throwable $exception) {
                    Log::error('Kashier status reconciliation failed', [
                        'order_ref' => $order->order_ref,
                        'order_id' => $order->id,
                        'user_id' => $user->id,
                        'exception' => $exception::class,
                        'error_fingerprint' => hash('sha256', $exception->getMessage()),
                    ]);
                    $order->refresh();
                    $order->loadMissing('package');
                }
            } elseif ($checkoutExpired && $apiResponse !== null) {
                $order = $this->payments->cancelPendingOrder($order);
            }
        }

        Log::info('Kashier status poll', [
            'order_ref' => $orderRef,
            'order_id' => $order->id,
            'user_id' => $user->id,
            'status' => $order->status,
        ]);

        return $this->responses->make(true, 'Payment status retrieved.', [
            'order_ref' => $order->order_ref,
            'status' => $order->status,
            'transaction_id' => $order->transaction_id,
            'amount' => $order->final_amount,
            'package' => $order->package ? [
                'id' => $order->package->id,
                'name_ar' => $order->package->name_ar,
                'name_en' => $order->package->name_en,
                'coins' => $this->payments->coinAmount($order),
            ] : null,
            'approved_at' => $order->approved_at,
        ]);
    }
}
