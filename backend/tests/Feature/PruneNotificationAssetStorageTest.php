<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\PruneOperationalData;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\TestCase;

final class PruneNotificationAssetStorageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('student_notifications', function (Blueprint $table): void {
            $table->id();
            $table->text('image_url')->nullable();
        });
        Schema::create('notification_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->text('image_url')->nullable();
        });
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('notification_campaigns');
        Schema::dropIfExists('student_notifications');
        parent::tearDown();
    }

    public function test_cleanup_keeps_encoded_queried_references_and_deletes_only_orphans(): void
    {
        $kept = 'student-notifications/keep image.png';
        $orphan = 'student-notifications/orphan.png';
        Storage::disk('public')->put($kept, 'kept');
        Storage::disk('public')->put($orphan, 'orphan');
        touch(Storage::disk('public')->path($kept), now()->subDays(2)->timestamp);
        touch(Storage::disk('public')->path($orphan), now()->subDays(2)->timestamp);

        DB::table('student_notifications')->insert([
            'image_url' => '/storage/student-notifications/keep%20image.png?v=2#preview',
        ]);

        $method = new ReflectionMethod(PruneOperationalData::class, 'pruneNotificationAssets');
        $method->setAccessible(true);
        $deleted = $method->invoke(app(PruneOperationalData::class), 100);

        self::assertSame(1, $deleted);
        Storage::disk('public')->assertExists($kept);
        Storage::disk('public')->assertMissing($orphan);
    }
}
