<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ProductFeatureFlag;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ProductFeatureFlagService
{
    /** @return array<string, array{default_enabled: bool, safe_default: bool, description: string}> */
    private function definitions(): array
    {
        return collect(config('product_features.definitions', []))
            ->mapWithKeys(fn (array $definition, string $key): array => [
                $key => [
                    'default_enabled' => (bool) ($definition['default_enabled'] ?? false),
                    'safe_default' => (bool) ($definition['safe_default'] ?? false),
                    'description' => (string) ($definition['description'] ?? $key),
                ],
            ])
            ->all();
    }

    /** @return Collection<string, ProductFeatureFlag> */
    private function rows(): Collection
    {
        try {
            if (!Schema::hasTable('product_feature_flags')) {
                return collect();
            }

            return ProductFeatureFlag::query()
                ->whereIn('key', array_keys($this->definitions()))
                ->get()
                ->keyBy('key');
        } catch (Throwable) {
            return collect();
        }
    }

    public function enabled(string $key, int|string|null $subject = null): bool
    {
        $definitions = $this->definitions();
        if (!array_key_exists($key, $definitions)) {
            return false;
        }
        $row = $this->rows()->get($key);
        if (!$row) {
            return $definitions[$key]['default_enabled'];
        }
        if ($row->expires_at?->isPast()) {
            return $definitions[$key]['safe_default'];
        }
        if (!$row->enabled) {
            return false;
        }

        $rollout = max(0, min(100, (int) $row->rollout_percentage));
        if ($rollout >= 100) {
            return true;
        }
        if ($rollout <= 0 || $subject === null || trim((string) $subject) === '') {
            return false;
        }

        return $this->bucket($key, (string) $subject) < $rollout;
    }

    /** @return array{version: string, ttl_seconds: int, expires_at: string, flags: array<string, bool>, safe_defaults: array<string, bool>} */
    public function clientSnapshot(int $bucket): array
    {
        $bucket = max(0, min(99, $bucket));
        $definitions = $this->definitions();
        $rows = $this->rows();
        $flags = [];
        $safeDefaults = [];
        $versionSource = [];

        foreach ($definitions as $key => $definition) {
            $row = $rows->get($key);
            $expired = $row?->expires_at?->isPast() ?? false;
            $enabled = $row
                ? (!$expired && (bool) $row->enabled)
                : $definition['default_enabled'];
            $rollout = $row ? max(0, min(100, (int) $row->rollout_percentage)) : 100;
            $flags[$key] = $enabled && $bucket < $rollout;
            if ($expired) {
                $flags[$key] = $definition['safe_default'];
            }
            $safeDefaults[$key] = $definition['safe_default'];
            $versionSource[$key] = [
                $enabled, $rollout, $expired, $row?->updated_at?->getTimestamp() ?? 0,
            ];
        }

        $ttl = max(60, (int) config('product_features.client_ttl_seconds', 300));

        return [
            'version' => hash('sha256', json_encode($versionSource, JSON_THROW_ON_ERROR)),
            'ttl_seconds' => $ttl,
            'expires_at' => now()->addSeconds($ttl)->toIso8601String(),
            'flags' => $flags,
            'safe_defaults' => $safeDefaults,
        ];
    }

    /** @return array<string, array{enabled: bool, rollout_percentage: int, owner: ?string, reason: ?string, expires_at: ?string, safe_default: bool, description: string}> */
    public function operationalSnapshot(): array
    {
        $rows = $this->rows();
        $result = [];
        foreach ($this->definitions() as $key => $definition) {
            $row = $rows->get($key);
            $expired = $row?->expires_at?->isPast() ?? false;
            $result[$key] = [
                'enabled' => $row
                    ? (!$expired && (bool) $row->enabled)
                    : $definition['default_enabled'],
                'rollout_percentage' => $row ? (int) $row->rollout_percentage : 100,
                'owner' => $row?->owner,
                'reason' => $row?->reason,
                'expires_at' => $row?->expires_at?->toIso8601String(),
                'safe_default' => $definition['safe_default'],
                'description' => $definition['description'],
            ];
        }

        return $result;
    }

    private function bucket(string $key, string $subject): int
    {
        return (int) (hexdec(substr(hash('sha256', $key.'|'.$subject), 0, 8)) % 100);
    }
}
