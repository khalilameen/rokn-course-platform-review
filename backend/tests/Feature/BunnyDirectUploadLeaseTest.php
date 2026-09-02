<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BunnyDirectUpload;
use App\Models\BunnyVideoCleanupCandidate;
use App\Models\Course;
use App\Models\User;
use App\Services\BunnyDirectUploadService;
use App\Services\BunnyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

final class BunnyDirectUploadLeaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fresh_allocation_cannot_be_taken_over(): void
    {
        [$admin, $course] = $this->authoringContext();
        $key = '96e07193-d6a9-4b62-9976-b652b4e4f8a7';
        $this->allocation($admin, $course, $key, now());

        $bunny = Mockery::mock(BunnyService::class);
        $bunny->shouldNotReceive('createVideo');

        $this->expectException(ValidationException::class);
        (new BunnyDirectUploadService($bunny))->issue(
            $course,
            $admin,
            'الدرس الأول',
            1024,
            'video/mp4',
            'lesson.mp4',
            $key
        );
    }

    public function test_a_stale_allocation_is_reclaimed_and_the_remote_guid_is_persisted_before_cleanup(): void
    {
        [$admin, $course] = $this->authoringContext();
        $key = '96e07193-d6a9-4b62-9976-b652b4e4f8a7';
        $session = $this->allocation($admin, $course, $key, now()->subMinutes(5));
        $guid = '12345678-1234-4234-8234-123456789abc';

        Crypt::shouldReceive('encryptString')->once()->andReturn('signed-claim');
        $bunny = Mockery::mock(BunnyService::class);
        $bunny->shouldReceive('findVideoGuidsByTitleMarker')
            ->once()
            ->with('[rokn:' . $key . ']')
            ->andReturn([]);
        $bunny->shouldReceive('createVideo')->once()->andReturn([
            'guid' => $guid,
            'title' => 'الدرس الأول',
        ]);
        $bunny->shouldReceive('queueVideoCleanup')->once()->andReturnUsing(
            function (string $videoId) use ($guid): BunnyVideoCleanupCandidate {
                self::assertSame($guid, $videoId);
                self::assertSame(
                    $guid,
                    BunnyDirectUpload::query()->firstOrFail()->video_guid,
                    'The recoverable remote identifier must be durable before the next fallible operation.'
                );

                return BunnyVideoCleanupCandidate::query()->create([
                    'video_guid' => $guid,
                    'reason' => 'direct_upload_pending',
                    'eligible_after' => now()->addDay(),
                    'requires_review' => false,
                ]);
            }
        );
        $bunny->shouldReceive('directUploadAuthorization')->once()->with($guid)->andReturn([
            'authorizationSignature' => 'signature',
            'authorizationExpire' => now()->addMinutes(30)->timestamp,
            'videoLibraryId' => 123,
        ]);

        $result = (new BunnyDirectUploadService($bunny))->issue(
            $course,
            $admin,
            'الدرس الأول',
            1024,
            'video/mp4',
            'lesson.mp4',
            $key
        );

        self::assertSame($guid, $result['video_id']);
        self::assertSame('signed-claim', $result['claim']);
        self::assertSame($session->id, BunnyDirectUpload::query()->sole()->id);
        self::assertSame('pending', BunnyDirectUpload::query()->sole()->status);
        self::assertNull(BunnyDirectUpload::query()->sole()->allocation_token);
    }

    public function test_a_remote_guid_left_on_a_stale_allocation_is_queued_for_cleanup(): void
    {
        [$admin, $course] = $this->authoringContext();
        $key = '96e07193-d6a9-4b62-9976-b652b4e4f8a7';
        $oldGuid = '12345678-1234-4234-8234-123456789abe';
        $session = $this->allocation($admin, $course, $key, now()->subMinutes(5));
        DB::table('bunny_direct_uploads')->where('id', $session->id)->update([
            'video_guid' => $oldGuid,
            'updated_at' => now()->subMinutes(5),
        ]);

        $bunny = Mockery::mock(BunnyService::class);
        $bunny->shouldReceive('queueVideoCleanup')
            ->once()
            ->with($oldGuid, null, 'direct_upload_stale_allocation', 1)
            ->andReturnUsing(fn (): BunnyVideoCleanupCandidate => BunnyVideoCleanupCandidate::query()->create([
                'video_guid' => $oldGuid,
                'reason' => 'direct_upload_stale_allocation',
                'eligible_after' => now()->addHour(),
                'requires_review' => true,
            ]));
        $bunny->shouldReceive('createVideo')->once()->andReturnNull();

        try {
            (new BunnyDirectUploadService($bunny))->issue(
                $course,
                $admin,
                'الدرس الأول',
                1024,
                'video/mp4',
                'lesson.mp4',
                $key
            );
            self::fail('The replacement allocation should report the simulated provider failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('تعذر تجهيز مساحة رفع الفيديو', $exception->getMessage());
        }

        $candidate = BunnyVideoCleanupCandidate::query()->where('video_guid', $oldGuid)->firstOrFail();
        self::assertFalse($candidate->requires_review);
        self::assertNotNull($candidate->reviewed_at);
        self::assertSame($admin->id, $candidate->reviewed_by);
        self::assertSame('failed', BunnyDirectUpload::query()->findOrFail($session->id)->status);
    }

    /** @return array{User, Course} */
    private function authoringContext(): array
    {
        $admin = new User([
            'name' => 'Moderator',
            'email' => 'moderator@example.test',
            'password' => 'unused',
        ]);
        $admin->forceFill(['role' => 'moderator', 'active' => true])->save();

        $course = new Course([
            'name_ar' => 'كورس اختباري',
        ]);
        $course->forceFill([
            'tenant_id' => 1,
            'course_type' => 'online',
            'is_coming_soon' => true,
            'is_catalog_visible' => false,
        ])->save();

        return [$admin, $course];
    }

    private function allocation(User $admin, Course $course, string $key, \DateTimeInterface $updatedAt): BunnyDirectUpload
    {
        $hash = hash('sha256', json_encode([
            'course' => (int) $course->id,
            'section' => null,
            'title' => 'الدرس الأول',
            'size' => 1024,
            'mime' => 'video/mp4',
            'original_name' => 'lesson.mp4',
        ], JSON_THROW_ON_ERROR));
        $session = BunnyDirectUpload::query()->create([
            'user_id' => $admin->id,
            'course_id' => $course->id,
            'idempotency_key' => $key,
            'request_hash' => $hash,
            'allocation_token' => '12345678-1234-4234-8234-123456789abd',
            'status' => 'allocating',
            'expires_at' => now()->addDay(),
        ]);
        DB::table('bunny_direct_uploads')->where('id', $session->id)->update([
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ]);

        return $session->fresh();
    }
}
