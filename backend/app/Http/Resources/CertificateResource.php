<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CertificateResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'certificate_id' => $this->id,
            'public_id' => $this->public_id,
            'certificate_url' => $this->certificate_url,
            'portfolio_url' => $this->portfolio_url,
            'status' => $this->status ?? 'active',
            'verification_level' => $this->verification_level ?? 'completion',
            'verification_label' => ($this->verification_level ?? 'completion') === 'reviewed_project'
                ? 'إتمام الكورس ومراجعة المشروع'
                : 'إتمام الكورس',
            'generated_at' => $this->generated_at?->format('c'),
            'course' => $this->whenLoaded('course', function () {
                return [
                    'id' => $this->course->id,
                    'name' => $this->course->name ?? $this->course->name_ar ?? $this->course->name_en,
                    'image' => $this->course->image ? (string) $this->course->image : null,
                ];
            }),
        ];
    }
}
