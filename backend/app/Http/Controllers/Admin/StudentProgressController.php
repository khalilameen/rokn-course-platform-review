<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\StudentSectionProgress;
use App\Models\CourseSection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentProgressController extends Controller
{
    /**
     * Display a listing of students with their progress
     * Shows last enrolled course progress for each user
     *
     * @param Request $request
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function index(Request $request)
    {
        $query = User::where('role', '<>', 'Admin')
            ->OrderByDesc('active');

        // Search functionality
        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('phone', 'LIKE', "%{$search}%");
            });
        }

        // Filter by course enrollment
        if ($request->has('course_id') && !empty($request->course_id)) {
            $query->whereHas('enrollments', function($q) use ($request) {
                $q->where('course_id', $request->course_id);
            });
        }

        $users = $query->paginate(15)->appends($request->query());

        // Get progress data for each user (last enrolled course)
        $usersWithProgress = $users->map(function ($user) {
            $lastEnrollment = CourseEnrollment::where('user_id', $user->id)
                ->where('is_active', true)
                ->with('course')
                ->latest('enrolled_at')
                ->first();

            if (!$lastEnrollment) {
                return [
                    'user' => $user,
                    'has_enrollment' => false,
                    'course' => null,
                    'progress' => null
                ];
            }

            $progressData = $this->calculateCourseProgress($user->id, $lastEnrollment->course_id);

            return [
                'user' => $user,
                'has_enrollment' => true,
                'course' => $lastEnrollment->course,
                'enrolled_at' => $lastEnrollment->enrolled_at,
                'progress' => $progressData
            ];
        });

        // Get courses for filter dropdown
        $courses = Course::select('id', 'name_ar', 'name_en')->get();

        return view('admin.student-progress.index', [
            'usersWithProgress' => $usersWithProgress,
            'users' => $users,
            'courses' => $courses,
            'filters' => $request->all()
        ]);
    }

    /**
     * Show detailed progress for a specific user
     * Displays all enrolled courses with section completion details
     *
     * @param int $userId
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function show($userId)
    {
        $user = User::findOrFail($userId);

        // Get all active enrollments for the user
        $enrollments = CourseEnrollment::where('user_id', $userId)
            ->where('is_active', true)
            ->with('course')
            ->orderBy('enrolled_at', 'desc')
            ->get();

        // Calculate progress for each enrolled course
        $coursesProgress = $enrollments->map(function ($enrollment) use ($userId) {
            $progressData = $this->calculateCourseProgress($userId, $enrollment->course_id);

            return [
                'enrollment' => $enrollment,
                'course' => $enrollment->course,
                'progress' => $progressData,
                'sections_detail' => $this->getSectionsDetail($userId, $enrollment->course_id)
            ];
        });

        return view('admin.student-progress.show', [
            'user' => $user,
            'coursesProgress' => $coursesProgress,
            'totalEnrollments' => $enrollments->count()
        ]);
    }

    /**
     * Get progress statistics for a specific course and user
     *
     * @param int $userId
     * @param int $courseId
     * @return array
     */
    private function calculateCourseProgress($userId, $courseId)
    {
        // Get all sections for the course
        $sections = CourseSection::where('course_id', $courseId)
            ->with('sectionable')
            ->orderBy('order')
            ->get();

        $totalSections = $sections->count();

        if ($totalSections === 0) {
            return [
                'total_sections' => 0,
                'completed_sections' => 0,
                'progress_percentage' => 0,
                'sections_by_type' => [],
                'completed_by_type' => [],
                'last_activity' => 0
            ];
        }

        // Get completed sections for this user
        $completedSectionIds = StudentSectionProgress::where('user_id', $userId)
            ->where('is_completed', true)
            ->whereIn('course_section_id', $sections->pluck('id'))
            ->pluck('course_section_id')
            ->toArray();

        $completedCount = count($completedSectionIds);

        // Calculate progress by section type
        $sectionsByType = [];
        $completedByType = [];

        foreach ($sections as $section) {
            $type = $section->getSectionType();

            if (!isset($sectionsByType[$type])) {
                $sectionsByType[$type] = 0;
                $completedByType[$type] = 0;
            }

            $sectionsByType[$type]++;

            if (in_array($section->id, $completedSectionIds)) {
                $completedByType[$type]++;
            }
        }

        return [
            'total_sections' => $totalSections,
            'completed_sections' => $completedCount,
            'progress_percentage' => $totalSections > 0 ? round(($completedCount / $totalSections) * 100) : 0,
            'sections_by_type' => $sectionsByType,
            'completed_by_type' => $completedByType,
            'last_activity' => $this->getLastActivity($userId, $courseId)
        ];
    }

    /**
     * Get detailed section information with completion status
     *
     * @param int $userId
     * @param int $courseId
     * @return \Illuminate\Support\Collection
     */
    private function getSectionsDetail($userId, $courseId)
    {
        $sections = CourseSection::where('course_id', $courseId)
            ->with('sectionable')
            ->orderBy('order')
            ->get();

        $progress = StudentSectionProgress::where('user_id', $userId)
            ->whereIn('course_section_id', $sections->pluck('id'))
            ->get()
            ->keyBy('course_section_id');

        return $sections->map(function ($section) use ($progress) {
            $sectionProgress = $progress->get($section->id);

            return [
                'id' => $section->id,
                'title' => $section->title,
                'order' => $section->order,
                'type' => $section->getSectionType(),
                'is_completed' => $sectionProgress ? $sectionProgress->is_completed : false,
                'completed_at' => $sectionProgress ? $sectionProgress->updated_at : null
            ];
        });
    }

    /**
     * Get last activity timestamp for a user in a course
     *
     * @param int $userId
     * @param int $courseId
     * @return \Illuminate\Support\Carbon|null
     */
    private function getLastActivity($userId, $courseId)
    {
        $sectionIds = CourseSection::where('course_id', $courseId)->pluck('id');

        $lastProgress = StudentSectionProgress::where('user_id', $userId)
            ->whereIn('course_section_id', $sectionIds)
            ->latest('updated_at')
            ->first();

        return $lastProgress ? $lastProgress->updated_at : null;
    }

    /**
     * Get progress statistics for all users (for dashboard widgets)
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function statistics()
    {
        $totalUsers = User::where('role', '<>', 'Admin')->count();
        $activeEnrollments = CourseEnrollment::where('is_active', true)->count();

        $averageProgress = DB::table('student_section_progress')
            ->join('course_sections', 'student_section_progress.course_section_id', '=', 'course_sections.id')
            ->join('course_enrollments', function($join) {
                $join->on('student_section_progress.user_id', '=', 'course_enrollments.user_id')
                     ->on('course_sections.course_id', '=', 'course_enrollments.course_id');
            })
            ->where('course_enrollments.is_active', true)
            ->where('student_section_progress.is_completed', true)
            ->selectRaw('COUNT(DISTINCT student_section_progress.id) as completed, COUNT(DISTINCT course_sections.id) as total')
            ->first();

        $avgProgress = $averageProgress && $averageProgress->total > 0
            ? round(($averageProgress->completed / $averageProgress->total) * 100, 2)
            : 0;

        // Get most active students (by completion count)
        $topStudents = DB::table('student_section_progress')
            ->join('users', 'student_section_progress.user_id', '=', 'users.id')
            ->where('student_section_progress.is_completed', true)
            ->select('users.id', 'users.name', DB::raw('COUNT(*) as completed_count'))
            ->groupBy('users.id', 'users.name')
            ->orderBy('completed_count', 'desc')
            ->limit(5)
            ->get();

        return response()->json([
            'total_users' => $totalUsers,
            'active_enrollments' => $activeEnrollments,
            'average_progress' => $avgProgress,
            'top_students' => $topStudents
        ]);
    }

    /**
     * Get comparison data between multiple students
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function compare(Request $request)
    {
        $request->validate([
            'user_ids' => 'required|array|min:2|max:5',
            'user_ids.*' => 'exists:users,id',
            'course_id' => 'required|exists:courses,id'
        ]);

        $courseId = $request->course_id;
        $userIds = $request->user_ids;

        $comparison = collect($userIds)->map(function ($userId) use ($courseId) {
            $user = User::find($userId);
            $progressData = $this->calculateCourseProgress($userId, $courseId);

            return [
                'user_id' => $userId,
                'user_name' => $user->name,
                'progress' => $progressData
            ];
        });

        return response()->json([
            'course_id' => $courseId,
            'comparisons' => $comparison
        ]);
    }
}
