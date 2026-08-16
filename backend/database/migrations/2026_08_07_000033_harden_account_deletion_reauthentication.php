<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;

return new class extends Migration
{
    public function up(): void
    {
        $tokenTable = (string) config('multiple-tokens-auth.table', 'api_tokens');
        if (Schema::hasTable($tokenTable) && ! Schema::hasColumn($tokenTable, 'issued_at')) {
            Schema::table($tokenTable, function (Blueprint $table): void {
                $table->timestamp('issued_at')->nullable()->after('token')->index();
            });

            // Preserve a conservative issuance estimate for existing rows.
            // They still cannot authorize deletion without a matching recent
            // provider verification, so a rolling deploy never weakens reauth.
            $days = max(1, (int) config('multiple-tokens-auth.token.life_length', 60));
            DB::table($tokenTable)
                ->whereNull('issued_at')
                ->orderBy('token')
                ->chunk(1000, function ($tokens) use ($tokenTable, $days): void {
                    foreach ($tokens as $token) {
                        DB::table($tokenTable)
                            ->where('token', $token->token)
                            ->update([
                                'issued_at' => Carbon::parse($token->expired_at)->subDays($days),
                            ]);
                    }
                });
        }

        if (! Schema::hasTable('deleted_social_reward_tombstones')) {
            Schema::create('deleted_social_reward_tombstones', function (Blueprint $table): void {
                $table->id();
                $table->string('provider', 32);
                $table->char('identity_hmac', 64);
                $table->json('consumed_reward_keys');
                $table->timestamps();

                $table->unique(['provider', 'identity_hmac'], 'deleted_social_reward_identity_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('deleted_social_reward_tombstones');

        $tokenTable = (string) config('multiple-tokens-auth.table', 'api_tokens');
        if (Schema::hasTable($tokenTable) && Schema::hasColumn($tokenTable, 'issued_at')) {
            Schema::table($tokenTable, function (Blueprint $table): void {
                $table->dropIndex(['issued_at']);
                $table->dropColumn('issued_at');
            });
        }
    }
};
