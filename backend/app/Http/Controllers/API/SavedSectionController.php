<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\SavedFolderResource;
use App\Http\Resources\SavedLessonResource;
use App\Models\CourseEnrollment;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\SavedFolder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class SavedSectionController extends Controller
{
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
        $latestSaves = DB::table('saved_folder_lessons as saved_memberships')
            ->join('saved_folders as owned_folders', 'owned_folders.id', '=', 'saved_memberships.saved_folder_id')
            ->where('owned_folders.user_id', $user->id)
            ->groupBy('saved_memberships.lesson_id')
            ->selectRaw('saved_memberships.lesson_id, MAX(saved_memberships.created_at) as saved_at');

        $lessons = Lesson::query()
            ->joinSub($latestSaves, 'user_saves', function ($join) {
                $join->on('user_saves.lesson_id', '=', 'lessons.id');
            })
            ->select('lessons.*', 'user_saves.saved_at')
            ->with([
                'course',
                'savedFolders' => fn ($query) => $query
                    ->where('saved_folders.user_id', $user->id)
                    ->orderBy('saved_folders.name'),
            ])
            ->orderByDesc('user_saves.saved_at')
            ->orderByDesc('lessons.id')
            ->paginate($validated['per_page'] ?? 20);

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'Saved lessons retrieved successfully',
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
            $folders = SavedFolder::where('user_id', $user->id)
                                  ->with(['lessons' => function ($q) {
                                      $q->with('course')->orderByPivot('created_at', 'desc')->limit(1);
                                  }])
                                  ->withCount('lessons')
                                  ->orderBy('created_at', 'desc')
                                  ->get();

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'Folders retrieved successfully',
                'data' => SavedFolderResource::collection($folders)
            ]);
        } catch (\Exception $e) {
            report($e);
            return response()->json([
                'status' => 500,
                'success' => false,
                'message' => 'Failed to retrieve folders',
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
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 422,
                    'success' => false,
                    'message' => 'Validation failed',
                    'data' => null,
                    'errors' => $validator->errors(),
                ], 422);
            }

            $user = auth('api')->user();
            $folder = SavedFolder::create([
                'user_id' => $user->id,
                'name' => $request->name,
            ]);

            return response()->json([
                'status' => 201,
                'success' => true,
                'message' => 'Folder created successfully',
                'data' => new SavedFolderResource($folder)
            ], 201);
        } catch (\Exception $e) {
            report($e);
            return response()->json([
                'status' => 500,
                'success' => false,
                'message' => 'Failed to create folder',
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
                                 ->first();

            if (!$folder) {
                return response()->json([
                    'status' => 404,
                    'success' => false,
                    'message' => 'Folder not found',
                    'data' => null,
                ], 404);
            }

            $lessons = $folder->lessons()
                ->with('course')
                ->orderByPivot('created_at', 'desc')
                ->get();

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'Folder retrieved successfully',
                'data' => [
                    'id' => (int)$folder->id,
                    'name' => (string)$folder->name,
                    'created_at' => (string)$folder->created_at,
                    'updated_at' => (string)$folder->updated_at,
                    'lessons' => SavedLessonResource::collection($lessons),
                ]
            ]);
        } catch (\Exception $e) {
            report($e);
            return response()->json([
                'status' => 500,
                'success' => false,
                'message' => 'Failed to retrieve folder',
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
                'message' => 'Folder not found',
                'data' => null,
            ], 404);
        }

        $lessons = $folder->lessons()
            ->with('course')
            ->orderByPivot('created_at', 'desc')
            ->paginate($validated['per_page'] ?? 20);

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'Folder lessons retrieved successfully',
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
        $lesson = Lesson::find($lessonId);

        if (!$lesson) {
            return response()->json([
                'status' => 404,
                'success' => false,
                'message' => 'Lesson not found',
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
            'message' => 'Lesson folders retrieved successfully',
            'data' => [
                'lesson_id' => (int) $lesson->id,
                'is_saved' => $savedFolderIds->isNotEmpty(),
                'folders' => $folders,
            ],
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
            $folder = SavedFolder::where('id', $id)
                                 ->where('user_id', $user->id)
                                 ->first();

            if (!$folder) {
                return response()->json([
                    'status' => 404,
                    'success' => false,
                    'message' => 'Folder not found',
                    'data' => null,
                ], 404);
            }

            $folder->delete();

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'Folder deleted successfully',
                'data' => null,
            ]);
        } catch (\Exception $e) {
            report($e);
            return response()->json([
                'status' => 500,
                'success' => false,
                'message' => 'Failed to delete folder',
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
                    'message' => 'Validation failed',
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
                    'message' => 'Folder not found',
                    'data' => null,
                ], 404);
            }

            $lesson = Lesson::findOrFail($request->integer('lesson_id'));
            $courseId = (int) $lesson->list_id;
            $hasAccess = $courseId > 0 && $this->hasCourseAccess($user->id, $courseId);
            if (!$hasAccess) {
                return response()->json([
                    'status' => 403,
                    'success' => false,
                    'message' => 'Course access is required before saving this lesson',
                    'data' => null,
                ], 403);
            }

            // Check if lesson is already saved in this folder
            if ($folder->lessons()->where('lesson_id', $request->lesson_id)->exists()) {
                return response()->json([
                    'status' => 409,
                    'success' => false,
                    'message' => 'Lesson already saved in this folder',
                    'data' => null,
                ], 409);
            }

            $folder->lessons()->attach($request->lesson_id);

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'Lesson saved successfully',
                'data' => [
                    'lesson_id' => (int) $lesson->id,
                    'folder_id' => (int) $folder->id,
                    'folder_name' => (string) $folder->name,
                    'is_saved' => true,
                ],
            ]);
        } catch (\Exception $e) {
            report($e);
            return response()->json([
                'status' => 500,
                'success' => false,
                'message' => 'Failed to save lesson',
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
                    'message' => 'Folder not found',
                    'data' => null,
                ], 404);
            }

            $folder->lessons()->detach($lessonId);

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'Lesson removed successfully',
                'data' => null,
            ]);
        } catch (\Exception $e) {
            report($e);
            return response()->json([
                'status' => 500,
                'success' => false,
                'message' => 'Failed to remove lesson',
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
                'message' => 'تمت إزالة الخطوة من المحفوظات',
                'data' => ['removed_memberships' => $removed],
            ]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'status' => 500,
                'success' => false,
                'message' => 'تعذر تحديث المحفوظات',
                'data' => null,
            ], 500);
        }
    }

    private function hasCourseAccess(int $userId, int $courseId): bool
    {
        $direct = CourseEnrollment::query()
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();

        if ($direct) {
            return true;
        }

        $parentCourseIds = CourseSection::query()
            ->where('sectionable_type', 'App\\Models\\Course')
            ->where('sectionable_id', $courseId)
            ->pluck('course_id');

        return !$parentCourseIds->isEmpty() && CourseEnrollment::query()
            ->where('user_id', $userId)
            ->whereIn('course_id', $parentCourseIds)
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();
    }
}
