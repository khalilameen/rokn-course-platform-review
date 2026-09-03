<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Support\RoknPublicUrl;

use App\Http\Controllers\Controller;
use App\Http\Resources\PortfolioItemResource;
use App\Http\Resources\PortfolioMediaResource;
use App\Models\Course;
use App\Models\PortfolioItem;
use App\Models\PortfolioVideoUpload;
use App\Models\Project;
use App\Models\User;
use App\Models\UserProjectEvaluation;
use App\Services\BunnyService;
use App\Services\CourseChatAccessService;
use App\Services\PortfolioShareIdentityService;
use App\Services\PortfolioVideoUploadService;
use App\Services\SafeExternalUrl;
use App\Services\CourseRevisionLearnerReadService;
use App\Services\CourseStagedAuthoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Throwable;
use UnexpectedValueException;
use App\Support\DownloadFilename;
use App\Support\UnicodeText;

final class PortfolioController extends Controller
{
    private const MAX_MEDIA_PER_ITEM = 12;

    public function __construct(
        private BunnyService $bunnyService,
        private CourseChatAccessService $courseAccess,
        private PortfolioShareIdentityService $portfolioShares,
        private PortfolioVideoUploadService $videoUploads,
        private CourseRevisionLearnerReadService $revisionReads,
        private CourseStagedAuthoringService $stagedAuthoring
    ) {
    }

