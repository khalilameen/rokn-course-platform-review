<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProductionPreflight extends Command
{
    protected $signature = 'rokn:preflight
        {--connectivity : Verify the configured database and shared cache at runtime}
        {--configuration-only : Skip post-migration legacy-data release gates}';

    protected $description = 'Fail a Rokn production deployment when required configuration is missing or unsafe';

    public function handle(): int
    {
        $failures = $this->configurationFailures();

        if (!(bool) $this->option('configuration-only')) {
            $failures = [
                ...$failures,
                ...$this->legacyPublicAssetFailures(),
                ...$this->publishedVideoFailures(),
                ...$this->financialProvenanceFailures(),
            ];
        }

        if ((bool) $this->option('connectivity')) {
            $failures = [...$failures, ...$this->connectivityFailures()];
        }

        if ($failures !== []) {
            $this->error('Rokn production preflight failed:');
            foreach ($failures as $failure) {
                $this->line(' - ' . $failure);
            }

            return self::FAILURE;
        }

        $this->info('Rokn production preflight passed.');

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function configurationFailures(): array
    {
        $failures = [];
        $require = static function (bool $condition, string $message) use (&$failures): void {
            if (!$condition) {
                $failures[] = $message;
            }
        };

        $appUrl = trim((string) config('app.url'));
        $appHost = strtolower((string) parse_url($appUrl, PHP_URL_HOST));
        $appDomain = strtolower(trim((string) config('app.app_domain')));
        $appKey = trim((string) config('app.key'));
        $redisHost = strtolower(trim((string) config('database.redis.default.host')));
        $firebaseCredentials = trim((string) config('firebase.credentials.file'));
        $firebaseCredentialsBase64 = trim((string) config('firebase.credentials.base64'));
        $decodedFirebaseCredentials = $firebaseCredentialsBase64 !== ''
            ? base64_decode($firebaseCredentialsBase64, true)
            : false;
        $firebaseCredentialsData = is_string($decodedFirebaseCredentials)
            ? json_decode($decodedFirebaseCredentials, true)
            : null;
        $hasInjectedFirebaseCredentials = is_array($firebaseCredentialsData)
            && filled($firebaseCredentialsData['project_id'] ?? null)
            && filled($firebaseCredentialsData['client_email'] ?? null)
            && filled($firebaseCredentialsData['private_key'] ?? null);
        $trustedProxies = array_values(array_filter((array) config('trusted_proxies.proxies', [])));
        $coursePdfDisk = trim((string) config('course_pdfs.disk'));
        $coursePdfDiskConfig = $coursePdfDisk !== '' ? config("filesystems.disks.{$coursePdfDisk}") : null;
        $coursePdfDriver = is_array($coursePdfDiskConfig) ? strtolower((string) ($coursePdfDiskConfig['driver'] ?? '')) : '';
        $androidPackage = trim((string) config('app_links.android_package'));
        $androidFingerprints = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => strtoupper(trim((string) $value)),
            (array) config('app_links.android_sha256_fingerprints', [])
        ))));
        $appleAppIds = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            (array) config('app_links.apple_app_ids', [])
        ))));
        $trustedHosts = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => strtolower(trim((string) $value)),
            (array) config('trusted_hosts.hosts', [])
        ))));
        $socialPublicApiUrl = trim((string) config('social_auth.public_api_url'));
        $socialPublicApiValid = $this->validSocialPublicApiUrl($socialPublicApiUrl);
        $socialPublicApiHost = strtolower((string) parse_url($socialPublicApiUrl, PHP_URL_HOST));
        $socialReturnUrls = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            (array) config('social_auth.return_urls', [])
        ))));
        $facebookGraphVersion = trim((string) config('services.facebook.graph_version'));

        $require(config('app.env') === 'production', 'APP_ENV must be production.');
        $require(config('app.debug') === false, 'APP_DEBUG must be false.');
        $require($appKey !== '' && !str_contains($appKey, 'AAAAAAA'), 'APP_KEY must be a real, non-placeholder key.');
        $require(
            str_starts_with($appUrl, 'https://')
                && $appHost !== ''
                && !in_array($appHost, ['localhost', 'example.com', 'api.example.com'], true),
            'APP_URL must be the real public HTTPS API origin.'
        );
        $require(
            $appDomain !== '' && !in_array($appDomain, ['localhost', 'example.com'], true),
            'APP_DOMAIN must be the real public application domain.'
        );
        $require(config('app.timezone') === 'Africa/Cairo', 'APP_TIMEZONE must be Africa/Cairo.');
        $require(
            $trustedHosts !== []
                && collect($trustedHosts)->every(fn (string $host): bool => $this->validPublicHost($host))
                && in_array($appHost, $trustedHosts, true),
            'APP_TRUSTED_HOSTS must contain explicit non-local public hosts, including the APP_URL host.'
        );
        $require(
            $this->validAndroidPackage($androidPackage),
            'APP_LINK_ANDROID_PACKAGE must be the real Android application ID.'
        );
        $require(
            $androidFingerprints !== []
                && collect($androidFingerprints)->every(fn (string $value): bool => $this->validAndroidFingerprint($value)),
            'APP_LINK_ANDROID_SHA256_FINGERPRINTS must contain valid colon-separated SHA-256 signing fingerprints.'
        );
        $require(
            $appleAppIds !== []
                && collect($appleAppIds)->every(fn (string $value): bool => $this->validAppleAppId($value)),
            'APP_LINK_APPLE_APP_IDS must contain valid Team-ID and bundle-ID pairs.'
        );
        $require(
            $socialPublicApiValid,
            'SOCIAL_AUTH_PUBLIC_API_URL must be the real public HTTPS API prefix ending in /api/v1.'
        );
        $require(
            !$socialPublicApiValid || in_array($socialPublicApiHost, $trustedHosts, true),
            'APP_TRUSTED_HOSTS must include the SOCIAL_AUTH_PUBLIC_API_URL host.'
        );
        $require(
            config('social_auth.allow_legacy_pkce') === false,
            'SOCIAL_AUTH_ALLOW_LEGACY_PKCE must be false in production.'
        );
        $require(
            $socialReturnUrls !== []
                && collect($socialReturnUrls)->every(fn (string $url): bool => $this->validSocialReturnUrl($url)),
            'SOCIAL_AUTH_RETURN_URLS must contain only the explicit rokn://auth callback.'
        );

        $require(in_array(config('database.default'), ['mysql', 'pgsql'], true), 'Production DB_CONNECTION must be mysql or pgsql.');
        $require(config('cache.default') === 'redis', 'CACHE_DRIVER must be redis.');
        $require(config('queue.default') === 'redis', 'QUEUE_CONNECTION must be redis.');
        $require(config('session.driver') === 'redis', 'SESSION_DRIVER must be redis.');
        $require(config('session.secure') === true, 'SESSION_SECURE_COOKIE must be true.');
        $require(
            config('multiple-tokens-auth.allow_legacy_transports') === false,
            'API_TOKEN_ALLOW_LEGACY_TRANSPORTS must be false; production accepts Bearer tokens only.'
        );
        $require(
            $trustedProxies !== [] && collect($trustedProxies)->every(fn ($proxy) => $this->validTrustedProxy((string) $proxy)),
            'TRUSTED_PROXIES must contain explicit edge IPs/CIDRs and must not trust the whole internet.'
        );
        $require(
            $redisHost !== '' && !in_array($redisHost, ['127.0.0.1', 'localhost'], true),
            'REDIS_HOST must point to the shared production Redis service.'
        );

        $require($this->configured('bunny.stream_api_key'), 'BUNNY_STREAM_API_KEY is required.');
        $require($this->configured('bunny.library_id'), 'BUNNY_STREAM_LIBRARY_ID is required.');
        $require($this->configured('bunny.cdn_hostname'), 'BUNNY_CDN_HOSTNAME is required.');
        $require($this->configured('bunny.token_auth_key'), 'BUNNY_TOKEN_AUTH_KEY is required for signed playback.');

        $require(config('kashier.mode') === 'live', 'KASHIER_MODE must be live.');
        $require($this->configured('kashier.live.api_key'), 'KASHIER_LIVE_API_KEY is required.');
        $require($this->configured('kashier.live.mid'), 'KASHIER_LIVE_MID is required.');

        $require($this->configured('services.facebook.client_id'), 'FACEBOOK_CLIENT_ID is required.');
        $require($this->configured('services.facebook.client_secret'), 'FACEBOOK_CLIENT_SECRET is required.');
        $require(
            preg_match('/\Av\d+\.\d+\z/', $facebookGraphVersion) === 1 && $facebookGraphVersion !== 'v19.0',
            'FACEBOOK_GRAPH_VERSION must be explicitly set to a supported vN.N version and must not use retired v19.0.'
        );
        $require($this->configured('services.google.client_id'), 'GOOGLE_CLIENT_ID is required.');
        $require($this->configured('services.google.client_secret'), 'GOOGLE_CLIENT_SECRET is required.');
        $require($this->configured('services.tiktok.client_key'), 'TIKTOK_CLIENT_KEY is required.');
        $require($this->configured('services.tiktok.client_secret'), 'TIKTOK_CLIENT_SECRET is required.');

        $require($this->configured('openrouter.api_key'), 'OPENROUTER_API_KEY is required while Rokn AI is enabled.');
        $require($this->configured('openrouter.default_model'), 'OPENROUTER_DEFAULT_MODEL is required while Rokn AI is enabled.');
        $require(
            in_array(
                (string) config('openrouter.default_model'),
                array_values(array_filter((array) config('openrouter.allowed_models', []))),
                true
            ),
            'OPENROUTER_DEFAULT_MODEL must be present in OPENROUTER_ALLOWED_MODELS.'
        );
        $require((int) config('openrouter.global_daily_request_limit') > 0, 'OpenRouter daily request budget must be positive.');
        $require((int) config('openrouter.global_daily_token_budget') > 0, 'OpenRouter daily token budget must be positive.');
        $require((int) config('openrouter.global_monthly_token_budget') > 0, 'OpenRouter monthly token budget must be positive.');
        $require(
            config('course_plans.economics_configured') === true,
            'ROKN_NET_USD_PER_PAID_COIN and ROKN_AI_COST_SAFETY_MULTIPLIER must be explicitly configured.'
        );
        $require(
            (float) config('course_plans.net_usd_per_paid_coin') > 0,
            'ROKN_NET_USD_PER_PAID_COIN must be a positive finance-calibrated value.'
        );
        $require(
            (float) config('course_plans.ai_cost_safety_multiplier') >= 1,
            'ROKN_AI_COST_SAFETY_MULTIPLIER must be at least 1.'
        );
        $require(
            (int) config('course_plans.ai_reservation_ttl_seconds')
                >= max(60, (int) config('openrouter.timeout_seconds') + 15),
            'ROKN_AI_RESERVATION_TTL_SECONDS must exceed the provider timeout with recovery headroom.'
        );

        $require(
            !in_array(config('projects.submission_disk'), ['local', 'public', null, ''], true),
            'PROJECT_SUBMISSION_DISK must be a private shared disk.'
        );
        $require(
            !in_array(config('certificate.disk'), ['local', 'public', null, ''], true),
            'CERTIFICATE_DISK must be a shared disk.'
        );
        $require(
            !in_array(config('course_attachments.disk'), ['local', 'public', null, ''], true),
            'COURSE_ATTACHMENT_DISK must be the private shared module-attachment disk.'
        );
        $require(
            !in_array($coursePdfDisk, ['', 'local', 'public'], true) && is_array($coursePdfDiskConfig),
            'COURSE_PDF_DISK must name a configured private shared disk.'
        );
        $require(
            !is_array($coursePdfDiskConfig) || ($coursePdfDiskConfig['visibility'] ?? null) !== 'public',
            'COURSE_PDF_DISK must not have public visibility.'
        );
        $require(
            !is_array($coursePdfDiskConfig)
                || $coursePdfDriver !== 'local'
                || config('course_pdfs.shared_storage') === true,
            'A local-driver COURSE_PDF_DISK requires COURSE_PDF_SHARED_STORAGE=true and a shared mounted path.'
        );

        $require(
            filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) !== false,
            'MAIL_FROM_ADDRESS must be a real support email address.'
        );
        $require(
            $hasInjectedFirebaseCredentials
                || ($firebaseCredentials !== '' && is_readable($firebaseCredentials)),
            'Firebase credentials must be a valid FIREBASE_CREDENTIALS_BASE64 secret or a readable FIREBASE_CREDENTIALS file.'
        );

        return $failures;
    }

    /** @return list<string> */
    private function legacyPublicAssetFailures(): array
    {
        $failures = [];

        try {
            if (Schema::hasTable('attachments')) {
                if (!Schema::hasColumn('attachments', 'storage_disk')) {
                    $legacyCount = DB::table('attachments')
                        ->where('attachable_type', \App\Models\CourseModule::class)
                        ->count();
                    if ($legacyCount > 0) {
                        $failures[] = "{$legacyCount} module attachment(s) predate private storage. Run migrations, then attachments:privatize --execute --delete-public.";
                    }
                } else {
                    $legacyCount = DB::table('attachments')
                        ->where('attachable_type', \App\Models\CourseModule::class)
                        ->where(function ($query): void {
                            $query->whereNull('storage_disk')->orWhere('storage_disk', 'public');
                        })
                        ->count();
                    if ($legacyCount > 0) {
                        $failures[] = "{$legacyCount} public module attachment(s) remain. Run attachments:privatize --execute --delete-public and audit again.";
                    }
                }
            }

            if (Schema::hasTable('users') && Schema::hasColumn('users', 'profile_image')) {
                $svgCount = DB::table('users')
                    ->whereNotNull('profile_image')
                    ->whereRaw('LOWER(profile_image) LIKE ?', ['%.svg'])
                    ->count();
                if ($svgCount > 0) {
                    $failures[] = "{$svgCount} public SVG profile image(s) remain. Run security:quarantine-profile-svg --execute and audit again.";
                }
            }

            if (Schema::hasTable('course_pdfs')) {
                $targetDisk = trim((string) config('course_pdfs.disk'));
                if (!Schema::hasColumn('course_pdfs', 'storage_disk')) {
                    $legacyCount = DB::table('course_pdfs')->count();
                } else {
                    $legacyCount = DB::table('course_pdfs')
                        ->where(function ($query) use ($targetDisk): void {
                            $query->whereNull('storage_disk')->orWhere('storage_disk', '<>', $targetDisk);
                        })
                        ->count();
                }
                if ($legacyCount > 0) {
                    $failures[] = "{$legacyCount} course PDF(s) are not on the configured shared disk. Run course-pdfs:migrate-storage --execute, verify, then repeat with --delete-source.";
                }
            }

            foreach ([
                ['table' => 'portfolio_media', 'column' => 'file_path', 'label' => 'portfolio image'],
                ['table' => 'lessons', 'column' => 'thumbnail_path', 'label' => 'lesson thumbnail'],
            ] as $asset) {
                if (!Schema::hasTable($asset['table']) || !Schema::hasColumn($asset['table'], $asset['column'])) {
                    continue;
                }

                $duplicates = DB::table($asset['table'])
                    ->select($asset['column'])
                    ->whereNotNull($asset['column'])
                    ->where($asset['column'], '<>', '')
                    ->groupBy($asset['column'])
                    ->havingRaw('COUNT(*) > 1')
                    ->get()
                    ->count();
                if ($duplicates > 0) {
                    $failures[] = "{$duplicates} duplicate Bunny {$asset['label']} object key(s) remain. Re-upload each affected record with a unique key before release.";
                }
            }
        } catch (Throwable) {
            $failures[] = 'Legacy public-asset audit could not complete; production release is blocked.';
        }

        return $failures;
    }

    /** @return list<string> */
    private function connectivityFailures(): array
    {
        $failures = [];

        try {
            DB::select('SELECT 1');
        } catch (Throwable) {
            $failures[] = 'The configured production database is not reachable.';
        }

        $pdfDiskName = trim((string) config('course_pdfs.disk'));
        if ($pdfDiskName !== '' && is_array(config("filesystems.disks.{$pdfDiskName}"))) {
            $probe = 'preflight/' . bin2hex(random_bytes(8)) . '.txt';
            try {
                $disk = Storage::disk($pdfDiskName);
                $disk->put($probe, 'ok', ['visibility' => 'private']);
                if (!$disk->exists($probe) || $disk->get($probe) !== 'ok') {
                    $failures[] = 'The configured shared course-PDF disk did not return its preflight object.';
                }
            } catch (Throwable) {
                $failures[] = 'The configured shared course-PDF disk is not reachable.';
            } finally {
                try {
                    Storage::disk($pdfDiskName)->delete($probe);
                } catch (Throwable) {
                    // The reachability failure above is the useful signal.
                }
            }
        }

        $key = 'preflight:' . bin2hex(random_bytes(8));
        try {
            Cache::put($key, 'ok', 15);
            if (Cache::get($key) !== 'ok') {
                $failures[] = 'The shared cache did not return its preflight value.';
            }
        } catch (Throwable) {
            $failures[] = 'The configured shared cache is not reachable.';
        } finally {
            try {
                Cache::forget($key);
            } catch (Throwable) {
                // The reachability failure above is the useful operator signal.
            }
        }

        return $failures;
    }

    /** @return list<string> */
    private function publishedVideoFailures(): array
    {
        if (!Schema::hasTable('courses')
            || !Schema::hasTable('course_sections')
            || !Schema::hasTable('lessons')
            || !Schema::hasColumn('courses', 'is_coming_soon')
            || !Schema::hasColumn('lessons', 'video_source_type')
            || !Schema::hasColumn('lessons', 'bunny_video_id')) {
            return ['Published-video audit cannot run until the current content schema is migrated.'];
        }

        try {
            $invalid = DB::table('course_sections as sections')
                ->join('courses', 'courses.id', '=', 'sections.course_id')
                ->join('lessons', function ($join): void {
                    $join->on('lessons.id', '=', 'sections.sectionable_id')
                        ->where('sections.sectionable_type', '=', \App\Models\Lesson::class);
                })
                ->where('courses.is_coming_soon', false)
                ->whereNull('sections.deleted_at')
                ->where(function ($query): void {
                    $query->whereNull('lessons.bunny_video_id')
                        ->orWhere('lessons.bunny_video_id', '')
                        ->orWhere('lessons.video_source_type', '<>', 'bunny')
                        ->orWhereNull('lessons.video_source_type');
                })
                ->count();

            return $invalid > 0
                ? ["{$invalid} published lesson(s) are not backed by a Bunny Stream GUID; migrate them or return their courses to coming-soon before release."]
                : [];
        } catch (Throwable) {
            return ['Published-video audit could not complete; production release is blocked.'];
        }
    }

    /** @return list<string> */
    private function financialProvenanceFailures(): array
    {
        $releaseFailures = [];
        foreach (['payment_reconciliation_checkpoints', 'payment_reconciliation_findings'] as $table) {
            if (!Schema::hasTable($table)) {
                $releaseFailures[] = implode(' ', [
                    'Payment provider reconciliation tables are missing.',
                    'Run the current forward migrations before release.',
                ]);

                break;
            }
        }

        $required = [
            'wallet_credit_lots',
            'wallet_debit_allocations',
            'financial_entitlement_holds',
        ];
        foreach ($required as $table) {
            if (!Schema::hasTable($table)) {
                return [
                    ...$releaseFailures,
                    'Financial provenance tables are missing. Run migrations and finance:backfill-provenance --apply before release.',
                ];
            }
        }
        if (
            !Schema::hasTable('orders')
            || !Schema::hasTable('wallet_transactions')
            || !Schema::hasTable('users')
        ) {
            return [
                ...$releaseFailures,
                'Financial provenance audit cannot run because core finance tables are missing.',
            ];
        }

        try {
            $failures = $releaseFailures;
            $missingLots = DB::table('orders as orders')
                ->leftJoin('wallet_credit_lots as lot', 'lot.source_order_id', '=', 'orders.id')
                ->whereNotNull('orders.package_id')
                ->where('orders.status', 'approved')
                ->where('orders.financial_status', 'settled')
                ->whereNull('lot.id')
                ->count();
            if ($missingLots > 0) {
                $failures[] = "{$missingLots} settled paid package order(s) have no immutable credit lot.";
            }

            $unreconciledReversals = DB::table('orders as orders')
                ->leftJoin('wallet_credit_lots as lot', 'lot.source_order_id', '=', 'orders.id')
                ->whereNotNull('orders.package_id')
                ->where('orders.status', 'approved')
                ->where(function ($query): void {
                    $query->whereNotNull('orders.reversed_at')
                        ->orWhereIn('orders.financial_status', [
                            'refunded',
                            'chargeback',
                            'reversed',
                            'partially_recovered',
                            'review_required',
                        ]);
                })
                ->where(function ($query): void {
                    $query->whereNull('lot.id')->orWhere('lot.status', 'active');
                })
                ->count();
            if ($unreconciledReversals > 0) {
                $failures[] = "{$unreconciledReversals} historical package reversal(s) still require explicit finance reconciliation.";
            }

            $incompleteDebits = DB::query()->fromSub(
                DB::table('wallet_transactions as wt')
                    ->leftJoin('wallet_debit_allocations as allocation', 'allocation.wallet_transaction_id', '=', 'wt.id')
                    ->where('wt.direction', 'debit')
                    ->whereIn('wt.category', ['course_purchase', 'course_chat_upgrade', 'course_full_track_upgrade'])
                    ->where('wt.paid_amount', '>', 0)
                    ->groupBy('wt.id', 'wt.paid_amount')
                    ->havingRaw('COALESCE(SUM(allocation.amount), 0) <> wt.paid_amount')
                    ->select('wt.id'),
                'incomplete_paid_debits'
            )->count();
            if ($incompleteDebits > 0) {
                $failures[] = "{$incompleteDebits} paid wallet debit(s) have incomplete FIFO source allocation.";
            }

            $incompleteOrders = DB::query()->fromSub(
                DB::table('orders as orders')
                    ->leftJoin('wallet_debit_allocations as allocation', 'allocation.course_order_id', '=', 'orders.id')
                    ->whereNotNull('orders.course_id')
                    ->where('orders.payment_method', 'wallet_coins')
                    ->where('orders.status', 'approved')
                    ->where('orders.paid_coins', '>', 0)
                    ->groupBy('orders.id', 'orders.paid_coins')
                    ->havingRaw('COALESCE(SUM(allocation.amount), 0) <> orders.paid_coins')
                    ->select('orders.id'),
                'incomplete_paid_orders'
            )->count();
            if ($incompleteOrders > 0) {
                $failures[] = "{$incompleteOrders} paid course order(s) have incomplete source allocation.";
            }

            $balanceMismatches = DB::query()->fromSub(
                DB::table('users as users')
                    ->leftJoin('wallet_credit_lots as lot', function ($join): void {
                        $join->on('lot.user_id', '=', 'users.id')
                            ->where('lot.status', '=', 'active');
                    })
                    ->groupBy('users.id', 'users.wallet_purchased_coins')
                    ->havingRaw('COALESCE(SUM(lot.remaining_amount), 0) <> users.wallet_purchased_coins')
                    ->select('users.id'),
                'paid_balance_mismatches'
            )->count();
            if ($balanceMismatches > 0) {
                $failures[] = "{$balanceMismatches} learner paid balance(s) do not match active source lots.";
            }

            if ($failures !== []) {
                $failures[] = 'Run finance:backfill-provenance --apply and repeat the dry-run audit before release.';
            }

            return $failures;
        } catch (Throwable) {
            return ['Financial provenance audit could not complete; production release is blocked.'];
        }
    }

    private function configured(string $key): bool
    {
        $value = config($key);

        return is_string($value) ? trim($value) !== '' : $value !== null;
    }

    private function validTrustedProxy(string $proxy): bool
    {
        $proxy = trim($proxy);
        if ($proxy === '' || in_array($proxy, ['*', '0.0.0.0/0', '::/0'], true)) {
            return false;
        }
        if (!str_contains($proxy, '/')) {
            return filter_var($proxy, FILTER_VALIDATE_IP) !== false;
        }
        [$network, $prefix] = array_pad(explode('/', $proxy, 2), 2, null);
        $max = filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 32
            : (filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 128 : 0);
        $minimum = $max === 32 ? 8 : 32;

        return $max > 0 && ctype_digit((string) $prefix)
            && (int) $prefix >= $minimum && (int) $prefix <= $max;
    }

    private function validAndroidPackage(string $package): bool
    {
        return preg_match('/\A[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]*)+\z/', $package) === 1;
    }

    private function validAndroidFingerprint(string $fingerprint): bool
    {
        return preg_match('/\A(?:[0-9A-F]{2}:){31}[0-9A-F]{2}\z/', $fingerprint) === 1;
    }

    private function validAppleAppId(string $appId): bool
    {
        return preg_match('/\A[A-Z0-9]{10}\.(?:[A-Za-z0-9-]+\.)+[A-Za-z0-9-]+\z/', $appId) === 1;
    }

    private function validSocialPublicApiUrl(string $url): bool
    {
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        return strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && $this->validPublicHost((string) ($parts['host'] ?? ''))
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['query'])
            && !isset($parts['fragment'])
            && rtrim((string) ($parts['path'] ?? ''), '/') === '/api/v1';
    }

    private function validSocialReturnUrl(string $url): bool
    {
        return $url === 'rokn://auth';
    }

    private function validPublicHost(string $host): bool
    {
        $host = strtolower(trim($host));
        if ($host === '' || str_contains($host, ':') || !str_contains($host, '.')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) !== false;
        }

        if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return false;
        }

        foreach (['.localhost', '.local', '.test', '.example', '.invalid'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }

        return !in_array($host, [
            'localhost',
            'example.com',
            'example.net',
            'example.org',
        ], true);
    }
}
