<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Services\BunnyService;
use Illuminate\Support\Facades\Cache;
use Throwable;

class PortfolioMediaResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $data = [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'file_type' => $this->file_type,
            'sort_order' => $this->sort_order,
            'caption' => $this->caption,
            'width' => $this->width,
            'height' => $this->height,
            'duration_seconds' => $this->duration_seconds,
            'status' => $this->file_path
                && in_array($this->file_type, ['image', 'video'], true)
                ? 'processing'
                : 'failed',
            'video_url' => null,
            'playback_url' => null,
            'image_url' => null,
            'url_expires_at' => null,
        ];

        // A missing or temporarily unavailable asset must degrade one card,
        // not turn the learner's whole portfolio response into a 500.
        try {
            if ($this->file_type === 'video' && $this->file_path) {
                $bunnyService = app(BunnyService::class);
                $inspection = Cache::remember(
                    'portfolio:video-state:' . hash('sha256', (string) $this->file_path),
                    now()->addSeconds(45),
                    fn () => $bunnyService->inspectRemoteVideo((string) $this->file_path)
                );
                if (in_array((string) ($inspection['state'] ?? ''), [
                    'not_found',
                    'provider_guid_mismatch',
                    'provider_library_mismatch',
                ], true)) {
                    $data['status'] = 'failed';
                }
                $details = $inspection['details'] ?? null;
                if (($inspection['state'] ?? null) === 'ok' && is_array($details)) {
                    $resolutions = array_filter(array_map(
                        'trim',
                        explode(',', (string) ($details['availableResolutions'] ?? ''))
                    ));
                    $providerStatus = (int) ($details['status'] ?? -1);
                    $ready = BunnyService::providerVideoStatusIsPlayable($providerStatus)
                        || (float) ($details['encodeProgress'] ?? 0) >= 100
                        || $resolutions !== [];
                    $failed = BunnyService::providerVideoStatusIsFailure($providerStatus);
                    $data['status'] = $failed ? 'failed' : ($ready ? 'ready' : 'processing');
                    if ($ready) {
                        // Browser shares use Bunny's signed embed while native
                        // clients receive the media manifest explicitly. Keeping
                        // both contracts avoids treating an iframe document as a
                        // playable video source in ExoPlayer/AVPlayer.
                        $embed = $bunnyService->getSignedEmbedUrl($this->file_path, 300);
                        $playback = $bunnyService->getSignedPlayUrl($this->file_path, 300);
                        $data['video_url'] = $embed ? ($embed['url'] ?? null) : null;
                        $data['playback_url'] = $playback ? ($playback['url'] ?? null) : null;
                        $data['url_expires_at'] = $playback['expires_at']
                            ?? $embed['expires_at']
                            ?? null;
                        if (!$data['video_url'] && !$data['playback_url']) {
                            $data['status'] = 'failed';
                        }
                    }
                }
            }
            if ($this->file_type === 'image' && $this->file_path) {
                $bunnyService = app(BunnyService::class);
                $signedUrl = $bunnyService->generateBunnySignedUrl($this->file_path, 300);
                $data['image_url'] = $signedUrl ?: null;
                $data['url_expires_at'] = $signedUrl
                    ? now()->addSeconds(300)->toIso8601String()
                    : null;
                $data['status'] = $data['image_url'] ? 'ready' : 'failed';
            }
        } catch (Throwable $exception) {
            report($exception);
            $data['status'] = 'failed';
        }

        return $data;
    }
}
