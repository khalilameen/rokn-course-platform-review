<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Models\BunnyVideoCleanupCandidate;
use App\Services\BunnyService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Mockery;

/**
 * Feature tests covering Portfolio API endpoints:
 * listing user portfolio items, creating new entries, viewing details, and deletion.
 */
class PortfolioEndpointTest extends ApiTestCase
{
    public function test_can_list_portfolio_items(): void
    {
        $response = $this->actingAs($this->user, 'api')->getJson('/api/v1/portfolio');
        $this->assertNotEquals(404, $response->status());
    }

    public function test_can_create_portfolio_item(): void
    {
        $response = $this->actingAs($this->user, 'api')->postJson('/api/v1/portfolio', [
            'title' => 'My Portfolio Item',
            'description' => 'Description here',
            'file_type' => 'text',
            // Legacy clients may still send this field. The share-page model
            // no longer asks for a separate publication decision.
            'is_public' => false,
        ]);
        $response->assertOk()->assertJsonPath('data.is_public', true);
        $this->assertDatabaseHas('portfolio_items', [
            'user_id' => $this->user->id,
            'title' => 'My Portfolio Item',
            'is_public' => true,
        ]);
    }

    public function test_can_view_portfolio_item(): void
    {
        $response = $this->actingAs($this->user, 'api')->getJson('/api/v1/portfolio/1');
        $this->assertNotEquals(404, $response->status());
    }

    public function test_can_delete_portfolio_item(): void
    {
        $response = $this->actingAs($this->user, 'api')->deleteJson('/api/v1/portfolio/1');
        $this->assertNotEquals(404, $response->status());
    }

    public function test_failed_media_batch_leaves_no_empty_item_and_cleans_prior_uploads(): void
    {
        $itemCountBefore = DB::table('portfolio_items')->count();
        $mediaCountBefore = DB::table('portfolio_media')->count();
        $bunny = Mockery::mock(BunnyService::class);
        $bunny->shouldReceive('uploadFileToStorage')
            ->once()
            ->with(
                Mockery::type(UploadedFile::class),
                'portfolio',
                Mockery::type('string'),
                'portfolio_upload_unpublished'
            )
            ->andReturnNull();
        $this->app->instance(BunnyService::class, $bunny);

        $response = $this->actingAs($this->user, 'api')->post('/api/v1/portfolio', [
            'title' => 'مشروع قابل لإعادة المحاولة',
            'files' => [UploadedFile::fake()->image('first.jpg', 10, 10)->size(2)],
            'file_types' => ['image'],
        ]);

        $response->assertStatus(503)->assertJson(['status' => 503]);
        self::assertSame($itemCountBefore, DB::table('portfolio_items')->count());
        self::assertSame($mediaCountBefore, DB::table('portfolio_media')->count());
    }

    public function test_failed_video_append_leaves_no_media_row(): void
    {
        $bunny = Mockery::mock(BunnyService::class);
        $bunny->shouldReceive('uploadVerifiedVideo')
            ->once()
            ->with(
                'Sample Portfolio Item',
                Mockery::type(UploadedFile::class),
                null,
                Mockery::type('string')
            )
            ->andReturnNull();
        $this->app->instance(BunnyService::class, $bunny);

        $this->actingAs($this->user, 'api')
            ->post('/api/v1/portfolio/1/media', [
                'file' => UploadedFile::fake()->create('sample.mp4', 10, 'video/mp4'),
                'file_type' => 'video',
            ])
            ->assertStatus(503)
            ->assertJsonPath('success', false);

        self::assertSame(0, DB::table('portfolio_media')->where('file_path', 'orphan-guid')->count());
    }

    public function test_remote_cleanup_failure_keeps_portfolio_metadata_retryable(): void
    {
        $mediaId = (int) DB::table('portfolio_media')->insertGetId([
            'portfolio_item_id' => 1,
            'file_type' => 'video',
            'file_path' => 'remote-guid',
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $bunny = Mockery::mock(BunnyService::class);
        $bunny->shouldReceive('queueVideoCleanup')
            ->once()
            ->with('remote-guid', null, 'portfolio_media_deleted', 1, false)
            ->andReturn(new BunnyVideoCleanupCandidate(['video_guid' => 'remote-guid']));
        $this->app->instance(BunnyService::class, $bunny);

        $this->actingAs($this->user, 'api')
            ->deleteJson("/api/v1/portfolio/1/media/{$mediaId}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('portfolio_media', ['id' => $mediaId]);
    }

    public function test_portfolio_contract_never_exposes_private_storage_identifiers(): void
    {
        DB::table('portfolio_media')->insert([
            'portfolio_item_id' => 1,
            'file_type' => 'image',
            'file_path' => 'portfolio/private-object.jpg',
            'thumbnail_path' => 'portfolio/private-thumbnail.jpg',
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $bunny = Mockery::mock(BunnyService::class);
        $bunny->shouldReceive('generateBunnySignedUrl')
            ->once()
            ->with('portfolio/private-object.jpg', 300)
            ->andReturn('https://cdn.example/signed-image');
        $this->app->instance(BunnyService::class, $bunny);

        $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/portfolio')
            ->assertOk()
            ->assertJsonPath('data.0.media.0.image_url', 'https://cdn.example/signed-image')
            ->assertJsonMissingPath('data.0.media.0.file_path')
            ->assertJsonMissingPath('data.0.media.0.thumbnail_path');
    }
}
