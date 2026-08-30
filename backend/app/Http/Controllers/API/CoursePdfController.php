<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CoursePdf;
use App\Services\CourseModuleAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            return $this->error('Unauthorized', 401);
        }

        $course = Course::find($courseId);
        if (!$course) {
            return $this->error('Course not found', 404);
        }
        if (!$this->access->hasCourseAccess($user, $course)) {
            return $this->error('You are not enrolled in this course', 403);
        }

        $pdfs = $course->activePdfs()->get()->map(function (CoursePdf $pdf) use ($courseId): array {
            return $this->metadata($pdf, $courseId);
        });

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'Course PDFs retrieved successfully',
            'data' => $pdfs,
        ]);
    }

    /**
     * Stream an entitled document through Laravel's Storage abstraction.
     * This works identically with a shared mount or S3-compatible storage and
     * never assumes that the object exists on the current API node.
     */
    public function stream(Request $request, $courseId, $pdfId): Response
    {
        $user = auth('api')->user();
        if (!$user) {
            return $this->error('Unauthorized', 401);
        }
        $course = Course::query()->find($courseId);
        if (!$course) {
            return $this->error('Course not found', 404);
        }
        if (!$this->access->hasCourseAccess($user, $course)) {
            return $this->error('You are not enrolled in this course', 403);
        }

        $pdf = CoursePdf::query()
            ->whereKey($pdfId)
            ->where('course_id', $courseId)
            ->where('is_active', true)
            ->first();
        if (!$pdf) {
            return $this->error('PDF not found', 404);
        }

        $disk = Storage::disk($pdf->storage_disk);
        if (!$disk->exists($pdf->file_path)) {
            return $this->error('PDF file not found on server', 404);
        }

        $fileSize = (int) $disk->size($pdf->file_path);
        if ($fileSize <= 0) {
            return $this->error('PDF file is empty', 404);
        }

        $range = $this->requestedRange($request, $fileSize);
        if ($range === null) {
            return response('', 416, [
                'Accept-Ranges' => 'bytes',
                'Content-Range' => "bytes */{$fileSize}",
            ]);
        }

        [$start, $end, $partial] = $range;
        $length = $end - $start + 1;
        $headers = [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) $length,
            'Accept-Ranges' => 'bytes',
            'Content-Disposition' => 'inline; filename="document.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "default-src 'self'",
        ];
        if ($partial) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$fileSize}";
        }

        return new StreamedResponse(function () use ($disk, $pdf, $start, $length): void {
            $stream = $disk->readStream($pdf->file_path);
            if (!is_resource($stream)) {
                return;
            }

            try {
                // Do not rely on a local path or a seekable remote stream.
                $skip = $start;
                while ($skip > 0 && !feof($stream)) {
                    $chunk = fread($stream, min(8192, $skip));
                    if (!is_string($chunk) || $chunk === '') {
                        return;
                    }
                    $skip -= strlen($chunk);
                }

                $remaining = $length;
                while ($remaining > 0 && !feof($stream)) {
                    $chunk = fread($stream, min(8192, $remaining));
                    if (!is_string($chunk) || $chunk === '') {
                        break;
                    }
                    echo $chunk;
                    $remaining -= strlen($chunk);
                }
            } finally {
                fclose($stream);
            }
        }, $partial ? 206 : 200, $headers);
    }

    /** Get entitled PDF metadata without exposing a storage key. */
    public function show(Request $request, $courseId, $pdfId): JsonResponse
    {
        $user = auth('api')->user();
        if (!$user) {
            return $this->error('Unauthorized', 401);
        }
        $course = Course::query()->find($courseId);
        if (!$course) {
            return $this->error('Course not found', 404);
        }
        if (!$this->access->hasCourseAccess($user, $course)) {
            return $this->error('You are not enrolled in this course', 403);
        }

        $pdf = CoursePdf::query()
            ->whereKey($pdfId)
            ->where('course_id', $courseId)
            ->where('is_active', true)
            ->first();
        if (!$pdf) {
            return $this->error('PDF not found', 404);
        }

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'Course PDF retrieved successfully',
            'data' => $this->metadata($pdf, $courseId),
        ]);
    }

    /** @return array<string, mixed> */
    private function metadata(CoursePdf $pdf, mixed $courseId): array
    {
        return [
            'id' => $pdf->id,
            'title' => $pdf->title,
            'title_en' => $pdf->title_en,
            'description' => $pdf->description,
            'description_en' => $pdf->description_en,
            'order' => $pdf->order,
            'file_size' => $pdf->formatted_file_size,
            'stream_url' => url("/api/v1/courses/{$courseId}/pdfs/{$pdf->id}/stream"),
        ];
    }

    /** @return array{int, int, bool}|null */
    private function requestedRange(Request $request, int $fileSize): ?array
    {
        $header = trim((string) $request->header('Range', ''));
        if ($header === '') {
            return [0, $fileSize - 1, false];
        }
        if (!preg_match('/^bytes=(\d*)-(\d*)$/', $header, $matches)) {
            return null;
        }

        $startText = $matches[1];
        $endText = $matches[2];
        if ($startText === '' && $endText === '') {
            return null;
        }

        if ($startText === '') {
            $suffix = (int) $endText;
            if ($suffix <= 0) {
                return null;
            }
            $start = max(0, $fileSize - $suffix);
            $end = $fileSize - 1;
        } else {
            $start = (int) $startText;
            if ($start >= $fileSize) {
                return null;
            }
            $end = $endText === '' ? $fileSize - 1 : min((int) $endText, $fileSize - 1);
            if ($end < $start) {
                return null;
            }
        }

        return [$start, $end, true];
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
