<?php

declare(strict_types=1);

namespace App\Support;

/** Localize visible digits while preserving copyable machine tokens in RTL. */
final class ArabicDisplayText
{
    private const ISOLATE_START = "\u{2068}";
    private const ISOLATE_END = "\u{2069}";

    public static function format(mixed $value): string
    {
        $text = (string) ($value ?? '');
        if ($text === '') {
            return '';
        }

        $parts = preg_split(
            '~((?:https?://|www\.)[^\s<>{}\[\]()،؛!?,;]+|[\p{L}\p{N}._%+\-]+@[\p{L}\p{N}.\-]+\.[A-Za-z]{2,}|\+?\d(?:[\d\s()\-]{5,}\d)|[A-Za-z0-9._:/@+\-]*[A-Za-z][A-Za-z0-9._:/@+\-]*)~iu',
            $text,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );
        if ($parts === false) {
            return self::digits($text);
        }

        return implode('', array_map(static function (string $part): string {
            if ($part === '') {
                return '';
            }
            if (preg_match('/[A-Za-z@]|^(?:https?:\/\/|www\.)/i', $part) === 1
                || preg_match('/^\+?\d(?:[\d\s()\-]{5,}\d)$/', $part) === 1) {
                return self::ISOLATE_START.$part.self::ISOLATE_END;
            }

            return self::digits($part);
        }, $parts));
    }

    private static function digits(string $value): string
    {
        return strtr($value, [
            '0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤',
            '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩',
        ]);
    }
}
