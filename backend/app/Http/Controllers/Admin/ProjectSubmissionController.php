<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Services\ProjectSubmissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Support\DownloadFilename;

class ProjectSubmissionController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => 'nullable|in:pending,passed,needs_resubmission',
            'search' => 'nullable|string|max:100',
        ]);

        $query = ProjectSubmission::query()
            ->with(['user', 'project.section.course', 'reviewer'])
            ->orderByRaw("CASE review_status WHEN 'pending' THEN 0 WHEN 'needs_resubmission' THEN 1 ELSE 2 END")
            ->latest('submitted_at')
            ->latest('id');

        if (!empty($filters['status'])) {
            $query->where('review_status', $filters['status']);
        }

        if (!empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function ($submissionQuery) use ($search): void {
                $submissionQuery
                    ->where('public_id', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($userQuery) use ($search): void {
                        $userQuery
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
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
            'filters'
        ));
    }

    public function show(ProjectSubmission $projectSubmission): View
    {
        $projectSubmission->load([
            'user',
            'project.section.course',
            'reviewer',
        ]);

        return view('admin.project-submissions.show', [
            'submission' => $projectSubmission,
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
            'X-Content-Type-Options' => 'nosniff',
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
