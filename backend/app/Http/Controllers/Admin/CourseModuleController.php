<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\DesignSetting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CourseModuleController extends Controller
{
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
        $designSettings = $this->getDesignSettings();
        return view('admin.course-modules.create', compact('course', 'designSettings'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request, Course $course)
    {
        $request->validate([
            'title_ar' => 'required|string|max:255',
            'title_en' => 'nullable|string|max:255',
            'attachments_link' => 'nullable|url|max:2000',
            'attachment_platform' => 'required|in:computer,mobile,both',
            'order' => 'nullable|integer|min:0'
        ]);

        $maxOrder = $course->modules()->max('order') ?? 0;
        $order = $request->input('order', $maxOrder + 1);

        CourseModule::create([
            'course_id' => $course->id,
            'title_ar' => $request->title_ar,
            'title_en' => $request->title_en,
            'attachments_link' => $request->attachments_link,
            'attachment_platform' => $request->attachment_platform,
            'order' => $order
        ]);

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
        $request->validate([
            'title_ar' => 'required|string|max:255',
            'title_en' => 'nullable|string|max:255',
            'attachments_link' => 'nullable|url|max:2000',
            'attachment_platform' => 'required|in:computer,mobile,both',
            'order' => 'nullable|integer|min:0'
        ]);

        $module->update([
            'title_ar' => $request->title_ar,
            'title_en' => $request->title_en,
            'attachments_link' => $request->attachments_link,
            'order' => $request->input('order', $module->order),
            'attachment_platform' => $request->attachment_platform,
        ]);

        return $this->authoringRedirect($request, $course)
            ->with('success', 'تم تحديث الوحدة بنجاح');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\CourseModule  $module
     * @return \Illuminate\Http\Response
     */
    public function destroy(Course $course, CourseModule $module)
    {
        $this->ensureModuleBelongsToCourse($course, $module);

        if ($module->sections()->count() > 0) {
            return back()->with(
                'error',
                'لا يمكن حذف وحدة تحتوي على مقاطع أو مشروع\nانقل محتواها أو احذفه أولًا'
            );
        }

        $module->delete();

        return redirect()->route('admin.courses.sections.index', $course)
            ->with('success', 'تم حذف الوحدة بنجاح');
    }

    /**
     * Reorder modules
     */
    public function reorder(Request $request, Course $course)
    {
        $request->validate([
            'modules' => 'required|array',
            'modules.*.id' => [
                'required',
                'integer',
                Rule::exists('course_modules', 'id')->where(
                    fn ($query) => $query->where('course_id', $course->id)
                ),
            ],
            'modules.*.order' => 'required|integer|min:0'
        ]);

        foreach ($request->modules as $moduleData) {
            CourseModule::where('id', $moduleData['id'])
                ->where('course_id', $course->id)
                ->update(['order' => $moduleData['order']]);
        }

        return response()->json(['success' => true]);
    }

    private function ensureModuleBelongsToCourse(Course $course, CourseModule $module): void
    {
        abort_unless((int) $module->course_id === (int) $course->id, 404);
    }

    private function authoringRedirect(Request $request, Course $course)
    {
        return redirect()->route(
            $request->input('return_to') === 'studio'
                ? 'admin.courses.show'
                : 'admin.courses.sections.index',
            $course
        );
    }
}
