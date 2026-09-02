<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Resources\PortfolioMediaResource;
use App\Services\BunnyService;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

final class PortfolioMediaContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_portfolio_video_uses_the_shared_bunny_playability_contract(): void
    {
        $bunny = Mockery::mock(BunnyService::class);
        $bunny->shouldReceive('inspectRemoteVideo')->once()->andReturn([
            'state' => 'ok',
            'details' => [
                'status' => 3,
                'encodeProgress' => 0,
                'availableResolutions' => '',
            ],
            'http_status' => 200,
        ]);
        $bunny->shouldReceive('getSignedEmbedUrl')->once()->andReturn([
            'url' => 'https://video.example.test/embed/ready',
        ]);
        $bunny->shouldReceive('getSignedPlayUrl')->once()->andReturn([
            'url' => 'https://video.example.test/play/ready.m3u8',
        ]);
        $this->app->instance(BunnyService::class, $bunny);

        $payload = (new PortfolioMediaResource($this->media(81, 'ready-guid')))->resolve();

        self::assertSame('ready', $payload['status']);
        self::assertSame('https://video.example.test/embed/ready', $payload['video_url']);
        self::assertSame('https://video.example.test/play/ready.m3u8', $payload['playback_url']);
    }

    public function test_presigned_upload_started_is_processing_not_a_false_failure(): void
    {
        $bunny = Mockery::mock(BunnyService::class);
        $bunny->shouldReceive('inspectRemoteVideo')->once()->andReturn([
            'state' => 'ok',
            'details' => [
                'status' => 6,
                'encodeProgress' => 0,
                'availableResolutions' => '',
            ],
            'http_status' => 200,
        ]);
        $bunny->shouldNotReceive('getSignedEmbedUrl');
        $this->app->instance(BunnyService::class, $bunny);

        $payload = (new PortfolioMediaResource($this->media(82, 'uploading-guid')))->resolve();

        self::assertSame('processing', $payload['status']);
        self::assertNull($payload['video_url']);
    }

    public function test_provider_confirmed_missing_portfolio_video_is_not_left_processing_forever(): void
    {
        $bunny = Mockery::mock(BunnyService::class);
        $bunny->shouldReceive('inspectRemoteVideo')->once()->andReturn([
            'state' => 'not_found',
            'details' => null,
            'http_status' => 404,
        ]);
        $bunny->shouldNotReceive('getSignedEmbedUrl');
        $this->app->instance(BunnyService::class, $bunny);

        $payload = (new PortfolioMediaResource($this->media(83, 'missing-guid')))->resolve();

        self::assertSame('failed', $payload['status']);
        self::assertNull($payload['video_url']);
    }

    private function media(int $id, string $path): object
    {
        return (object) [
            'id' => $id,
            'public_id' => '99999999-9999-4999-8999-' . str_pad((string) $id, 12, '0', STR_PAD_LEFT),
            'file_type' => 'video',
            'file_path' => $path,
            'sort_order' => 0,
            'caption' => null,
            'width' => null,
            'height' => null,
            'duration_seconds' => null,
        ];
    }
}
