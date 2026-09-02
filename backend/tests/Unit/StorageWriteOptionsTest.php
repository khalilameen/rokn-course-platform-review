<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\StorageWriteOptions;
use Tests\TestCase;

final class StorageWriteOptionsTest extends TestCase
{
    public function test_object_storage_never_receives_per_object_visibility(): void
    {
        config([
            'filesystems.disks.r2-contract' => [
                'driver' => 's3',
                // Even a stale legacy value must not become an ACL header.
                'visibility' => 'public',
            ],
            'filesystems.disks.local-contract' => ['driver' => 'local'],
        ]);

        self::assertSame([], StorageWriteOptions::forDisk('r2-contract', 'private'));
        self::assertSame(
            ['visibility' => 'private'],
            StorageWriteOptions::forDisk('local-contract', 'private')
        );
    }
}
