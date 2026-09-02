<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Course;
use App\Models\CourseModule;
use App\Services\CourseModuleAccessService;
use App\Services\CourseStagedAuthoringService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Support\DownloadFilename;

final class CourseModuleAttachmentController extends Controller
{
    public function download(
        Request $request,
        int|string $course,
        int|string $module,
        int|string $attachment,
        CourseModuleAccessService $access,
        CourseStagedAuthoringService $revisions
    ): StreamedResponse {
        // Native/system clients do not forward the bearer token. The signed URL
        // binds an opaque encrypted owner claim to every resource key; access is
        // still re-evaluated against current account and course state here.
        $user = $access->userFromSignedDownloadRequest($request);
        abort_unless($user, 403);

        // Resolve resources only after signature validation. Implicit route
        // binding would otherwise reveal which numeric ids exist to callers
        // that do not possess a valid capability.
        $courseModel = $revisions->canonicalFor(Course::query()->findOrFail($course));
        $module = $revisions->currentEntityId(CourseModule::class, (int) $module) ?? (int) $module;
        $attachment = $revisions->currentEntityId(Attachment::class, (int) $attachment)
            ?? (int) $attachment;
        $moduleModel = CourseModule::query()
            ->whereKey($module)
            ->where('course_id', $courseModel->id)
            ->firstOrFail();
        $attachmentModel = Attachment::query()->findOrFail($attachment);
        abort_unless($access->canDownload($user, $courseModel, $moduleModel, $attachmentModel), 403);

        $disk = Storage::disk($attachmentModel->storage_disk);
        abort_unless($disk->exists($attachmentModel->file_path), 404);

        $extension = $this->extensionFor($attachmentModel);
        $downloadName = DownloadFilename::safe(
            $attachmentModel->title,
            'rokn-attachment',
            $extension
        );

        $mime = trim((string) $attachmentModel->mime_type);
        if ($mime === '') {
            try {
                $mime = $disk->mimeType($attachmentModel->file_path) ?: 'application/octet-stream';
            } catch (\Throwable) {
                $mime = 'application/octet-stream';
            }
        }

        return $disk->download($attachmentModel->file_path, $downloadName, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Disposition' => DownloadFilename::disposition($downloadName),
        ]);
    }

    private function extensionFor(Attachment $attachment): string
    {
        $value = strtolower(trim((string) $attachment->file_type));
        $mimeExtensions = [
            'application/pdf' => 'pdf',
            'application/zip' => 'zip',
            'application/x-zip-compressed' => 'zip',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'text/plain' => 'txt',
        ];
        $candidate = $mimeExtensions[$value]
            ?? (pathinfo((string) $attachment->file_path, PATHINFO_EXTENSION) ?: $value);

        return preg_match('/^[a-z0-9]{1,8}$/', $candidate)
            ? $candidate
            : '';
    }

}
