<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ContinuousIntegrationLayoutTest extends TestCase
{
    public function test_backend_workflow_is_discoverable_from_the_monorepo_root(): void
    {
        $backend = dirname(__DIR__, 2);
        $workflow = dirname($backend).'/.github/workflows/backend-ci.yml';

        self::assertFileExists($workflow);
        self::assertFileDoesNotExist($backend.'/.github/workflows/backend-ci.yml');

        $contents = (string) file_get_contents($workflow);
        self::assertStringContainsString('working-directory: backend', $contents);
        self::assertStringContainsString('cache-dependency-path: backend/package-lock.json', $contents);
        self::assertMatchesRegularExpression('/-[ ]+["\']backend\/\*\*["\']/', $contents);
        self::assertStringContainsString('php-version: "8.4.24"', $contents);
        self::assertStringContainsString('test "$(php -r \'echo PHP_VERSION;\')" = "8.4.24"', $contents);
        self::assertStringContainsString('tools: composer:2.10.3', $contents);
        self::assertStringContainsString('php scripts/verify-repository-secrets.php --history', $contents);
        self::assertStringContainsString('php artisan test', $contents);

        $composer = json_decode(
            (string) file_get_contents($backend.'/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame('^8.4.1', $composer['require']['php'] ?? null);
        self::assertSame('8.4.24', $composer['config']['platform']['php'] ?? null);
    }
}
