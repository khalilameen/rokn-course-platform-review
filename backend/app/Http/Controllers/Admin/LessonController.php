<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use Illuminate\Http\Request;

/**
 * Compatibility redirects for the retired standalone lesson dashboard.
 *
 * Lesson creation, editing and deletion belong to CourseSectionController so
 * the content pointer, ordering and Bunny lifecycle share one transaction.
 * Legacy mutation routes remain hard stops for cached form posts.
 */
class LessonController extends Controller
{
    public function index(Request $request)
    {
        return redirect()->route('admin.courses.index')
            ->with('info', 'إدارة الدروس أصبحت من داخل خريطة كل كورس');
    }

    public function create()
    {
        return redirect()->route('admin.courses.index')
            ->with('info', 'اختر الكورس ثم أضف الدرس من خريطته');
    }

    public function show(Lesson $lesson)
    {
        $section = $lesson->courseSection;
        if ($section) {
            return redirect()->route('admin.courses.sections.edit', [
                $section->course_id,
                $section->id,
            ]);
        }

        return redirect()->route('admin.courses.index')
            ->with('warning', 'هذا درس قديم غير مرتبط بخريطة كورس');
    }

    public function edit(Lesson $lesson)
    {
        return $this->show($lesson);
    }

    public function store(Request $request): void
    {
        abort(410, 'استخدم مسار أقسام الكورس لإضافة الدروس');
    }

    public function update(Request $request, Lesson $lesson): void
    {
        abort(410, 'استخدم مسار أقسام الكورس لتعديل الدروس');
    }

    public function destroy(Lesson $lesson): void
    {
        abort(410, 'استخدم مسار أقسام الكورس لحذف الدروس');
    }
}
