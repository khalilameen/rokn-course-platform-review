<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Services\MediaHealthService;
use Illuminate\Http\RedirectResponse;

class MediaHealthController extends Controller
{
    public function probe(Lesson $lesson, MediaHealthService $health): RedirectResponse
    {
        $state = $health->probe($lesson);

        return back()->with($state->status === 'ready' ? 'success' : 'warning', $state->status === 'ready' ? 'الفيديو جاهز للتشغيل.' : 'تم تحديث حالة الفيديو: ' . $state->status);
    }
}
