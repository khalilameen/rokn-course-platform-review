<?php

declare(strict_types=1);

use App\Models\CoinEarningMethod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('coin_earning_methods')) {
            return;
        }

        CoinEarningMethod::updateOrCreate(
            ['action_key' => 'register'],
            [
                'title_ar' => 'مكافأة التسجيل',
                'title_en' => 'Registration bonus',
                'coins_amount' => 20,
                'action_url' => null,
                'requires_external_visit' => false,
                'verification_delay_seconds' => 0,
                'is_active' => true,
                'is_repeatable' => false,
            ]
        );
    }

    public function down(): void
    {
        // Do not restore the conflicting historical 180/500-coin values.
    }
};
