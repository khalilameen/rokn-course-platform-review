<?php

declare(strict_types=1);

namespace App\Support;

use Sentry\Event;
use Sentry\EventHint;

final class SentryEventScrubber
{
    private const SENSITIVE_KEY_PARTS = [
        'authorization', 'cookie', 'password', 'token', 'secret', 'api_key',
        'signature', 'card_number', 'cvv', 'cvc',
    ];

    public static function scrub(Event $event, ?EventHint $hint = null): Event
    {
        $request = $event->getRequest();
        unset($request['data'], $request['cookies'], $request['query_string']);
        if (isset($request['url'])) {
            $request['url'] = strtok((string) $request['url'], '?#') ?: '';
        }
        if (isset($request['headers']) && is_array($request['headers'])) {
            $request['headers'] = self::scrubMap($request['headers']);
        }
        $event->setRequest($request);
        $event->setExtra(self::scrubMap($event->getExtra()));
        $event->setTags(self::scrubMap($event->getTags()));
        $event->setUser(null);

        return $event;
    }

    /** @param array<mixed> $values @return array<mixed> */
    private static function scrubMap(array $values): array
    {
        foreach ($values as $key => $value) {
            $normalized = strtolower(str_replace('-', '_', (string) $key));
            if (self::isSensitiveKey($normalized)) {
                $values[$key] = '[Filtered]';
                continue;
            }
            if (is_array($value)) {
                $values[$key] = self::scrubMap($value);
            }
        }

        return $values;
    }

    private static function isSensitiveKey(string $key): bool
    {
        foreach (self::SENSITIVE_KEY_PARTS as $sensitivePart) {
            if (str_contains($key, $sensitivePart)) {
                return true;
            }
        }

        return false;
    }
}
