<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\SendWhatsAppMessage;
use App\Models\CoinEarningMethod;
use App\Models\User;
use App\Models\UserWhatsAppConnection;
use App\Services\ApiResponseService;
use App\Services\StudentNotificationService;
use App\Services\WhatsAppLinkService;
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
            'تم تحميل حالة ربط واتساب'
        );
    }

    public function webhook(Request $request): JsonResponse
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
        // Whatspie documents a webhook URL but no caller-configurable request
        // header. Keep the header for providers which support it and accept
        // the same high-entropy token in the configured webhook URL so the
        // production integration is actually callable.
        $provided = $request->header('X-WhatsApp-Webhook-Secret');
        if (!is_string($provided) || trim($provided) === '') {
            $provided = $request->query('token');
        }
        $providedSecret = is_string($provided) ? trim($provided) : '';
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
            'message',
            'message.text',
            'message.body',
            'text.body',
            'body',
            'text',
            'data.message',
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
                $this->sendReply(
                    $sender,
                    "هذا الرقم مرتبط بحساب ركن آخر\nافتح المهمة من الحساب الصحيح"
                );
            }

            $data = ['matched' => false];

            return $this->responses->success(
                $data,
                'Inbound message was not linked',
                200,
                $data
            );
        }

        // A provider retry also repairs the inbox receipt when the first
        // request committed the wallet entry but stopped before this point.
        // delivery_key makes this safe across duplicate webhooks.
        if ($result['matched'] && $result['user'] && $result['earned_coins'] > 0) {
            $method = CoinEarningMethod::query()->where('action_key', 'link_whatsapp')->first();
            try {
                StudentNotificationService::notifyUser(
                    $result['user'],
                    StudentNotificationService::TYPE_WHATSAPP_CONNECTED,
                    'تم ربط واتساب',
                    'WhatsApp Connected',
                    'أضفنا ' . $result['earned_coins'] . " عملة ركن إلى رصيدك\nافتح المحفظة لمعرفة التفاصيل",
                    $result['earned_coins'] . ' Rokn coins were added to your wallet.',
                    null,
                    $method ? CoinEarningMethod::class : null,
                    $method?->id,
                    'whatsapp-linked:' . $result['user']->id,
                    ['coins' => (int) $result['earned_coins']]
                );
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        if (
            $result['matched']
            && !$result['already_claimed']
            && $result['coins'] > 0
        ) {
            $this->sendReply(
                $sender,
                'تم ربط حسابك وإضافة ' . $result['coins'] . " عملة ركن\nرصيدك الآن "
                    . $result['balance'] . ' عملة'
            );
        } elseif ($result['matched'] && ($result['reward_deferred'] ?? false)) {
            $this->sendReply(
                $sender,
                "تم ربط حسابك\nافتح التطبيق لاستلام المكافأة"
            );
        } elseif ($result['matched'] && ($result['reward_unavailable'] ?? false)) {
            $this->sendReply($sender, 'تم ربط حسابك');
        }

        $data = [
            'matched' => $result['matched'],
            'already_claimed' => $result['already_claimed'],
            'coins_added' => $result['coins'],
            'reward_deferred' => (bool) ($result['reward_deferred'] ?? false),
            'reward_unavailable' => (bool) ($result['reward_unavailable'] ?? false),
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
            return $this->responses->error('اربط واتساب من مهمة العملات أولًا', 409);
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
            $optIn ? 'تم تفعيل رسائل واتساب' : 'تم إيقاف رسائل واتساب'
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

    private function sendReply(string $phone, string $message): void
    {
        // Whatspie requires the webhook to return within five seconds. Never
        // hold its inbound acknowledgement open while a second provider call
        // connects; a unique queued reply also absorbs webhook retries.
        try {
            SendWhatsAppMessage::dispatch($phone, $message);
        } catch (\Throwable $exception) {
            // Linking and its wallet receipt are already durable. A temporary
            // queue outage must not make Whatspie retry a completed financial
            // event or report the link itself as failed.
            report($exception);
        }
    }
}
