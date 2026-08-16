<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use App\Models\CoinEarningMethod;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('coin_earning_methods')) {
            CoinEarningMethod::updateOrCreate(
                ['action_key' => 'register'],
                [
                    'title_ar' => 'مكافأة التسجيل الجديد',
                    'title_en' => 'New Registration Bonus',
                    'coins_amount' => 500,
                    'is_active' => true,
                    'is_repeatable' => false,
                ]
            );
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasTable('coin_earning_methods')) {
            CoinEarningMethod::where('action_key', 'register')->delete();
        }
    }
};
