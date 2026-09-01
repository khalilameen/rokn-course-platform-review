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

    public function initiate(Request $request, bool $allowPendingRecovery = true): JsonResponse
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
                "تغيّر طلب الدفع أثناء التنفيذ\nأعد المحاولة",
                [],
                422,
                'checkout_idempotency_mismatch',
                [
                    'idempotency_key' => [
                        'أعد محاولة الدفع',
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
                'expected_amount' => 'nullable|numeric|min:0.01|max:100000000',
                'expected_coins' => 'nullable|integer|min:1|max:1000000000',
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
                'راجع بيانات الدفع',
                [],
                422,
                'validation_error',
                $exception->errors()
            );
        }

        /** @var User $user */
        $user = auth('api')->user();
        $package = Package::findOrFail($request->package_id);

        try {
            $this->payments->configuration();
        } catch (\RuntimeException $exception) {
            Log::critical('Kashier payment initiation blocked by configuration', [
                'exception' => $exception::class,
            ]);

            return $this->responses->make(
                false,
                "الدفع غير متاح الآن\nحاول لاحقًا",
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
            $checkout = $this->payments->beginCheckout(
                $user,
                $package,
                $clientRequestKey,
                isset($validated['expected_amount']) ? (float) $validated['expected_amount'] : null,
                isset($validated['expected_coins']) ? (int) $validated['expected_coins'] : null
            );

            /** @var Order $order */
            $order = $checkout['order'];
            $orderRef = (string) $order->order_ref;

            if ($checkout['closed'] !== null) {
                if ($checkout['closed'] === 'expired' && $order->status === Order::STATUS_PENDING) {
                    $order = $this->reconcileProviderOrder($order);
                    if ($order->status === Order::STATUS_PENDING) {
                        return $this->pendingCheckoutResponse($order);
                    }
                }
                $expired = $checkout['closed'] === 'expired'
                    && $order->status !== Order::STATUS_APPROVED;

                return $this->responses->make(
                    false,
                    $expired
                        ? "انتهت محاولة الدفع\nابدأ محاولة جديدة"
                        : "أغلقت محاولة الدفع\nابدأ محاولة جديدة",
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
            $pendingCheckout = $exception->getMessage() === 'A previous payment is still pending confirmation.';
            $packageUnavailable = $exception->getMessage()
                === 'This package is not available for checkout.';
            $packageTermsChanged = in_array($exception->getMessage(), [
                'Package terms changed before checkout.',
                'Checkout idempotency key was replayed with different package terms.',
            ], true);
            $pendingOrder = $pendingCheckout
                ? Order::query()
                    ->where('user_id', $user->id)
                    ->where('package_id', $package->id)
                    ->where('payment_method', Order::PAYMENT_METHOD_KASHIER)
                    ->where('status', Order::STATUS_PENDING)
                    ->where(function ($query): void {
                        $query->where('checkout_expires_at', '>', now())
                            ->orWhere(function ($legacy): void {
                                $legacy->whereNull('checkout_expires_at')
                                    ->where('created_at', '>', now()->subMinutes(30));
                            });
                    })
                    ->with('package')
                    ->latest('id')
                    ->first()
                : null;
            if ($pendingOrder) {
                $pendingOrder = $this->reconcileProviderOrder($pendingOrder);
                if ($pendingOrder->status !== Order::STATUS_PENDING && $allowPendingRecovery) {
                    return $this->initiate($request, false);
                }
                if ($pendingOrder->status === Order::STATUS_PENDING) {
                    return $this->pendingCheckoutResponse($pendingOrder);
                }
            }
            return $this->responses->make(
                false,
                $pendingCheckout
                    ? 'لديك عملية دفع قيد التأكيد'
                    : ($packageUnavailable
                        ? 'الباقة غير متاحة الآن'
                        : ($packageTermsChanged
                            ? "تغيّرت تفاصيل الباقة\nراجعها قبل الدفع"
                            : "تغيّر طلب الدفع أثناء التنفيذ\nأعد المحاولة")),
                [],
                409,
                $pendingCheckout
                    ? 'pending_checkout_exists'
                    : ($packageUnavailable
                        ? 'package_not_available'
                        : ($packageTermsChanged
                            ? 'package_terms_changed'
                            : 'checkout_idempotency_conflict'))
            );
        } catch (\Throwable $exception) {
            Log::error('Kashier order creation failed', [
                'user_id' => $user->id,
                'package_id' => $package->id,
                'order_ref' => $orderRef,
                'exception' => $exception::class,
                'error_fingerprint' => hash('sha256', $exception->getMessage()),
            ]);

            return $this->responses->make(false, 'تعذّر بدء الدفع', [], 500);
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
                "الدفع غير متاح الآن\nحاول بعد لحظات",
                [],
                503,
                'checkout_temporarily_unavailable'
            );
        }

        Log::info('Kashier HPP URL generated', [
            'order_ref' => $orderRef,
            'user_id' => $user->id,
        ]);

        return $this->responses->make(true, 'تم تجهيز صفحة الدفع', [
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

    public function status(
        Request $request,
        string $orderRef,
        bool $reconcile = false
    ): JsonResponse
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
                'عملية الدفع غير متاحة',
                [],
                404,
                'order_not_found'
            );
        }

        if ($reconcile && $order->status === Order::STATUS_PENDING) {
            $order = $this->reconcileProviderOrder($order);
        }

        Log::info('Kashier status poll', [
            'order_ref' => $orderRef,
            'order_id' => $order->id,
            'user_id' => $user->id,
            'status' => $order->status,
            'reconciliation_requested' => $reconcile,
        ]);

        return $this->responses->make(true, 'تم تحميل حالة الدفع', [
            'order_ref' => $order->order_ref,
            'status' => $order->status,
            'financial_status' => $order->financial_status,
            'reversed_at' => $order->reversed_at?->toIso8601String(),
            'transaction_id' => $order->transaction_id,
            'amount' => $order->final_amount,
            'checkout_expires_at' => $order->checkout_expires_at?->toIso8601String(),
            'package' => $order->package ? [
                'id' => $order->package->id,
                'name_ar' => $order->package->name_ar,
                'name_en' => $order->package->name_en,
                'coins' => $this->payments->coinAmount($order),
            ] : null,
            'approved_at' => $order->approved_at,
        ]);
    }

    private function reconcileProviderOrder(Order $order): Order
    {
        $apiResponse = $this->payments->verifyOrderViaApi((string) $order->order_ref);
        if ($this->payments->isOrderCaptured($apiResponse)) {
            try {
                return $this->payments->fulfillOrder(
                    $order,
                    $this->payments->extractTransactionId($apiResponse),
                    [
                        'verified_via' => 'kashier_api_status_poll',
                        'kashier_api_response' => $apiResponse,
                    ]
                );
            } catch (\Throwable $exception) {
                Log::error('Kashier status reconciliation failed', [
                    'order_ref' => $order->order_ref,
                    'order_id' => $order->id,
                    'user_id' => $order->user_id,
                    'exception' => $exception::class,
                    'error_fingerprint' => hash('sha256', $exception->getMessage()),
                ]);

                return $order->fresh(['package']);
            }
        }

        $providerStatus = $this->payments->providerOrderStatus($apiResponse);
        if ($this->payments->isProviderFailureStatus($providerStatus)) {
            return $this->payments->cancelPendingOrder($order, $apiResponse);
        }

        return $order->fresh(['package']);
    }

    private function pendingCheckoutResponse(Order $order): JsonResponse
    {
        $paymentUrl = null;
        try {
            $paymentUrl = $this->kashier->getHppUrl(
                (string) $order->order_ref,
                number_format((float) $order->final_amount, 2, '.', ''),
                'EGP',
                route('payment.callback')
            );
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $this->responses->make(
            false,
            'لديك عملية دفع قيد التأكيد',
            [
                'order_ref' => (string) $order->order_ref,
                'status' => (string) $order->status,
                'payment_url' => $paymentUrl,
                'checkout_expires_at' => $order->checkout_expires_at?->toIso8601String(),
                'package' => [
                    'id' => (int) $order->package_id,
                    'coins' => $this->payments->coinAmount($order),
                ],
            ],
            409,
            'pending_checkout_exists'
        );
    }
}
