<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AdminNotification;
use App\Models\RewardRule;
use App\Models\Setting;
use Illuminate\Support\Facades\Schema;

final class EngagementMessageService
{
    /** @return array<string, mixed>|null */
    public function publicMessage(string $systemKey, array $variables = []): ?array
    {
        if (!Schema::hasTable('admin_notifications') || !Schema::hasColumn('admin_notifications', 'system_key')) {
            return null;
        }
        $message = AdminNotification::query()
            ->available()
            ->where('system_key', $systemKey)
            ->first();

        if (!$message) {
            return null;
        }

        $coins = $variables['coins'] ?? $this->welcomeCoins();
        $variables['coins'] = max(0, (int) $coins);

        return [
            'id' => (string) $message->id,
            'key' => (string) $message->system_key,
            'surface' => (string) $message->surface,
            'title_ar' => $this->render((string) $message->title_ar, $variables, true),
            'title_en' => $this->render((string) $message->title_en, $variables, false),
            'description_ar' => $this->render((string) $message->description_ar, $variables, true),
            'description_en' => $this->render((string) $message->description_en, $variables, false),
            'action_label_ar' => $this->render((string) $message->action_label_ar, $variables, true),
            'action_label_en' => $this->render((string) $message->action_label_en, $variables, false),
            'secondary_action_label_ar' => $this->render((string) $message->secondary_action_label_ar, $variables, true),
            'secondary_action_label_en' => $this->render((string) $message->secondary_action_label_en, $variables, false),
            'link' => $message->link,
            'image_url' => $message->image,
            'coins' => (int) $variables['coins'],
            'dismissible' => (bool) $message->is_dismissible,
            'cooldown_hours' => (int) $message->cooldown_hours,
            'version' => optional($message->updated_at)->toIso8601String(),
        ];
    }

    /** @return array{title_ar:string,title_en:string,message_ar:string,message_en:string} */
    public function copy(string $systemKey, array $variables, array $fallback): array
    {
        $message = $this->publicMessage($systemKey, $variables);
        if (!$message) {
            return $fallback;
        }

        return [
            'title_ar' => $message['title_ar'] ?: $fallback['title_ar'],
            'title_en' => $message['title_en'] ?: $fallback['title_en'],
            'message_ar' => $message['description_ar'] ?: $fallback['message_ar'],
            'message_en' => $message['description_en'] ?: $fallback['message_en'],
        ];
    }

    private function welcomeCoins(): int
    {
        return RewardRule::configuredAmount(
            'welcome_bonus',
            (int) (Setting::query()->value('welcome_bonus_coins')
                ?? config('social_auth.welcome_bonus_coins', 20))
        );
    }

    private function render(string $value, array $variables, bool $arabic): string
    {
        $replacements = [];
        foreach ($variables as $key => $replacement) {
            $text = (string) $replacement;
            if ($arabic && is_numeric($replacement)) {
                $text = strtr($text, [
                    '0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤',
                    '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩',
                ]);
            }
            $replacements['{' . $key . '}'] = $text;
        }

        return trim(strtr($value, $replacements));
    }
}
