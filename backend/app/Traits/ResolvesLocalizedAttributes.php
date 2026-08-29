<?php

declare(strict_types=1);

namespace App\Traits;

trait ResolvesLocalizedAttributes
{
    /**
     * Resolve a localized value without treating null, whitespace or an empty
     * translation as content. Legacy columns may be supplied last as a final
     * compatibility fallback.
     */
    protected function localizedValue(string $arabic, string $english, string ...$legacy): ?string
    {
        $attributes = str_starts_with(
            strtolower((string) request()->header('Accept-Language', 'ar')),
            'en'
        ) ? [$english, $arabic, ...$legacy] : [$arabic, $english, ...$legacy];

        foreach ($attributes as $attribute) {
            $value = trim((string) ($this->attributes[$attribute] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
