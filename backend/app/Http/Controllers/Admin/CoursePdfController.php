<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CoursePdf;
use App\Models\DesignSetting;
use App\Services\CoursePublishingService;
use App\Services\CourseAuthoringConcurrencyService;
use App\Services\CourseMediaFilePolicy;
use App\Services\StoredFileDeletionService;
use App\Services\AdminAuthoringCreateIntentService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Support\DownloadFilename;
use App\Support\UnicodeText;

class CoursePdfController extends Controller
{
    public function __construct(
        private readonly CoursePublishingService $publishingService,
        private readonly CourseAuthoringConcurrencyService $authoring,
        private readonly CourseMediaFilePolicy $filePolicy,
        private readonly StoredFileDeletionService $fileDeletion,
        private readonly AdminAuthoringCreateIntentService $createIntents
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
     * Display PDFs for a specific course
     */
    public function index(Course $course)
    {
        $pdfs = $course->pdfs()->ordered()->get();
        $designSettings = $this->getDesignSettings();
        return view('admin.course-pdfs.index', compact('course', 'pdfs', 'designSettings'));
    }

    /**
     * Show the form for creating a new PDF
     */
    public function create(Course $course)
    {
        if (!$course->is_coming_soon) {
            return redirect()->route('admin.courses.pdfs.index', $course)
                ->with('error', 'حوّل الكورس إلى مسودة قبل إضافة مرفق جديد');
        }
        $designSettings = $this->getDesignSettings();
        $maxOrder = $course->pdfs()->max('order') ?? 0;
        return view('admin.course-pdfs.create', compact('course', 'designSettings', 'maxOrder'));
    }

    /**
     * Store a newly created PDF
     */
    public function store(Request $request, Course $course)
    {
        $this->assertDraft($course);
        $this->normalizeText($request);
        $request->validate([
            'title' => 'required|string|max:255',
            'title_en' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'description_en' => 'nullable|string|max:1000',
            'pdf_file' => 'required|file|mimes:pdf|max:51200', // Max 50MB
            'order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            'authoring_version' => 'required|integer|min:1',
            'authoring_request_id' => 'required|uuid',
        ]);

        $stored = null;
        $metadataCreated = false;
        $duplicate = false;
        try {
            $file = $request->file('pdf_file');
            $metadata = $this->filePolicy->pdf($file);
            $existingPdf = $course->pdfs()->where('content_sha256', $metadata['sha256'])->first();
            if ($existingPdf) {
                DB::transaction(function () use ($request, $course, $existingPdf): void {
                    CoursePdf::query()->whereKey($existingPdf->id)->lockForUpdate()->firstOrFail();
                    $this->createIntents->completeRedirect(
                        $request,
                        route('admin.courses.pdfs.index', $course),
                        302,
                        CoursePdf::class,
                        $existingPdf->id
                    );
                }, 3);
                return redirect()
                    ->route('admin.courses.pdfs.index', $course)
                    ->with('success', 'هذا الملف مضاف بالفعل');
            }
            $stored = $this->storePdf(
                $file,
                $course,
                'course-pdf|'.$course->id.'|'.$metadata['sha256']
            );

            DB::transaction(function () use ($request, $course, $file, $stored, $metadata, &$duplicate): void {
                $lockedCourse = $this->authoring->lock($request, $course);
                $this->assertDraft($lockedCourse);
                $existingPdf = $course->pdfs()
                    ->where('content_sha256', $metadata['sha256'])
                    ->lockForUpdate()
                    ->first();
                if ($existingPdf) {
                    $duplicate = true;
                    $this->createIntents->completeRedirect(
                        $request,
                        route('admin.courses.pdfs.index', $course),
                        302,
                        CoursePdf::class,
                        $existingPdf->id
                    );
                    return;
                }
                $maxOrder = $course->pdfs()->max('order') ?? 0;
                $pdf = CoursePdf::create([
                    'course_id' => $course->id,
                    'title' => $request->title,
                    'title_en' => $request->title_en,
                    'description' => $request->description,
                    'description_en' => $request->description_en,
                    'file_path' => $stored['path'],
                    'storage_disk' => $stored['disk'],
                    'original_filename' => $this->safeOriginalFilename($file),
                    'file_size' => $file->getSize(),
                    'content_sha256' => $metadata['sha256'],
                    'order' => $request->order ?? ($maxOrder + 1),
                    'is_active' => $request->has('is_active') ? $request->is_active : true,
                ]);
                $this->authoring->advance($lockedCourse);
                $this->createIntents->completeRedirect(
                    $request,
                    route('admin.courses.pdfs.index', $course),
                    302,
                    CoursePdf::class,
                    $pdf->id
                );
            });
            if ($duplicate) {
                $this->fileDeletion->deleteOrQueue($stored['disk'], $stored['path']);
                $stored = null;
            }
            $metadataCreated = true;

            return redirect()
                ->route('admin.courses.pdfs.index', $course)
                ->with('success', $duplicate ? 'هذا الملف مضاف بالفعل' : 'تم رفع ملف PDF بنجاح');

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if (is_array($stored) && !$metadataCreated) {
                $this->fileDeletion->deleteOrQueue($stored['disk'], $stored['path']);
            }
            report($e);
            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'تعذر رفع الملف الآن. حاول مرة أخرى.');
        }
    }

