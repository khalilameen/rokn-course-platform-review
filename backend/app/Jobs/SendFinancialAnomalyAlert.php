<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\FinancialAnomaly;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

final class SendFinancialAnomalyAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $anomalyId)
    {
    }

    public function handle(): void
    {
        $anomaly = FinancialAnomaly::query()
            ->with(['user:id,name,email', 'course:id,name_ar,name_en'])
            ->find($this->anomalyId);
        if (!$anomaly || $anomaly->status !== FinancialAnomaly::STATUS_OPEN) {
            return;
        }

        $recipients = User::query()
            ->where('role', 'admin')
            ->where('active', true)
            ->whereNotNull('email')
            ->pluck('email')
            ->filter()
            ->unique();
        foreach ($recipients as $email) {
            Mail::raw(
                "تم إيقاف AI لهذا الاشتراك فقط لحين المراجعة\n"
                . 'الطالب: ' . ($anomaly->user?->email ?: '#' . $anomaly->user_id) . "\n"
                . 'الكورس: ' . ($anomaly->course?->name_ar ?: $anomaly->course?->name_en) . "\n"
                . 'المفروض مدفوع: ' . $anomaly->expected_paid_coins . " عملة\n"
                . 'الفعلي المدفوع: ' . $anomaly->actual_paid_coins . " عملة\n"
                . 'رقم التنبيه: ' . $anomaly->public_id,
                static fn ($message) => $message
                    ->to((string) $email)
                    ->subject('تنبيه مالي من ركن: اشتراك أقل من الحد المدفوع')
            );
        }
    }
}
