<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Support\RoknPublicUrl;

use App\Http\Controllers\Controller;
use App\Http\Resources\PortfolioItemResource;
use App\Http\Resources\PortfolioMediaResource;
use App\Models\PortfolioItem;
use App\Models\Project;
use App\Models\UserProjectEvaluation;
use App\Services\BunnyService;
use App\Services\CourseChatAccessService;
use App\Services\PortfolioShareIdentityService;
use App\Services\SafeExternalUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;
use UnexpectedValueException;
use App\Support\DownloadFilename;
use App\Support\UnicodeText;

final class PortfolioController extends Controller
{
    public function __construct(
        private BunnyService $bunnyService,
        private CourseChatAccessService $courseAccess,
        private PortfolioShareIdentityService $portfolioShares
    ) {
    }

    /**
     * List user's portfolio items.
     */
    public function index(): JsonResponse
    {
        $user = auth('api')->user();
        $items = $user->portfolioItems()
            ->with(['mediaFiles', 'course'])
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

        $evaluations = UserProjectEvaluation::query()
            ->where('user_id', $user->id)
            ->where('passed', true)
            ->when($usedProjectIds->isNotEmpty(), function ($query) use ($usedProjectIds) {
                $query->whereNotIn('project_id', $usedProjectIds);
            })
            ->whereHas('project.section')
            ->with([
                'project.section.course:id,name_ar,name_en,image',
                'project.section.module:id,course_id,title,title_ar,title_en,order',
            ])
            ->latest('updated_at')
            ->paginate($validated['per_page'] ?? 20);

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
                        'score' => data_get($evaluation->evaluation_data, 'assessment_type') === 'participation'
                            ? null
                            : $evaluation->score,
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
            'files' => 'nullable|array|max:12',
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
        $sourceProject = null;
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
            'files' => $fileFingerprints,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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

        if ($request->filled('source_project_id')) {
            if ($user->portfolioItems()
                ->where('source_project_id', $request->integer('source_project_id'))
                ->exists()) {
                throw ValidationException::withMessages([
                    'source_project_id' => ['This project is already in your portfolio.'],
                ]);
            }

            $sourceProject = Project::with('section')->findOrFail($request->integer('source_project_id'));
            $hasPassed = UserProjectEvaluation::query()
                ->where('user_id', $user->id)
                ->where('project_id', $request->integer('source_project_id'))
                ->where('passed', true)
                ->exists();
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
                if ($fileType === 'video') {
                    $filePath = $this->bunnyService->uploadVerifiedVideo($itemTitle, $file);
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
                    $filePath = $this->bunnyService->uploadFileToStorage($file, 'portfolio');
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
                &$replayedAfterUpload
            ) {
                DB::table('users')->where('id', $user->id)->lockForUpdate()->first();

                $existing = $user->portfolioItems()
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

                if ($request->filled('source_project_id') && $user->portfolioItems()
                    ->where('source_project_id', $request->integer('source_project_id'))
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'source_project_id' => ['This project is already in your portfolio.'],
                    ]);
                }

                $item = $user->portfolioItems()->create([
                    'client_request_id' => $request->string('client_request_id')->toString(),
                    'request_fingerprint' => $requestFingerprint,
                    'title' => $itemTitle,
                    'description' => $itemDescription,
                    'course_id' => $courseId,
                    'source_project_id' => $request->input('source_project_id'),
                    'slug' => $this->portfolioItemSlug($itemTitle),
                    'role' => $request->input('role'),
                    'tools' => $request->input('tools'),
                    'external_url' => $request->input('external_url'),
                    'completed_at' => $request->input('completed_at'),
                    'is_public' => true,
                    'is_featured' => $request->boolean('is_featured'),
                    'sort_order' => $request->integer('sort_order', 0),
                ]);

                foreach ($uploadedMedia as $media) {
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

        if ($replayedAfterUpload) {
            $this->cleanupUploadedMedia($uploadedMedia);
        }
        $item->load(['mediaFiles', 'course']);

        return $this->createdItemResponse($item, $replayedAfterUpload);
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
        $item = $user->portfolioItems()->with(['mediaFiles', 'course'])->find($id);

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
        $user = auth('api')->user();
        $item = $user->portfolioItems()->find($id);

        if (!$item) {
            return $this->error('المشروع غير متاح', 404);
        }

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

        $item->update($request->only([
            'title', 'description', 'role', 'tools', 'external_url', 'completed_at',
            'is_featured', 'sort_order',
        ]) + ($request->filled('title')
            ? ['slug' => $this->portfolioItemSlug($request->input('title'), (string) $item->slug)]
            : []));

        $item->load(['mediaFiles', 'course']);

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
        $item = $user->portfolioItems()->with('mediaFiles')->find($id);

        if (!$item) {
            return $this->error('المشروع غير متاح', 404);
        }

        foreach ($item->mediaFiles as $media) {
            if ($media->file_type === 'video' && $media->file_path) {
                $deleted = $this->bunnyService->deleteVideo($media->file_path);
            } elseif ($media->file_type === 'image' && $media->file_path) {
                $deleted = $this->bunnyService->deleteFileFromStorage($media->file_path);
            } else {
                $deleted = true;
            }

            if (!$deleted) {
                return $this->error('تعذّر حذف ملفات المشروع الآن', 503);
            }

            // Commit each completed remote deletion immediately. If a later
            // artifact is unavailable, the retryable item never advertises a
            // Bunny object that has already gone.
            $media->delete();
        }

        $item->delete();

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم حذف المشروع',
            'data' => null,
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
        $filePath = null;

        if ($fileType === 'video') {
            $filePath = $this->bunnyService->uploadVerifiedVideo(
                $item->title ?? 'Portfolio Video',
                $file
            );
            if (!$filePath) return $this->error('تعذّر رفع الفيديو', 503);
        } elseif ($fileType === 'image') {
            // Upload image to Bunny Storage
            $filePath = $this->bunnyService->uploadFileToStorage($file, 'portfolio');
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
                $lockedItem = PortfolioItem::query()->lockForUpdate()->findOrFail($item->id);
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
                $maxSortOrder = $lockedItem->mediaFiles()->max('sort_order') ?? -1;
                return $lockedItem->mediaFiles()->create([
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

    /**
     * Delete a media file from a portfolio item.
     */
    public function deleteMedia($id, $mediaId): JsonResponse
    {
        $user = auth('api')->user();
        $item = $user->portfolioItems()->find($id);

        if (!$item) {
            return $this->error('المشروع غير متاح', 404);
        }

        $media = $item->mediaFiles()->find($mediaId);

        if (!$media) {
            return $this->error('الملف غير متاح', 404);
        }

        if ($media->file_type === 'video' && $media->file_path) {
            $deleted = $this->bunnyService->deleteVideo($media->file_path);
        } elseif ($media->file_type === 'image' && $media->file_path) {
            $deleted = $this->bunnyService->deleteFileFromStorage($media->file_path);
        } else {
            $deleted = true;
        }

        if (!$deleted) {
            return $this->error('تعذّر حذف الملف الآن', 503);
        }

        $media->delete();

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم حذف الملف',
            'data' => null,
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
                'sometimes', 'required', 'string', 'min:3', 'max:60', 'regex:/^[a-z0-9-]+$/',
                'not_regex:/^student-[1-9][0-9]*$/',
                Rule::unique('users', 'portfolio_slug')->ignore($user->id),
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

        if (array_key_exists('portfolio_slug', $validated)) {
            $validated['portfolio_slug'] = Str::slug((string) $validated['portfolio_slug']);
        }
        $user->update($validated);

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
            $deleted = $media['file_type'] === 'video'
                ? $this->bunnyService->deleteVideo($media['file_path'])
                : $this->bunnyService->deleteFileFromStorage($media['file_path']);
            if (!$deleted) {
                if ($media['file_type'] === 'video') {
                    $this->bunnyService->queueVideoCleanup(
                        $media['file_path'],
                        null,
                        'portfolio_rollback',
                        1,
                        true
                    );
                } else {
                    $this->bunnyService->queueStorageCleanup(
                        $media['file_path'],
                        'portfolio_rollback',
                        5
                    );
                }
                Log::warning('Portfolio rollback could not remove Bunny artifact', [
                    'file_type' => $media['file_type'],
                    'file_path' => $media['file_path'],
                ]);
            }
        }
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
