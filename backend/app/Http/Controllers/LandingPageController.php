<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DesignSetting;
use App\Models\AppVersion;
use App\Models\Setting;
use App\Services\PackageChannelPricingService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LandingPageController extends Controller
{
    public function index(
        Request $request,
        PackageChannelPricingService $pricing
    ): View
    {
        $locale = $request->query('lang');

        if ($locale && in_array($locale, config('app.locales', ['ar', 'en']))) {
            session(['locale' => $locale]);
        }

        $locale = session('locale', config('app.locale', 'ar'));
        app()->setLocale($locale);

        $setting = Setting::first();
        $designSetting = DesignSetting::getDefaultSettings();
        $releaseUrls = AppVersion::query()
            ->active()
            ->whereIn('distribution_channel', ['play', 'direct', 'appstore'])
            ->orderByDesc('version_code')
            ->orderByDesc('build_number')
            ->orderByDesc('id')
            ->get()
            ->unique('distribution_channel')
            ->mapWithKeys(fn (AppVersion $version): array => [
                $version->distribution_channel => $version->download_url,
            ]);
        $downloadChannels = [
            'play' => $releaseUrls->get('play') ?: $setting?->android_app_url,
            'appstore' => $releaseUrls->get('appstore') ?: $setting?->ios_app_url,
            'direct' => $releaseUrls->get('direct'),
        ];
        $downloadChannels = array_map(
            static fn ($url): ?string => is_string($url) && filter_var($url, FILTER_VALIDATE_URL)
                ? $url
                : null,
            $downloadChannels
        );
        $directDiscountPercent = $pricing->directDiscountPercent();

        return view('landing.index', compact(
            'setting',
            'designSetting',
            'locale',
            'downloadChannels',
            'directDiscountPercent'
        ));
    }
}
