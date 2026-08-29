<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\DesignSetting;
use App\Models\Setting;
use App\Services\ApiResponseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;

final class PublicContentController extends Controller
{
    private const PAGES = [
        'about' => 'about',
        'privacy' => 'privacy',
        'terms' => 'terms',
        'returns' => 'returns',
        'contact' => 'contact',
    ];

    public function show(
        Request $request,
        string $page,
        ApiResponseService $responses
    ): JsonResponse {
        abort_unless(array_key_exists($page, self::PAGES), 404);

        $locale = str_starts_with(
            strtolower((string) $request->header('Accept-Language', 'ar')),
            'en'
        ) ? 'en' : 'ar';
        $translation = Lang::get(self::PAGES[$page], [], $locale);
        abort_unless(is_array($translation), 404);

        $payload = [
            'slug' => $page,
            'locale' => $locale,
            'title' => (string) ($translation['title'] ?? $translation['heading'] ?? ''),
            'heading' => (string) ($translation['heading'] ?? $translation['title'] ?? ''),
            'content' => $translation,
            'web_url' => $this->webUrl($page),
        ];

        if ($page === 'contact') {
            $settings = Setting::query()->firstOrNew();
            $design = DesignSetting::getDefaultSettings();
            $payload['contact'] = [
                'email' => $settings->email,
                'phone' => $settings->phone,
                'whatsapp' => $settings->support_whatsapp_url ?: $design->whatsapp_url,
                'form' => [
                    'method' => 'POST',
                    'endpoint' => '/api/v1/contact',
                    'required_fields' => ['name', 'email', 'message'],
                    'optional_fields' => ['phone'],
                ],
            ];
        }

        return $responses->success($payload, 'Public content page retrieved successfully');
    }

    private function webUrl(string $page): string
    {
        return match ($page) {
            'about' => route('about'),
            'privacy' => route('privacy'),
            'terms' => route('terms'),
            'returns' => route('returns-policy'),
            'contact' => route('contact'),
        };
    }
}
