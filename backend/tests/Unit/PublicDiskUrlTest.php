<?php

namespace Tests\Unit;

use App\Support\PublicDiskUrl;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicDiskUrlTest extends TestCase
{
    private array $originalDisk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDisk = (array) config('filesystems.disks.public', []);
        config()->set('filesystems.disks.public', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/public-url-contract'),
            'url' => 'https://media.example.test/rokn',
            'visibility' => 'public',
        ]);
        Storage::forgetDisk('public');
    }

    protected function tearDown(): void
    {
        config()->set('filesystems.disks.public', $this->originalDisk);
        Storage::forgetDisk('public');

        parent::tearDown();
    }

    public function test_it_uses_the_public_disk_url_and_normalizes_legacy_prefixes(): void
    {
        self::assertSame(
            'https://media.example.test/rokn/courses/cover.webp',
            PublicDiskUrl::from('courses/cover.webp')
        );
        self::assertSame(
            'https://media.example.test/rokn/courses/cover.webp',
            PublicDiskUrl::from('/storage/courses/cover.webp')
        );
        self::assertSame(
            'https://media.example.test/rokn/courses/cover.webp',
            PublicDiskUrl::from('storage\\courses\\cover.webp')
        );
    }

    public function test_it_preserves_complete_urls_without_touching_storage(): void
    {
        $url = 'https://external.example.test/image.webp?version=2';

        self::assertSame($url, PublicDiskUrl::from($url));
        self::assertNull(PublicDiskUrl::from('http://external.example.test/image.webp'));
        self::assertNull(PublicDiskUrl::from('//external.example.test/image.webp'));
        self::assertNull(PublicDiskUrl::from('  '));
    }

    public function test_it_reverses_only_urls_owned_by_the_public_disk(): void
    {
        self::assertSame(
            'courses/cover.webp',
            PublicDiskUrl::pathFrom('https://media.example.test/rokn/courses/cover.webp')
        );
        self::assertSame(
            'courses/cover.webp',
            PublicDiskUrl::pathFrom('/storage/courses/cover.webp')
        );
        self::assertSame(
            'courses/غلاف رئيسي.webp',
            PublicDiskUrl::pathFrom(
                'https://media.example.test/rokn/courses/%D8%BA%D9%84%D8%A7%D9%81%20%D8%B1%D8%A6%D9%8A%D8%B3%D9%8A.webp?v=2#preview'
            )
        );
        self::assertSame(
            'courses/cover.webp',
            PublicDiskUrl::pathFrom('/storage/courses/cover.webp?version=2#preview')
        );
        self::assertNull(PublicDiskUrl::pathFrom('https://external.example.test/cover.webp'));
        self::assertNull(PublicDiskUrl::pathFrom('https://media.example.test/roknx/courses/cover.webp'));
        self::assertNull(PublicDiskUrl::pathFrom('http://media.example.test/rokn/courses/cover.webp'));
        self::assertNull(PublicDiskUrl::pathFrom('https://media.example.test:444/rokn/courses/cover.webp'));
        self::assertNull(PublicDiskUrl::pathFrom('https://media.example.test/rokn/%2e%2e/private.txt'));
    }
}
