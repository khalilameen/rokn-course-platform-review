<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DesignSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DesignSettingController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // GET uses persisted values or unsaved branded defaults.
        $settings = DesignSetting::getDefaultSettings();

        return view('admin.design-settings.index', compact('settings'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $settings = DesignSetting::getDefaultSettings();
        return view('admin.design-settings.create', compact('settings'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name_ar' => 'required|string|max:255',
            'name_en' => 'required|string|max:255',
            'slogan_1_ar' => 'nullable|string|max:255',
            'slogan_1_en' => 'nullable|string|max:255',
            'slogan_2_ar' => 'nullable|string|max:255',
            'slogan_2_en' => 'nullable|string|max:255',
            'slogan_3_ar' => 'nullable|string|max:255',
            'slogan_3_en' => 'nullable|string|max:255',
            'color_1' => 'required|string|max:7',
            'color_2' => 'required|string|max:7',
            'color_3' => 'required|string|max:7',
            'color_4' => 'required|string|max:7',
            'logo_file' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:1524',
            'icon_file' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:1524',
            'home_background_file' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'facebook_url' => 'nullable|url',
            'youtube_url' => 'nullable|url',
            'instagram_url' => 'nullable|url',
            'tiktok_url' => 'nullable|url',
            'whatsapp_url' => 'nullable|url',
            'telegram_url' => 'nullable|url',
            'technical_contact' => 'nullable|string|max:20',
            'policy_content_ar' => 'nullable|string',
            'policy_content_en' => 'nullable|string',
            'show_how_platform_works' => 'nullable|boolean',
            'how_platform_works_title_ar' => 'nullable|string|max:255',
            'how_platform_works_title_en' => 'nullable|string|max:255',
            'how_platform_works_video_link' => 'nullable|url',
        ]);

        $data = $request->all();

        // Handle logo file upload
        if ($request->hasFile('logo_file')) {
            $logoFile = $request->file('logo_file');
            $logoPath = $logoFile->store('design-settings/logos', 'public');
            $data['logo_url'] = Storage::url($logoPath);
        }

        // Handle icon file upload
        if ($request->hasFile('icon_file')) {
            $iconFile = $request->file('icon_file');
            $iconPath = $iconFile->store('design-settings/icons', 'public');
            $data['icon_url'] = Storage::url($iconPath);
        }

        // Handle home background file upload
        if ($request->hasFile('home_background_file')) {
            $homeBackgroundFile = $request->file('home_background_file');
            $homeBackgroundPath = $homeBackgroundFile->store('design-settings/home-backgrounds', 'public');
            $data['home_background_url'] = Storage::url($homeBackgroundPath);
        }


        // Handle powered by as array
        if ($request->has('powered_by_titles') && $request->has('powered_by_urls')) {
            $titles = $request->powered_by_titles;
            $urls = $request->powered_by_urls;
            $poweredBy = [];

            for ($i = 0; $i < count($titles); $i++) {
                if (!empty($titles[$i]) && !empty($urls[$i])) {
                    $poweredBy[] = [
                        'title' => $titles[$i],
                        'url' => $urls[$i]
                    ];
                }
            }
            $data['powered_by'] = $poweredBy;
        }

        // Handle show_how_platform_works checkbox
        $data['show_how_platform_works'] = $request->has('show_how_platform_works') ? true : false;

        $existing = DesignSetting::first();
        if ($existing) {
            $existing->update($data);
            $settings = $existing;
        } else {
            $settings = DesignSetting::create($data);
        }

        return redirect()->route('admin.design-settings.index')
            ->with('success', 'تم حفظ إعدادات التصميم بنجاح');
    }

    /**
     * Display the specified resource.
     */
    public function show(DesignSetting $designSetting)
    {
        return view('admin.design-settings.show', compact('designSetting'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(DesignSetting $designSetting)
    {
        return view('admin.design-settings.edit', compact('designSetting'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, DesignSetting $designSetting)
    {
        $request->validate([
            'name_ar' => 'required|string|max:255',
            'name_en' => 'required|string|max:255',
            'slogan_1_ar' => 'nullable|string|max:255',
            'slogan_1_en' => 'nullable|string|max:255',
            'slogan_2_ar' => 'nullable|string|max:255',
            'slogan_2_en' => 'nullable|string|max:255',
            'slogan_3_ar' => 'nullable|string|max:255',
            'slogan_3_en' => 'nullable|string|max:255',
            'color_1' => 'required|string|max:7',
            'color_2' => 'required|string|max:7',
            'color_3' => 'required|string|max:7',
            'color_4' => 'required|string|max:7',
            'logo_file' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:1024',
            'icon_file' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:1024',
            'home_background_file' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'facebook_url' => 'nullable|url',
            'youtube_url' => 'nullable|url',
            'instagram_url' => 'nullable|url',
            'tiktok_url' => 'nullable|url',
            'whatsapp_url' => 'nullable|url',
            'telegram_url' => 'nullable|url',
            'technical_contact' => 'nullable|string|max:20',
            'policy_content_ar' => 'nullable|string',
            'policy_content_en' => 'nullable|string',
            'show_how_platform_works' => 'nullable|boolean',
            'how_platform_works_title_ar' => 'nullable|string|max:255',
            'how_platform_works_title_en' => 'nullable|string|max:255',
            'how_platform_works_video_link' => 'nullable|url',
        ]);

        $data = $request->all();

        // Handle logo file upload
        if ($request->hasFile('logo_file')) {
            // Delete old logo if exists
            if ($designSetting->logo_url) {
                $oldLogoPath = str_replace('/storage/', '', $designSetting->logo_url);
                if (Storage::disk('public')->exists($oldLogoPath)) {
                    Storage::disk('public')->delete($oldLogoPath);
                }
            }

            $logoFile = $request->file('logo_file');
            $logoPath = $logoFile->store('design-settings/logos', 'public');
            $data['logo_url'] = Storage::url($logoPath);
        }

        // Handle icon file upload
        if ($request->hasFile('icon_file')) {
            // Delete old icon if exists
            if ($designSetting->icon_url) {
                $oldIconPath = str_replace('/storage/', '', $designSetting->icon_url);
                if (Storage::disk('public')->exists($oldIconPath)) {
                    Storage::disk('public')->delete($oldIconPath);
                }
            }

            $iconFile = $request->file('icon_file');
            $iconPath = $iconFile->store('design-settings/icons', 'public');
            $data['icon_url'] = Storage::url($iconPath);
        }

        // Handle home background file upload
        if ($request->hasFile('home_background_file')) {
            // Delete old home background if exists
            if ($designSetting->home_background_url) {
                $oldHomeBackgroundPath = str_replace('/storage/', '', $designSetting->home_background_url);
                if (Storage::disk('public')->exists($oldHomeBackgroundPath)) {
                    Storage::disk('public')->delete($oldHomeBackgroundPath);
                }
            }

            $homeBackgroundFile = $request->file('home_background_file');
            $homeBackgroundPath = $homeBackgroundFile->store('design-settings/home-backgrounds', 'public');
            $data['home_background_url'] = Storage::url($homeBackgroundPath);
        }


        // Handle powered by as array
        if ($request->has('powered_by_titles') && $request->has('powered_by_urls')) {
            $titles = $request->powered_by_titles;
            $urls = $request->powered_by_urls;
            $poweredBy = [];

            for ($i = 0; $i < count($titles); $i++) {
                if (!empty($titles[$i]) && !empty($urls[$i])) {
                    $poweredBy[] = [
                        'title' => $titles[$i],
                        'url' => $urls[$i]
                    ];
                }
            }
            $data['powered_by'] = $poweredBy;
        }

        // Handle show_how_platform_works checkbox
        $data['show_how_platform_works'] = $request->has('show_how_platform_works') ? true : false;

        $designSetting->update($data);

        return redirect()->route('admin.design-settings.index')
            ->with('success', 'تم تحديث إعدادات التصميم بنجاح');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(DesignSetting $designSetting)
    {
        // Delete logo file if exists
        if ($designSetting->logo_url) {
            $logoPath = str_replace('/storage/', '', $designSetting->logo_url);
            if (Storage::disk('public')->exists($logoPath)) {
                Storage::disk('public')->delete($logoPath);
            }
        }

        // Delete icon file if exists
        if ($designSetting->icon_url) {
            $iconPath = str_replace('/storage/', '', $designSetting->icon_url);
            if (Storage::disk('public')->exists($iconPath)) {
                Storage::disk('public')->delete($iconPath);
            }
        }

        // Delete home background file if exists
        if ($designSetting->home_background_url) {
            $homeBackgroundPath = str_replace('/storage/', '', $designSetting->home_background_url);
            if (Storage::disk('public')->exists($homeBackgroundPath)) {
                Storage::disk('public')->delete($homeBackgroundPath);
            }
        }

        $designSetting->delete();
        return redirect()->route('admin.design-settings.index')
            ->with('success', 'تم حذف إعدادات التصميم بنجاح');
    }
}
