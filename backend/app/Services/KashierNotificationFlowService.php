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
        $hasSignatureCandidate = $this->hasSignatureCandidate($request, $params);

        $orderRef = $params['merchantOrderId']
            ?? $params['orderId']
            ?? $params['merchant_order_id']
            ?? $params['order_ref']
            ?? null;
        $paymentStatus = strtoupper(trim((string) (
            $params['paymentStatus'] ?? $params['status'] ?? 'FAILURE'
        )));
        $transactionId = $this->payments->normalizeTransactionId(
            $params['transactionId'] ?? $params['transaction_id'] ?? null
        );

        if (!$this->payments->isValidOrderReference($orderRef)) {
            Log::warning('Kashier callback rejected: missing or invalid order reference');

            return view('payment.result', [
                'success' => false,
                'order_ref' => null,
                'message' => 'تعذّر التحقق من عملية الدفع',
            ]);
        }

        Log::info('Kashier callback received', [
            'order_ref' => $orderRef,
            'payment_status' => $paymentStatus,
            'transaction_id' => $transactionId,
            'has_signature' => $hasSignatureCandidate,
        ]);

        if (
            !$this->payments->isCaptureNotificationStatus($paymentStatus)
            && !$hasSignatureCandidate
        ) {
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
                'message' => 'تعذّر التحقق من عملية الدفع',
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
                'message' => 'عملية الدفع غير متاحة',
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
                'message' => 'نراجع عملية رد المبلغ',
            ]);
        }

        if ($this->payments->flagApprovedTransactionConflict($order, $transactionId, $params)) {
            return view('payment.result', [
                'success' => false,
                'order_ref' => $orderRef,
                'message' => 'نراجع حالة الدفع الآن',
            ]);
        }

        if ($this->isSettled($order)) {
            Log::info('Kashier callback: order already approved (idempotent)', [
                'order_ref' => $orderRef,
                'transaction_id' => $order->transaction_id,
            ]);

            return view('payment.result', [
                'success' => true,
                'order_ref' => $orderRef,
                'transaction_id' => $order->transaction_id,
                'package' => $order->package,
                'message' => 'تمت معالجة الدفع من قبل',
            ]);
        }

        if ($this->payments->isCaptureNotificationStatus($paymentStatus)) {
            return $this->handleSuccessfulCallback($order, $orderRef, $transactionId, $params);
        }

        if (!$this->payments->isProviderFailureStatus($paymentStatus)) {
            return view('payment.result', [
                'success' => false,
                'pending' => true,
                'order_ref' => $orderRef,
                'message' => "نعالج عملية الدفع الآن\nعد إلى التطبيق لمتابعة حالتها",
            ]);
        }

        $order = $this->payments->cancelPendingOrder($order, $params);

        if ($this->isSettled($order)) {
            return view('payment.result', [
                'success' => true,
                'order_ref' => $orderRef,
                'transaction_id' => $order->transaction_id,
                'package' => $order->package,
                'message' => 'تمت معالجة الدفع من قبل',
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
            'message' => 'لم تكتمل عملية الدفع',
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
        $hasSignatureCandidate = $this->hasSignatureCandidate($request, $params);

        $orderRef = $params['merchantOrderId']
            ?? $params['orderId']
            ?? $params['merchant_order_id']
            ?? $params['order_ref']
            ?? null;
        $paymentStatus = strtoupper(trim((string) (
            $params['paymentStatus'] ?? $params['status'] ?? 'FAILURE'
        )));
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
            'has_signature' => $hasSignatureCandidate,
        ]);

        if (
            !$this->payments->isCaptureNotificationStatus($paymentStatus)
            && !$hasSignatureCandidate
        ) {
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

        if ($this->isSettled($order)) {
            Log::info('Kashier webhook: order already approved (idempotent)', [
                'order_ref' => $orderRef,
                'transaction_id' => $order->transaction_id,
            ]);

            return $this->responses->make(true, 'Already processed');
        }

        if ($this->payments->isCaptureNotificationStatus($paymentStatus)) {
            return $this->handleSuccessfulWebhook($order, $orderRef, $transactionId, $params);
        }

        if (!$this->payments->isProviderFailureStatus($paymentStatus)) {
            return $this->responses->make(true, 'Payment state accepted for reconciliation', [], 202);
        }

        $order = $this->payments->cancelPendingOrder($order, $params);

        if ($this->isSettled($order)) {
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

        if ($order && $this->isSettled($order)) {
            return view('payment.result', [
                'success' => true,
                'order_ref' => $orderRef,
                'transaction_id' => $order->transaction_id,
                'package' => $order->package,
                'message' => 'تمت معالجة الدفع من قبل',
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
                            'message' => 'نراجع حالة الدفع الآن',
                        ]);
                    }

                    if (!$this->isSettled($order)) {
                        return view('payment.result', [
                            'success' => false,
                            'order_ref' => $orderRef,
                            'message' => "أغلقت صفحة الدفع\nنراجع المبلغ المدفوع الآن",
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
                        'message' => 'تم الدفع بنجاح',
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
                'message' => "نعالج عملية الدفع الآن\nعد إلى التطبيق لمتابعة حالتها",
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
            'message' => 'لم تكتمل عملية الدفع',
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
                    'message' => 'نراجع حالة الدفع الآن',
                ]);
            }

            if (!$this->isSettled($order)) {
                return view('payment.result', [
                    'success' => false,
                    'order_ref' => $orderRef,
                    'message' => "وصل الدفع\nنراجع إضافة العملات الآن",
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
                'message' => 'تم الدفع بنجاح',
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
                'message' => "وصل الدفع ولم تُضف العملات\nتواصل مع الدعم",
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

            if (!$this->isSettled($order)) {
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

    private function isSettled(Order $order): bool
    {
        return $order->status === Order::STATUS_APPROVED
            && $order->financial_status === Order::FINANCIAL_SETTLED
            && $order->reversed_at === null;
    }

    /** @param array<string, mixed> $params */
    private function hasSignatureCandidate(Request $request, array $params): bool
    {
        foreach ([
            $request->header('x-kashier-signature'),
            $request->header('kashier-signature'),
            $request->header('signature'),
            $params['kashierSignature'] ?? null,
            $params['signature'] ?? null,
            $params['hash'] ?? null,
            data_get($params, 'data.kashierSignature'),
            data_get($params, 'data.signature'),
            data_get($params, 'data.hash'),
        ] as $candidate) {
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                return true;
            }
        }

        return false;
    }
}
