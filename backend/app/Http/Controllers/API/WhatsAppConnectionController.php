<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CoinEarningMethod;
use App\Models\User;
use App\Models\UserWhatsAppConnection;
use App\Services\ApiResponseService;
use App\Services\StudentNotificationService;
use App\Services\WhatsAppLinkService;
use App\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WhatsAppConnectionController extends Controller
{
    public function __construct(
        private readonly ApiResponseService $responses,
        private readonly WhatsAppLinkService $links
    ) {
    }

    public function show(): JsonResponse
    {
        /** @var User $user */
        $user = auth('api')->user();

        return $this->responses->success(
            $this->present($user->whatsappConnection),
            'WhatsApp connection retrieved successfully'
        );
    }

    public function webhook(Request $request, WhatsAppService $whatsApp): JsonResponse
    {
        $configuredSecret = trim((string) config('whatsapp.linking.webhook_secret'));
        if ($configuredSecret === '') {
            return $this->responses->error(
                'Webhook is not configured',
                503,
                null,
                ['code' => 'webhook_not_configured']
            );
        }
        $providedSecret = trim((string) $request->header('X-WhatsApp-Webhook-Secret'));
        if ($providedSecret === '' || !hash_equals($configuredSecret, $providedSecret)) {
            return $this->responses->error(
                'Unauthorized',
                401,
                null,
                ['code' => 'unauthenticated']
            );
        }

        $payload = $request->all();
        $sender = $this->firstString($payload, [
            'sender',
            'from',
            'phone',
            'data.sender',
            'data.from',
            'entry.0.changes.0.value.messages.0.from',
        ]);
        $message = $this->firstString($payload, [
            'message.text',
            'message.body',
            'text.body',
            'body',
            'text',
            'data.message.text',
            'data.message.body',
            'data.text',
            'entry.0.changes.0.value.messages.0.text.body',
        ]);

        if ($sender === null || $message === null) {
            $data = ['matched' => false];

            return $this->responses->success(
                $data,
                'Inbound message ignored',
                200,
                $data
            );
        }

        try {
            $result = $this->links->consumeInbound($sender, $message);
        } catch (\DomainException $exception) {
            if ($exception->getMessage() === 'whatsapp_phone_in_use') {
                $whatsApp->sendTextMessage(
                    $sender,
                    'رقم واتساب ده مربوط بحساب ركن آخر. افتح المهمة من الحساب الصحيح وحاول تاني.'
                );
            }

            $data = [
                'matched' => false,
                'reason' => $exception->getMessage(),
            ];

            return $this->responses->success(
                $data,
                'Inbound message was not linked',
                200,
                $data
            );
        }

        if ($result['matched'] && !$result['already_claimed'] && $result['user']) {
            $method = CoinEarningMethod::query()->where('action_key', 'link_whatsapp')->first();
            try {
                StudentNotificationService::notifyUser(
                    $result['user'],
                    StudentNotificationService::TYPE_COINS_CLAIMED,
                    'تم ربط واتساب',
                    'WhatsApp Connected',
                    'أضفنا ' . $result['coins'] . " عملة ركن إلى رصيدك\nافتح المحفظة لمعرفة التفاصيل",
                    $result['coins'] . ' Rokn coins were added to your wallet.',
                    null,
                    $method ? CoinEarningMethod::class : null,
                    $method?->id,
                    'whatsapp-linked:' . $result['user']->id
                );
            } catch (\Throwable $exception) {
                report($exception);
            }
            $whatsApp->sendTextMessage(
                $sender,
                'تم ربط حسابك وإضافة ' . $result['coins'] . ' عملة ركن. رصيدك الآن '
                    . $result['balance'] . ' عملة.'
            );
        }

        $data = [
            'matched' => $result['matched'],
            'already_claimed' => $result['already_claimed'],
            'coins_added' => $result['coins'],
        ];

        return $this->responses->success(
            $data,
            'Inbound message processed',
            200,
            $data
        );
    }

    public function consent(Request $request): JsonResponse
    {
        $validated = $request->validate(['marketing_opt_in' => 'required|boolean']);
        /** @var User $user */
        $user = auth('api')->user();
        $connection = $user->whatsappConnection;
        if (!$connection?->verified_at) {
            return $this->responses->error('اربط واتساب من مهمة العملات أولًا.', 409);
        }
        $optIn = (bool) $validated['marketing_opt_in'];
        $connection->forceFill([
            'marketing_opt_in' => $optIn,
            'marketing_consent_at' => $optIn ? now() : null,
            'marketing_withdrawn_at' => $optIn ? null : now(),
            'consent_version' => (string) config('social_auth.legal_notice_version', '2026-08-06'),
            'consent_source' => 'settings_whatsapp_consent',
        ])->save();

        return $this->responses->success(
            ['connection' => $this->present($connection->fresh())],
            $optIn ? 'تم تفعيل رسائل واتساب.' : 'تم إيقاف رسائل واتساب.'
        );
    }

    /** @return array<string, mixed>|null */
    private function present(?UserWhatsAppConnection $connection): ?array
    {
        if (!$connection) {
            return null;
        }

        return [
            'phone' => $connection->phone_e164,
            'connected' => (bool) $connection->verified_at,
            'connected_at' => $connection->verified_at?->toIso8601String(),
            'ownership_verified' => (bool) $connection->ownership_verified,
            'marketing_opt_in' => (bool) $connection->marketing_opt_in,
            'marketing_consent_at' => $connection->marketing_consent_at?->toIso8601String(),
            'marketing_withdrawn_at' => $connection->marketing_withdrawn_at?->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $payload @param list<string> $paths */
    private function firstString(array $payload, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = data_get($payload, $path);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
