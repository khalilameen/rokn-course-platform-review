<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Classification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ClassificationController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $classifications = Classification::query()->orderBy('home_order')->orderBy('name_ar')->get();
        return view('admin.classifications.index', compact('classifications'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        return view('admin.classifications.create');
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
            'show_on_home' => 'nullable|boolean',
            'home_order' => 'required|integer|min:0|max:10000',
        ]);

        $validated['show_on_home'] = $request->boolean('show_on_home');
        $classification = Classification::create($validated);
        $this->forgetHomeCache($classification->id);

        return redirect()->route('admin.classifications.index')
            ->with('success', 'تم إضافة التصنيف بنجاح');
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Classification  $classification
     * @return \Illuminate\Http\Response
     */
    public function edit(Classification $classification)
    {
        return view('admin.classifications.edit', compact('classification'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Classification  $classification
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Classification $classification)
    {
        $validated = $request->validate([
            'name_ar' => 'required|string|max:255',
            'name_en' => 'required|string|max:255',
            'show_on_home' => 'nullable|boolean',
            'home_order' => 'required|integer|min:0|max:10000',
        ]);

        $validated['show_on_home'] = $request->boolean('show_on_home');
        $classification->update($validated);
        $this->forgetHomeCache($classification->id);

        return redirect()->route('admin.classifications.index')
            ->with('success', 'تم تحديث التصنيف بنجاح');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Classification  $classification
     * @return \Illuminate\Http\Response
     */
    public function destroy(Classification $classification)
    {
        $classificationId = $classification->id;
        $classification->delete();
        $this->forgetHomeCache($classificationId);

        return redirect()->route('admin.classifications.index')
            ->with('success', 'تم حذف التصنيف بنجاح');
    }

    private function forgetHomeCache(int $classificationId): void
    {
        try {
            // Course cards embed their classification metadata. Rotate the
            // paginated catalogue generation as well as the dedicated home
            // keys so a dashboard row edit reaches current mobile clients
            // immediately without flushing unrelated application caches.
            $catalogRevisionKey = 'courses:catalog-revision';
            Cache::forever(
                $catalogRevisionKey,
                max(1, (int) Cache::get($catalogRevisionKey, 1)) + 1
            );
            Cache::forget('home:courses:v4');
            Cache::forget('mobile-home:main-courses:v3');
            Cache::forget('mobile-home:classifications:v4');
            Cache::forget('mobile-home:classifications:v5');
            Cache::forget('mobile-home:classifications:v6:managed');
            Cache::forget('mobile-home:classifications:v6:legacy');
            Cache::forget("mobile-home:classification:{$classificationId}:courses:v4");
            Cache::forget("mobile-home:classification:{$classificationId}:courses:v5");
            Cache::forget("mobile-home:classification:{$classificationId}:courses:v6:managed");
            Cache::forget("mobile-home:classification:{$classificationId}:courses:v6:legacy");
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
