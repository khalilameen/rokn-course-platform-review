<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SocialOAuthAttempt;
use Illuminate\Support\Facades\DB;

final class SocialOAuthAttemptService
{
    public function begin(
        string $state,
        string $provider,
        string $returnTo,
        ?string $codeChallenge
    ): SocialOAuthAttempt {
        return SocialOAuthAttempt::query()->create([
            'state_hash' => $this->hash($state),
            'provider' => $provider,
            'return_to' => $returnTo,
            'code_challenge' => $codeChallenge,
            'state_expires_at' => now()->addMinutes(10),
        ]);
    }

    public function inspectState(string $state): ?SocialOAuthAttempt
    {
        return SocialOAuthAttempt::query()
            ->where('state_hash', $this->hash($state))
            ->whereNull('state_consumed_at')
            ->where('state_expires_at', '>', now())
            ->first();
    }

    public function consumeState(string $state, string $provider): ?SocialOAuthAttempt
    {
        return DB::transaction(function () use ($state, $provider): ?SocialOAuthAttempt {
            $attempt = SocialOAuthAttempt::query()
                ->where('state_hash', $this->hash($state))
                ->whereNull('state_consumed_at')
                ->where('state_expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (!$attempt || !hash_equals((string) $attempt->provider, $provider)) {
                return null;
            }

            $attempt->forceFill(['state_consumed_at' => now()])->save();

            return $attempt;
        }, 3);
    }

    public function issueCompletion(
        SocialOAuthAttempt $attempt,
        string $completionCode,
        string $encryptedToken
    ): void {
        $attempt->forceFill([
            'completion_hash' => $this->hash($completionCode),
            'encrypted_token' => $encryptedToken,
            // Match the mobile handoff window. PKCE and the one-time hash keep
            // the code constrained while a killed/slow app gets enough time
            // to resume the completed login instead of showing a false expiry.
            'completion_expires_at' => now()->addMinutes(10),
        ])->save();
    }

    public function inspectCompletion(string $completionCode): ?SocialOAuthAttempt
    {
        return SocialOAuthAttempt::query()
            ->where('completion_hash', $this->hash($completionCode))
            ->where('completion_expires_at', '>', now())
            ->first();
    }

    public function consumeCompletion(string $completionCode): ?SocialOAuthAttempt
    {
        return DB::transaction(function () use ($completionCode): ?SocialOAuthAttempt {
            $attempt = SocialOAuthAttempt::query()
                ->where('completion_hash', $this->hash($completionCode))
                ->whereNull('completion_consumed_at')
                ->where('completion_expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (!$attempt) return null;

            $attempt->forceFill(['completion_consumed_at' => now()])->save();

            return $attempt;
        }, 3);
    }

    public function claimCompletion(string $completionCode): ?SocialOAuthAttempt
    {
        return DB::transaction(function () use ($completionCode): ?SocialOAuthAttempt {
            $attempt = SocialOAuthAttempt::query()
                ->where('completion_hash', $this->hash($completionCode))
                ->whereNull('completion_consumed_at')
                ->where('completion_expires_at', '>', now())
                ->where(function ($query): void {
                    $query->whereNull('completion_processing_at')
                        ->orWhere('completion_processing_at', '<=', now()->subMinutes(2));
                })
                ->lockForUpdate()
                ->first();

            if (!$attempt) return null;

            $attempt->forceFill(['completion_processing_at' => now()])->save();

            return $attempt;
        }, 3);
    }

    public function releaseCompletion(SocialOAuthAttempt $attempt): void
    {
        SocialOAuthAttempt::query()
            ->whereKey($attempt->id)
            ->whereNull('completion_consumed_at')
            ->update(['completion_processing_at' => null]);
    }

    public function finalizeCompletion(
        SocialOAuthAttempt $attempt,
        ?string $encryptedSessionResponse = null
    ): void
    {
        DB::transaction(function () use ($attempt, $encryptedSessionResponse): void {
            $locked = SocialOAuthAttempt::query()->lockForUpdate()->find($attempt->id);
            if (!$locked || $locked->completion_consumed_at) return;

            $locked->forceFill([
                'completion_processing_at' => null,
                'completion_consumed_at' => now(),
                'encrypted_token' => null,
                'encrypted_session_response' => $encryptedSessionResponse,
            ])->save();
        }, 3);
    }

    private function hash(string $value): string
    {
        return hash('sha256', $value);
    }
}
