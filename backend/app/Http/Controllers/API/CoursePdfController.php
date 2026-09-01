<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CoursePdf;
use App\Models\User;
use App\Services\CourseModuleAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Support\DownloadFilename;

final class CoursePdfController extends Controller
{
    public function __construct(private CourseModuleAccessService $access)
    {
    }

    /** Get all active PDFs for an actively enrolled user. */
    public function index(Request $request, $courseId): JsonResponse
    {
        $user = auth('api')->user();
        if (!$user) {
            return $this->error('سجّل الدخول أولًا', 401);
        }

        $course = Course::find($courseId);
        if (!$course) {
            return $this->error('الكورس غير متاح', 404);
        }
        if (!$this->access->hasCourseAccess($user, $course)) {
            return $this->error('هذا الكورس غير مضاف إلى حسابك', 403);
        }

        $pdfs = $course->activePdfs()->get()->map(function (CoursePdf $pdf) use ($course, $user): array {
            return $this->metadata($pdf, $course, $user);
        });

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحميل مرفقات الكورس',
            'data' => $pdfs,
        ]);
    }

    /** Get entitled PDF metadata without exposing a storage key. */
    public function show(Request $request, $courseId, $pdfId): JsonResponse
    {
        $user = auth('api')->user();
        if (!$user) {
            return $this->error('سجّل الدخول أولًا', 401);
        }
        $course = Course::query()->find($courseId);
        if (!$course) {
            return $this->error('الكورس غير متاح', 404);
        }
        if (!$this->access->hasCourseAccess($user, $course)) {
            return $this->error('هذا الكورس غير مضاف إلى حسابك', 403);
        }

        $pdf = CoursePdf::query()
            ->whereKey($pdfId)
            ->where('course_id', $courseId)
            ->where('is_active', true)
            ->first();
        if (!$pdf) {
            return $this->error('المرفق غير متاح', 404);
        }

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحميل مرفق الكورس',
            'data' => $this->metadata($pdf, $course, $user),
        ]);
    }

    /** Download a course file from native/system clients without a bearer header. */
    public function download(
        Request $request,
        int|string $course,
        int|string $pdf
    ): StreamedResponse
    {
        $user = $this->access->userFromSignedDownloadRequest($request);
        abort_unless($user, 403);

        $courseModel = Course::query()->findOrFail($course);
        $pdfModel = CoursePdf::query()
            ->whereKey($pdf)
            ->where('course_id', $courseModel->id)
            ->firstOrFail();
        abort_unless($this->access->canDownloadPdf($user, $courseModel, $pdfModel), 403);

        $disk = Storage::disk($pdfModel->storage_disk);
        abort_unless($disk->exists($pdfModel->file_path), 404);

        $name = DownloadFilename::safe(
            $pdfModel->original_filename ?: $pdfModel->title,
            'rokn-file',
            'pdf'
        );

        return $disk->download($pdfModel->file_path, $name, [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Disposition' => DownloadFilename::disposition($name),
        ]);
    }

    /** @return array<string, mixed> */
    private function metadata(CoursePdf $pdf, Course $course, User $user): array
    {
        $expiresInSeconds = max(
            300,
            min(3600, (int) config('course_attachments.signed_url_minutes', 30) * 60)
        );

        return [
            'id' => $pdf->id,
            'title' => $pdf->title,
            'title_en' => $pdf->title_en,
            'description' => $pdf->description,
            'description_en' => $pdf->description_en,
            'order' => $pdf->order,
            'file_size' => $pdf->formatted_file_size,
            'file_size_bytes' => (int) $pdf->file_size,
            'file_type' => 'pdf',
            'mime_type' => 'application/pdf',
            'download_only' => true,
            'download_url' => $this->access->temporaryPdfDownloadUrl($user, $course, $pdf),
            'expires_in_seconds' => $expiresInSeconds,
            'download_url_expires_at' => now()->addSeconds($expiresInSeconds)->toIso8601String(),
            'download_url_is_temporary' => true,
            'download_version' => sha1(implode('|', [
                $pdf->id,
                $pdf->updated_at,
                $pdf->file_path,
                $pdf->file_size,
            ])),
        ];
    }

    private function error(string $message, int $httpStatus): JsonResponse
    {
        return response()->json([
            'status' => $httpStatus,
            'success' => false,
            'message' => $message,
            'data' => null,
        ], $httpStatus);
    }
}
