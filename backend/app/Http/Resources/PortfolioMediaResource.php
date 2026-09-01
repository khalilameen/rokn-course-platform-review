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
            'image_url' => null,
        ];

        // A missing or temporarily unavailable asset must degrade one card,
        // not turn the learner's whole portfolio response into a 500.
        try {
            if ($this->file_type === 'video' && $this->file_path) {
                $bunnyService = app(BunnyService::class);
                $details = Cache::remember(
                    'portfolio:video-state:' . hash('sha256', (string) $this->file_path),
                    now()->addSeconds(45),
                    fn () => $bunnyService->getRemoteVideoDetails((string) $this->file_path)
                );
                if (is_array($details)) {
                    $resolutions = array_filter(array_map(
                        'trim',
                        explode(',', (string) ($details['availableResolutions'] ?? ''))
                    ));
                    $ready = (int) ($details['status'] ?? -1) === 4
                        || (float) ($details['encodeProgress'] ?? 0) >= 100
                        || $resolutions !== [];
                    $failed = (int) ($details['status'] ?? -1) === 6;
                    $data['status'] = $failed ? 'failed' : ($ready ? 'ready' : 'processing');
                    if ($ready) {
                        // A public portfolio can be disabled at any time. Keep the
                        // provider capability short so a previously viewed page
                        // cannot keep serving media long after revocation.
                        $signedUrl = $bunnyService->getSignedEmbedUrl($this->file_path, 300);
                        $data['video_url'] = $signedUrl ? ($signedUrl['url'] ?? null) : null;
                        if (!$data['video_url']) {
                            $data['status'] = 'failed';
                        }
                    }
                }
            }
            if ($this->file_type === 'image' && $this->file_path) {
                $bunnyService = app(BunnyService::class);
                $signedUrl = $bunnyService->generateBunnySignedUrl($this->file_path, 300);
                $data['image_url'] = $signedUrl ?: null;
                $data['status'] = $data['image_url'] ? 'ready' : 'failed';
            }
        } catch (Throwable $exception) {
            report($exception);
            $data['status'] = 'failed';
        }

        return $data;
    }
}
