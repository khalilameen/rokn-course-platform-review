<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CoinEarningMethod;
use App\Models\User;
use App\Models\UserCoinTaskAttempt;
use App\Models\UserWhatsAppConnection;
use App\Models\WalletTransaction;
use App\Models\WhatsAppLinkToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class WhatsAppLinkService
{
    private const TOKEN_PREFIX = 'ROKN_LINK_';

    public function __construct(private WalletService $wallet)
    {
    }

    /** @return array<string, mixed> */
    public function createLink(User $user, CoinEarningMethod $method): array
    {
        if (!$method->is_active || $method->action_key !== 'link_whatsapp') {
            throw new \DomainException('task_unavailable');
        }

        $botPhone = $this->normalizeBotPhone((string) config('whatsapp.linking.bot_phone'));
        if ($botPhone === null) {
            throw new \DomainException('whatsapp_bot_unavailable');
        }

        $rawToken = bin2hex(random_bytes(24));
        $expiresAt = now()->addMinutes(max(
            5,
            min(1440, (int) config('whatsapp.linking.token_minutes', 30))
        ));

        $result = DB::transaction(function () use ($user, $method, $rawToken, $expiresAt): array {
            /** @var User $lockedUser */
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $earning = $lockedUser->coinEarnings()
                ->where('coin_earning_method_id', $method->id)
                ->first();
            $attempt = UserCoinTaskAttempt::query()->firstOrCreate(
                [
                    'user_id' => $lockedUser->id,
                    'coin_earning_method_id' => $method->id,
                ],
                [
                    'public_id' => (string) Str::uuid(),
                    'status' => UserCoinTaskAttempt::STATUS_STARTED,
                    'started_at' => now(),
                    'claim_available_at' => null,
                ]
            );

            if ($earning || $attempt->status === UserCoinTaskAttempt::STATUS_CLAIMED) {
                return ['claimed' => true, 'attempt' => $attempt];
            }

            WhatsAppLinkToken::query()
                ->where('user_id', $lockedUser->id)
                ->where('coin_earning_method_id', $method->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            WhatsAppLinkToken::query()->create([
                'user_id' => $lockedUser->id,
                'coin_earning_method_id' => $method->id,
                'token_hash' => hash('sha256', $rawToken),
                'expires_at' => $expiresAt,
            ]);

            return ['claimed' => false, 'attempt' => $attempt];
        });

        if ($result['claimed']) {
            return [
                'task_state' => 'claimed',
                'action_url' => null,
                'attempt_id' => $result['attempt']->public_id,
            ];
        }

        $message = 'أرسل هذه الجملة لربط الحساب والحصول على المكافأة '
            . self::TOKEN_PREFIX . $rawToken;

        return [
            'task_state' => 'started',
            'attempt_id' => $result['attempt']->public_id,
            'action_url' => 'https://wa.me/' . $botPhone . '?text=' . rawurlencode($message),
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /** @return array{matched:bool,already_claimed:bool,user:?User,coins:int,balance:int} */
    public function consumeInbound(string $sender, string $message): array
    {
        $rawToken = $this->extractToken($message);
        $phone = $this->normalizeSenderPhone($sender);
        if ($rawToken === null || $phone === null) {
            return $this->unmatched();
        }

        return DB::transaction(function () use ($rawToken, $phone): array {
            /** @var WhatsAppLinkToken|null $link */
            $link = WhatsAppLinkToken::query()
                ->where('token_hash', hash('sha256', $rawToken))
                ->lockForUpdate()
                ->first();
            if (!$link || $link->expires_at->isPast()) {
                return $this->unmatched();
            }

            /** @var User $user */
            $user = User::query()->lockForUpdate()->findOrFail($link->user_id);
            /** @var CoinEarningMethod $method */
            $method = CoinEarningMethod::query()->findOrFail($link->coin_earning_method_id);

            if ($link->consumed_at) {
                $alreadyClaimed = $user->coinEarnings()
                    ->where('coin_earning_method_id', $method->id)
                    ->exists();

                return [
                    'matched' => true,
                    'already_claimed' => $alreadyClaimed,
                    'user' => $user,
                    'coins' => 0,
                    'balance' => (int) $user->wallet_coins,
                ];
            }

            if (UserWhatsAppConnection::query()
                ->where('phone_e164', $phone)
                ->where('user_id', '!=', $user->id)
                ->exists()) {
                throw new \DomainException('whatsapp_phone_in_use');
            }

            $connection = UserWhatsAppConnection::query()->firstOrNew(['user_id' => $user->id]);
            $connection->forceFill([
                'phone_e164' => $phone,
                'declared_at' => $connection->declared_at ?? now(),
                'ownership_verified' => true,
                'verified_at' => $connection->verified_at ?? now(),
                'marketing_opt_in' => (bool) ($connection->marketing_opt_in ?? false),
                'consent_source' => 'whatsapp_link_message',
            ])->save();

            $earning = $user->coinEarnings()
                ->where('coin_earning_method_id', $method->id)
                ->first();
            $attempt = UserCoinTaskAttempt::query()->firstOrCreate(
                [
                    'user_id' => $user->id,
                    'coin_earning_method_id' => $method->id,
                ],
                [
                    'public_id' => (string) Str::uuid(),
                    'status' => UserCoinTaskAttempt::STATUS_STARTED,
                    'started_at' => now(),
                ]
            );
            $alreadyClaimed = $earning !== null
                || $attempt->status === UserCoinTaskAttempt::STATUS_CLAIMED;
            $coins = 0;
            $balance = (int) $user->wallet_coins;

            if (!$alreadyClaimed) {
                $transaction = $this->wallet->credit(
                    $user->id,
                    (int) $method->coins_amount,
                    'task_reward',
                    "coin-task:{$user->id}:{$method->id}",
                    $method,
                    ['action_key' => $method->action_key, 'verified_by' => 'whatsapp_inbound'],
                    WalletTransaction::BUCKET_REWARD
                );
                $user->coinEarnings()->firstOrCreate(
                    ['coin_earning_method_id' => $method->id],
                    ['amount' => $method->coins_amount]
                );
                $attempt->forceFill([
                    'status' => UserCoinTaskAttempt::STATUS_CLAIMED,
                    'claim_available_at' => now(),
                    'claimed_at' => now(),
                    'metadata' => array_merge((array) $attempt->metadata, [
                        'verification' => 'whatsapp_inbound',
                    ]),
                ])->save();
                $coins = (int) $method->coins_amount;
                $balance = (int) $transaction->balance_after;
            }

            $link->forceFill([
                'consumed_at' => now(),
                'sender_phone_e164' => $phone,
            ])->save();

            return [
                'matched' => true,
                'already_claimed' => $alreadyClaimed,
                'user' => $user->fresh(),
                'coins' => $coins,
                'balance' => $balance,
            ];
        });
    }

    private function extractToken(string $message): ?string
    {
        if (!preg_match('/' . self::TOKEN_PREFIX . '([a-f0-9]{48})/i', $message, $matches)) {
            return null;
        }

        return strtolower($matches[1]);
    }

    private function normalizeBotPhone(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?: '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        return preg_match('/^[1-9][0-9]{9,14}$/', $digits) ? $digits : null;
    }

    private function normalizeSenderPhone(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?: '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $digits = '20' . substr($digits, 1);
        }

        return preg_match('/^[1-9][0-9]{9,14}$/', $digits) ? '+' . $digits : null;
    }

    /** @return array{matched:false,already_claimed:false,user:null,coins:0,balance:0} */
    private function unmatched(): array
    {
        return [
            'matched' => false,
            'already_claimed' => false,
            'user' => null,
            'coins' => 0,
            'balance' => 0,
        ];
    }
}
