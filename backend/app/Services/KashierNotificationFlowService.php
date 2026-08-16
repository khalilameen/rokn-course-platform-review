<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final readonly class KashierNotificationFlowService
{
    public function __construct(
        private KashierService $kashier,
        private KashierPaymentService $payments,
        private PaymentApiResponseService $responses
    ) {
    }

    public function callback(Request $request): View
    {
        $params = $request->all();
        $isValidSignature = $this->payments->parseAndValidateSignature(
            $request,
            $this->kashier,
            $params
        );

        $orderRef = $params['merchantOrderId']
            ?? $params['orderId']
            ?? $params['merchant_order_id']
            ?? $params['order_ref']
            ?? null;
        $paymentStatus = strtoupper((string) ($params['paymentStatus'] ?? $params['status'] ?? 'FAILURE'));
        $transactionId = $this->payments->normalizeTransactionId(
            $params['transactionId'] ?? $params['transaction_id'] ?? null
        );

        if (!$this->payments->isValidOrderReference($orderRef)) {
            Log::warning('Kashier callback rejected: missing or invalid order reference');

            return view('payment.result', [
                'success' => false,
                'order_ref' => null,
                'message' => 'Invalid payment reference.',
            ]);
        }

        Log::info('Kashier callback received', [
            'order_ref' => $orderRef,
            'payment_status' => $paymentStatus,
            'transaction_id' => $transactionId,
            'has_signature' => isset($params['signature']),
        ]);

        if ($paymentStatus !== 'SUCCESS' && empty($params['signature'])) {
            return $this->handleUnsignedCallbackFailure(
                $params,
                $orderRef,
                $paymentStatus,
                $transactionId
            );
        }

        if (!$isValidSignature) {
            Log::warning('Kashier callback: invalid signature', [
                'order_ref' => $orderRef,
                'payment_status' => $paymentStatus,
            ]);

            return view('payment.result', [
                'success' => false,
                'order_ref' => $orderRef,
                'message' => 'Invalid payment signature.',
            ]);
        }

        Log::info('Kashier callback: signature validated', [
            'order_ref' => $orderRef,
            'payment_status' => $paymentStatus,
        ]);

        $order = Order::byOrderRef($orderRef)->with(['user', 'package'])->first();

        if (!$order) {
            Log::warning('Kashier callback: order not found', [
                'order_ref' => $orderRef,
                'payment_status' => $paymentStatus,
            ]);

            return view('payment.result', [
                'success' => false,
                'order_ref' => $orderRef,
                'message' => 'Order not found.',
            ]);
        }

        if ($reversalType = $this->payments->financialReversalType($paymentStatus)) {
            $this->payments->recordFinancialReversal(
                $order,
                $reversalType,
                $paymentStatus,
                $transactionId,
                $params
            );

            return view('payment.result', [
                'success' => false,
                'order_ref' => $orderRef,
                'message' => 'Payment reversal received and queued for review.',
            ]);
        }

        if ($this->payments->flagApprovedTransactionConflict($order, $transactionId, $params)) {
            return view('payment.result', [
                'success' => false,
                'order_ref' => $orderRef,
                'message' => 'A conflicting payment event was queued for review.',
            ]);
        }

        if ($order->status === Order::STATUS_APPROVED) {
            Log::info('Kashier callback: order already approved (idempotent)', [
                'order_ref' => $orderRef,
                'transaction_id' => $order->transaction_id,
            ]);

            return view('payment.result', [
                'success' => true,
                'order_ref' => $orderRef,
                'transaction_id' => $order->transaction_id,
                'package' => $order->package,
                'message' => 'Payment already processed successfully.',
            ]);
        }

        if ($paymentStatus === 'SUCCESS') {
            return $this->handleSuccessfulCallback($order, $orderRef, $transactionId, $params);
        }

        $order = $this->payments->cancelPendingOrder($order, $params);

        if ($order->status === Order::STATUS_APPROVED) {
            return view('payment.result', [
                'success' => true,
                'order_ref' => $orderRef,
                'transaction_id' => $order->transaction_id,
                'package' => $order->package,
                'message' => 'Payment already processed successfully.',
            ]);
        }

        Log::warning('Kashier callback: payment failed (signed)', [
            'order_ref' => $orderRef,
            'order_id' => $order->id,
            'payment_status' => $paymentStatus,
            'transaction_id' => $transactionId,
        ]);

        return view('payment.result', [
            'success' => false,
            'order_ref' => $orderRef,
            'message' => 'Payment was not completed.',
        ]);
    }

    public function webhook(Request $request): JsonResponse
    {
        $params = $request->all();
        $isValidSignature = $this->payments->parseAndValidateSignature(
            $request,
            $this->kashier,
            $params
        );

        $orderRef = $params['merchantOrderId']
            ?? $params['orderId']
            ?? $params['merchant_order_id']
            ?? $params['order_ref']
            ?? null;
        $paymentStatus = strtoupper((string) ($params['paymentStatus'] ?? $params['status'] ?? 'FAILURE'));
        $transactionId = $this->payments->normalizeTransactionId(
            $params['transactionId'] ?? $params['transaction_id'] ?? null
        );

        if (!$this->payments->isValidOrderReference($orderRef)) {
            Log::warning('Kashier webhook rejected: missing or invalid order reference');

            return $this->responses->make(
                false,
                'Invalid payment reference',
                [],
                422,
                'invalid_payment_reference'
            );
        }

        Log::info('Kashier webhook received', [
            'order_ref' => $orderRef,
            'payment_status' => $paymentStatus,
            'transaction_id' => $transactionId,
            'has_signature' => isset($params['signature']),
        ]);

        if ($paymentStatus !== 'SUCCESS' && !isset($params['signature'])) {
            Log::warning('Kashier webhook: unsigned failure notification', [
                'order_ref' => $orderRef,
                'payment_status' => $paymentStatus,
            ]);

            return $this->responses->make(true, 'Unsigned failure ignored', [], 202);
        }

        if (!$isValidSignature) {
            Log::warning('Kashier webhook: invalid signature', [
                'order_ref' => $orderRef,
                'payment_status' => $paymentStatus,
            ]);

            return $this->responses->make(false, 'Invalid signature', [], 403, 'invalid_signature');
        }

        Log::info('Kashier webhook: signature validated', [
            'order_ref' => $orderRef,
            'payment_status' => $paymentStatus,
        ]);

        $order = Order::byOrderRef($orderRef)->with(['user', 'package'])->first();

        if (!$order) {
            Log::warning('Kashier webhook: order not found', [
                'order_ref' => $orderRef,
                'payment_status' => $paymentStatus,
            ]);

            return $this->responses->make(false, 'Order not found', [], 404, 'order_not_found');
        }

        if ($reversalType = $this->payments->financialReversalType($paymentStatus)) {
            $this->payments->recordFinancialReversal(
                $order,
                $reversalType,
                $paymentStatus,
                $transactionId,
                $params
            );

            return $this->responses->make(true, 'Financial reversal queued for review');
        }

        if ($this->payments->flagApprovedTransactionConflict($order, $transactionId, $params)) {
            return $this->responses->make(true, 'Conflicting payment event queued for review');
        }

        if ($order->status === Order::STATUS_APPROVED) {
            Log::info('Kashier webhook: order already approved (idempotent)', [
                'order_ref' => $orderRef,
                'transaction_id' => $order->transaction_id,
            ]);

            return $this->responses->make(true, 'Already processed');
        }

        if ($paymentStatus === 'SUCCESS') {
            return $this->handleSuccessfulWebhook($order, $orderRef, $transactionId, $params);
        }

        $order = $this->payments->cancelPendingOrder($order, $params);

        if ($order->status === Order::STATUS_APPROVED) {
            return $this->responses->make(true, 'Already processed');
        }

        Log::warning('Kashier webhook: payment failed (signed)', [
            'order_ref' => $orderRef,
            'order_id' => $order->id,
            'payment_status' => $paymentStatus,
            'transaction_id' => $transactionId,
        ]);

        return $this->responses->make(true, 'Payment failure recorded');
    }

    /**
     * @param array<string, mixed> $params
     */
    private function handleUnsignedCallbackFailure(
        array $params,
        string $orderRef,
        string $paymentStatus,
        ?string $transactionId
    ): View {
        Log::warning('Kashier callback: unsigned failure redirect', [
            'order_ref' => $orderRef,
            'payment_status' => $paymentStatus,
        ]);

        $order = Order::byOrderRef($orderRef)->with(['user', 'package'])->first();

        if ($order && $order->status === Order::STATUS_APPROVED) {
            return view('payment.result', [
                'success' => true,
                'order_ref' => $orderRef,
                'transaction_id' => $order->transaction_id,
                'package' => $order->package,
                'message' => 'Payment already processed successfully.',
            ]);
        }

        if ($paymentStatus === 'SERVERERROR' && $order && $order->status === Order::STATUS_PENDING) {
            $apiResponse = $this->payments->verifyOrderViaApi($orderRef);
            if ($this->payments->isOrderCaptured($apiResponse)) {
                try {
                    $transactionId = $this->payments->extractTransactionId($apiResponse) ?? $transactionId;
                    $order = $this->payments->fulfillOrder($order, $transactionId, array_merge($params, [
                        'verified_via' => 'kashier_api',
                        'kashier_api_response' => $apiResponse,
                    ]));

                    if ($this->payments->transactionIdConflicts($order, $transactionId)) {
                        return view('payment.result', [
                            'success' => false,
                            'order_ref' => $orderRef,
                            'message' => 'A conflicting payment event was queued for review.',
                        ]);
                    }

                    if ($order->status !== Order::STATUS_APPROVED) {
                        return view('payment.result', [
                            'success' => false,
                            'order_ref' => $orderRef,
                            'message' => 'This checkout is closed. The captured payment is queued for review.',
                        ]);
                    }

                    Log::info('Kashier callback: payment fulfilled via API after serverError redirect', [
                        'order_ref' => $orderRef,
                        'order_id' => $order->id,
                        'transaction_id' => $transactionId,
                    ]);

                    return view('payment.result', [
                        'success' => true,
                        'order_ref' => $orderRef,
                        'transaction_id' => $transactionId,
                        'package' => $order->package,
                        'coins_credited' => $this->payments->coinAmount($order),
                        'message' => 'Payment successful.',
                    ]);
                } catch (\Exception $exception) {
                    Log::error('Kashier callback: API-confirmed payment fulfillment failed', [
                        'order_ref' => $orderRef,
                        'order_id' => $order->id,
                        'exception' => $exception::class,
                        'error_fingerprint' => hash('sha256', $exception->getMessage()),
                    ]);
                }
            }

            Log::info('Kashier callback: serverError redirect — order left pending for status polling', [
                'order_ref' => $orderRef,
                'order_id' => $order->id,
            ]);

            return view('payment.result', [
                'success' => false,
                'pending' => true,
                'order_ref' => $orderRef,
                'message' => 'جاري معالجة الدفع. يرجى الانتظار '
                    . 'أو العودة إلى التطبيق للتحقق من حالة الدفع.',
            ]);
        }

        if ($order && $order->status === Order::STATUS_PENDING) {
            Log::info('Kashier callback: unsigned failure left order unchanged', [
                'order_ref' => $orderRef,
                'order_id' => $order->id,
            ]);
        } elseif ($order) {
            Log::info('Kashier callback: unsigned failure — order not in pending state, skipped', [
                'order_ref' => $orderRef,
                'order_status' => $order->status,
            ]);
        } else {
            Log::warning('Kashier callback: unsigned failure — order not found', [
                'order_ref' => $orderRef,
            ]);
        }

        return view('payment.result', [
            'success' => false,
            'order_ref' => $orderRef,
            'message' => 'فشلت عملية الدفع (' . $paymentStatus . ').',
        ]);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function handleSuccessfulCallback(
        Order $order,
        string $orderRef,
        ?string $transactionId,
        array $params
    ): View {
        [$transactionId, $captureEvidence] = $this->payments->captureEvidenceWithTransactionId(
            $orderRef,
            $transactionId,
            $params
        );

        try {
            $order = $this->payments->fulfillOrder($order, $transactionId, $captureEvidence);

            if ($this->payments->transactionIdConflicts($order, $transactionId)) {
                return view('payment.result', [
                    'success' => false,
                    'order_ref' => $orderRef,
                    'message' => 'A conflicting payment event was queued for review.',
                ]);
            }

            if ($order->status !== Order::STATUS_APPROVED) {
                return view('payment.result', [
                    'success' => false,
                    'order_ref' => $orderRef,
                    'message' => 'Payment could not be fulfilled automatically and is queued for review.',
                ]);
            }

            Log::info('Kashier callback: payment fulfilled successfully', [
                'order_ref' => $orderRef,
                'order_id' => $order->id,
                'transaction_id' => $transactionId,
                'user_id' => $order->user_id,
                'package_id' => $order->package_id,
                'coins_credited' => $this->payments->coinAmount($order),
                'wallet_total' => $order->user->wallet_coins,
            ]);

            return view('payment.result', [
                'success' => true,
                'order_ref' => $orderRef,
                'transaction_id' => $transactionId,
                'package' => $order->package,
                'coins_credited' => $this->payments->coinAmount($order),
                'message' => 'Payment successful.',
            ]);
        } catch (\Exception $exception) {
            Log::error('Kashier callback: fulfillment failed after successful payment', [
                'order_ref' => $orderRef,
                'order_id' => $order->id,
                'transaction_id' => $transactionId,
                'user_id' => $order->user_id,
                'exception' => $exception::class,
                'error_fingerprint' => hash('sha256', $exception->getMessage()),
            ]);

            return view('payment.result', [
                'success' => false,
                'order_ref' => $orderRef,
                'message' => 'Payment received but fulfillment failed. Please contact support.',
            ]);
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function handleSuccessfulWebhook(
        Order $order,
        string $orderRef,
        ?string $transactionId,
        array $params
    ): JsonResponse {
        [$transactionId, $captureEvidence] = $this->payments->captureEvidenceWithTransactionId(
            $orderRef,
            $transactionId,
            $params
        );

        try {
            $order = $this->payments->fulfillOrder($order, $transactionId, $captureEvidence);

            if ($this->payments->transactionIdConflicts($order, $transactionId)) {
                return $this->responses->make(true, 'Conflicting payment event queued for review');
            }

            if ($order->status !== Order::STATUS_APPROVED) {
                return $this->responses->make(true, 'Payment capture queued for review');
            }

            Log::info('Kashier webhook: payment fulfilled successfully', [
                'order_ref' => $orderRef,
                'order_id' => $order->id,
                'transaction_id' => $transactionId,
                'user_id' => $order->user_id,
                'package_id' => $order->package_id,
                'coins_credited' => $this->payments->coinAmount($order),
                'wallet_total' => $order->user->wallet_coins,
            ]);

            return $this->responses->make(true, 'Webhook processed successfully');
        } catch (\Exception $exception) {
            Log::error('Kashier webhook: fulfillment failed after successful payment', [
                'order_ref' => $orderRef,
                'order_id' => $order->id,
                'transaction_id' => $transactionId,
                'user_id' => $order->user_id,
                'exception' => $exception::class,
                'error_fingerprint' => hash('sha256', $exception->getMessage()),
            ]);

            return $this->responses->make(false, 'Fulfillment error', [], 500, 'fulfillment_error');
        }
    }
}
