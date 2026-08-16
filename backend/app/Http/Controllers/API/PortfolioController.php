<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\PortfolioItemResource;
use App\Http\Resources\PortfolioMediaResource;
use App\Models\CourseEnrollment;
use App\Models\Project;
use App\Models\UserProjectEvaluation;
use App\Services\BunnyService;
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

final class PortfolioController extends Controller
{
    public function __construct(private BunnyService $bunnyService)
    {
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
            'status' => true,
            'success' => true,
            'message' => 'Portfolio items retrieved successfully',
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
            'status' => true,
            'success' => true,
            'message' => 'Eligible portfolio projects retrieved successfully',
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
        $request->validate([
            'title' => 'nullable|required_without:source_project_id|string|max:255',
            'description' => 'nullable|string',
            'course_id' => 'nullable|integer|exists:courses,id',
            'source_project_id' => 'nullable|integer|exists:projects,id',
            'role' => 'nullable|string|max:255',
            'tools' => 'nullable|array|max:20',
            'tools.*' => 'string|max:80',
            'external_url' => ['nullable', 'string', 'max:2000', SafeExternalUrl::validationRule()],
            'completed_at' => 'nullable|date',
            'is_public' => 'nullable|boolean',
            'is_featured' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:10000',
            'files' => 'nullable|array|max:12',
            'files.*' => [
                'file',
                'max:51200',
                'mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/quicktime,video/webm',
            ],
            'file_types' => 'nullable|array',
            'file_types.*' => 'in:image,video',
        ]);

        $user = auth('api')->user();
        $courseId = $request->filled('course_id') ? $request->integer('course_id') : null;

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
        } elseif ($courseId && !CourseEnrollment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->exists()) {
            throw ValidationException::withMessages([
                'course_id' => ['Only one of your courses can be linked to this portfolio item.'],
            ]);
        }

        $files = $request->hasFile('files') ? $request->file('files') : [];
        $fileTypes = $request->input('file_types', []);
        foreach ($files as $index => $file) {
            $this->assertMediaMatchesType(
                $file,
                $fileTypes[$index] ?? 'image',
                "files.{$index}"
            );
        }