    /**
     * List user's portfolio items.
     */
    public function index(Request $request): JsonResponse
    {
        $user = auth('api')->user();
        $summary = $request->boolean('summary');
        $items = $user->portfolioItems()
            ->whereNull('deletion_started_at')
            ->withCount('mediaFiles')
            ->with([
                'mediaFiles' => function ($media) use ($summary): void {
                    if ($summary) {
                        // The mobile gallery needs a cover only. Excluding video
                        // rows here also prevents PortfolioMediaResource from
                        // performing one Bunny inspection for every saved video.
                        $media->where('file_type', 'image')->limit(1);
                    }
                },
                'course',
            ])
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->latest('id')
            ->get();

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحميل المشروعات',
            'data' => PortfolioItemResource::collection($items),
        ]);
    }

    /** Passed course projects that have not yet been added to this portfolio. */
    public function eligibleProjects(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        $user = auth('api')->user();
        $usedProjectIds = $user->portfolioItems()
            ->whereNotNull('source_project_id')
            ->pluck('source_project_id');
        $usedCurrentProjectIds = collect($this->stagedAuthoring->currentLearnerEntityMap(
            Project::class,
            $usedProjectIds
        ))->values()->unique()->values();

        $projects = Project::query()
            ->whereHas('section.course', fn ($courses) => $courses
                ->where('is_coming_soon', false)->whereNull('parent_id'))
            ->when($usedCurrentProjectIds->isNotEmpty(), fn ($query) =>
                $query->whereNotIn('id', $usedCurrentProjectIds)
            )
            ->with([
                'section.course:id,name_ar,name_en,image',
                'section.module:id,course_id,title,title_ar,title_en,order',
            ])->get()->keyBy('id');
        $eligible = $this->revisionReads
            ->projectEvaluations((int) $user->id, $projects->keys())
            ->filter(fn (UserProjectEvaluation $row): bool => (bool) $row->passed)
            ->map(function (UserProjectEvaluation $row, int $currentId) use ($projects) {
                $row->setRelation('project', $projects->get($currentId));
                $row->project_id = $currentId;
                return $row;
            })->sortByDesc('updated_at')->values();
        $perPage = (int) ($validated['per_page'] ?? 20);
        $page = max(1, (int) $request->input('page', 1));
        $evaluations = new LengthAwarePaginator(
            $eligible->forPage($page, $perPage)->values(),
            $eligible->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحميل المشروعات المتاحة',
            'data' => [
                'items' => collect($evaluations->items())->map(function (UserProjectEvaluation $evaluation) {
                    $project = $evaluation->project;
                    $section = $project?->section;

                    return [
                        'project_id' => $project?->id,
                        'course_section_id' => $section?->id,
                        'title' => $section?->title,
                        'requirements' => $project?->requirements_text,
                        'course' => $section?->course ? [
                            'id' => $section->course->id,
                            'title' => $section->course->name_ar,
                            'title_en' => $section->course->name_en,
                            'image' => $section->course->image,
                        ] : null,
                        'module' => $section?->module ? [
                            'id' => $section->module->id,
                            'title' => $section->module->title,
                            'order' => $section->module->order,
                        ] : null,
                        'score' => data_get($evaluation->evaluation_data, 'assessment_type') === 'human_review'
                            ? $evaluation->score
                            : null,
                        'assessment_type' => data_get($evaluation->evaluation_data, 'assessment_type', 'legacy'),
                        'skill_verified' => (bool) data_get($evaluation->evaluation_data, 'skill_verified', false),
                        'passed_at' => $evaluation->updated_at,
                    ];
                })->values(),
                'pagination' => [
                    'current_page' => $evaluations->currentPage(),
                    'last_page' => $evaluations->lastPage(),
                    'per_page' => $evaluations->perPage(),
                    'total' => $evaluations->total(),
                ],
            ],
        ]);
    }

    /**
     * Store a new portfolio item.
     */
    public function store(Request $request): JsonResponse
    {
        $this->normalizePortfolioInput($request);
        if (!$request->filled('client_request_id')) {
            $candidate = trim((string) $request->header('Idempotency-Key'));
            $request->merge([
                'client_request_id' => Str::isUuid($candidate)
                    ? $candidate
                    : (string) Str::uuid(),
            ]);
        }
        $request->validate([
            'client_request_id' => 'required|uuid',
            'title' => 'nullable|required_without:source_project_id|string|max:255',
            'description' => 'nullable|string',
            'course_id' => 'nullable|integer|exists:courses,id',
            'source_project_id' => 'nullable|integer|exists:projects,id',
            'role' => 'nullable|string|max:255',
            'tools' => 'nullable|array|max:20',
            'tools.*' => 'string|max:80',
            'external_url' => ['nullable', 'string', 'max:2000', SafeExternalUrl::validationRule()],
            'completed_at' => 'nullable|date',
            'is_featured' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:10000',
            'expected_media_count' => 'nullable|integer|min:0|max:12',
            // Creation is intentionally metadata-first. One legacy cover is
            // accepted for rolling clients; the current app uploads every
            // file through the per-media idempotent endpoint.
            'files' => 'nullable|array|max:1',
            'files.*' => [
                'file',
                'min:1',
                'max:51200',
                'mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/quicktime,video/webm',
            ],
            'file_types' => 'nullable|array',
            'file_types.*' => 'in:image,video',
        ]);

        $user = auth('api')->user();
        $courseId = $request->filled('course_id') ? $request->integer('course_id') : null;
        $requestedSourceProjectId = $request->filled('source_project_id')
            ? $request->integer('source_project_id')
            : null;
        $currentSourceProjectId = $requestedSourceProjectId
            ? $this->currentProjectId($requestedSourceProjectId)
            : null;
        $sourceProject = null;
        $equivalentSourceProjectIds = $currentSourceProjectId
            ? $this->logicalProjectIds($currentSourceProjectId)
            : [];
        $files = $request->hasFile('files') ? $request->file('files') : [];
        $fileTypes = $request->input('file_types', []);
        $fileFingerprints = [];
        foreach ($files as $index => $file) {
            $fileType = (string) ($fileTypes[$index] ?? 'image');
            $this->assertMediaMatchesType($file, $fileType, "files.{$index}");
            $fileFingerprints[] = $this->uploadedFileFingerprint($file, $fileType);
        }
        if (count($fileFingerprints) !== count(array_unique(array_map(
            static fn (array $file): string => $file['sha256'] . ':' . $file['file_type'],
            $fileFingerprints
        )))) {
            throw ValidationException::withMessages([
                'files' => ['The same media file was selected more than once.'],
            ]);
        }

        $requestFingerprint = hash('sha256', json_encode([
            'title' => trim((string) $request->input('title')),
            'description' => trim((string) $request->input('description')),
            'course_id' => $courseId,
            'source_project_id' => $request->filled('source_project_id')
                ? $request->integer('source_project_id')
                : null,
            'role' => trim((string) $request->input('role')),
            'tools' => array_values((array) $request->input('tools', [])),
            'external_url' => trim((string) $request->input('external_url')),
            'completed_at' => $request->input('completed_at'),
            'is_featured' => $request->boolean('is_featured'),
            'sort_order' => $request->integer('sort_order', 0),
            'expected_media_count' => $request->integer(
                'expected_media_count',
                count($fileFingerprints)
            ),
            'files' => $fileFingerprints,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $clientRequestId = $request->string('client_request_id')->toString();
        try {
            return Cache::lock(
                'portfolio-item-create:' . $user->id . ':' . strtolower($clientRequestId),
                3900
            )->block(10, function () use (
                $user,
                $request,
                $requestFingerprint,
                $courseId,
                $requestedSourceProjectId,
                $currentSourceProjectId,
                $sourceProject,
                $equivalentSourceProjectIds,
                $files,
                $fileTypes,
                $fileFingerprints
            ): JsonResponse {
        $existingRequest = $user->portfolioItems()
            ->with(['mediaFiles', 'course'])
            ->where('client_request_id', $request->string('client_request_id')->toString())
            ->first();
        if ($existingRequest) {
            abort_unless(
                hash_equals((string) $existingRequest->request_fingerprint, $requestFingerprint),
                409
            );
            return $this->createdItemResponse($existingRequest, true);
        }

        if ($currentSourceProjectId) {
            if ($user->portfolioItems()
                ->whereIn('source_project_id', $equivalentSourceProjectIds)
                ->exists()) {
                throw ValidationException::withMessages([
                    'source_project_id' => ['This project is already in your portfolio.'],
                ]);
            }

            $sourceProject = $this->currentPublishedProject($currentSourceProjectId);
            $hasPassed = $this->revisionReads->passedProjectIds(
                (int) $user->id,
                [$currentSourceProjectId]
            )->contains($currentSourceProjectId);
            if (!$hasPassed || !$sourceProject->section) {
                throw ValidationException::withMessages([
                    'source_project_id' => ['Only a passed project can be added to the portfolio.'],
                ]);
            }

            $projectCourseId = (int) $sourceProject->section->course_id;
            if ($courseId && $courseId !== $projectCourseId) {
                throw ValidationException::withMessages([
                    'course_id' => ['The course does not match the selected project.'],
                ]);
            }
            $courseId = $projectCourseId;
        } elseif ($courseId && !$this->courseAccess->hasLearningAccess(
            (int) $user->id,
            $courseId
        )) {
            throw ValidationException::withMessages([
                'course_id' => ['Only one of your courses can be linked to this portfolio item.'],
            ]);
        }

        $itemTitle = trim((string) $request->input('title'));
        if ($itemTitle === '') {
            $itemTitle = trim((string) ($sourceProject?->section?->title ?: 'مشروع تطبيقي'));
        }
        $itemDescription = $request->filled('description')
            ? (string) $request->input('description')
            : (string) ($sourceProject?->requirements_text ?? '');

        // Remote uploads happen before the database write. Every uploaded
        // artifact is tracked and removed if a later upload or the atomic DB
        // write fails, so a learner can never receive a successful but empty
        // project or become trapped by source_project_id on retry.
        $uploadedMedia = [];
        $replayedAfterUpload = false;
        try {
            foreach ($files as $index => $file) {
                $fileType = $fileTypes[$index] ?? 'image';
                $mediaRequestId = $this->portfolioMediaRequestId(
                    $request->string('client_request_id')->toString(),
                    (int) $index,
                    (string) $fileFingerprints[$index]['sha256']
                );
                if ($fileType === 'video') {
                    $filePath = $this->bunnyService->uploadVerifiedVideo(
                        $itemTitle,
                        $file,
                        null,
                        $mediaRequestId
                    );
                    if (!$filePath) throw new UnexpectedValueException('Bunny video upload failed.');
                    $uploadedMedia[] = [
                        'file_path' => $filePath,
                        'file_type' => 'video',
                        'sort_order' => $index,
                        'content_sha256' => $fileFingerprints[$index]['sha256'],
                        'mime_type' => $fileFingerprints[$index]['mime'],
                        'size_bytes' => $fileFingerprints[$index]['size'],
                        'original_name' => $this->safeOriginalName($file->getClientOriginalName()),
                    ];
                } else {
                    $filePath = $this->bunnyService->uploadFileToStorage(
                        $file,
                        'portfolio',
                        $mediaRequestId,
                        'portfolio_upload_unpublished'
                    );
                    if (!$filePath) {
                        throw new UnexpectedValueException('Bunny image upload failed.');
                    }
                    $uploadedMedia[] = [
                        'file_path' => $filePath,
                        'file_type' => 'image',
                        'sort_order' => $index,
                        'content_sha256' => $fileFingerprints[$index]['sha256'],
                        'mime_type' => $fileFingerprints[$index]['mime'],
                        'size_bytes' => $fileFingerprints[$index]['size'],
                        'original_name' => $this->safeOriginalName($file->getClientOriginalName()),
                    ];
                }
            }

            $item = DB::transaction(function () use (
                $user,
                $request,
                $courseId,
                $uploadedMedia,
                $itemTitle,
                $itemDescription,
                $requestFingerprint,
                $requestedSourceProjectId,
                &$replayedAfterUpload
            ) {
                $lockedUser = User::query()
                    ->whereKey($user->id)
                    ->lockForUpdate()
                    ->first();
                abort_if(!$lockedUser, 404);

                $existing = $lockedUser->portfolioItems()
                    ->with(['mediaFiles', 'course'])
                    ->where('client_request_id', $request->string('client_request_id')->toString())
                    ->first();
                if ($existing) {
                    abort_unless(
                        hash_equals((string) $existing->request_fingerprint, $requestFingerprint),
                        409
                    );
                    $replayedAfterUpload = true;
                    return $existing;
                }

                // A publish may finish while remote media is uploading. Resolve
                // the project again under the learner lock so the new item never
                // stores an archived graph ID or a project removed by that publish.
                $lockedCurrentSourceProjectId = $requestedSourceProjectId
                    ? $this->currentProjectId($requestedSourceProjectId)
                    : null;
                $currentEquivalentSourceProjectIds = $lockedCurrentSourceProjectId
                    ? $this->logicalProjectIds($lockedCurrentSourceProjectId)
                    : [];
                if ($currentEquivalentSourceProjectIds !== [] && $lockedUser->portfolioItems()
                    ->whereIn('source_project_id', $currentEquivalentSourceProjectIds)->exists()) {
                    throw ValidationException::withMessages([
                        'source_project_id' => ['This project is already in your portfolio.'],
                    ]);
                }

                $lockedCourseId = $courseId;
                if ($lockedCurrentSourceProjectId) {
                    $lockedSourceProject = $this->currentPublishedProject($lockedCurrentSourceProjectId);
                    $lockedCourse = Course::query()
                        ->whereKey($lockedSourceProject->section->course_id)
                        ->lockForUpdate()
                        ->firstOrFail();
                    // Publication takes the same canonical course lock. Resolve
                    // once more after acquiring it; from here until commit the
                    // selected project cannot be archived underneath this row.
                    $lockedCurrentSourceProjectId = $this->currentProjectId(
                        $requestedSourceProjectId
                    );
                    $lockedSourceProject = $this->currentPublishedProject(
                        $lockedCurrentSourceProjectId
                    );
                    abort_unless(
                        (int) $lockedSourceProject->section->course_id === (int) $lockedCourse->id,
                        409
                    );
                    $hasPassed = $this->revisionReads->passedProjectIds(
                        (int) $lockedUser->id,
                        [$lockedCurrentSourceProjectId]
                    )->contains($lockedCurrentSourceProjectId);
                    if (!$hasPassed || !$lockedSourceProject->section) {
                        throw ValidationException::withMessages([
                            'source_project_id' => ['Only a passed project can be added to the portfolio.'],
                        ]);
                    }
                    $projectCourseId = (int) $lockedSourceProject->section->course_id;
                    if ($lockedCourseId && $lockedCourseId !== $projectCourseId) {
                        throw ValidationException::withMessages([
                            'course_id' => ['The course does not match the selected project.'],
                        ]);
                    }
                    $lockedCourseId = $projectCourseId;
                }

                $item = $lockedUser->portfolioItems()->create([
                    'client_request_id' => $request->string('client_request_id')->toString(),
                    'request_fingerprint' => $requestFingerprint,
                    'title' => $itemTitle,
                    'description' => $itemDescription,
                    'course_id' => $lockedCourseId,
                    'source_project_id' => $lockedCurrentSourceProjectId,
                    'slug' => $this->portfolioItemSlug($itemTitle),
                    'role' => $request->input('role'),
                    'tools' => $request->input('tools'),
                    'external_url' => $request->input('external_url'),
                    'completed_at' => $request->input('completed_at'),
                    'is_featured' => $request->boolean('is_featured'),
                    'sort_order' => $request->integer('sort_order', 0),
                    'expected_media_count' => $request->integer(
                        'expected_media_count',
                        count($uploadedMedia)
                    ),
                    'is_public' => $request->integer(
                        'expected_media_count',
                        count($uploadedMedia)
                    ) === 0 || count($uploadedMedia) >= max(
                        1,
                        $request->integer('expected_media_count', count($uploadedMedia))
                    ),
                ]);

                foreach ($uploadedMedia as $media) {
                    $this->consumeUploadedMediaCandidate($media);
                    $item->mediaFiles()->create($media);
                }

                return $item;
            });
        } catch (UnexpectedValueException $exception) {
            $this->cleanupUploadedMedia($uploadedMedia);
            Log::error('Portfolio media upload failed atomically', [
                'user_id' => $user->id,
                'exception' => $exception::class,
            ]);

            return $this->error(
                'تعذر رفع الملف الآن. مشروعك لم يُضف ويمكنك المحاولة مرة أخرى.',
                503
            );
        } catch (Throwable $exception) {
            $this->cleanupUploadedMedia($uploadedMedia);
            throw $exception;
        }

        // A replay uses deterministic per-file provider identities. Its paths
        // can be the exact objects already referenced by the winning rows, so
        // they must never be retired as generic losing uploads.
        $item->load(['mediaFiles', 'course']);

                return $this->createdItemResponse($item, $replayedAfterUpload);
            });
        } catch (LockTimeoutException) {
            return $this->error('جارٍ حفظ هذا المشروع\nحاول بعد قليل', 409);
        }
    }

    private function createdItemResponse(PortfolioItem $item, bool $replayed): JsonResponse
    {
        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تمت إضافة المشروع',
            'data' => new PortfolioItemResource($item),
            'replayed' => $replayed,
        ]);
    }

    /**
     * Show a single portfolio item.
     */
    public function show($id): JsonResponse
    {
        $user = auth('api')->user();
        $item = $user->portfolioItems()
            ->whereNull('deletion_started_at')
            ->withCount('mediaFiles')
            ->with(['mediaFiles', 'course'])
            ->find($id);

        if (!$item) {
            return $this->error('المشروع غير متاح', 404);
        }

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحميل المشروع',
            'data' => new PortfolioItemResource($item),
        ]);
    }

    /**
     * Update a portfolio item.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $this->normalizePortfolioInput($request);
        $request->validate([
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'role' => 'nullable|string|max:255',
            'tools' => 'nullable|array|max:20',
            'tools.*' => 'string|max:80',
            'external_url' => ['nullable', 'string', 'max:2000', SafeExternalUrl::validationRule()],
            'completed_at' => 'nullable|date',
            'is_featured' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:10000',
        ]);

        $user = auth('api')->user();
        $item = DB::transaction(function () use ($user, $id, $request): PortfolioItem {
            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->first();
            abort_if(!$lockedUser, 404);
            $item = $lockedUser->portfolioItems()->lockForUpdate()->find($id);
            abort_if(!$item, 404);
            abort_if($item->deletion_started_at !== null, 409);

            $item->update($request->only([
                'title', 'description', 'role', 'tools', 'external_url', 'completed_at',
                'is_featured', 'sort_order',
            ]) + ($request->filled('title')
                ? ['slug' => $this->portfolioItemSlug(
                    $request->input('title'),
                    (string) $item->slug
                )]
                : []));

            return $item->fresh(['mediaFiles', 'course']);
        }, 3);

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحديث المشروع',
            'data' => new PortfolioItemResource($item),
        ]);
    }

    /**
     * Delete a portfolio item.
     */
    public function destroy($id): JsonResponse
    {
        $user = auth('api')->user();
        $deleted = DB::transaction(function () use ($user, $id): bool {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->first();
            abort_if(!$lockedUser, 404);
            $item = $lockedUser->portfolioItems()->lockForUpdate()->find($id);
            if (!$item) return false;
            $item->forceFill([
                'is_public' => false,
                'deletion_started_at' => now(),
            ])->save();

            foreach ($item->mediaFiles()->lockForUpdate()->get() as $media) {
                $media->forceFill([
                    'deletion_lease_id' => (string) Str::uuid(),
                    'deletion_started_at' => now(),
                ])->save();
                $this->queuePortfolioMediaCleanup($media, 'portfolio_item_deleted');
                $media->delete();
            }
            $item->delete();
            return true;
        }, 3);

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم حذف المشروع',
            'data' => ['already_deleted' => !$deleted],
        ]);
    }

    /**
     * Append a media file to an existing portfolio item.
     */
    public function appendMedia(Request $request, $id): JsonResponse
    {
        if ($request->has('caption')) {
            $request->merge([
                'caption' => UnicodeText::clean($request->input('caption'), false),
            ]);
        }
        $user = auth('api')->user();
        $item = $user->portfolioItems()->find($id);

        if (!$item) {
            return $this->error('المشروع غير متاح', 404);
        }

        if (!$request->filled('client_request_id')) {
            $candidate = trim((string) $request->header('Idempotency-Key'));
            $request->merge([
                'client_request_id' => Str::isUuid($candidate)
                    ? $candidate
                    : (string) Str::uuid(),
            ]);
        }
        $request->validate([
            'client_request_id' => 'required|uuid',
            'file' => [
                'required',
                'file',
                'min:1',
                'max:51200',
                'mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/quicktime,video/webm',
            ],
            'file_type' => 'required|in:image,video',
            'caption' => 'nullable|string|max:255',
        ]);

        $file = $request->file('file');
        $fileType = $request->file_type;
        $this->assertMediaMatchesType($file, $fileType, 'file');
        $fileFingerprint = $this->uploadedFileFingerprint($file, $fileType);
        $requestFingerprint = hash('sha256', json_encode([
            'file' => $fileFingerprint,
            'caption' => trim((string) $request->input('caption')),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $clientRequestId = $request->string('client_request_id')->toString();

        try {
            return Cache::lock(
                'portfolio-media-upload:' . $item->id . ':' . strtolower($clientRequestId),
                3900
            )->block(10, function () use (
                $item,
                $clientRequestId,
                $fileFingerprint,
                $fileType,
                $file,
                $request
            ): JsonResponse {
        $existing = $item->mediaFiles()
            ->where('client_request_id', $clientRequestId)
            ->first();
        if ($existing) {
            abort_unless(
                hash_equals((string) $existing->content_sha256, $fileFingerprint['sha256'])
                && hash_equals(
                    hash('sha256', json_encode([
                        'file' => [
                            'sha256' => $existing->content_sha256,
                            'size' => (int) $existing->size_bytes,
                            'mime' => (string) $existing->mime_type,
                            'file_type' => (string) $existing->file_type,
                        ],
                        'caption' => trim((string) $existing->caption),
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                    $requestFingerprint
                ),
                409
            );
            return $this->mediaResponse($existing, true);
        }
        if ($item->mediaFiles()->where('content_sha256', $fileFingerprint['sha256'])->exists()) {
            throw ValidationException::withMessages([
                'file' => ['This media file is already attached to the project.'],
            ]);
        }
        if (PortfolioVideoUpload::query()
            ->where('portfolio_item_id', $item->id)
            ->where('content_sha256', $fileFingerprint['sha256'])
            ->where('expires_at', '>', now())
            ->whereIn('status', ['allocating', 'pending'])
            ->exists()) {
            throw ValidationException::withMessages([
                'file' => ['This media file is already being uploaded.'],
            ]);
        }
        $reservedVideoSlots = PortfolioVideoUpload::query()
            ->where('portfolio_item_id', $item->id)
            ->where('expires_at', '>', now())
            ->whereIn('status', ['allocating', 'pending'])
            ->count();
        if ($item->mediaFiles()->count() + $reservedVideoSlots >= $this->mediaCapacity($item)) {
            throw ValidationException::withMessages([
                'file' => ['A portfolio project can contain up to 12 media files.'],
            ]);
        }
        $filePath = null;

        if ($fileType === 'video') {
            $filePath = $this->bunnyService->uploadVerifiedVideo(
                $item->title ?? 'Portfolio Video',
                $file,
                null,
                $clientRequestId
            );
            if (!$filePath) return $this->error('تعذّر رفع الفيديو', 503);
        } elseif ($fileType === 'image') {
            // Upload image to Bunny Storage
            $filePath = $this->bunnyService->uploadFileToStorage(
                $file,
                'portfolio',
                $clientRequestId,
                'portfolio_upload_unpublished'
            );
            if (!$filePath) {
                return $this->error('تعذّر رفع الصورة', 500);
            }
        }

        $replayed = false;
        try {
            $media = DB::transaction(function () use (
                $item,
                $clientRequestId,
                $filePath,
                $fileType,
                $fileFingerprint,
                $request,
                $file,
                &$replayed
            ) {
                $lockedUser = User::query()
                    ->whereKey($item->user_id)
                    ->lockForUpdate()
                    ->first();
                abort_if(!$lockedUser, 404);
                $lockedItem = PortfolioItem::query()->lockForUpdate()->findOrFail($item->id);
                abort_unless((int) $lockedItem->user_id === (int) $lockedUser->id, 404);
                abort_if($lockedItem->deletion_started_at !== null, 409);
                $existing = $lockedItem->mediaFiles()
                    ->where('client_request_id', $clientRequestId)
                    ->first();
                if ($existing) {
                    abort_unless(
                        hash_equals((string) $existing->content_sha256, $fileFingerprint['sha256'])
                        && (int) $existing->size_bytes === (int) $fileFingerprint['size']
                        && (string) $existing->mime_type === (string) $fileFingerprint['mime']
                        && (string) $existing->file_type === (string) $fileFingerprint['file_type']
                        && trim((string) $existing->caption) === trim((string) $request->input('caption')),
                        409
                    );
                    $replayed = true;
                    return $existing;
                }
                if ($lockedItem->mediaFiles()
                    ->where('content_sha256', $fileFingerprint['sha256'])
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'file' => ['This media file is already attached to the project.'],
                    ]);
                }
                if (PortfolioVideoUpload::query()
                    ->where('portfolio_item_id', $lockedItem->id)
                    ->where('content_sha256', $fileFingerprint['sha256'])
                    ->where('expires_at', '>', now())
                    ->whereIn('status', ['allocating', 'pending'])
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'file' => ['This media file is already being uploaded.'],
                    ]);
                }
                $mediaCount = $lockedItem->mediaFiles()->count();
                $reservedVideoSlots = PortfolioVideoUpload::query()
                    ->where('portfolio_item_id', $lockedItem->id)
                    ->where('expires_at', '>', now())
                    ->whereIn('status', ['allocating', 'pending'])
                    ->count();
                if ($mediaCount + $reservedVideoSlots >= $this->mediaCapacity($lockedItem)) {
                    throw ValidationException::withMessages([
                        'file' => ['A portfolio project can contain up to 12 media files.'],
                    ]);
                }
                $maxSortOrder = $lockedItem->mediaFiles()->max('sort_order') ?? -1;
                $media = $lockedItem->mediaFiles()->create([
                    'client_request_id' => $clientRequestId,
                    'file_path' => $filePath,
                    'file_type' => $fileType,
                    'content_sha256' => $fileFingerprint['sha256'],
                    'mime_type' => $fileFingerprint['mime'],
                    'size_bytes' => $fileFingerprint['size'],
                    'original_name' => $this->safeOriginalName($file->getClientOriginalName()),
                    'sort_order' => $maxSortOrder + 1,
                    'caption' => $request->input('caption'),
                ]);
                $this->consumeUploadedMediaCandidate([
                    'file_path' => $filePath,
                    'file_type' => $fileType,
                ]);
                $uploadedCount = $lockedItem->mediaFiles()->count();
                $expectedCount = max(0, (int) $lockedItem->expected_media_count);
                if (
                    !$lockedItem->is_public
                    && $uploadedCount >= max(1, $expectedCount)
                ) {
                    $lockedItem->forceFill(['is_public' => true])->save();
                } else {
                    $lockedItem->touch();
                }
                return $media;
            });
        } catch (Throwable $exception) {
            $this->cleanupUploadedMedia([[
                'file_path' => $filePath,
                'file_type' => $fileType,
            ]]);

            throw $exception;
        }

        if ($replayed) {
            $this->cleanupUploadedMedia([[
                'file_path' => $filePath,
                'file_type' => $fileType,
            ]]);
        }

                return $this->mediaResponse($media, $replayed);
            });
        } catch (LockTimeoutException) {
            return $this->error('جارٍ رفع هذا الملف\nحاول بعد قليل', 409);
        }
    }

    public function issueVideoUpload(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'size' => ['required', 'integer', 'min:1', 'max:' . PortfolioVideoUploadService::MAX_BYTES],
            'mime' => ['required', Rule::in(PortfolioVideoUploadService::MIMES)],
            'original_name' => ['required', 'string', 'max:255'],
            'sha256' => ['required', 'regex:/^[a-f0-9]{64}$/'],
        ]);
        /** @var User $user */
        $user = auth('api')->user();
        $item = $user->portfolioItems()->whereNull('deletion_started_at')->findOrFail($id);

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تجهيز رفع الفيديو',
            'data' => $this->videoUploads->issue(
                $user,
                $item,
                (string) $validated['idempotency_key'],
                (int) $validated['size'],
                (string) $validated['mime'],
                (string) $validated['original_name'],
                (string) $validated['sha256']
            ),
        ]);
    }

    public function renewVideoUpload(Request $request, $id): JsonResponse
    {
        $validated = $request->validate(['claim' => ['required', 'string', 'max:4096']]);
        /** @var User $user */
        $user = auth('api')->user();
        abort_unless($user->portfolioItems()->whereNull('deletion_started_at')->whereKey($id)->exists(), 404);

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تجديد رابط الرفع',
            'data' => $this->videoUploads->renew($user, (int) $id, (string) $validated['claim']),
        ]);
    }

    public function claimVideoUpload(Request $request, $id): JsonResponse
    {
        if ($request->has('caption')) {
            $request->merge(['caption' => UnicodeText::clean($request->input('caption'), false)]);
        }
        $validated = $request->validate([
            'claim' => ['required', 'string', 'max:4096'],
            'caption' => ['nullable', 'string', 'max:255'],
        ]);
        /** @var User $user */
        $user = auth('api')->user();
        abort_unless($user->portfolioItems()->whereNull('deletion_started_at')->whereKey($id)->exists(), 404);
        $media = $this->videoUploads->attach(
            $user,
            (int) $id,
            (string) $validated['claim'],
            $validated['caption'] ?? null
        );

        return $this->mediaResponse($media, $media->wasRecentlyCreated === false);
    }

    private function mediaCapacity(PortfolioItem $item): int
    {
        $expected = max(0, (int) $item->expected_media_count);
        return !$item->is_public && $expected > 0
            ? min(self::MAX_MEDIA_PER_ITEM, $expected)
            : self::MAX_MEDIA_PER_ITEM;
    }

    private function mediaResponse($media, bool $replayed): JsonResponse
    {
        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تمت إضافة الملف',
            'data' => new PortfolioMediaResource($media),
            'replayed' => $replayed,
        ]);
    }

    /** Publish an intentionally shortened upload after at least one file landed. */
    public function finalize($id): JsonResponse
    {
        $user = auth('api')->user();
        $item = DB::transaction(function () use ($user, $id): PortfolioItem {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->first();
            abort_if(!$lockedUser, 404);
            $item = $lockedUser->portfolioItems()->lockForUpdate()->find($id);
            abort_if(!$item || $item->deletion_started_at, 404);
            $uploadedCount = $item->mediaFiles()->count();
            abort_if($uploadedCount < 1 && (int) $item->expected_media_count > 0, 409);
            $item->forceFill([
                'expected_media_count' => $uploadedCount,
                'is_public' => true,
            ])->save();
            return $item->fresh(['mediaFiles', 'course'])->loadCount('mediaFiles');
        }, 3);

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم نشر المشروع',
            'data' => new PortfolioItemResource($item),
        ]);
    }

    /**
     * Delete a media file from a portfolio item.
     */
    public function deleteMedia($id, $mediaId): JsonResponse
    {
        $user = auth('api')->user();
        $deleted = DB::transaction(function () use ($user, $id, $mediaId): bool {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->first();
            abort_if(!$lockedUser, 404);
            $item = $lockedUser->portfolioItems()->lockForUpdate()->find($id);
            if (!$item || $item->deletion_started_at) return false;
            $media = $item->mediaFiles()->lockForUpdate()->find($mediaId);
            if (!$media) return false;
            $media->forceFill([
                'deletion_lease_id' => (string) Str::uuid(),
                'deletion_started_at' => now(),
            ])->save();
            $this->queuePortfolioMediaCleanup($media, 'portfolio_media_deleted');
            $media->delete();
            if (!$item->mediaFiles()->exists() && $item->expected_media_count > 0) {
                $item->forceFill(['is_public' => false])->save();
            }
            return true;
        }, 3);

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم حذف الملف',
            'data' => ['already_deleted' => !$deleted],
        ]);
    }

    public function profile(): JsonResponse
    {
        $user = auth('api')->user();

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحميل بيانات المعرض',
            'data' => $this->profilePayload($user),
        ]);
    }

    private function queuePortfolioMediaCleanup($media, string $reason): void
    {
        if ($media->file_type === 'video' && $media->file_path) {
            $candidate = $this->bunnyService->queueVideoCleanup(
                $media->file_path,
                null,
                $reason,
                1,
                false
            );
            if (!$candidate) {
                throw new \RuntimeException('Unable to persist portfolio video cleanup.');
            }
            return;
        }
        if ($media->file_type === 'image' && $media->file_path) {
            if (!$this->bunnyService->queueStorageCleanup($media->file_path, $reason)) {
                throw new \RuntimeException('Unable to persist portfolio image cleanup.');
            }
        }
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = auth('api')->user();
        foreach (['portfolio_headline', 'portfolio_location'] as $field) {
            if ($request->has($field)) {
                $request->merge([$field => UnicodeText::clean($request->input($field), false)]);
            }
        }
        if ($request->has('portfolio_slug')) {
            $request->merge([
                'portfolio_slug' => Str::slug(
                    strtolower(UnicodeText::identifier($request->input('portfolio_slug')))
                ),
            ]);
        }
        if (is_array($request->input('portfolio_skills'))) {
            $request->merge([
                'portfolio_skills' => array_map(
                    static fn ($skill): string => UnicodeText::clean($skill, false),
                    $request->input('portfolio_skills')
                ),
            ]);
        }
        if (is_array($request->input('portfolio_links'))) {
            $request->merge([
                'portfolio_links' => array_map(static function ($link): array {
                    if (!is_array($link)) return [];
                    if (array_key_exists('label', $link)) {
                        $link['label'] = UnicodeText::clean($link['label'], false);
                    }
                    return $link;
                }, $request->input('portfolio_links')),
            ]);
        }
        $validated = $request->validate([
            'portfolio_slug' => [
                // Rolling clients may still send the former editable alias.
                // It is ignored because the server owns the unlisted token.
                'sometimes', 'nullable', 'string', 'max:60',
            ],
            'portfolio_headline' => 'nullable|string|max:160',
            'portfolio_location' => 'nullable|string|max:120',
            'portfolio_skills' => 'nullable|array|max:30',
            'portfolio_skills.*' => 'string|max:80',
            'portfolio_links' => 'nullable|array|max:10',
            'portfolio_links.*.label' => 'required_with:portfolio_links|string|max:40',
            'portfolio_links.*.url' => [
                'required_with:portfolio_links',
                'string',
                'max:2000',
                SafeExternalUrl::validationRule(),
            ],
        ]);

        unset($validated['portfolio_slug']);
        $user = DB::transaction(function () use ($user, $validated): User {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked->update($validated);

            return $locked->fresh();
        });

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحديث بيانات المعرض',
            'data' => $this->profilePayload($user->fresh()),
        ]);
    }

    private function profilePayload($user): array
    {
        $slug = $this->portfolioShares->ensure($user);
        return [
            'slug' => $slug,
            'share_mode' => 'unlisted',
            // Backward-compatible alias for app builds that used this value to
            // decide whether the share button should be visible.
            'is_public' => true,
            'headline' => $user->portfolio_headline,
            'location' => $user->portfolio_location,
            'skills' => $user->portfolio_skills ?? [],
            'links' => collect($user->portfolio_links ?? [])
                ->map(function ($link): ?array {
                    $safeUrl = SafeExternalUrl::sanitize($link['url'] ?? null);
                    if (!$safeUrl) {
                        return null;
                    }

                    return [
                        'label' => (string) ($link['label'] ?? ''),
                        'url' => $safeUrl,
                    ];
                })
                ->filter()
                ->values()
                ->all(),
            'public_url' => RoknPublicUrl::portfolio($slug),
        ];
    }

    /** @param array<int, array{file_path:string,file_type:string}> $uploadedMedia */
    private function cleanupUploadedMedia(array $uploadedMedia): void
    {
        foreach (array_reverse($uploadedMedia) as $media) {
            // A replay can observe an object that another request has already
            // published. Never delete synchronously: the cleanup workers check
            // the live reference graph before retiring the queued generation.
            if ($media['file_type'] === 'video') {
                $this->bunnyService->queueVideoCleanup(
                    $media['file_path'],
                    null,
                    'portfolio_rollback',
                    1,
                    false
                );
            } else {
                $this->bunnyService->queueStorageCleanup(
                    $media['file_path'],
                    'portfolio_rollback',
                    5
                );
            }
        }
    }

    /** Consume the staged cleanup lease beside the reference created by this transaction. */
    private function consumeUploadedMediaCandidate(array $media): void
    {
        if (($media['file_type'] ?? null) === 'video') {
            $this->bunnyService->consumeVideoCleanupCandidate((string) ($media['file_path'] ?? ''));
            return;
        }

        $this->bunnyService->consumeStorageCleanupCandidate((string) ($media['file_path'] ?? ''));
    }

    private function assertMediaMatchesType($file, string $fileType, string $field): void
    {
        $mimeType = (string) $file->getMimeType();
        $matches = $fileType === 'image'
            ? in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)
            : in_array($mimeType, ['video/mp4', 'video/quicktime', 'video/webm'], true);

        if (!$matches) {
            throw ValidationException::withMessages([
                $field => ['The uploaded file does not match its selected media type.'],
            ]);
        }

        if ($fileType === 'image') {
            $dimensions = @getimagesize($file->getRealPath());
            $width = (int) ($dimensions[0] ?? 0);
            $height = (int) ($dimensions[1] ?? 0);
            if ($dimensions === false
                || $width < 2
                || $height < 2
                || ($height > 0 && $width > intdiv(40000000, $height))) {
                throw ValidationException::withMessages([
                    $field => ['The selected image is damaged or has unsafe dimensions.'],
                ]);
            }
        }
    }

    /** @return array{sha256:string,size:int,mime:string,file_type:string} */
    private function uploadedFileFingerprint($file, string $fileType): array
    {
        $path = (string) $file->getRealPath();
        $sha256 = $path !== '' ? hash_file('sha256', $path) : false;
        $size = (int) $file->getSize();
        if (!$sha256 || $size <= 0) {
            throw ValidationException::withMessages([
                'file' => ['The selected media file is empty or could not be read completely.'],
            ]);
        }

        return [
            'sha256' => $sha256,
            'size' => $size,
            'mime' => strtolower((string) $file->getMimeType()),
            'file_type' => $fileType,
        ];
    }

    private function safeOriginalName(?string $name): string
    {
        return DownloadFilename::safe($name, 'portfolio-file');
    }

    private function portfolioMediaRequestId(string $parentRequestId, int $index, string $sha256): string
    {
        $hex = sha1(strtolower($parentRequestId) . '|' . $index . '|' . strtolower($sha256));
        return substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . '5' . substr($hex, 13, 3) . '-'
            . dechex((hexdec($hex[16]) & 0x3) | 0x8) . substr($hex, 17, 3) . '-'
            . substr($hex, 20, 12);
    }

    private function normalizePortfolioInput(Request $request): void
    {
        foreach (['title', 'role'] as $field) {
            if ($request->has($field)) {
                $request->merge([$field => UnicodeText::clean($request->input($field), false)]);
            }
        }
        if ($request->has('description')) {
            $request->merge([
                'description' => UnicodeText::clean($request->input('description')),
            ]);
        }
        if (is_array($request->input('tools'))) {
            $request->merge([
                'tools' => array_map(
                    static fn ($tool): string => UnicodeText::clean($tool, false),
                    $request->input('tools')
                ),
            ]);
        }
    }

    private function portfolioItemSlug(mixed $title, string $fallback = ''): string
    {
        $slug = Str::slug(UnicodeText::clean($title, false));
        if ($slug !== '') return $slug;
        if ($fallback !== '') return $fallback;

        return 'item-' . Str::lower((string) Str::uuid());
    }

    /** @return list<int> */
    private function logicalProjectIds(int $projectId): array
    {
        $currentProjectId = $this->stagedAuthoring->currentLearnerEntityMap(
            Project::class,
            [$projectId]
        )[$projectId] ?? $projectId;

        return $this->stagedAuthoring->equivalentEntityIds(
            Project::class,
            (int) $currentProjectId
        );
    }

    private function currentProjectId(int $projectId): int
    {
        return (int) ($this->stagedAuthoring->currentLearnerEntityMap(
            Project::class,
            [$projectId]
        )[$projectId] ?? $projectId);
    }

    private function currentPublishedProject(int $projectId): Project
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $projectId = $this->currentProjectId($projectId);
            $project = Project::query()
                ->whereKey($projectId)
                ->whereHas('section.course', fn ($courses) => $courses
                    ->where('is_coming_soon', false)
                    ->whereNull('parent_id'))
                ->with('section')
                ->first();
            if ($project) return $project;
        }

        throw (new ModelNotFoundException())->setModel(Project::class, [$projectId]);
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
