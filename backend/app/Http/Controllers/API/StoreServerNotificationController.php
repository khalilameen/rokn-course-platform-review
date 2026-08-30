<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Exceptions\StorePurchaseVerificationException;
use App\Http\Controllers\Controller;
use App\Services\StoreServerNotificationService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class StoreServerNotificationController extends Controller
{
    public function google(Request $request, StoreServerNotificationService $service): Response
    {
        if (strlen($request->getContent()) > 131072) {
            return response()->json(['error' => 'notification_payload_too_large'], 413);
        }

        try {
            $token = trim((string) $request->bearerToken());
            if ($token === '') {
                throw new StorePurchaseVerificationException(
                    'google_rtdn_identity_missing',
                    'Google Play notification identity is missing.',
                    401
                );
            }
            $service->handleGoogle((array) $request->json()->all(), $token);
        } catch (StorePurchaseVerificationException $exception) {
            return response()->json(['error' => $exception->errorCode], $exception->httpStatus);
        }

        return response()->noContent();
    }

    public function apple(Request $request, StoreServerNotificationService $service): Response
    {
        if (strlen($request->getContent()) > 131072) {
            return response()->json(['error' => 'notification_payload_too_large'], 413);
        }

        try {
            $signedPayload = trim((string) $request->input('signedPayload'));
            if ($signedPayload === '') {
                throw new StorePurchaseVerificationException('apple_notification_payload_missing');
            }
            $service->handleApple($signedPayload);
        } catch (StorePurchaseVerificationException $exception) {
            return response()->json(['error' => $exception->errorCode], $exception->httpStatus);
        }

        return response()->noContent();
    }
}
