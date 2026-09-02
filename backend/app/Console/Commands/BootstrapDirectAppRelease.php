<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AppVersion;
use App\Services\AppReleasePolicyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class BootstrapDirectAppRelease extends Command
{
    protected $signature = 'app-release:bootstrap-direct
        {--version-name= : Semantic display version, for example 1.0.0}
        {--version-code= : Positive Android versionCode}
        {--download-url= : Final HTTPS APK URL on rokn.app}
        {--activate : Explicitly publish this direct release}';

    protected $description = 'Create the first direct Android release without weakening dashboard release rules';

    public function handle(AppReleasePolicyService $releasePolicy): int
    {
        if (config('app.env') !== 'production') {
            $this->error('This bootstrap command is restricted to APP_ENV=production.');

            return self::INVALID;
        }
        if (!in_array(AppReleasePolicyService::CHANNEL_DIRECT, (array) config('mobile_contract.launch_channels'), true)) {
            $this->error('The direct channel is not declared in MOBILE_RELEASE_REQUIRED_CHANNELS.');

            return self::INVALID;
        }
        if (!(bool) $this->option('activate')) {
            $this->error('Pass --activate explicitly; the command never creates an ambiguous inactive bootstrap row.');

            return self::INVALID;
        }
        if (!Schema::hasTable('app_versions') || !Schema::hasColumn('app_versions', 'distribution_channel')) {
            $this->error('Run the current forward migrations before bootstrapping a release.');

            return self::FAILURE;
        }

        $versionName = trim((string) $this->option('version-name'));
        $versionCode = filter_var(
            $this->option('version-code'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $downloadUrl = trim((string) $this->option('download-url'));
        if (preg_match('/^\d+(?:\.\d+){1,3}$/', $versionName) !== 1) {
            $this->error('The version name must contain two to four numeric components, for example 1.0.0.');

            return self::INVALID;
        }
        if ($versionCode === false) {
            $this->error('The version code must be a positive integer.');

            return self::INVALID;
        }
        if (!$releasePolicy->isAllowedDownloadUrl(AppReleasePolicyService::CHANNEL_DIRECT, $downloadUrl)) {
            $this->error('The download URL must be a direct HTTPS .apk URL on rokn.app.');

            return self::INVALID;
        }

        try {
            return DB::transaction(function () use ($versionName, $versionCode, $downloadUrl): int {
                $existing = AppVersion::query()
                    ->where('platform', 'android')
                    ->where('distribution_channel', AppReleasePolicyService::CHANNEL_DIRECT)
                    ->where('version_code', $versionCode)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    if (
                        $existing->version_name === $versionName
                        && $existing->download_url === $downloadUrl
                        && $existing->is_active
                    ) {
                        $this->info("Direct release {$versionName} ({$versionCode}) is already active.");

                        return self::SUCCESS;
                    }

                    $this->error('That direct versionCode already exists with different or inactive release facts; review it in the dashboard.');

                    return self::FAILURE;
                }

                $channelMaximum = (int) AppVersion::query()
                    ->where('platform', 'android')
                    ->where('distribution_channel', AppReleasePolicyService::CHANNEL_DIRECT)
                    ->lockForUpdate()
                    ->max('version_code');
                $platformMaximum = (int) AppVersion::query()
                    ->where('platform', 'android')
                    ->lockForUpdate()
                    ->max('version_code');
                if (($channelMaximum > 0 && $versionCode <= $channelMaximum) || $versionCode < $platformMaximum) {
                    $this->error('The versionCode would move the direct channel or Android platform backwards.');

                    return self::FAILURE;
                }

                $otherNames = AppVersion::query()
                    ->where('platform', 'android')
                    ->where('version_code', $versionCode)
                    ->lockForUpdate()
                    ->pluck('version_name')
                    ->map(static fn ($name): string => (string) $name)
                    ->unique();
                if ($otherNames->isNotEmpty() && !$otherNames->contains($versionName)) {
                    $this->error('The same Android versionCode already has a different version name.');

                    return self::FAILURE;
                }

                AppVersion::query()->create([
                    'platform' => 'android',
                    'distribution_channel' => AppReleasePolicyService::CHANNEL_DIRECT,
                    'version_name' => $versionName,
                    'version_code' => $versionCode,
                    'build_number' => null,
                    'is_force_update' => false,
                    'is_active' => true,
                    'download_url' => $downloadUrl,
                ]);

                $this->info("Direct release {$versionName} ({$versionCode}) is active.");

                return self::SUCCESS;
            }, 3);
        } catch (Throwable $exception) {
            report($exception);
            $this->error('The direct release was not created; no existing release was changed.');

            return self::FAILURE;
        }
    }
}
