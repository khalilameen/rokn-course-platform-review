<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\DesignSetting;
use App\Services\CoursePublishingService;
use App\Services\CourseAuthoringConcurrencyService;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\SafeExternalUrl;
use App\Support\UnicodeText;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CourseModuleController extends Controller
{
    public function __construct(
        private readonly CoursePublishingService $publishingService,
        private readonly CourseAuthoringConcurrencyService $authoring
    )
    {
    }

    /**
     * Get design settings for the views
     */
    private function getDesignSettings()
    {
        return DesignSetting::getDefaultSettings();
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create(Course $course)
    {
        if (!$course->is_coming_soon) {
            return redirect()->route('admin.courses.show', $course)->with(
                'error',
                'حوّل الكورس إلى مسودة قبل إضافة وحدة جديدة'
            );
        }
        $designSettings = $this->getDesignSettings();
        return view('admin.course-modules.create', compact('course', 'designSettings'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(
        Request $request,
        Course $course,
        AdminAuthoringCreateIntentService $createIntents
    )
    {
        $this->assertDraftForStructuralChange($course);
        $this->normalizeTitles($request);
        $request->validate([
            'title_ar' => 'required|string|max:255',
            'title_en' => 'nullable|string|max:255',
            'attachments_link' => ['nullable', SafeExternalUrl::validationRule()],
            'attachment_platform' => 'required|in:computer,mobile,both',
            'order' => 'nullable|integer|min:0',
            'authoring_version' => 'required|integer|min:1',
            'authoring_request_id' => 'required|uuid',
        ]);

        DB::transaction(function () use ($request, $course, $createIntents): void {
            $lockedCourse = $this->authoring->lock($request, $course);
            $this->assertDraftForStructuralChange($lockedCourse);
            $maxOrder = $lockedCourse->modules()->max('order') ?? 0;
            $module = CourseModule::create([
                'course_id' => $lockedCourse->id,
                'title_ar' => $request->title_ar,
                'title_en' => $request->title_en,
                'attachments_link' => SafeExternalUrl::sanitize($request->input('attachments_link')),
                'attachment_platform' => $request->attachment_platform,
                'order' => $request->input('order', $maxOrder + 1),
            ]);
            $this->normalizeOrder($lockedCourse);
            $this->authoring->advance($lockedCourse);
            $createIntents->completeRedirect(
                $request,
                $this->authoringLocation($request, $course),
                302,
                CourseModule::class,
                $module->id
            );
        }, 3);

        return $this->authoringRedirect($request, $course)
            ->with('success', 'تم إضافة الوحدة بنجاح');
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\CourseModule  $module
     * @return \Illuminate\Http\Response
     */
    public function edit(Course $course, CourseModule $module)
    {
        $this->ensureModuleBelongsToCourse($course, $module);
        if (!$course->is_coming_soon) {
            return redirect()->route('admin.courses.show', $course)
                ->with('error', 'حوّل الكورس إلى مسودة قبل تعديل وحداته');
        }
        $designSettings = $this->getDesignSettings();
        return view('admin.course-modules.edit', compact('course', 'module', 'designSettings'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\CourseModule  $module
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Course $course, CourseModule $module)
    {
        $this->ensureModuleBelongsToCourse($course, $module);
        $this->assertDraftForStructuralChange($course);
        $this->normalizeTitles($request);
        $request->validate([
            'title_ar' => 'required|string|max:255',
            'title_en' => 'nullable|string|max:255',
            'attachments_link' => ['nullable', SafeExternalUrl::validationRule()],
            'attachment_platform' => 'required|in:computer,mobile,both',
            'order' => 'nullable|integer|min:0',
            'authoring_version' => 'required|integer|min:1',
        ]);

        DB::transaction(function () use ($course, $module, $request): void {
            $lockedCourse = $this->authoring->lock($request, $course);
            $this->assertDraftForStructuralChange($lockedCourse);
            $lockedModule = CourseModule::query()->whereKey($module->id)
                ->where('course_id', $course->id)->lockForUpdate()->firstOrFail();
            $lockedModule->update([
                'title_ar' => $request->title_ar,
                'title_en' => $request->title_en,
                'attachments_link' => SafeExternalUrl::sanitize($request->input('attachments_link')),
                'order' => $request->input('order', $module->order),
                'attachment_platform' => $request->attachment_platform,
            ]);
            $this->normalizeOrder($lockedCourse);
            $this->assertLiveCourseReady($course);
            $this->authoring->advance($lockedCourse);
        }, 3);

        return $this->authoringRedirect($request, $course)
            ->with('success', 'تم تحديث الوحدة بنجاح');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\CourseModule  $module
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request, Course $course, CourseModule $module)
    {
        $this->ensureModuleBelongsToCourse($course, $module);
        $this->assertDraftForStructuralChange($course);

        $request->validate(['authoring_version' => 'required|integer|min:1']);
        DB::transaction(function () use ($course, $module, $request): void {
            $lockedCourse = $this->authoring->lock($request, $course);
            $this->assertDraftForStructuralChange($lockedCourse);
            $lockedModule = CourseModule::query()->whereKey($module->id)
                ->where('course_id', $course->id)->lockForUpdate()->firstOrFail();
            if ($lockedModule->sections()->lockForUpdate()->get(['course_sections.id'])->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'module' => 'انقل محتوى الوحدة أو احذفه قبل حذف الوحدة',
                ]);
            }
            $lockedModule->delete();
            $this->normalizeOrder($lockedCourse);
            $this->assertLiveCourseReady($course);
            $this->authoring->advance($lockedCourse);
        }, 3);

        return redirect()->route('admin.courses.sections.index', $course)
            ->with('success', 'تم حذف الوحدة بنجاح');
    }

    /**
     * Reorder modules
     */
    public function reorder(Request $request, Course $course)
    {
        $this->assertDraftForStructuralChange($course);
        $request->validate([
            'modules' => 'required|array',
            'modules.*.id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('course_modules', 'id')->where(
                    fn ($query) => $query->where('course_id', $course->id)
                ),
            ],
            'modules.*.order' => 'required|integer|min:0|distinct',
            'authoring_version' => 'required|integer|min:1',
        ]);

        $version = DB::transaction(function () use ($request, $course): int {
            $lockedCourse = $this->authoring->lock($request, $course);
            $this->assertDraftForStructuralChange($lockedCourse);
            $lockedModules = CourseModule::query()
                ->where('course_id', $course->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);
            $submittedIds = collect($request->modules)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->sort()
                ->values();
            if ($lockedModules->pluck('id')->map(static fn ($id): int => (int) $id)
                ->sort()->values()->all() !== $submittedIds->all()) {
                throw ValidationException::withMessages([
                    'modules' => 'تغيّرت قائمة الوحدات منذ بدء السحب\nحدّث الصفحة ثم أعد الترتيب',
                ])->status(409);
            }
            foreach ($request->modules as $moduleData) {
                CourseModule::where('id', $moduleData['id'])
                    ->where('course_id', $course->id)
                    ->update(['order' => $moduleData['order']]);
            }
            $this->normalizeOrder($lockedCourse);
            $this->assertLiveCourseReady($course);
            return $this->authoring->advance($lockedCourse);
        }, 3);

        return response()->json(['success' => true, 'authoring_version' => $version]);
    }

    private function normalizeTitles(Request $request): void
    {
        $request->merge([
            'title_ar' => UnicodeText::clean($request->input('title_ar'), false),
            'title_en' => $request->filled('title_en')
                ? UnicodeText::clean($request->input('title_en'), false)
                : null,
        ]);
    }

    private function ensureModuleBelongsToCourse(Course $course, CourseModule $module): void
    {
        abort_unless((int) $module->course_id === (int) $course->id, 404);
    }

    private function assertDraftForStructuralChange(Course $course): void
    {
        if (!$course->is_coming_soon) {
            throw ValidationException::withMessages([
                'course' => [
                    'حوّل الكورس إلى مسودة قبل إضافة وحدة ثم أعد نشره بعد اكتمال محتواها',
                ],
            ]);
        }
    }

    private function normalizeOrder(Course $course): void
    {
        $course->modules()->orderBy('order')->orderBy('id')->get()
            ->each(function (CourseModule $module, int $index): void {
                $order = $index + 1;
                if ((int) $module->order !== $order) {
                    $module->updateQuietly(['order' => $order]);
                }
            });
    }

    private function assertLiveCourseReady(Course $course): void
    {
        if ($course->is_coming_soon) return;

        $audit = $this->publishingService->audit($course->fresh());
        if (!$audit['ready']) {
            throw ValidationException::withMessages(['course' => $audit['issues']]);
        }
    }

    private function authoringRedirect(Request $request, Course $course)
    {
        return redirect()->to($this->authoringLocation($request, $course));
    }

    private function authoringLocation(Request $request, Course $course): string
    {
        return route(
            $request->input('return_to') === 'studio'
                ? 'admin.courses.show'
                : 'admin.courses.sections.index',
            $course
        );
    }
}
