<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\User;
use App\Services\BunnyDirectUploadService;
use App\Support\UnicodeText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CourseSectionVideoUploadController extends Controller
{
    public function store(
        Request $request,
        Course $course,
        BunnyDirectUploadService $uploads
    ): JsonResponse {
        $request->merge([
            'title' => UnicodeText::clean($request->input('title'), false),
        ]);
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'size' => 'required|integer|min:1|max:' . BunnyDirectUploadService::MAX_BYTES,
            'mime' => ['required', 'string', Rule::in(BunnyDirectUploadService::MIMES)],
            'original_name' => ['required', 'string', 'max:255'],
            'idempotency_key' => ['required', 'uuid'],
            'section_id' => [
                'nullable',
                'integer',
                Rule::exists('course_sections', 'id')->where(
                    fn ($query) => $query->where('course_id', $course->id)->whereNull('deleted_at')
                ),
            ],
        ]);
        /** @var User $admin */
        $admin = $request->user();
        $section = !empty($validated['section_id'])
            ? CourseSection::query()->where('course_id', $course->id)->findOrFail($validated['section_id'])
            : null;

        return response()->json([
            'success' => true,
            'data' => $uploads->issue(
                $course,
                $admin,
                (string) $validated['title'],
                (int) $validated['size'],
                (string) $validated['mime'],
                (string) $validated['original_name'],
                (string) $validated['idempotency_key'],
                $section
            ),
        ]);
    }

    public function renew(
        Request $request,
        Course $course,
        BunnyDirectUploadService $uploads
    ): JsonResponse {
        $validated = $request->validate(['claim' => 'required|string|max:4096']);
        /** @var User $admin */
        $admin = $request->user();

        return response()->json([
            'success' => true,
            'data' => $uploads->authorization($course, $admin, (string) $validated['claim']),
        ]);
    }
}
