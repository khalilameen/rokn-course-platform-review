<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DesignSetting;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StaticPageController extends Controller
{
    public function about(Request $request): View
    {
        return $this->renderPage($request, 'static.about');
    }

    public function contact(Request $request): View
    {
        return $this->renderPage($request, 'static.contact');
    }

    public function privacy(Request $request): View
    {
        return $this->renderPage($request, 'static.privacy');
    }

    public function terms(Request $request): View
    {
        return $this->renderPage($request, 'static.terms');
    }
    
    public function returnsPolicy(Request $request): View
    {
        return $this->renderPage($request, 'static.returns');
    }

    private function renderPage(Request $request, string $view): View
    {
        $locale = $request->query('lang');

        if ($locale && in_array($locale, config('app.locales', ['ar', 'en']))) {
            session(['locale' => $locale]);
        }

        $locale = session('locale', config('app.locale', 'ar'));
        app()->setLocale($locale);

        $setting = Setting::first();
        $designSetting = DesignSetting::getDefaultSettings();

        return view($view, compact('setting', 'designSetting', 'locale'));
    }
}
