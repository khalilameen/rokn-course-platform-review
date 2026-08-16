<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\User;
use App\Services\CourseModuleAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CourseModuleAttachmentController extends Controller
{
    public function download(
        Request $request,
        Course $course,
        CourseModule $module,
        Attachment $attachment,
        CourseModuleAccessService $access
    ): StreamedResponse {
        $userId = filter_var($request->query('uid'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        abort_unless($userId !== false, 403);

        // The validated URL signature binds uid to this exact course/module/file.
        // Resolve the owner from that signed claim because native/system download
        // clients deliberately call this route without the app bearer token.
        $user = User::query()->find($userId);
        abort_unless($user instanceof User, 403);
        abort_unless($access->canDownload($user, $course, $module, $attachment), 403);

        $disk = Storage::disk($attachment->storage_disk);
        abort_unless($disk->exists($attachment->file_path), 404);

        $extension = trim((string) $attachment->file_type, '.');
        $downloadName = trim((string) $attachment->title);
        if ($extension !== '' && !str_ends_with(strtolower($downloadName), '.' . strtolower($extension))) {
            $downloadName .= '.' . $extension;
        }

        return $disk->download($attachment->file_path, $downloadName);
    }
}