    /**
     * Show the form for editing a PDF
     */
    public function edit(Course $course, CoursePdf $pdf)
    {
        $this->assertPdfBelongsToCourse($course, $pdf);
        $designSettings = $this->getDesignSettings();
        return view('admin.course-pdfs.edit', compact('course', 'pdf', 'designSettings'));
    }

    /**
     * Update the specified PDF
     */
    public function update(Request $request, Course $course, CoursePdf $pdf)
    {
        $this->assertPdfBelongsToCourse($course, $pdf);
        $this->assertDraft($course);
        $this->normalizeText($request);
        $request->validate([
            'title' => 'required|string|max:255',
            'title_en' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'description_en' => 'nullable|string|max:1000',
            'pdf_file' => 'nullable|file|mimes:pdf|max:51200', // Max 50MB
            'order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            'authoring_version' => 'required|integer|min:1',
        ]);

        if ($request->hasFile('pdf_file') && $pdf->courseSection && !$course->is_coming_soon) {
            throw ValidationException::withMessages([
                'pdf_file' => ['حوّل الكورس إلى مسودة قبل استبدال ملف ظاهر في خريطة الكورس'],
            ]);
        }

        $stored = null;
        $metadataUpdated = false;
        $oldDisk = $pdf->storage_disk;
        $oldPath = $pdf->file_path;
        try {
            $data = [
                'title' => $request->title,
                'title_en' => $request->title_en,
                'description' => $request->description,
                'description_en' => $request->description_en,
                'order' => $request->order ?? $pdf->order,
                'is_active' => $request->has('is_active') ? $request->is_active : $pdf->is_active,
            ];

            // If a new file is uploaded, replace the old one
            if ($request->hasFile('pdf_file')) {
                $file = $request->file('pdf_file');
                $metadata = $this->filePolicy->pdf($file);
                $stored = $this->storePdf(
                    $file,
                    $course,
                    'course-pdf|'.$course->id.'|'.$metadata['sha256']
                );
                $data['file_path'] = $stored['path'];
                $data['storage_disk'] = $stored['disk'];
                $data['original_filename'] = $this->safeOriginalFilename($file);
                $data['file_size'] = $file->getSize();
                $data['content_sha256'] = $metadata['sha256'];
            }

            DB::transaction(function () use ($course, $pdf, $data, $request, $stored, $oldDisk, $oldPath): void {
                $lockedCourse = $this->authoring->lock($request, $course);
                $this->assertDraft($lockedCourse);
                $lockedPdf = CoursePdf::query()->whereKey($pdf->id)
                    ->where('course_id', $course->id)->lockForUpdate()->firstOrFail();
                if (!empty($data['content_sha256']) && CoursePdf::query()
                    ->where('course_id', $course->id)
                    ->where('content_sha256', $data['content_sha256'])
                    ->where('id', '<>', $lockedPdf->id)
                    ->lockForUpdate()
                    ->exists()) {
                    throw ValidationException::withMessages(['pdf_file' => 'هذا الملف مضاف بالفعل']);
                }
                $lockedPdf->update($data);
                if (is_array($stored)) {
                    $this->fileDeletion->deleteOrQueue((string) $oldDisk, (string) $oldPath);
                }
                if ($lockedPdf->courseSection) {
                    $lockedPdf->courseSection->update(['title' => $request->title]);
                }
                $this->assertLiveCourseReady($course);
                $this->authoring->advance($lockedCourse);
            });
            $metadataUpdated = true;

            return redirect()
                ->route('admin.courses.pdfs.index', $course)
                ->with('success', 'تم تحديث ملف PDF بنجاح');

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if (is_array($stored) && !$metadataUpdated) {
                $this->fileDeletion->deleteOrQueue($stored['disk'], $stored['path']);
            }
            report($e);
            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'تعذر تحديث الملف الآن. حاول مرة أخرى.');
        }
    }

    /**
     * Remove the specified PDF
     */
    public function destroy(Request $request, Course $course, CoursePdf $pdf)
    {
        $this->assertPdfBelongsToCourse($course, $pdf);
        $this->assertDraft($course);
        $request->validate(['authoring_version' => 'required|integer|min:1']);
        try {
            $disk = $pdf->storage_disk;
            $path = $pdf->file_path;
            DB::transaction(function () use ($request, $course, $pdf): void {
                $lockedCourse = $this->authoring->lock($request, $course);
                $this->assertDraft($lockedCourse);
                $lockedPdf = CoursePdf::query()->whereKey($pdf->id)
                    ->where('course_id', $course->id)->lockForUpdate()->firstOrFail();
                if ($lockedPdf->courseSection) {
                    $lockedPdf->courseSection->delete();
                }
                $lockedPdf->delete();
                $this->fileDeletion->deleteOrQueue(
                    (string) $lockedPdf->storage_disk,
                    (string) $lockedPdf->file_path
                );
                $this->assertLiveCourseReady($course);
                $this->authoring->advance($lockedCourse);
            });

            return redirect()
                ->route('admin.courses.pdfs.index', $course)
                ->with('success', 'تم حذف ملف PDF بنجاح');

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            report($e);
            return redirect()
                ->back()
                ->with('error', 'تعذر حذف الملف الآن');
        }
    }

    /**
     * Reorder PDFs
     */
    public function reorder(Request $request, Course $course)
    {
        $this->assertDraft($course);
        $request->validate([
            'order' => 'required|array',
            'order.*' => [
                'integer',
                'distinct',
                Rule::exists('course_pdfs', 'id')->where(
                    fn ($query) => $query->where('course_id', $course->id)
                ),
            ],
            'authoring_version' => 'required|integer|min:1',
        ]);

        try {
            $version = DB::transaction(function () use ($request, $course): int {
                $lockedCourse = $this->authoring->lock($request, $course);
                $this->assertDraft($lockedCourse);
                CoursePdf::query()->where('course_id', $course->id)
                    ->whereIn('id', $request->order)->orderBy('id')->lockForUpdate()->get();
                foreach ($request->order as $position => $pdfId) {
                    CoursePdf::where('id', $pdfId)
                        ->where('course_id', $course->id)
                        ->update(['order' => $position + 1]);
                }
                return $this->authoring->advance($lockedCourse);
            }, 3);

            return response()->json(['success' => true, 'message' => 'تم تحديث الترتيب بنجاح', 'authoring_version' => $version]);

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            report($e);
            return response()->json(['success' => false, 'message' => 'تعذر تحديث الترتيب الآن'], 500);
        }
    }

    /**
     * Toggle PDF active status
     */
    public function toggleStatus(Request $request, Course $course, CoursePdf $pdf)
    {
        $this->assertPdfBelongsToCourse($course, $pdf);
        $this->assertDraft($course);
        $request->validate(['authoring_version' => 'required|integer|min:1']);
        try {
            $version = DB::transaction(function () use ($request, $course, $pdf): int {
                $lockedCourse = $this->authoring->lock($request, $course);
                $this->assertDraft($lockedCourse);
                $lockedPdf = CoursePdf::query()->whereKey($pdf->id)
                    ->where('course_id', $course->id)->lockForUpdate()->firstOrFail();
                $lockedPdf->update(['is_active' => !$lockedPdf->is_active]);
                $this->assertLiveCourseReady($course);
                $pdf->is_active = $lockedPdf->is_active;
                return $this->authoring->advance($lockedCourse);
            }, 3);

            return response()->json([
                'success' => true,
                'is_active' => $pdf->is_active,
                'message' => $pdf->is_active ? 'تم تفعيل الملف' : 'تم إلغاء تفعيل الملف',
                'authoring_version' => $version,
            ]);

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            report($e);
            return response()->json(['success' => false, 'message' => 'تعذر تحديث حالة الملف الآن'], 500);
        }
    }

    /**
     * Preview PDF (admin only)
     */
    public function preview(Course $course, CoursePdf $pdf)
    {
        $this->assertPdfBelongsToCourse($course, $pdf);
        if (!$pdf->fileExists()) {
            abort(404, 'الملف غير موجود');
        }

        return Storage::disk($pdf->storage_disk)->response($pdf->file_path, 'document.pdf', [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }

    private function normalizeText(Request $request): void
    {
        $normalized = [];
        foreach (['title', 'title_en'] as $field) {
            $normalized[$field] = $request->filled($field)
                ? UnicodeText::clean($request->input($field), false)
                : null;
        }
        foreach (['description', 'description_en'] as $field) {
            $normalized[$field] = $request->filled($field)
                ? UnicodeText::clean($request->input($field))
                : null;
        }
        $request->merge($normalized);
    }

    /** @return array{disk: string, path: string} */
    private function storePdf(UploadedFile $file, Course $course, string $operationIdentity): array
    {
        $disk = trim((string) config('course_pdfs.disk'));
        if ($disk === '' || in_array($disk, ['local', 'public'], true)) {
            throw new \RuntimeException('Course PDF storage is not configured as a private shared disk.');
        }

        $path = $this->fileDeletion->storeTrackedUpload(
            $file,
            'courses/' . $course->id,
            $disk,
            60,
            $operationIdentity
        );

        return ['disk' => $disk, 'path' => $path];
    }

    private function safeOriginalFilename(UploadedFile $file): string
    {
        return DownloadFilename::safe(
            $file->getClientOriginalName(),
            'document',
            'pdf'
        );
    }

    private function assertPdfBelongsToCourse(Course $course, CoursePdf $pdf): void
    {
        abort_unless((int) $pdf->course_id === (int) $course->id, 404);
    }

    private function assertLiveCourseReady(Course $course): void
    {
        if ($course->is_coming_soon) return;

        $audit = $this->publishingService->audit($course->fresh());
        if (!$audit['ready']) {
            throw ValidationException::withMessages(['course' => $audit['issues']]);
        }
    }

    private function assertDraft(Course $course): void
    {
        if (!$course->is_coming_soon) {
            throw ValidationException::withMessages([
                'course' => 'حوّل الكورس إلى مسودة قبل تغيير مرفقاته',
            ]);
        }
    }
}
