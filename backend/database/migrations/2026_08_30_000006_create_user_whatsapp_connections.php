<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        foreach (['users', 'coin_earning_methods'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Required table [{$table}] is missing.");
            }
        }

        if (! Schema::hasTable('user_whatsapp_connections')) {
            Schema::create('user_whatsapp_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('phone_e164', 20)->unique();
            $table->timestamp('declared_at')->index();
            $table->boolean('ownership_verified')->default(false);
            $table->timestamp('verified_at')->nullable()->index();
            $table->boolean('marketing_opt_in')->default(false)->index();
            $table->timestamp('marketing_consent_at')->nullable();
            $table->timestamp('marketing_withdrawn_at')->nullable();
            $table->string('consent_version', 40)->nullable();
            $table->string('consent_source', 80)->nullable();
            $table->timestamps();
            });
        }

        if (! Schema::hasTable('whatsapp_link_tokens')) {
            Schema::create('whatsapp_link_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('coin_earning_method_id')->constrained()->cascadeOnDelete();
            // Only a one-way digest is stored. The raw token exists briefly in
            // the WhatsApp deep link returned to the authenticated learner.
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable()->index();
            $table->string('sender_phone_e164', 20)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'coin_earning_method_id']);
            });
        }

        DB::table('coin_earning_methods')->insertOrIgnore([
            'title_ar' => 'اربط واتسابك بحساب ركن',
            'title_en' => 'Connect WhatsApp to your Rokn account',
            'coins_amount' => 15,
            'action_key' => 'link_whatsapp',
            'action_url' => null,
            'requires_external_visit' => true,
            'verification_delay_seconds' => 0,
            'is_active' => true,
            'is_repeatable' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('coin_earning_methods')) {
            DB::table('coin_earning_methods')->where('action_key', 'link_whatsapp')->delete();
        }
        Schema::dropIfExists('whatsapp_link_tokens');
        Schema::dropIfExists('user_whatsapp_connections');
    }
};