        // Remote uploads happen before the database write. Every uploaded
        // artifact is tracked and removed if a later upload or the atomic DB
        // write fails, so a learner can never receive a successful but empty
        // project or become trapped by source_project_id on retry.
        $uploadedMedia = [];
        try {
            foreach ($files as $index => $file) {
                $fileType = $fileTypes[$index] ?? 'image';
                if ($fileType === 'video') {
                    $videoData = $this->bunnyService->createVideo(
                        $request->input('title') ?: 'Portfolio Video'
                    );
                    if (!$videoData || empty($videoData['guid'])) {
                        throw new UnexpectedValueException('Bunny video entry could not be created.');
                    }
                    $uploadedMedia[] = [
                        'file_path' => $videoData['guid'],
                        'file_type' => 'video',
                        'sort_order' => $index,
                    ];
                    if (!$this->bunnyService->uploadVideo($videoData['guid'], $file)) {
                        throw new UnexpectedValueException('Bunny video upload failed.');
                    }
                } else {
                    $filePath = $this->bunnyService->uploadFileToStorage($file, 'portfolio');
                    if (!$filePath) {
                        throw new UnexpectedValueException('Bunny image upload failed.');
                    }
                    $uploadedMedia[] = [
                        'file_path' => $filePath,
                        'file_type' => 'image',
                        'sort_order' => $index,
                    ];
                }
            }

            $item = DB::transaction(function () use (
                $user,
                $request,
                $courseId,
                $uploadedMedia
            ) {
                DB::table('users')->where('id', $user->id)->lockForUpdate()->first();

                if ($request->filled('source_project_id') && $user->portfolioItems()
                    ->where('source_project_id', $request->integer('source_project_id'))
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'source_project_id' => ['This project is already in your portfolio.'],
                    ]);
                }

                $item = $user->portfolioItems()->create([
                    'title' => $request->input('title'),
                    'description' => $request->input('description'),
                    'course_id' => $courseId,
                    'source_project_id' => $request->input('source_project_id'),
                    'slug' => Str::slug($request->input('title') ?: Str::uuid()),
                    'role' => $request->input('role'),
                    'tools' => $request->input('tools'),
                    'external_url' => $request->input('external_url'),
                    'completed_at' => $request->input('completed_at'),
                    'is_public' => $request->boolean(
                        'is_public',
                        (bool) $user->portfolio_is_public
                    ),
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

        $item->load(['mediaFiles', 'course']);

        return response()->json([
            'status' => true,
            'success' => true,
            'message' => 'Portfolio item created successfully',
            'data' => new PortfolioItemResource($item),
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
            return $this->error('Item not found', 404);
        }

        return response()->json([
            'status' => true,
            'success' => true,
            'message' => 'Portfolio item retrieved successfully',
            'data' => new PortfolioItemResource($item),
        ]);
    }

    /**
     * Update a portfolio item.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $user = auth('api')->user();
        $item = $user->portfolioItems()->find($id);

        if (!$item) {
            return $this->error('Item not found', 404);
        }

        $request->validate([
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'role' => 'nullable|string|max:255',
            'tools' => 'nullable|array|max:20',
            'tools.*' => 'string|max:80',
            'external_url' => ['nullable', 'string', 'max:2000', SafeExternalUrl::validationRule()],
            'completed_at' => 'nullable|date',
            'is_public' => 'nullable|boolean',
            'is_featured' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:10000',
        ]);

        $item->update($request->only([
            'title', 'description', 'role', 'tools', 'external_url', 'completed_at',
            'is_public', 'is_featured', 'sort_order',
        ]) + ($request->filled('title') ? ['slug' => Str::slug($request->input('title'))] : []));

        $item->load(['mediaFiles', 'course']);

        return response()->json([
            'status' => true,
            'success' => true,
            'message' => 'Portfolio item updated successfully',
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
            return $this->error('Item not found', 404);
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
                return $this->error('Media cleanup is temporarily unavailable', 503);
            }
        }

        $item->delete();

        return response()->json([
            'status' => true,
            'success' => true,
            'message' => 'Portfolio item deleted successfully',
            'data' => null,
        ]);
    }

    /**
     * Append a media file to an existing portfolio item.
     */
    public function appendMedia(Request $request, $id): JsonResponse
    {
        $user = auth('api')->user();
        $item = $user->portfolioItems()->find($id);

        if (!$item) {
            return $this->error('Item not found', 404);
        }

        $request->validate([
            'file' => [
                'required',
                'file',
                'max:51200',
                'mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/quicktime,video/webm',
            ],
            'file_type' => 'required|in:image,video',
            'caption' => 'nullable|string|max:255',
        ]);

        $file = $request->file('file');
        $fileType = $request->file_type;
        $this->assertMediaMatchesType($file, $fileType, 'file');
        $filePath = null;

        if ($fileType === 'video') {
            // Upload video to Bunny Stream
            $videoData = $this->bunnyService->createVideo($item->title ?? 'Portfolio Video');
            if ($videoData) {
                $success = $this->bunnyService->uploadVideo($videoData['guid'], $file);
                if ($success) {
                    $filePath = $videoData['guid'];
                } else {
                    $this->bunnyService->queueVideoCleanup(
                        $videoData['guid'],
                        null,
                        'portfolio_upload_failed',
                        24
                    );
                    return $this->error('Failed to upload video to BunnyCDN', 500);
                }
            } else {
                return $this->error('Failed to create video entry in BunnyCDN', 500);
            }
        } elseif ($fileType === 'image') {
            // Upload image to Bunny Storage
            $filePath = $this->bunnyService->uploadFileToStorage($file, 'portfolio');
            if (!$filePath) {
                return $this->error('Failed to upload image to BunnyCDN', 500);
            }
        }

        try {
            $maxSortOrder = $item->mediaFiles()->max('sort_order') ?? -1;
            $media = $item->mediaFiles()->create([
                'file_path' => $filePath,
                'file_type' => $fileType,
                'sort_order' => $maxSortOrder + 1,
                'caption' => $request->input('caption'),
            ]);
        } catch (Throwable $exception) {
            $this->cleanupUploadedMedia([[
                'file_path' => $filePath,
                'file_type' => $fileType,
            ]]);

            throw $exception;
        }

        return response()->json([
            'status' => true,
            'success' => true,
            'message' => 'Media file added successfully',
            'data' => new PortfolioMediaResource($media),
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
            return $this->error('Item not found', 404);
        }

        $media = $item->mediaFiles()->find($mediaId);

        if (!$media) {
            return $this->error('Media not found', 404);
        }

        if ($media->file_type === 'video' && $media->file_path) {
            $deleted = $this->bunnyService->deleteVideo($media->file_path);
        } elseif ($media->file_type === 'image' && $media->file_path) {
            $deleted = $this->bunnyService->deleteFileFromStorage($media->file_path);
        } else {
            $deleted = true;
        }

        if (!$deleted) {
            return $this->error('Media cleanup is temporarily unavailable', 503);
        }

        $media->delete();

        return response()->json([
            'status' => true,
            'success' => true,
            'message' => 'Media file deleted successfully',
            'data' => null,
        ]);
    }

    public function profile(): JsonResponse
    {
        $user = auth('api')->user();

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'Portfolio profile retrieved successfully',
            'data' => $this->profilePayload($user),
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = auth('api')->user();
        $validated = $request->validate([
            'portfolio_slug' => [
                'sometimes', 'required', 'string', 'min:3', 'max:60', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('users', 'portfolio_slug')->ignore($user->id),
            ],
            'portfolio_is_public' => 'nullable|boolean',
            'publish_existing_items' => 'nullable|boolean',
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
        $publishExisting = (bool) ($validated['publish_existing_items'] ?? false);
        unset($validated['publish_existing_items']);
        $user->update($validated);

        if (array_key_exists('portfolio_is_public', $validated)) {
            if (!(bool) $validated['portfolio_is_public']) {
                // Closing the profile must close every project immediately.
                $user->portfolioItems()->update(['is_public' => false]);
            } elseif ($publishExisting) {
                // This is only reached after the learner confirms the explicit
                // publish prompt in the app.
                $user->portfolioItems()->update(['is_public' => true]);
            }
        }

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'Portfolio profile updated successfully',
            'data' => $this->profilePayload($user->fresh()),
        ]);
    }

    private function profilePayload($user): array
    {
        $slug = $user->portfolio_slug ?: ('student-' . $user->id);
        return [
            'slug' => $slug,
            'is_public' => (bool) $user->portfolio_is_public,
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
            'public_url' => route('portfolio.public', ['slug' => $slug]),
        ];
    }

    /** @param array<int, array{file_path:string,file_type:string,sort_order:int}> $uploadedMedia */
    private function cleanupUploadedMedia(array $uploadedMedia): void
    {
        foreach (array_reverse($uploadedMedia) as $media) {
            $deleted = $media['file_type'] === 'video'
                ? $this->bunnyService->deleteVideo($media['file_path'])
                : $this->bunnyService->deleteFileFromStorage($media['file_path']);
            if (!$deleted) {
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
    }

    private function error(string $message, int $httpStatus): JsonResponse
    {
        return response()->json([
            'status' => false,
            'success' => false,
            'message' => $message,
            'data' => null,
        ], $httpStatus);
    }
}
