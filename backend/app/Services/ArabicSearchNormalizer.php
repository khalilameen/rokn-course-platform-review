<?php

declare(strict_types=1);

namespace App\Services;

final class ArabicSearchNormalizer
{
    public function normalize(?string $value): string
    {
        $value = mb_strtolower(trim((string) $value));
        $value = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u', '', $value) ?? $value;
        $value = strtr($value, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي', 'ة' => 'ه', 'ـ' => '',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $value = preg_replace('/[^\p{Arabic}\p{L}\p{N}]+/u', ' ', $value) ?? $value;
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    public function courseTitle(?string $arabic, ?string $english): string
    {
        return $this->normalize(trim((string) $arabic . ' ' . (string) $english));
    }

    public function courseTerms(
        ?string $arabicTitle,
        ?string $englishTitle,
        ?string $arabicDescription,
        ?string $englishDescription
    ): string {
        return $this->normalize(implode(' ', array_filter([
            $arabicTitle,
            $englishTitle,
            $arabicDescription,
            $englishDescription,
        ], fn ($value) => trim((string) $value) !== '')));
    }
}
