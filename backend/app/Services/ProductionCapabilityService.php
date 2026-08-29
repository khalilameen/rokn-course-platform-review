<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\RecordQueueHeartbeat;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class ProductionCapabilityService
{
    /**
     * Configuration readiness is deliberately separate from live provider
     * connectivity. A public readiness probe must not call third parties or
     * restart healthy app instances merely because a provider is unavailable.
     * Queue readiness is stronger: its heartbeat proves that both scheduler
     * dispatch and a queue worker completed a real asynchronous job recently.
     *
     * @return array{
     *   ready: bool,
     *   checked_at: string,
     *   capabilities: array{
     *     bunny: array{ready: bool, stream: array, upload: array, playback: array, signing: array, assets: array},
     *     payment: array{ready: bool, reason: string},
     *     ai: array{ready: bool, reason: string},
     *     mail: array{ready: bool, reason: string},
     *     queue: array{ready: bool, reason: string, required_queues: list<string>, queues: array<string, array>}
     *   }
     * }
     */
    public function report(): array
    {
        $settings = $this->settings();
        $bunnyEnabled = (bool) ($settings?->bunny_enabled ?? false);
        $streamKey = $this->configuredValue(
            config('bunny.stream_api_key'),
            $this->settingValue($settings, 'bunny_api_key_secret'),
            $this->settingValue($settings, 'bunny_api_key')
        );
        $libraryId = $this->configuredValue(
            config('bunny.library_id'),
            $this->settingValue($settings, 'bunny_library_id')
        );
        $cdnHostname = $this->configuredValue(
            config('bunny.cdn_hostname'),
            $this->settingValue($settings, 'bunny_cdn_hostname')
        );
        $signingKey = $this->configuredValue(
            config('bunny.token_auth_key'),
            $this->settingValue($settings, 'bunny_security_key_secret')
        );
        $storageZone = trim((string) config('bunny.storage_zone'));
        $storagePassword = trim((string) config('bunny.storage_password'));
        $storageHostname = trim((string) config('bunny.storage_cdn_hostname'));
        $storageSigningKey = trim((string) config('bunny.storage_token_auth_key'));

        $streamReady = $bunnyEnabled && $streamKey !== '' && $libraryId !== '';
        $uploadReady = $streamReady
            && (int) config('bunny.connect_timeout_seconds', 0) > 0
            && (int) config('bunny.upload_timeout_seconds', 0) >= 60;
        $playbackReady = $bunnyEnabled && $this->validHostname($cdnHostname);
        $signingReady = $bunnyEnabled && $signingKey !== '';
        $assetsReady = $bunnyEnabled
            && $storageZone !== ''
            && $storagePassword !== ''
            && $this->validHostname($storageHostname)
            && $storageSigningKey !== '';

        $bunny = [
            'stream' => $this->item(
                $streamReady,
                !$bunnyEnabled
                    ? 'Bunny متوقف من الإعدادات'
                    : ($streamReady ? 'مفتاح Stream ومعرّف المكتبة موجودان' : 'مفتاح Stream أو معرّف المكتبة ناقص')
            ),
            'upload' => $this->item(
                $uploadReady,
                $uploadReady ? 'رفع Stream مضبوط بمهلة مناسبة' : 'إعداد رفع الفيديو أو المهلة ناقص'
            ),
            'playback' => $this->item(
                $playbackReady,
                $playbackReady ? 'اسم CDN صالح للتشغيل' : 'اسم CDN ناقص أو غير صالح'
            ),
            'signing' => $this->item(
                $signingReady,
                $signingReady ? 'مفتاح توقيع التشغيل موجود' : 'مفتاح توقيع التشغيل ناقص'
            ),
            'assets' => $this->item(
                $assetsReady,
                $assetsReady
                    ? 'رفع وتوقيع صور وملفات Bunny مضبوط'
                    : 'Storage Zone أو كلمة الرفع أو CDN أو مفتاح توقيع الملفات ناقص'
            ),
        ];
        $bunny['ready'] = $streamReady
            && $uploadReady
            && $playbackReady
            && $signingReady
            && $assetsReady;

        $payment = $this->paymentCapability();
        $ai = $this->aiCapability();
        $mail = $this->mailCapability();
        $queue = $this->queueCapability();

        return [
            'ready' => $bunny['ready'] && $payment['ready'] && $ai['ready'] && $mail['ready'] && $queue['ready'],
            'checked_at' => now()->toIso8601String(),
            'capabilities' => compact('bunny', 'payment', 'ai', 'mail', 'queue'),
        ];
    }

    private function paymentCapability(): array
    {
        $mode = strtolower(trim((string) config('kashier.mode')));
        $selected = is_array(config("kashier.{$mode}")) ? config("kashier.{$mode}") : [];
        $configured = in_array($mode, ['live', 'test'], true)
            && trim((string) ($selected['api_key'] ?? '')) !== ''
            && trim((string) ($selected['mid'] ?? '')) !== ''
            && filter_var($selected['base_url'] ?? null, FILTER_VALIDATE_URL) !== false;
        $productionModeReady = config('app.env') !== 'production' || $mode === 'live';
        $ready = $configured && $productionModeReady;

        return $this->item(
            $ready,
            !$configured
                ? 'بيانات بوابة Kashier المختارة ناقصة'
                : ($productionModeReady ? "Kashier مضبوط على {$mode}" : 'الإنتاج ما زال يستخدم وضع Kashier التجريبي')
        );
    }

    private function aiCapability(): array
    {
        $apiKey = trim((string) config('openrouter.api_key'));
        $model = trim((string) config('openrouter.default_model'));
        $allowed = array_values(array_filter(config('openrouter.allowed_models', [])));
        $budgetsReady = (int) config('openrouter.global_daily_request_limit') > 0
            && (int) config('openrouter.global_daily_token_budget') > 0
            && (int) config('openrouter.global_monthly_token_budget') > 0;
        $ready = $apiKey !== '' && $model !== '' && in_array($model, $allowed, true) && $budgetsReady;

        return $this->item(
            $ready,
            $ready
                ? 'المفتاح والنموذج وقائمة السماح وحدود التكلفة مضبوطة'
                : 'مفتاح OpenRouter أو النموذج المسموح أو حدود التكلفة ناقصة'
        );
    }

    private function mailCapability(): array
    {
        $mailer = strtolower(trim((string) config('mail.default')));
        $host = trim((string) config("mail.mailers.{$mailer}.host"));
        $port = (int) config("mail.mailers.{$mailer}.port");
        $username = trim((string) config("mail.mailers.{$mailer}.username"));
        $password = trim((string) config("mail.mailers.{$mailer}.password"));
        $from = trim((string) config('mail.from.address'));
        $ready = $mailer === 'smtp'
            && $this->validHostname($host)
            && $port > 0
            && $port <= 65535
            && $username !== ''
            && $password !== ''
            && filter_var($from, FILTER_VALIDATE_EMAIL) !== false;

        return $this->item(
            $ready,
            $ready
                ? 'SMTP transactional mail and sender are configured'
                : 'Transactional mail host, credentials, port, or sender is incomplete'
        );
    }

    private function queueCapability(): array
    {
        $driver = strtolower(trim((string) config('queue.default')));
        $asynchronous = !in_array($driver, ['', 'sync', 'null'], true);
        if (!$asynchronous) {
            return $this->item(false, 'QUEUE_CONNECTION متزامن ولا يناسب التشغيل الفعلي');
        }
        if (config('app.env') === 'production' && $driver !== 'redis') {
            return $this->item(false, 'الإنتاج يحتاج Redis queue لهذا الحجم');
        }

        $requiredQueues = RecordQueueHeartbeat::requiredQueues();
        if ($requiredQueues === []) {
            return [
                ...$this->item(false, 'No required queue heartbeats are configured'),
                'required_queues' => [],
                'queues' => [],
            ];
        }

        $maxAge = max(60, (int) config('operations.queue_heartbeat_max_age_seconds', 180));
        $oldestAllowed = now()->subSeconds($maxAge);
        $queueChecks = [];

        foreach ($requiredQueues as $queue) {
            try {
                $value = Cache::get(RecordQueueHeartbeat::cacheKey($queue));

                // During a rolling deployment, accept the historical key only
                // for the configured default queue. It can never satisfy a
                // heartbeat for notifications, AI feedback, or webhooks.
                if (($value === null || $value === '') && $queue === RecordQueueHeartbeat::defaultQueueName()) {
                    $value = Cache::get(RecordQueueHeartbeat::legacyCacheKey());
                }

                $heartbeat = is_string($value) && $value !== ''
                    ? CarbonImmutable::parse($value)
                    : null;
                $fresh = $heartbeat !== null && $heartbeat->greaterThanOrEqualTo($oldestAllowed);
            } catch (Throwable) {
                $heartbeat = null;
                $fresh = false;
            }

            $queueChecks[$queue] = [
                'ready' => $fresh,
                'last_heartbeat_at' => $heartbeat?->toIso8601String(),
            ];
        }

        $missing = array_keys(array_filter(
            $queueChecks,
            static fn (array $check): bool => !$check['ready']
        ));
        $fresh = $missing === [];

        return [
            ...$this->item(
                $fresh,
                $fresh
                    ? "Every required {$driver} queue executed a recent heartbeat"
                    : 'Missing or stale queue heartbeats: '.implode(', ', $missing)
            ),
            'required_queues' => $requiredQueues,
            'queues' => $queueChecks,
        ];
    }

    private function settings(): ?Setting
    {
        try {
            return Setting::query()->first();
        } catch (Throwable) {
            return null;
        }
    }

    private function settingValue(?Setting $settings, string $attribute): mixed
    {
        try {
            return $settings?->{$attribute};
        } catch (Throwable) {
            return null;
        }
    }

    private function configuredValue(mixed ...$values): string
    {
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function validHostname(string $hostname): bool
    {
        $hostname = strtolower(trim($hostname));
        if (str_starts_with($hostname, 'http://') || str_starts_with($hostname, 'https://')) {
            $hostname = (string) parse_url($hostname, PHP_URL_HOST);
        }

        return $hostname !== ''
            && filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    /** @return array{ready: bool, reason: string} */
    private function item(bool $ready, string $reason): array
    {
        return ['ready' => $ready, 'reason' => $reason];
    }
}
