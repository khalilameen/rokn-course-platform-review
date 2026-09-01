<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

final class AdminTransactionBoundaryTest extends TestCase
{
    public function test_bulk_course_code_creation_uses_an_automatic_transaction_boundary(): void
    {
        $source = file_get_contents(
            app_path('Http/Controllers/Admin/CourseCodeController.php')
        );

        self::assertIsString($source);
        self::assertStringContainsString('DB::transaction(function () use ($request, $numberOfCodes)', $source);
        self::assertStringNotContainsString('DB::beginTransaction()', $source);
        self::assertStringNotContainsString('DB::commit()', $source);
        self::assertStringNotContainsString('DB::rollBack()', $source);
    }
}
