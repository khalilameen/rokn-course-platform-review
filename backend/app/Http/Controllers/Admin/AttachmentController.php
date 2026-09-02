<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Services\CoursePublishingService;
use App\Services\CourseAuthoringConcurrencyService;
use App\Services\CourseMediaFilePolicy;
use App\Services\StoredFileDeletionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Support\UnicodeText;

final class AttachmentController extends Controller
{
    public function __construct(
        private readonly CoursePublishingService $publishingService,
        private readonly CourseAuthoringConcurrencyService $authoring,
        private readonly CourseMediaFilePolicy $filePolicy,
        private readonly StoredFileDeletionService $fileDeletion
    )
    {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:' . max(1, (int) config('course_attachments.max_upload_kilobytes', 51200))],
            'attachable_type' => ['required', 'in:course_module,course_section'],
            'attachable_id' => ['required', 'integer', 'min:1'],
            'name' => ['nullable', 'string', 'max:255'],
            'authoring_version' => ['required', 'integer', 'min:1'],
        ]);

        $attachable = $this->attachable(
            (string) $validated['attachable_type'],
            (int) $validated['attachable_id']
        );
        $course = $this->courseFor($attachable);
        $this->assertDraft($course);
        $file = $request->file('file');
        $metadata = $this->filePolicy->attachment($file);
        $safeTitle = trim((string) ($validated['name'] ?? ''));
        if ($safeTitle === '') {
            $safeTitle = pathinfo(basename(str_replace('\\', '/', $file->getClientOriginalName())), PATHINFO_FILENAME);
        }
        $safeTitle = UnicodeText::limit(
            UnicodeText::clean($safeTitle !== '' ? $safeTitle : 'مرفق', false),
            255
        );
        $disk = (string) config('course_attachments.disk', 'module-attachments');
        $directory = 'attachments/' . $validated['attachable_type'] . '/' . $attachable->getKey();
        $existingAttachment = $attachable->attachments()
            ->where('content_sha256', $metadata['sha256'])
            ->first();
        if ($existingAttachment) {
            return $this->storedResponse(
                $existingAttachment,
                true,
                (int) $course->fresh()->authoring_version
            );
        }
        $savedPath = null;
        $duplicate = false;
        $version = (int) $course->authoring_version;

        try {
            $savedPath = $this->fileDeletion->storeTrackedUpload(
                $file,
                $directory,
                $disk,
                60,
                implode('|', [
                    'course-attachment', $validated['attachable_type'],
                    $attachable->getKey(), $metadata['sha256'],
                ])
            );

            $attachment = DB::transaction(function () use (
                $validated,
                $safeTitle,
                $savedPath,
                $disk,
                $metadata,
                $file,
                $request,
                $course,
                &$duplicate,
                &$version
            ): Attachment {
                $lockedCourse = $this->authoring->lock($request, $course);
                $this->assertDraft($lockedCourse);
                $lockedAttachable = $this->attachable(
                    (string) $validated['attachable_type'],
                    (int) $validated['attachable_id'],
                    true
                );
                abort_unless((int) $lockedAttachable->course_id === (int) $lockedCourse->id, 404);
                $existing = $lockedAttachable->attachments()
                    ->where('content_sha256', $metadata['sha256'])
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    $duplicate = true;
                    $version = (int) $lockedCourse->authoring_version;
                    return $existing;
                }
                $attachment = new Attachment([
                    'title' => $safeTitle,
                    'file_path' => $savedPath,
                    'storage_disk' => $disk,
                    'file_type' => $metadata['extension'],
                    'mime_type' => $metadata['mime'],
                    'file_size' => $file->getSize(),
                    'content_sha256' => $metadata['sha256'],
                    'order' => ((int) $lockedAttachable->attachments()->max('order')) + 1,
                ]);
                $lockedAttachable->attachments()->save($attachment);
                $version = $this->authoring->advance($lockedCourse);

                return $attachment;
            });
            if ($duplicate) {
                $this->fileDeletion->deleteOrQueue($disk, $savedPath);
                $savedPath = null;
            }
        } catch (\Throwable $exception) {
            if (is_string($savedPath) && $savedPath !== '') {
                $this->fileDeletion->deleteOrQueue($disk, $savedPath);
            }
            if ($exception instanceof ValidationException) {
                throw $exception;
            }
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'تعذر رفع المرفق الآن',
            ], 500);
        }

        return $this->storedResponse($attachment, $duplicate, $version);
    }

    private function storedResponse(Attachment $attachment, bool $duplicate, int $version): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $duplicate ? 'هذا المرفق مضاف بالفعل' : 'تم رفع المرفق',
            'attachment' => [
                'id' => (int) $attachment->id,
                'title' => (string) $attachment->title,
                'file_type' => (string) $attachment->file_type,
                'file_size' => (int) $attachment->file_size,
                'mime_type' => (string) $attachment->mime_type,
            ],
            'delete_url' => route('admin.attachments.destroy', $attachment),
            'authoring_version' => $version,
        ]);
    }

    public function destroy(Request $request, Attachment $attachment): JsonResponse
    {
        $disk = (string) $attachment->storage_disk;
        $path = (string) $attachment->file_path;
        $course = $this->courseFor($attachment->attachable);

        try {
            $request->validate(['authoring_version' => ['required', 'integer', 'min:1']]);
            $version = DB::transaction(function () use ($request, $attachment, $course): int {
                $lockedCourse = $this->authoring->lock($request, $course);
                $this->assertDraft($lockedCourse);
                $lockedAttachment = Attachment::query()
                    ->with('attachable')
                    ->whereKey($attachment->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                abort_unless(
                    (int) $this->courseFor($lockedAttachment->attachable)->id === (int) $lockedCourse->id,
                    404
                );

                $lockedAttachment->delete();
                $this->fileDeletion->deleteOrQueue(
                    (string) $lockedAttachment->storage_disk,
                    (string) $lockedAttachment->file_path
                );
                return $this->authoring->advance($lockedCourse);
            });
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'تعذر حذف المرفق الآن',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم حذف المرفق',
            'authoring_version' => $version,
        ]);
    }

    private function attachable(string $type, int $id, bool $lock = false): Model
    {
        $query = match ($type) {
            'course_module' => CourseModule::query(),
            'course_section' => CourseSection::query(),
        };
        if ($lock) $query->lockForUpdate();

        return $query->findOrFail($id);
    }

    private function courseFor(Model $attachable): Course
    {
        if ($attachable instanceof CourseModule || $attachable instanceof CourseSection) {
            return $attachable->course()->firstOrFail();
        }

        abort(404);
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
