<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_audit_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('request_id', 100)->index();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role', 30);
            $table->string('route_name')->nullable();
            $table->string('http_method', 10);
            $table->string('path', 500);
            $table->json('route_parameters')->nullable();
            $table->json('request_fields')->nullable();
            $table->unsignedSmallInteger('response_status');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('occurred_at')->index();

            $table->index(['actor_id', 'occurred_at']);
            $table->index(['route_name', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_logs');
    }
};
