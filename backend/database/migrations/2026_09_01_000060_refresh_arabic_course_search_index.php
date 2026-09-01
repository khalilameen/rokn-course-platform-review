<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (
            !Schema::hasTable('courses')
            || !Schema::hasColumn('courses', 'search_title_normalized')
            || !Schema::hasColumn('courses', 'search_terms_normalized')
        ) {
            return;
        }

        DB::table('courses')
            ->select([
                'id', 'name_ar', 'name_en', 'description_ar', 'description_en',
                'search_keywords_ar', 'search_keywords_en',
                'search_title_normalized', 'search_terms_normalized',
            ])
            ->orderBy('id')
            ->chunkById(250, function ($courses): void {
                foreach ($courses as $course) {
                    $title = implode(' ', array_filter([
                        $course->name_ar,
                        $course->name_en,
                    ], static fn ($value): bool => trim((string) $value) !== ''));
                    $terms = implode(' ', array_filter([
                        $course->name_ar,
                        $course->name_en,
                        $course->description_ar,
                        $course->description_en,
                        $course->search_keywords_ar,
                        $course->search_keywords_en,
                    ], static fn ($value): bool => trim((string) $value) !== ''));

                    $normalizedTitle = self::normalize($title);
                    $normalizedTerms = self::normalize($terms);
                    if (
                        hash_equals((string) $course->search_title_normalized, $normalizedTitle)
                        && hash_equals((string) $course->search_terms_normalized, $normalizedTerms)
                    ) {
                        continue;
                    }

                    DB::table('courses')
                        ->where('id', $course->id)
                        ->update([
                            'search_title_normalized' => $normalizedTitle,
                            'search_terms_normalized' => $normalizedTerms,
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Canonical search text is derived data. Older app versions can read
        // the refreshed values, and reversing them would be lossy.
    }

    /** Frozen copy of the v2 Arabic search contract for deterministic replay. */
    private static function normalize(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            $repaired = function_exists('iconv')
                ? iconv('UTF-8', 'UTF-8//IGNORE', $value)
                : false;
            $value = is_string($repaired) ? $repaired : '';
        }
        if (class_exists(\Normalizer::class)) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_C) ?: $value;
        }
        $value = preg_replace(
            '/[\x{00AD}\x{034F}\x{061C}\x{180E}\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2060}\x{2066}-\x{2069}\x{FEFF}]/u',
            '',
            $value
        ) ?? '';
        $value = str_replace(["\r\n", "\r", "\u{2028}", "\u{2029}"], "\n", $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        $value = preg_replace(
            '/[\x{00A0}\x{1680}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]/u',
            ' ',
            $value
        ) ?? $value;
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        if (class_exists(\Normalizer::class)) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_KC) ?: $value;
        }
        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u', '', $value) ?? $value;
        $value = strtr($value, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ٲ' => 'ا', 'ٳ' => 'ا', 'ٵ' => 'ا',
            'ى' => 'ي', 'ی' => 'ي', 'ې' => 'ي', 'ۍ' => 'ي', 'ے' => 'ي',
            'ؤ' => 'و', 'ئ' => 'ي',
            'ة' => 'ه', 'ۀ' => 'ه', 'ہ' => 'ه', 'ھ' => 'ه',
            'ک' => 'ك', 'ـ' => '',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
        $value = preg_replace('/[^\p{Arabic}\p{L}\p{N}]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
};
