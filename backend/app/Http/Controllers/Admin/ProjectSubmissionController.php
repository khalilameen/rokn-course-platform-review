<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProjectSubmission;
use App\Models\AiInputAttachment;
use App\Models\User;
use App\Models\ProjectFeedbackMessage;
use App\Services\ProjectSubmissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Support\DownloadFilename;

class ProjectSubmissionController extends Controller
{
    public function index(Request $request): View
    {
        $isAdministrator = strtolower(trim((string) $request->user()?->role)) === 'admin';
        $filters = $request->validate([
            'status' => 'nullable|in:pending,passed,needs_resubmission',
            'search' => 'nullable|string|max:100',
        ]);

        $query = ProjectSubmission::query()
            ->with(['project.section.course', 'reviewer'])
            ->when($isAdministrator, fn ($submissions) => $submissions->with('user'))
            ->orderByRaw("CASE review_status WHEN 'pending' THEN 0 WHEN 'needs_resubmission' THEN 1 ELSE 2 END")
            ->latest('submitted_at')
            ->latest('id');

        if (!empty($filters['status'])) {
            $query->where('review_status', $filters['status']);
        }

        if (!empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function ($submissionQuery) use ($search, $isAdministrator): void {
                $submissionQuery
                    ->where('public_id', 'like', "%{$search}%");
                if ($isAdministrator) {
                    $submissionQuery->orWhereHas('user', function ($userQuery) use ($search): void {
                        $userQuery
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
                }
            });
        }

        $submissions = $query->paginate(25)->appends($request->query());
        $statusCounts = ProjectSubmission::query()
            ->selectRaw('review_status, COUNT(*) as aggregate')
            ->groupBy('review_status')
            ->pluck('aggregate', 'review_status');

        return view('admin.project-submissions.index', compact(
            'submissions',
            'statusCounts',
            'filters',
            'isAdministrator'
        ));
    }

    public function show(ProjectSubmission $projectSubmission): View
    {
        $isAdministrator = strtolower(trim((string) request()->user()?->role)) === 'admin';
        $projectSubmission->load(array_filter([
            $isAdministrator ? 'user' : null,
            'project.section.course',
            'reviewer',
            'reviewDecisions.reviewer',
            'aiInputAttachments',
            'feedbackThread',
        ]));

        $threadMessages = collect();
        if ($projectSubmission->feedbackThread) {
            $query = ProjectFeedbackMessage::query()
                ->where('thread_id', $projectSubmission->feedbackThread->id)
                ->orderByDesc('id')
                ->limit(60)
                ->get()
                ->reverse()
                ->values();
            $initial = ProjectFeedbackMessage::query()
                ->where('thread_id', $projectSubmission->feedbackThread->id)
                ->where('client_request_id', 'report:' . $projectSubmission->public_id)
                ->first();
            if ($isAdministrator) {
                $query->load('usageEvent');
                $initial?->load('usageEvent');
            }
            if ($initial && !$query->contains('id', $initial->id)) $query->prepend($initial);
            $attachments = AiInputAttachment::query()
                ->where('owner_type', AiInputAttachment::OWNER_PROJECT_FEEDBACK_MESSAGE)
                ->whereIn('owner_id', $query->pluck('id'))
                ->orderBy('id')
                ->get()
                ->groupBy('owner_id');
            $threadMessages = $query->map(function (ProjectFeedbackMessage $message) use ($attachments) {
                $message->setRelation('inputAttachments', $attachments->get($message->id, collect()));
                return $message;
            });
        }

        return view('admin.project-submissions.show', [
            'submission' => $projectSubmission,
            'threadMessages' => $threadMessages,
            'isAdministrator' => $isAdministrator,
        ]);
    }

    public function download(ProjectSubmission $projectSubmission): StreamedResponse
    {
        $path = ltrim(str_replace('\\', '/', trim((string) $projectSubmission->submission_file)), '/');
        $expectedPrefix = "project_submissions/{$projectSubmission->user_id}/{$projectSubmission->project_id}/";
        abort_if(
            $path === ''
            || str_contains($path, '../')
            || !str_starts_with($path, $expectedPrefix),
            404
        );

        $disk = null;
        foreach ($projectSubmission->submissionDiskCandidates() as $candidate) {
            $candidateDisk = Storage::disk($candidate);
            if ($candidateDisk->exists($path)) {
                $disk = $candidateDisk;
                break;
            }
        }
        abort_if(!$disk, 404);

        $downloadName = DownloadFilename::safe(
            $projectSubmission->original_file_name,
            'project-submission',
            pathinfo($path, PATHINFO_EXTENSION)
        );

        return $disk->download($path, $downloadName, [
            'Content-Disposition' => DownloadFilename::disposition($downloadName),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    public function downloadAttachment(
        ProjectSubmission $projectSubmission,
        AiInputAttachment $attachment
    ): StreamedResponse {
        $isSubmissionFile = $attachment->owner_type === AiInputAttachment::OWNER_PROJECT_SUBMISSION
            && (int) $attachment->owner_id === (int) $projectSubmission->id;
        $isThreadFile = $attachment->owner_type === AiInputAttachment::OWNER_PROJECT_FEEDBACK_MESSAGE
            && ProjectFeedbackMessage::query()
                ->whereKey($attachment->owner_id)
                ->whereHas('thread', fn ($query) =>
                    $query->where('submission_id', $projectSubmission->id)
                )->exists();
        abort_unless($isSubmissionFile || $isThreadFile, 404);
        $disk = Storage::disk((string) $attachment->storage_disk);
        abort_unless($disk->exists((string) $attachment->storage_path), 404);
        $name = DownloadFilename::safe(
            (string) $attachment->original_file_name,
            'project-submission',
            pathinfo((string) $attachment->storage_path, PATHINFO_EXTENSION)
        );
        Log::info('Admin downloaded learner project artifact.', [
            'admin_id' => auth()->id(),
            'submission_id' => $projectSubmission->id,
            'attachment_id' => $attachment->id,
            'owner_type' => $attachment->owner_type,
        ]);
        return $disk->download((string) $attachment->storage_path, $name, [
            'Content-Disposition' => DownloadFilename::disposition($name),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    public function pass(
        Request $request,
        ProjectSubmission $projectSubmission,
        ProjectSubmissionService $submissionService
    ): RedirectResponse {
        $data = $request->validate([
            'feedback' => 'nullable|string|max:2000',
        ]);

        $submissionService->reviewByAdmin(
            $projectSubmission,
            $this->reviewer($request),
            true,
            $data['feedback'] ?? null
        );

        return redirect()
            ->route('admin.project-submissions.show', $projectSubmission)
            ->with('success', 'تم قبول محاولة المشروع وتسجيل قرار المراجع.');
    }

    public function reject(
        Request $request,
        ProjectSubmission $projectSubmission,
        ProjectSubmissionService $submissionService
    ): RedirectResponse {
        $data = $request->validate([
            'feedback' => 'required|string|min:3|max:2000',
        ]);

        $submissionService->reviewByAdmin(
            $projectSubmission,
            $this->reviewer($request),
            false,
            $data['feedback']
        );

        return redirect()
            ->route('admin.project-submissions.show', $projectSubmission)
            ->with('success', 'تم طلب إعادة إرسال محاولة المشروع وتسجيل قرار المراجع.');
    }

    private function reviewer(Request $request): User
    {
        $reviewer = $request->user();
        abort_unless($reviewer instanceof User, 403);

        return $reviewer;
    }
}
