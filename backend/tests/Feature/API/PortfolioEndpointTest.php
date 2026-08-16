<?php

declare(strict_types=1);

namespace Tests\Feature\API;

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
            'file_type' => 'text'
        ]);
        $this->assertNotEquals(404, $response->status());
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
            ->twice()
            ->with(Mockery::type(UploadedFile::class), 'portfolio')
            ->andReturn('portfolio/first.jpg', null);
        $bunny->shouldReceive('deleteFileFromStorage')
            ->once()
            ->with('portfolio/first.jpg')
            ->andReturnTrue();
        $this->app->instance(BunnyService::class, $bunny);

        $response = $this->actingAs($this->user, 'api')->post('/api/v1/portfolio', [
            'title' => 'مشروع قابل لإعادة المحاولة',
            'files' => [
                UploadedFile::fake()->image('first.jpg'),
                UploadedFile::fake()->image('second.jpg'),
            ],
            'file_types' => ['image', 'image'],
        ]);

        $response->assertStatus(503)->assertJson(['status' => false]);
        self::assertSame($itemCountBefore, DB::table('portfolio_items')->count());
        self::assertSame($mediaCountBefore, DB::table('portfolio_media')->count());
    }

    public function test_failed_video_append_records_a_reviewed_cleanup_candidate(): void
    {
        $bunny = Mockery::mock(BunnyService::class);
        $bunny->shouldReceive('createVideo')
            ->once()
            ->andReturn(['guid' => 'orphan-guid', 'title' => 'Portfolio Video']);
        $bunny->shouldReceive('uploadVideo')
            ->once()
            ->with('orphan-guid', Mockery::type(UploadedFile::class))
            ->andReturnFalse();
        $bunny->shouldReceive('queueVideoCleanup')
            ->once()
            ->with('orphan-guid', null, 'portfolio_upload_failed', 24)
            ->andReturnNull();
        $this->app->instance(BunnyService::class, $bunny);

        $this->actingAs($this->user, 'api')
            ->post('/api/v1/portfolio/1/media', [
                'file' => UploadedFile::fake()->create('sample.mp4', 10, 'video/mp4'),
                'file_type' => 'video',
            ])
            ->assertStatus(500)
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
        $bunny->shouldReceive('deleteVideo')->once()->with('remote-guid')->andReturnFalse();
        $this->app->instance(BunnyService::class, $bunny);

        $this->actingAs($this->user, 'api')
            ->deleteJson("/api/v1/portfolio/1/media/{$mediaId}")
            ->assertStatus(503)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('portfolio_media', ['id' => $mediaId, 'file_path' => 'remote-guid']);
    }
}
