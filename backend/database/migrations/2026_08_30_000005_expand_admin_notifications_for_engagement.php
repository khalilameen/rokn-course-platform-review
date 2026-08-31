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
        Schema::table('admin_notifications', function (Blueprint $table): void {
            $table->string('system_key', 80)->nullable()->unique();
            $table->string('surface', 32)->default('announcement')->index();
            $table->string('action_label_ar')->nullable();
            $table->string('action_label_en')->nullable();
            $table->string('secondary_action_label_ar')->nullable();
            $table->string('secondary_action_label_en')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_dismissible')->default(true);
            $table->unsignedSmallInteger('priority')->default(100);
            $table->unsignedSmallInteger('cooldown_hours')->default(72);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
        });

        $now = now();
        DB::table('admin_notifications')->insertOrIgnore([
            [
                'system_key' => 'guest_registration_prompt',
                'surface' => 'guest_prompt',
                'title_ar' => 'هديتك جاهزة',
                'title_en' => 'Claim your welcome gift',
                'description_ar' => "سجّل الدخول واحصل على {coins} عملة ركن\nأو أكمل كزائر",
                'description_en' => 'Sign in to receive {coins} Rokn coins. You can also keep browsing as a guest.',
                'action_label_ar' => 'تسجيل الدخول',
                'action_label_en' => 'Sign in and claim',
                'secondary_action_label_ar' => 'المتابعة كزائر',
                'secondary_action_label_en' => 'Continue as guest',
                'link' => '/login',
                'is_active' => true,
                'is_dismissible' => true,
                'priority' => 10,
                'cooldown_hours' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'system_key' => 'welcome_bonus_received',
                'surface' => 'transactional',
                'title_ar' => 'وصلت هديتك',
                'title_en' => 'Your balance is ready',
                'description_ar' => "أضفنا {coins} عملة ركن إلى محفظتك\nاستخدمها داخل التطبيق",
                'description_en' => 'We added {coins} Rokn coins to your wallet. They are in-app credits, not cash.',
                'action_label_ar' => 'افتح الكورسات',
                'action_label_en' => 'Browse courses',
                'secondary_action_label_ar' => 'إغلاق',
                'secondary_action_label_en' => 'Close',
                'link' => '/home',
                'is_active' => true,
                'is_dismissible' => true,
                'priority' => 1,
                'cooldown_hours' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'system_key' => 'coin_offer',
                'surface' => 'retention',
                'title_ar' => 'عملات إضافية لك',
                'title_en' => 'A quick task for more coins',
                'description_ar' => "{task}\nاحصل على {coins} عملة ركن",
                'description_en' => '{task} and receive {coins} Rokn coins after completion.',
                'action_label_ar' => 'افتح المهمة',
                'action_label_en' => 'View task',
                'secondary_action_label_ar' => 'لاحقًا',
                'secondary_action_label_en' => 'Not now',
                'link' => '/wallet',
                'is_active' => true,
                'is_dismissible' => true,
                'priority' => 40,
                'cooldown_hours' => 72,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'system_key' => 'learning_nudge',
                'surface' => 'retention',
                'title_ar' => 'مقطعك التالي جاهز',
                'title_en' => 'Your next step is ready',
                'description_ar' => "{course}\nأكمل بمقطع واحد اليوم",
                'description_en' => 'Continue {course}. One short clip is enough to get back into it.',
                'action_label_ar' => 'أكمل من مكانك',
                'action_label_en' => 'Keep learning',
                'secondary_action_label_ar' => 'لاحقًا',
                'secondary_action_label_en' => 'Later',
                'link' => null,
                'is_active' => true,
                'is_dismissible' => true,
                'priority' => 50,
                'cooldown_hours' => 24,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::table('admin_notifications', function (Blueprint $table): void {
            $table->dropUnique(['system_key']);
            $table->dropIndex(['surface']);
            $table->dropIndex(['is_active']);
            $table->dropColumn([
                'system_key',
                'surface',
                'action_label_ar',
                'action_label_en',
                'secondary_action_label_ar',
                'secondary_action_label_en',
                'is_active',
                'is_dismissible',
                'priority',
                'cooldown_hours',
                'starts_at',
                'ends_at',
            ]);
        });
    }
};
