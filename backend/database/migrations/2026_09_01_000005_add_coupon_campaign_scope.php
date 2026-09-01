<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (!Schema::hasColumn('coupons', 'course_id')) {
            Schema::table('coupons', fn (Blueprint $table) =>
                $table->foreignId('course_id')->nullable()->after('code')
                    ->constrained('courses')->restrictOnDelete()
            );
        }
        if (!Schema::hasColumn('coupons', 'starts_at')) {
            Schema::table('coupons', fn (Blueprint $table) =>
                $table->timestamp('starts_at')->nullable()->after('course_id')
            );
        }
        if (!Schema::hasColumn('coupons', 'max_redemptions')) {
            Schema::table('coupons', fn (Blueprint $table) =>
                $table->unsignedInteger('max_redemptions')->nullable()->after('balance')
            );
        }
        if (!Schema::hasIndex('coupons', 'coupon_campaign_window')) {
            Schema::table('coupons', fn (Blueprint $table) =>
                $table->index(['active', 'starts_at', 'expiry_date'], 'coupon_campaign_window')
            );
        }
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table): void {
            $table->dropIndex('coupon_campaign_window');
            $table->dropConstrainedForeignId('course_id');
            $table->dropColumn(['starts_at', 'max_redemptions']);
        });
    }
};
