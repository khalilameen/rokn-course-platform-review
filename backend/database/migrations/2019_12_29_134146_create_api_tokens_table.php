<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable((string) config('multiple-tokens-auth.table', 'api_tokens'))) {
            return;
        }

        Schema::create((string) config('multiple-tokens-auth.table', 'api_tokens'), function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->index();
            $table->string('token', 80)->unique();
            $table->dateTime('issued_at');
            $table->dateTime('expired_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((string) config('multiple-tokens-auth.table', 'api_tokens'));
    }
};
