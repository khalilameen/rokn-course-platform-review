<?php

namespace App\Http\Resources;

use App\Services\StudentNotificationPresentationService;
use App\Support\BusinessClock;
use App\Support\RoknLocale;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentNotificationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request)
    {
        $locale = RoknLocale::fromRequest($request);
        $isArabic = $locale === RoknLocale::ARABIC;

        $titleAr = $this->learnerArabicText($this->title_ar, 'إشعار من ركن');
        $titleEn = $this->firstText($this->title_en, $this->title_ar, 'Rokn notification');
        $messageAr = $this->learnerArabicText($this->message_ar, 'لديك إشعار جديد');
        $messageEn = $this->firstText($this->message_en, $this->message_ar);
        $presentation = app(StudentNotificationPresentationService::class)->for($this->resource);

        return [
            'id' => $this->id,
            'campaign_id' => $this->delivery_key,
            'notification_type' => $this->notification_type,
            'title' => $isArabic ? $titleAr : $titleEn,
            'title_ar' => $titleAr,
            'title_en' => $titleEn,
            'message' => $isArabic ? $messageAr : $messageEn,
            'message_ar' => $messageAr,
            'message_en' => $messageEn,
            'link' => $presentation['link'],
            'course_id' => $presentation['course_id'],
            'image_url' => $presentation['image_url'],
            'action_label_ar' => $this->learnerArabicText(
                $presentation['action_label_ar'],
                'افتح ركن'
            ),
            'action_label_en' => $presentation['action_label_en'],
            'is_read' => $this->is_read,
            'read_at' => $this->read_at ? $this->read_at->toIso8601String() : null,
            'created_at' => $this->created_at->toIso8601String(),
            // Compatibility for older APKs. Current clients derive relative
            // text from created_at so it cannot go stale in a cached payload.
            'created_at_formatted' => BusinessClock::relative($this->created_at, $locale),
            'notifiable_type' => $this->notifiable_type,
            'notifiable_id' => $this->notifiable_id,
        ];
    }

    private function firstText(mixed ...$values): string
    {
        foreach ($values as $value) {
            $text = trim((string) $value);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    /** Diagnostics and provider copy never cross into the Arabic learner UI. */
    private function learnerArabicText(mixed $value, string $fallback): string
    {
        $text = trim(strip_tags((string) $value));
        $diagnostic = preg_match(
            '/(?:https?:\/\/|www\.|sqlstate|exception|stack\s*trace|openrouter|bunny(?:cdn)?|kashier|firebase|oauth|pkce|google\s*play|app\s*store|storekit|billingclient|authorization(?:signature|expire)?|access[_ -]?key|api[_ -]?key|\b[A-Z][A-Z0-9_]{2,}\b)/iu',
            $text
        ) === 1;
        if ($text === '' || preg_match('/[\x{0600}-\x{06FF}]/u', $text) !== 1 || $diagnostic) {
            return $fallback;
        }

        $text = preg_replace('/\r\n?/', "\n", $text) ?? $fallback;
        $text = preg_replace('/[,،;؛:]+/u', "\n", $text) ?? $fallback;
        $text = preg_replace('/([^\d])\.(?=\s|$)/u', '${1}' . "\n", $text) ?? $fallback;
        $lines = array_values(array_filter(array_map(
            static fn (string $line): string => trim((string) preg_replace('/\s+/u', ' ', $line)),
            explode("\n", $text)
        )));

        return mb_substr(implode("\n", array_slice($lines, 0, 3)), 0, 240) ?: $fallback;
    }
}

