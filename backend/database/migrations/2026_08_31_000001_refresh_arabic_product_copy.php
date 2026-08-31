<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('admin_notifications') || !Schema::hasColumn('admin_notifications', 'system_key')) {
            return;
        }

        $messages = [
            'guest_registration_prompt' => [
                'title_ar' => 'هديتك جاهزة',
                'description_ar' => "سجّل الدخول واحصل على {coins} عملة ركن\nأو أكمل كزائر",
                'action_label_ar' => 'تسجيل الدخول',
                'secondary_action_label_ar' => 'المتابعة كزائر',
            ],
            'welcome_bonus_received' => [
                'title_ar' => 'وصلت هديتك',
                'description_ar' => "أضفنا {coins} عملة ركن إلى محفظتك\nاستخدمها داخل التطبيق",
                'action_label_ar' => 'افتح الكورسات',
                'secondary_action_label_ar' => 'إغلاق',
            ],
            'coin_offer' => [
                'title_ar' => 'عملات إضافية لك',
                'description_ar' => "{task}\nاحصل على {coins} عملة ركن",
                'action_label_ar' => 'افتح المهمة',
                'secondary_action_label_ar' => 'لاحقًا',
            ],
            'learning_nudge' => [
                'title_ar' => 'مقطعك التالي جاهز',
                'description_ar' => "{course}\nأكمل بمقطع واحد اليوم",
                'action_label_ar' => 'أكمل من مكانك',
                'secondary_action_label_ar' => 'لاحقًا',
            ],
        ];

        foreach ($messages as $systemKey => $copy) {
            DB::table('admin_notifications')
                ->where('system_key', $systemKey)
                ->update([...$copy, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Product copy remains safe to keep when rolling back unrelated schema changes.
    }
};
