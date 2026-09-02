<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CertificateResource extends JsonResource
{
    public function toArray($request)
    {
        $storedStatus = (string) ($this->status ?? 'active');
        $status = $storedStatus === 'revoked'
            ? 'revoked'
            : ($this->hasStoredArtifact() ? 'active' : 'pending');
        $holderName = trim((string) $this->holder_name);
        if ($holderName === '' && $this->relationLoaded('user') && $this->user) {
            $holderName = trim((string) $this->user->name);
        }
        $courseName = trim((string) $this->course_name);
        if ($courseName === '' && $this->relationLoaded('course') && $this->course) {
            $courseName = trim((string) ($this->course->name_ar ?: $this->course->name_en));
        }
        $textTemplateKey = trim((string) $this->certificate_text_template_key);
        $certificateText = trim((string) $this->certificate_text);

        return [
            'id' => $this->id,
            'certificate_id' => $this->id,
            'public_id' => $this->public_id,
            'holder_name' => $holderName !== '' ? $holderName : 'طالب ركن',
            'course_name' => $courseName !== '' ? $courseName : 'كورس ركن',
            'certificate_text_template_key' => $textTemplateKey !== '' ? $textTemplateKey : null,
            'certificate_text' => $certificateText !== '' ? $certificateText : null,
            'certificate_url' => $status === 'active' ? $this->certificate_url : '',
            'portfolio_url' => $this->portfolio_url,
            'verification_url' => $this->portfolio_url,
            'status' => $status,
            'verification_level' => $this->verification_level ?? 'completion',
            'verification_label' => ($this->verification_level ?? 'completion') === 'reviewed_project'
                ? 'إتمام الكورس ومراجعة المشروع'
                : 'إتمام الكورس',
            'generated_at' => $this->generated_at?->format('c'),
            'course' => $this->whenLoaded('course', function () {
                return $this->course ? [
                    'id' => $this->course->id,
                    'name' => trim((string) $this->course_name)
                        ?: ($this->course->name_ar ?: $this->course->name_en),
                    'image' => $this->course->image ? (string) $this->course->image : null,
                ] : null;
            }),
        ];
    }
}
