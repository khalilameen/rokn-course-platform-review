<?php

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\ExamAttempt;
use App\Models\StudentSectionProgress;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateTeacherReport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'report:teacher {course_id? : The ID of the course to generate report for (optional)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate teacher report with course statistics and top students. Optionally specify a course ID to generate report for a single course.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        try {
            $courseId = $this->argument('course_id');

            if ($courseId) {
                // Generate report for specific course
                $this->info("Generating teacher report for course ID: {$courseId}...");
                
                $course = Course::with('sections')->find($courseId);
                
                if (!$course) {
                    $this->error("Course with ID {$courseId} not found.");
                    return 1;
                }

                $reportData = [$this->generateCourseReport($course)];
                
            } else {
                // Generate report for all courses
                $this->info('Generating teacher report for all courses...');

                $courses = Course::whereNull('parent_id')->with('sections')->get();

                if ($courses->isEmpty()) {
                    $this->warn('No courses found in the system');
                    
                    Log::info('========== TEACHER REPORT ==========');
                    Log::info('No courses found in the system');
                    Log::info('=====================================');

                    return 0;
                }

                $reportData = [];
                foreach ($courses as $course) {
                    $courseReport = $this->generateCourseReport($course);
                    $reportData[] = $courseReport;
                }
            }

            // Log the report
            Log::info('========== TEACHER REPORT ==========');
            Log::info('Generated at: ' . now()->format('Y-m-d H:i:s'));
            Log::info('Total Courses: ' . count($reportData));
            Log::info('');

            foreach ($reportData as $report) {
                Log::info('--- Course: ' . $report['course_title'] . ' ---');
                Log::info('Course ID: ' . $report['course_id']);
                Log::info('Total Sections: ' . $report['total_sections']);
                Log::info('Students who completed: ' . $report['completed_students_count']);
                Log::info('Students with zero progress: ' . $report['zero_progress_students_count']);
                Log::info('Average progress: ' . $report['average_progress_percentage'] . '%');
                Log::info('Student Progress Page: ' . $report['student_progress_link']);

                if (!empty($report['top_3_students'])) {
                    Log::info('Top 3 Students:');
                    foreach ($report['top_3_students'] as $student) {
                        Log::info('  ' . $student['rank'] . '. ' . $student['name'] .
                                 ' (Progress: ' . $student['progress_percentage'] . '%, ' .
                                 'Score: ' . $student['average_score'] . '%, ' .
                                 'Ranking: ' . $student['ranking_score'] . ')');
                    }
                } else {
                    Log::info('Top 3 Students: No students enrolled');
                }
                Log::info('');
            }

            Log::info('====================================');

            $this->info('Teacher report generated successfully!');
            if ($courseId) {
                $this->info("Report generated for course: {$reportData[0]['course_title']}");
            } else {
                $this->info('Total courses: ' . count($reportData));
            }
            $this->info('Check the logs for detailed report.');

            return 0;

        } catch (\Exception $e) {
            $this->error('Failed to generate teacher report: ' . $e->getMessage());
            Log::error('Failed to generate teacher report: ' . $e->getMessage());
            Log::error($e->getTraceAsString());

            return 1;
        }
    }

    /**
     * Generate report for a single course
     *
     * @param Course $course
     * @return array
     */
    private function generateCourseReport(Course $course): array
    {
        $totalSections = $course->sections->count();
        $baseUrl = config('app.url');

        // Get all enrolled students
        $enrolledStudents = CourseEnrollment::where('course_id', $course->id)
            ->where('is_active', true)
            ->pluck('user_id');

        $completedCount = 0;
        $zeroProgressCount = 0;
        $totalProgressSum = 0;
        $studentsData = [];

        // Determine if course is "very short" (same logic as getBestStudents)
        $examSections = $course->sections()->where('sectionable_type', 'App\Models\ItemList')->count();
        $isVeryShortCourse = ($totalSections <= 3 && $examSections <= 1);

        foreach ($enrolledStudents as $userId) {
            $user = User::find($userId);
            if (!$user) continue;

            // Get section progress (with completion dates for very short courses)
            $completedSectionsQuery = StudentSectionProgress::where('user_id', $userId)
                ->whereIn('course_section_id', $course->sections->pluck('id'))
                ->where('is_completed', true);

            $completedSectionsData = $completedSectionsQuery->get();
            $completedSections = $completedSectionsData->count();

            $progressPercentage = $totalSections > 0 ? ($completedSections / $totalSections) * 100 : 0;
            $isFullyCompleted = ($completedSections === $totalSections && $totalSections > 0);

            // Count completed students
            if ($isFullyCompleted) {
                $completedCount++;
            }

            // Count zero progress students
            if ($completedSections === 0) {
                $zeroProgressCount++;
            }

            $totalProgressSum += $progressPercentage;

            // Get exam statistics
            $examAttempts = ExamAttempt::where('user_id', $userId)
                ->where('course_id', $course->id)
                ->where('status', 'completed')
                ->get();

            $totalExams = $examAttempts->count();
            $averageScore = $totalExams > 0 ? $examAttempts->avg('score_percentage') : 0;

            // Get first completion date (for very short courses)
            $firstCompletionDate = null;
            if ($isFullyCompleted && $isVeryShortCourse) {
                $firstCompletionDate = $completedSectionsData->max('updated_at');
            }

            $studentsData[] = [
                'user_id' => $userId,
                'name' => $user->name,
                'progress_percentage' => round($progressPercentage, 2),
                'average_score' => round($averageScore, 2),
                'is_completed' => $isFullyCompleted,
                'ranking_score' => 0, // Will be calculated after sorting
                'completion_date_for_sorting' => $firstCompletionDate,
                'progress_percentage_raw' => $progressPercentage,
                'average_score_raw' => $averageScore
            ];
        }

        // Calculate ranking scores based on course type (same logic as getBestStudents)
        if ($isVeryShortCourse) {
            // For very short courses: sort by completion date first, then by exam score
            usort($studentsData, function($a, $b) {
                // Compare completion status first
                $aCompleted = $a['is_completed'];
                $bCompleted = $b['is_completed'];

                if ($aCompleted && !$bCompleted) return -1;
                if (!$aCompleted && $bCompleted) return 1;

                // If both completed, sort by completion date (earlier is better)
                if ($aCompleted && $bCompleted) {
                    if ($a['completion_date_for_sorting'] && $b['completion_date_for_sorting']) {
                        $dateComparison = $a['completion_date_for_sorting'] <=> $b['completion_date_for_sorting'];
                        if ($dateComparison !== 0) return $dateComparison;
                    }

                    // Same completion date: use exam score as tiebreaker (higher is better)
                    return $b['average_score_raw'] <=> $a['average_score_raw'];
                }

                // If neither completed, sort by progress percentage (higher is better)
                return $b['progress_percentage_raw'] <=> $a['progress_percentage_raw'];
            });

            // Assign ranking scores based on sorted position
            foreach ($studentsData as $index => &$student) {
                if ($student['is_completed']) {
                    // Completed students: base score 1000, decreasing by position
                    // Each position gets 100 points less, exam score adds 0-500
                    $student['ranking_score'] = round(
                        1000 - ($index * 100) + $student['average_score_raw'] * 5,
                        2
                    );
                } else {
                    // Incomplete students: score based on progress (0-100)
                    $student['ranking_score'] = round(
                        $student['progress_percentage_raw'],
                        2
                    );
                }
            }
        } else {
            // For normal courses: comprehensive scoring
            foreach ($studentsData as &$student) {
                $rankingScore = 0;

                // Progress percentage (40% weight)
                $rankingScore += ($student['progress_percentage_raw'] * 4);

                // Average exam score (60% weight)
                $rankingScore += ($student['average_score_raw'] * 6);

                // Bonus for full completion
                if ($student['is_completed']) {
                    $rankingScore += 100;
                }

                $student['ranking_score'] = round($rankingScore, 2);
            }

            // Sort by ranking score (descending)
            usort($studentsData, function($a, $b) {
                return $b['ranking_score'] <=> $a['ranking_score'];
            });
        }

        // Clean up temporary fields
        foreach ($studentsData as &$student) {
            unset($student['completion_date_for_sorting']);
            unset($student['progress_percentage_raw']);
            unset($student['average_score_raw']);
        }

        // Get top 3
        $top3Students = array_slice($studentsData, 0, 3);

        // Add rank to top 3
        foreach ($top3Students as $index => &$student) {
            $student['rank'] = $index + 1;
        }

        // Calculate average progress
        $averageProgress = count($enrolledStudents) > 0 ? $totalProgressSum / count($enrolledStudents) : 0;

        return [
            'course_id' => $course->id,
            'course_title' => $course->name_ar ?? $course->name_en,
            'total_sections' => $totalSections,
            'total_enrolled_students' => count($enrolledStudents),
            'completed_students_count' => $completedCount,
            'zero_progress_students_count' => $zeroProgressCount,
            'average_progress_percentage' => round($averageProgress, 2),
            'top_3_students' => $top3Students,
            'student_progress_link' => $baseUrl . '/ar/dashboard/student-progress?course_id=' . $course->id
        ];
    }
}
