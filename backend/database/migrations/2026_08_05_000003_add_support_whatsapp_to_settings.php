<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->string('support_whatsapp_url')->nullable()->after('privacy_policy_url');
        });

        DB::table('settings')->whereNull('how_to_use_coins_ar')->update([
            'how_to_use_coins_ar' => 'عملات ركن رصيد افتراضي غير قابل للتحويل إلى نقد. الرصيد نوعان: عملات مشتراة وعملات مكافآت. عند فتح كورس تُستهلك عملات المكافآت أولًا ثم العملات المشتراة. عند الاسترداد تُعاد العملات إلى مصدرها الأصلي قدر الإمكان، ويظل احتساب الدفع والإيراد وفق سجل العملية المحفوظ.',
        ]);
        DB::table('settings')->whereNull('how_to_use_coins_en')->update([
            'how_to_use_coins_en' => 'Rokn coins are a non-withdrawable virtual balance. Reward coins are spent before purchased coins. Refunds return coins to their original source where possible, while payment attribution follows the immutable transaction record.',
        ]);
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->dropColumn('support_whatsapp_url');
        });
    }
};
