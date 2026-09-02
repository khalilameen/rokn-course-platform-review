<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Classification;
use App\Services\AdminAuthoringCreateIntentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
    public function store(Request $request, AdminAuthoringCreateIntentService $createIntents)
    {
        $validated = $request->validate([
            'name_ar' => 'required|string|max:255',
            'name_en' => 'required|string|max:255',
            'show_on_home' => 'nullable|boolean',
            'home_order' => 'required|integer|min:0|max:10000',
            'authoring_request_id' => 'required|uuid',
        ]);

        unset($validated['authoring_request_id']);
        $validated['show_on_home'] = $request->boolean('show_on_home');
        $classification = DB::transaction(function () use ($request, $validated, $createIntents): Classification {
            $classification = Classification::create($validated);
            $createIntents->completeRedirect(
                $request,
                route('admin.classifications.index'),
                302,
                Classification::class,
                $classification->id
            );
            return $classification;
        }, 3);
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
        $editorVersion = $this->editorVersion($classification);
        return view('admin.classifications.edit', compact('classification', 'editorVersion'));
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
            'editor_version' => 'required|string|size:64',
        ]);

        $validated['show_on_home'] = $request->boolean('show_on_home');
        $editorVersion = (string) $validated['editor_version'];
        unset($validated['editor_version']);
        DB::transaction(function () use ($classification, $validated, $editorVersion): void {
            $locked = Classification::query()->whereKey($classification->id)->lockForUpdate()->firstOrFail();
            if (!hash_equals($this->editorVersion($locked), $editorVersion)) {
                throw ValidationException::withMessages([
                    'editor_version' => 'عدّل شخص آخر هذا التصنيف\nأعد تحميل الصفحة قبل الحفظ',
                ]);
            }
            $locked->update($validated);
        }, 3);
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
        $blocked = DB::transaction(function () use ($classification): bool {
            $locked = Classification::query()->whereKey($classification->id)->lockForUpdate()->firstOrFail();
            if ($locked->courses()->exists()) return true;
            $locked->delete();
            return false;
        }, 3);
        if ($blocked) {
            return redirect()->route('admin.classifications.index')
                ->with('error', 'انقل الكورسات إلى تصنيف آخر قبل حذف هذا التصنيف');
        }
        $classificationId = $classification->id;
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
            // Never publish a revision with a read-then-write sequence. A
            // concurrent course/classification save may advance the counter
            // after get() and before forever(), letting this request move the
            // catalogue generation backwards and revive an older page.
            Cache::add($catalogRevisionKey, 1, now()->addYears(10));
            Cache::increment($catalogRevisionKey);
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

    private function editorVersion(Classification $classification): string
    {
        return hash('sha256', json_encode([
            $classification->name_ar,
            $classification->name_en,
            (bool) $classification->show_on_home,
            (int) $classification->home_order,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
