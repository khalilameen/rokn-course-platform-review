<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Level;
use App\Models\DesignSetting;
use Illuminate\Http\Request;

class LevelController extends Controller
{
    /**
     * Get design settings for the views
     */
    private function getDesignSettings()
    {
        return DesignSetting::getDefaultSettings();
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $levels = Level::ordered()->get();
        $designSettings = $this->getDesignSettings();
        return view('admin.levels.index', compact('levels', 'designSettings'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $designSettings = $this->getDesignSettings();
        return view('admin.levels.create', compact('designSettings'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name_ar' => 'required|string|max:255',
            'name_en' => 'required|string|max:255',
            'description_ar' => 'nullable|string|max:1000',
            'description_en' => 'nullable|string|max:1000',
            'badge_image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:4096',
            'order' => 'nullable|integer|min:1|max:1000',
        ]);

        unset($validated['badge_image']);
        $level = Level::create($validated);

        if ($request->hasFile('badge_image')) {
            $level->forceFill(['badge_image' => null])->save();
            $level->storeImage($request->file('badge_image'), 'levels', 'featured');
        }

        return redirect()->route('admin.levels.index')
            ->with('success', 'تم إضافة المستوى بنجاح');
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Level  $level
     * @return \Illuminate\Http\Response
     */
    public function edit(Level $level)
    {
        $designSettings = $this->getDesignSettings();
        return view('admin.levels.edit', compact('level', 'designSettings'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Level  $level
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Level $level)
    {
        $validated = $request->validate([
            'name_ar' => 'required|string|max:255',
            'name_en' => 'required|string|max:255',
            'description_ar' => 'nullable|string|max:1000',
            'description_en' => 'nullable|string|max:1000',
            'badge_image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:4096',
            'order' => 'nullable|integer|min:1|max:1000',
        ]);

        unset($validated['badge_image']);
        $level->update($validated);

        if ($request->hasFile('badge_image')) {
            $level->forceFill(['badge_image' => null])->save();
            $level->replaceImage($request->file('badge_image'), 'levels', 'featured');
        }

        return redirect()->route('admin.levels.index')
            ->with('success', 'تم تحديث المستوى بنجاح');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Level  $level
     * @return \Illuminate\Http\Response
     */
    public function destroy(Level $level)
    {
        if ($level->courses()->count() > 0) {
            return redirect()->route('admin.levels.index')
                ->with('error', 'لا يمكن حذف المستوى لأنه مرتبط بدورات تدريبية');
        }

        $level->delete();

        return redirect()->route('admin.levels.index')
            ->with('success', 'تم حذف المستوى بنجاح');
    }
}
