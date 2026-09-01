<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\Course;
use App\Services\MediaHealthService;
use App\Services\MediaReconciliationService;
use Illuminate\Http\RedirectResponse;

class MediaHealthController extends Controller
{
    public function probe(Lesson $lesson, MediaHealthService $health): RedirectResponse
    {
        $state = $health->probe($lesson);

        return back()->with($state->status === 'ready' ? 'success' : 'warning', $state->status === 'ready' ? 'الفيديو جاهز للتشغيل.' : 'تم تحديث حالة الفيديو: ' . $state->status);
    }

    public function probeCourse(
        Course $course,
        MediaReconciliationService $reconciliation
    ): RedirectResponse {
        $result = $reconciliation->reconcileCourse($course, true, true);
        $unavailable = (int) ($result['counts']['quarantined'] ?? 0);
        $attention = (int) ($result['counts']['attention'] ?? 0);

        if ($unavailable > 0) {
            return back()->with('warning', "تعذر تشغيل {$unavailable} من فيديوهات الكورس");
        }
        if ($attention > 0) {
            return back()->with('warning', 'الفيديوهات تعمل لكن توجد تفاصيل تحتاج مراجعة');
        }

        return back()->with('success', 'تم تشغيل وفحص فيديوهات الكورس');
    }
}
