<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Services\BunnyService;

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
            'file_path' => $this->file_path,
            'file_type' => $this->file_type,
            'sort_order' => $this->sort_order,
            'caption' => $this->caption,
            'thumbnail_path' => $this->thumbnail_path,
            'width' => $this->width,
            'height' => $this->height,
            'duration_seconds' => $this->duration_seconds,
            'video_url' => null,
            'image_url' => null,
        ];

        // Generate signed URL for videos
        if ($this->file_type === 'video' && $this->file_path) {
            $bunnyService = app(BunnyService::class);
            $signedUrl = $bunnyService->getSignedEmbedUrl($this->file_path);
            $data['video_url'] = $signedUrl ? $signedUrl['url'] : null;
        }
        if ($this->file_type === 'image' && $this->file_path) {
            $bunnyService = app(BunnyService::class);
            $signedUrl = $bunnyService->generateBunnySignedUrl($this->file_path);
            $data['image_url'] = $signedUrl ?? null;
        }

        return $data;
    }
}
