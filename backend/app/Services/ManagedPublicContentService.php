<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\About;
use App\Support\DatabaseCapabilities;

final class ManagedPublicContentService
{
    private const FIELDS = [
        'about' => ['ar' => 'about_ar', 'en' => 'about_en'],
        'privacy' => ['ar' => 'privacy_ar', 'en' => 'privacy_en'],
        'terms' => ['ar' => 'policy_ar', 'en' => 'policy_en'],
    ];

    public function body(string $page, string $locale): ?string
    {
        $field = self::FIELDS[$page][$locale === 'en' ? 'en' : 'ar'] ?? null;
        if ($field === null || !DatabaseCapabilities::hasTable('abouts')) {
            return null;
        }

        $value = trim((string) (About::query()->value($field) ?? ''));

        return $value !== '' ? $value : null;
    }
}
