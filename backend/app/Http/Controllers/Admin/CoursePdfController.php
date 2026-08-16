<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CoursePdf;
use App\Models\CourseSection;
use App\Models\DesignSetting;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CoursePdfController extends Controller
{
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
        $designSettings = $this->getDesignSettings();
        $maxOrder = $course->pdfs()->max('order') ?? 0;
        return view('admin.course-pdfs.create', compact('course', 'designSettings', 'maxOrder'));
    }

    /**
     * Store a newly created PDF
     */
    public function store(Request $request, Course $course)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'title_en' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'description_en' => 'nullable|string|max:1000',
            'pdf_file' => 'required|file|mimes:pdf|max:51200', // Max 50MB
            'order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
            'create_section' => 'nullable|boolean',
        ]);

        $stored = null;
        $metadataCreated = false;
        try {
            $file = $request->file('pdf_file');
            $stored = $this->storePdf($file, $course);

            DB::transaction(function () use ($request, $course, $file, $stored): void {
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
                    'order' => $request->order ?? ($maxOrder + 1),
                    'is_active' => $request->has('is_active') ? $request->is_active : true,
                ]);

                if ($request->create_section) {
                    $sectionMaxOrder = $course->sections()->max('order') ?? 0;
                    CourseSection::create([
                        'title' => $request->title,
                        'course_id' => $course->id,
                        'sectionable_type' => CoursePdf::class,
                        'sectionable_id' => $pdf->id,
                        'order' => $sectionMaxOrder + 1,
                    ]);
                }
            });
            $metadataCreated = true;

            return redirect()
                ->route('admin.courses.pdfs.index', $course)
                ->with('success', 'تم رفع ملف PDF بنجاح');

        } catch (\Throwable $e) {
            if (is_array($stored) && !$metadataCreated) {
                Storage::disk($stored['disk'])->delete($stored['path']);
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
        $request->validate([
            'title' => 'required|string|max:255',
            'title_en' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'description_en' => 'nullable|string|max:1000',
            'pdf_file' => 'nullable|file|mimes:pdf|max:51200', // Max 50MB
            'order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

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
                $stored = $this->storePdf($file, $course);
                $data['file_path'] = $stored['path'];
                $data['storage_disk'] = $stored['disk'];
                $data['original_filename'] = $this->safeOriginalFilename($file);
                $data['file_size'] = $file->getSize();
            }

            DB::transaction(function () use ($pdf, $data, $request): void {
                $pdf->update($data);
                if ($pdf->courseSection) {
                    $pdf->courseSection->update(['title' => $request->title]);
                }
            });
            $metadataUpdated = true;

            // Remove the old object after the replacement metadata commits.
            if (is_array($stored)) {
                try {
                    Storage::disk($oldDisk)->delete($oldPath);
                } catch (\Throwable $cleanupFailure) {
                    report($cleanupFailure);
                }
            }

            return redirect()
                ->route('admin.courses.pdfs.index', $course)
                ->with('success', 'تم تحديث ملف PDF بنجاح');

        } catch (\Throwable $e) {
            if (is_array($stored) && !$metadataUpdated) {
                Storage::disk($stored['disk'])->delete($stored['path']);
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
    public function destroy(Course $course, CoursePdf $pdf)
    {
        $this->assertPdfBelongsToCourse($course, $pdf);
        try {
            $disk = $pdf->storage_disk;
            $path = $pdf->file_path;
            DB::transaction(function () use ($pdf): void {
                if ($pdf->courseSection) {
                    $pdf->courseSection->delete();
                }
                $pdf->delete();
            });

            try {
                Storage::disk($disk)->delete($path);
            } catch (\Throwable $cleanupFailure) {
                // The database no longer exposes this document. Keep deletion
                // successful and report the orphan for asynchronous cleanup.
                report($cleanupFailure);
            }

            return redirect()
                ->route('admin.courses.pdfs.index', $course)
                ->with('success', 'تم حذف ملف PDF بنجاح');

        } catch (\Exception $e) {
            return redirect()
                ->back()
                ->with('error', 'حدث خطأ أثناء حذف الملف: ' . $e->getMessage());
        }
    }

    /**
     * Reorder PDFs
     */
    public function reorder(Request $request, Course $course)
    {
        $request->validate([
            'order' => 'required|array',
            'order.*' => 'integer|exists:course_pdfs,id',
        ]);

        try {
            foreach ($request->order as $position => $pdfId) {
                CoursePdf::where('id', $pdfId)
                    ->where('course_id', $course->id)
                    ->update(['order' => $position + 1]);
            }

            return response()->json(['success' => true, 'message' => 'تم تحديث الترتيب بنجاح']);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Toggle PDF active status
     */
    public function toggleStatus(Course $course, CoursePdf $pdf)
    {
        $this->assertPdfBelongsToCourse($course, $pdf);
        try {
            $pdf->update(['is_active' => !$pdf->is_active]);

            return response()->json([
                'success' => true,
                'is_active' => $pdf->is_active,
                'message' => $pdf->is_active ? 'تم تفعيل الملف' : 'تم إلغاء تفعيل الملف'
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
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

    /** @return array{disk: string, path: string} */
    private function storePdf(UploadedFile $file, Course $course): array
    {
        $disk = trim((string) config('course_pdfs.disk'));
        if ($disk === '' || in_array($disk, ['local', 'public'], true)) {
            throw new \RuntimeException('Course PDF storage is not configured as a private shared disk.');
        }

        $path = $file->storeAs(
            'courses/' . $course->id,
            (string) Str::uuid() . '.pdf',
            $disk
        );
        if (!is_string($path) || $path === '') {
            throw new \RuntimeException('Course PDF storage write failed.');
        }

        return ['disk' => $disk, 'path' => $path];
    }

    private function safeOriginalFilename(UploadedFile $file): string
    {
        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?: 'document.pdf';

        return Str::limit($name, 255, '');
    }

    private function assertPdfBelongsToCourse(Course $course, CoursePdf $pdf): void
    {
        abort_unless((int) $pdf->course_id === (int) $course->id, 404);
    }
}
