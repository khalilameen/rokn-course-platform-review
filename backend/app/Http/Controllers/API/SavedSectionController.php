<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\SavedFolderResource;
use App\Http\Resources\SavedLessonResource;
use App\Models\Lesson;
use App\Models\SavedFolder;
use App\Services\CourseChatAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class SavedSectionController extends Controller
{
    private const MAX_FOLDERS_PER_USER = 100;
    private const LEGACY_FOLDER_LESSON_LIMIT = 100;

    public function __construct(private readonly CourseChatAccessService $courseAccess) {}

    /**
     * Return a de-duplicated, paginated view of every lesson the current user
     * saved, together with all of its folder memberships.
     */
    public function getSavedLessons(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        $user = auth('api')->user();
        $lessonRelations = $this->lessonRelations();
        $latestSaves = DB::table('saved_folder_lessons as saved_memberships')
            ->join('saved_folders as owned_folders', 'owned_folders.id', '=', 'saved_memberships.saved_folder_id')
            ->where('owned_folders.user_id', $user->id)
            ->groupBy('saved_memberships.lesson_id')
            ->selectRaw('saved_memberships.lesson_id, MAX(saved_memberships.created_at) as saved_at');

        $lessons = Lesson::query()
            ->publishedLearningGraph()
            ->joinSub($latestSaves, 'user_saves', function ($join) {
                $join->on('user_saves.lesson_id', '=', 'lessons.id');
            })
            ->select('lessons.*', 'user_saves.saved_at')
            ->with(array_merge($lessonRelations, [
                'savedFolders' => fn ($query) => $query
                    ->where('saved_folders.user_id', $user->id)
                    ->orderBy('saved_folders.name'),
            ]))
            ->orderByDesc('user_saves.saved_at')
            ->orderByDesc('lessons.id')
            ->paginate($validated['per_page'] ?? 20);

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحميل المحفوظات',
            'data' => [
                'lessons' => SavedLessonResource::collection($lessons->getCollection()),
                'pagination' => [
                    'current_page' => $lessons->currentPage(),
                    'last_page' => $lessons->lastPage(),
                    'per_page' => $lessons->perPage(),
                    'total' => $lessons->total(),
                ],
            ],
        ]);
    }

    /**
     * Get all saved folders for the authenticated user.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getFolders(): JsonResponse
    {
        try {
            $user = auth('api')->user();
            $lessonRelations = $this->lessonRelations();
            $folders = SavedFolder::where('user_id', $user->id)
                                  ->with(['lessons' => function ($q) use ($lessonRelations) {
                                      $q->publishedLearningGraph()
                                          ->with($lessonRelations)
                                          ->orderByPivot('created_at', 'desc')->limit(1);
                                  }])
                                  ->withCount(['lessons' => fn ($q) => $q->publishedLearningGraph()])
                                  ->orderByDesc('created_at')
                                  ->orderByDesc('id')
                                  ->limit(self::MAX_FOLDERS_PER_USER)
                                  ->get();

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'تم تحميل المجلدات',
                'data' => SavedFolderResource::collection($folders)
            ]);
        } catch (\Exception $e) {
            $this->rethrowExpectedRequestException($e);
            report($e);
            return response()->json([
                'status' => 500,
                'success' => false,
                'message' => 'تعذّر تحميل المجلدات',
                'data' => null,
            ], 500);
        }
    }

    /**
     * Create a new saved folder.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function createFolder(Request $request): JsonResponse
    {
        try {
            $requestId = $request->input('client_request_id')
                ?: $request->header('Idempotency-Key')
                ?: (string) Str::uuid();
            $input = $request->all();
            $input['client_request_id'] = $requestId;
            $validator = Validator::make($input, [
                'name' => 'required|string|max:60',
                'client_request_id' => 'required|uuid',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 422,
                    'success' => false,
                    'message' => 'راجع اسم المجلد',
                    'data' => null,
                    'errors' => $validator->errors(),
                ], 422);
            }

            $user = auth('api')->user();
            $name = SavedFolder::cleanName($request->input('name'));
            $normalizedName = SavedFolder::normalizeName($name);
            [$folder, $created, $requestConflict, $limitReached] = DB::transaction(
                function () use ($user, $name, $normalizedName, $requestId): array {
                    // A per-account lock closes double taps and simultaneous
                    // creates from two devices without locking other learners.
                    DB::table('users')->where('id', $user->id)->lockForUpdate()->first();

                    $byRequest = SavedFolder::query()
                        ->where('user_id', $user->id)
                        ->where('client_request_id', $requestId)
                        ->first();
                    if ($byRequest) {
                        return [
                            $byRequest,
                            false,
                            (string) $byRequest->normalized_name !== $normalizedName,
                            false,
                        ];
                    }

                    $existing = SavedFolder::query()
                        ->where('user_id', $user->id)
                        ->where('normalized_name', $normalizedName)
                        ->first();
                    if ($existing) {
                        return [$existing, false, false, false];
                    }

                    if (SavedFolder::query()->where('user_id', $user->id)->count() >= self::MAX_FOLDERS_PER_USER) {
                        return [null, false, false, true];
                    }

                    return [SavedFolder::create([
                        'user_id' => $user->id,
                        'name' => $name,
                        'normalized_name' => $normalizedName,
                        'client_request_id' => $requestId,
                    ]), true, false, false];
                }
            );

            if ($requestConflict) {
                return response()->json([
                    'status' => 409,
                    'success' => false,
                    'message' => "تغيّر المجلد أثناء الحفظ\nأعد المحاولة",
                    'data' => null,
                ], 409);
            }

            if ($limitReached) {
                return response()->json([
                    'status' => 422,
                    'success' => false,
                    'message' => 'وصلت إلى الحد المتاح من المجلدات',
                    'data' => null,
                ], 422);
            }

            return response()->json([
                'status' => $created ? 201 : 200,
                'success' => true,
                'message' => $created ? 'تم إنشاء المجلد' : 'المجلد موجود بالفعل',
                'data' => new SavedFolderResource($folder),
            ], $created ? 201 : 200);
        } catch (\Exception $e) {
            $this->rethrowExpectedRequestException($e);
            report($e);
            return response()->json([
                'status' => 500,
                'success' => false,
                'message' => 'تعذّر إنشاء المجلد',
                'data' => null,
            ], 500);
        }
    }

    /**
     * Get a single saved folder with its lessons.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getFolder($id): JsonResponse
    {
        try {
            $user = auth('api')->user();
            $folder = SavedFolder::where('id', $id)
                                 ->where('user_id', $user->id)
                                 ->withCount(['lessons' => fn ($q) => $q->publishedLearningGraph()])
                                 ->first();

            if (!$folder) {
                return response()->json([
                    'status' => 404,
                    'success' => false,
                    'message' => 'المجلد غير متاح',
                    'data' => null,
                ], 404);
            }

            $lessons = $folder->lessons()
                ->publishedLearningGraph()
                ->with($this->lessonRelations())
                ->orderByPivot('created_at', 'desc')
                ->orderByPivot('id', 'desc')
                ->limit(self::LEGACY_FOLDER_LESSON_LIMIT)
                ->get();

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'تم تحميل المجلد',
                'data' => [
                    'id' => (int)$folder->id,
                    'name' => (string)$folder->name,
                    'created_at' => $folder->created_at?->toIso8601String(),
                    'updated_at' => $folder->updated_at?->toIso8601String(),
                    'lessons' => SavedLessonResource::collection($lessons),
                    'lessons_count' => (int) $folder->lessons_count,
                    'lessons_has_more' => (int) $folder->lessons_count > $lessons->count(),
                    // Keep the historical URL for installed APKs and expose
                    // the canonical contract to clients that understand v1.
                    'lessons_endpoint' => "/api/saved-folders/{$folder->id}/lessons",
                    'canonical_lessons_endpoint' => "/api/v1/saved-folders/{$folder->id}/lessons",
                ]
            ]);
        } catch (\Exception $e) {
            $this->rethrowExpectedRequestException($e);
            report($e);
            return response()->json([
                'status' => 500,
                'success' => false,
                'message' => 'تعذّر تحميل المجلد',
                'data' => null,
            ], 500);
        }
    }

    /**
     * Paginated folder contents for large libraries. The original getFolder
     * response remains unchanged for older app versions.
     */
    public function getFolderLessons(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        $user = auth('api')->user();
        $folder = SavedFolder::query()
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$folder) {
            return response()->json([
                'status' => 404,
                'success' => false,
                'message' => 'المجلد غير متاح',
                'data' => null,
            ], 404);
        }

        $lessons = $folder->lessons()
            ->publishedLearningGraph()
            ->with($this->lessonRelations())
            ->orderByPivot('created_at', 'desc')
            ->orderByPivot('id', 'desc')
            ->paginate($validated['per_page'] ?? 20);

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحميل المقاطع المحفوظة',
            'data' => [
                'folder' => [
                    'id' => (int) $folder->id,
                    'name' => (string) $folder->name,
                ],
                'lessons' => SavedLessonResource::collection($lessons->getCollection()),
                'pagination' => [
                    'current_page' => $lessons->currentPage(),
                    'last_page' => $lessons->lastPage(),
                    'per_page' => $lessons->perPage(),
                    'total' => $lessons->total(),
                ],
            ],
        ]);
    }

    /** Return every list with membership state for the reel save sheet. */
    public function getLessonFolders($lessonId): JsonResponse
    {
        $user = auth('api')->user();
        $lesson = Lesson::query()->publishedLearningGraph()->find($lessonId);

        if (!$lesson) {
            return response()->json([
                'status' => 404,
                'success' => false,
                'message' => 'المقطع غير متاح',
                'data' => null,
            ], 404);
        }

        $savedFolderIds = DB::table('saved_folder_lessons')
            ->join('saved_folders', 'saved_folders.id', '=', 'saved_folder_lessons.saved_folder_id')
            ->where('saved_folders.user_id', $user->id)
            ->where('saved_folder_lessons.lesson_id', $lesson->id)
            ->pluck('saved_folders.id')
            ->map(fn ($folderId) => (int) $folderId)
            ->flip();

        $folders = SavedFolder::query()
            ->where('user_id', $user->id)
            ->withCount('lessons')
            ->latest('updated_at')
            ->latest('id')
            ->limit(self::MAX_FOLDERS_PER_USER)
            ->get()
            ->map(function (SavedFolder $folder) use ($savedFolderIds) {
                return [
                    'id' => (int) $folder->id,
                    'name' => (string) $folder->name,
                    'lessons_count' => (int) $folder->lessons_count,
                    'contains_lesson' => isset($savedFolderIds[$folder->id]),
                    'updated_at' => $folder->updated_at,
                ];
            });

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحميل مجلدات المقطع',
            'data' => [
                'lesson_id' => (int) $lesson->id,
                'is_saved' => $savedFolderIds->isNotEmpty(),
                'folders' => $folders,
            ],
        ]);
    }

    /** Resolve bookmark state for a whole reel feed without one request per lesson. */
    public function getSavedLessonState(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lesson_ids' => 'required|array|min:1|max:200',
            'lesson_ids.*' => 'required|integer|min:1',
        ]);
        $user = auth('api')->user();
        $lessonIds = collect($validated['lesson_ids'])
            ->map(static fn ($id) => (int) $id)
            ->unique()
            ->values();

        $savedLessonIds = DB::table('saved_folder_lessons')
            ->join('saved_folders', 'saved_folders.id', '=', 'saved_folder_lessons.saved_folder_id')
            ->where('saved_folders.user_id', $user->id)
            ->whereIn('saved_folder_lessons.lesson_id', $lessonIds)
            ->distinct()
            ->orderBy('saved_folder_lessons.lesson_id')
            ->pluck('saved_folder_lessons.lesson_id')
            ->map(static fn ($id) => (int) $id)
            ->values();

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحميل حالة الحفظ',
            'data' => ['saved_lesson_ids' => $savedLessonIds],
        ]);
    }

    /**
     * Delete a saved folder.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteFolder($id): JsonResponse
    {
        try {
            $user = auth('api')->user();
            $deleted = SavedFolder::where('id', $id)
                ->where('user_id', $user->id)
                ->delete();

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => $deleted ? 'تم حذف المجلد' : 'المجلد محذوف بالفعل',
                'data' => ['already_deleted' => !$deleted],
            ]);
        } catch (\Exception $e) {
            $this->rethrowExpectedRequestException($e);
            report($e);
            return response()->json([
                'status' => 500,
                'success' => false,
                'message' => 'تعذّر حذف المجلد',
                'data' => null,
            ], 500);
        }
    }

    /**
     * Save a lesson to a folder.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function saveLesson(Request $request, $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'lesson_id' => 'required|exists:lessons,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 422,
                    'success' => false,
                    'message' => 'راجع بيانات الحفظ',
                    'data' => null,
                    'errors' => $validator->errors(),
                ], 422);
            }

            $user = auth('api')->user();
            $folder = SavedFolder::where('id', $id)
                                 ->where('user_id', $user->id)
                                 ->first();

            if (!$folder) {
                return response()->json([
                    'status' => 404,
                    'success' => false,
                    'message' => 'المجلد غير متاح',
                    'data' => null,
                ], 404);
            }

            $lesson = Lesson::with(['course', 'courseSection'])
                ->findOrFail($request->integer('lesson_id'));
            $courseId = (int) $lesson->list_id;
            $course = $lesson->course;
            $section = $lesson->courseSection;
            if (
                !$course
                || !$section
                || !$course->isPublishedForLearning()
                || $course->isNestedCourse()
                || (int) $section->course_id !== $courseId
                || $section->getSectionType() !== 'lesson'
                || (int) $section->sectionable_id !== (int) $lesson->id
            ) {
                return response()->json([
                    'status' => 404,
                    'success' => false,
                    'message' => 'المقطع غير متاح',
                    'data' => null,
                ], 404);
            }
            $hasAccess = $courseId > 0
                && $this->courseAccess->hasLearningAccess((int) $user->id, $courseId);
            // A learner may bookmark the same public sample they watched as a
            // guest. Saving metadata never grants media or course access.
            $isPublicPreview = (bool) $lesson->is_opened;
            if (!$hasAccess && !$isPublicPreview) {
                return response()->json([
                    'status' => 403,
                    'success' => false,
                    'message' => 'افتح الكورس أولًا لحفظ هذا المقطع',
                    'data' => null,
                ], 403);
            }

            // Saving is idempotent: retries and fast double taps must not turn
            // a successful bookmark into an error on the learner's screen.
            $inserted = DB::table('saved_folder_lessons')->insertOrIgnore([
                'saved_folder_id' => (int) $folder->id,
                'lesson_id' => (int) $lesson->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => $inserted ? 'تم حفظ المقطع' : 'المقطع محفوظ بالفعل',
                'data' => [
                    'lesson_id' => (int) $lesson->id,
                    'folder_id' => (int) $folder->id,
                    'folder_name' => (string) $folder->name,
                    'is_saved' => true,
                    'already_saved' => !$inserted,
                ],
            ]);
        } catch (\Exception $e) {
            $this->rethrowExpectedRequestException($e);
            report($e);
            return response()->json([
                'status' => 500,
                'success' => false,
                'message' => 'تعذّر حفظ المقطع',
                'data' => null,
            ], 500);
        }
    }

    /**
     * Remove a lesson from a folder.
     *
     * @param int $id
     * @param int $lessonId
     * @return \Illuminate\Http\JsonResponse
     */
    public function removeLesson($id, $lessonId): JsonResponse
    {
        try {
            $user = auth('api')->user();
            $folder = SavedFolder::where('id', $id)
                                 ->where('user_id', $user->id)
                                 ->first();

            if (!$folder) {
                return response()->json([
                    'status' => 404,
                    'success' => false,
                    'message' => 'المجلد غير متاح',
                    'data' => null,
                ], 404);
            }

            $folder->lessons()->detach($lessonId);

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'تمت إزالة المقطع',
                'data' => null,
            ]);
        } catch (\Exception $e) {
            $this->rethrowExpectedRequestException($e);
            report($e);
            return response()->json([
                'status' => 500,
                'success' => false,
                'message' => 'تعذّرت إزالة المقطع',
                'data' => null,
            ], 500);
        }
    }

    /** Remove a saved lesson from every folder owned by the current user. */
    public function removeLessonEverywhere($lessonId): JsonResponse
    {
        try {
            $user = auth('api')->user();
            $folderIds = SavedFolder::query()
                ->where('user_id', $user->id)
                ->pluck('id');

            $removed = $folderIds->isEmpty()
                ? 0
                : DB::table('saved_folder_lessons')
                    ->whereIn('saved_folder_id', $folderIds)
                    ->where('lesson_id', (int) $lessonId)
                    ->delete();

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'تمت إزالة المقطع من المحفوظات',
                'data' => ['removed_memberships' => $removed],
            ]);
        } catch (\Throwable $e) {
            $this->rethrowExpectedRequestException($e);
            report($e);
            return response()->json([
                'status' => 500,
                'success' => false,
                'message' => 'تعذر تحديث المحفوظات',
                'data' => null,
            ], 500);
        }
    }

    /** @return array<int,string> */
    private function lessonRelations(): array
    {
        $relations = ['course'];
        if (Schema::hasTable('lesson_media_states')) {
            $relations[] = 'mediaState:id,lesson_id,duration_seconds';
        }

        return $relations;
    }

}
