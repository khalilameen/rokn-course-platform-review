<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExamAttempt;
use App\Models\User;
use App\Models\ItemList;
use App\Models\DesignSetting;
use Illuminate\Http\Request;
use App\Support\BusinessClock;
use App\Support\CsvCell;

class ExamResultController extends Controller
{
    /**
     * Get design settings for the views
     */
    private function getDesignSettings()
    {
        return DesignSetting::getDefaultSettings();
    }

    /**
     * Display a listing of exam results
     */
    public function index(Request $request)
    {
        $query = $this->buildExamResultsQuery($request);
        $examResults = $query->paginate(20)->withQueryString();
        $designSettings = $this->getDesignSettings();

        return view('admin.exam-results.index', [
            'examResults' => $examResults,
            'students' => $this->getStudentsWithExams(),
            'exams' => $this->getReferencedExams(),
            'designSettings' => $designSettings
        ]);
    }

    private function buildExamResultsQuery(Request $request)
    {
        return ExamAttempt::with(['user:id,name,email', 'quiz:id,title,description'])
            ->completed()
            ->when($request->student_id, fn($q, $id) => $q->where('user_id', $id))
            ->when($request->quiz_id, fn($q, $id) => $q->where('quiz_id', $id))
            ->when($request->search, fn($q, $search) => $this->applySearchFilter($q, $search))
            ->when($request->grade, fn($q, $grade) => $this->applyGradeFilter($q, $grade))
            ->orderByDesc('completed_at')
            ->orderByDesc('id');
    }

    private function applySearchFilter($query, $search)
    {
        return $query->where(function ($matches) use ($search): void {
            $matches->whereHas('user', function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            })->orWhereHas('quiz', function($q) use ($search) {
                $q->where('title', 'like', "%{$search}%");
            });
        });
    }

    private function applyGradeFilter($query, $grade)
    {
        return match($grade) {
            'passed' => $query->where('is_passed', true),
            'failed' => $query->where('is_passed', false),
            default => $query
        };
    }

    private function getStudentsWithExams()
    {
        return User::whereHas('examAttempts')
            ->select('id', 'name')
            ->orderBy('name')
            ->get();
    }

    private function getReferencedExams()
    {
        $referencedQuizIds = ExamAttempt::distinct()->pluck('quiz_id');
        $quizzes = ItemList::query()
            ->whereIn('id', $referencedQuizIds)
            ->where('type', 'quiz')
            ->get(['id', 'title', 'title_ar', 'title_en'])
            ->keyBy('id');

        return $referencedQuizIds
            ->map(function($quizId) use ($quizzes) {
                $quiz = $quizzes->get($quizId);

                return $quiz && $quiz->title
                    ? $quiz
                    : (object)['id' => $quizId, 'title' => "امتحان رقم $quizId"];
            })
            ->sortBy('title');
    }

    /**
     * Display detailed exam result
     */
    public function show(ExamAttempt $examAttempt)
    {
        $examAttempt->load([
            'user:id,name,email,first_name,last_name',
            'quiz:id,title,description,image',
            'answers' => function($query) {
                $query->orderBy('answered_at');
            }
        ]);

        // Get questions with answers
        $questionsWithAnswers = collect();

        if ($examAttempt->exam_data && is_array($examAttempt->exam_data)) {
            $questionsWithAnswers = collect($examAttempt->exam_data)->map(function ($questionData) use ($examAttempt) {
                $answer = $examAttempt->answers->where('question_id', $questionData['id'])->first();

                return [
                    'question_id' => $questionData['id'] ?? null,
                    'title' => $questionData['title'] ?? 'سؤال بدون عنوان',
                    'question' => $questionData['question'] ?? 'نص السؤال غير متوفر',
                    'question_image' => $questionData['question_image'] ?? null,
                    'description' => $questionData['description'] ?? null,
                    'choices' => [
                        'choice1' => $questionData['choice1'] ?? null,
                        'choice2' => $questionData['choice2'] ?? null,
                        'choice3' => $questionData['choice3'] ?? null,
                        'choice4' => $questionData['choice4'] ?? null,
                        'choice5' => $questionData['choice5'] ?? null,
                        'choice6' => $questionData['choice6'] ?? null
                    ],
                    'right_answer' => $questionData['right_answer'] ?? null,
                    'priority' => $questionData['priority'] ?? 0,
                    'student_answer' => $answer ? $answer->selected_answer : null,
                    'is_correct' => $answer ? $answer->is_correct : null,
                    'points_earned' => $answer ? $answer->points_earned : 0,
                    'max_points' => $answer ? $answer->max_points : 10,
                    'answered_at' => $answer ? $answer->answered_at : null
                ];
            })->sortBy('priority');
        }

        $designSettings = $this->getDesignSettings();

        return view('admin.exam-results.show', compact('examAttempt', 'questionsWithAnswers', 'designSettings'));
    }

    /**
     * Get exam results for a specific student (for student profile page)
     */
    public function getStudentResults($studentId)
    {
        $student = User::findOrFail($studentId);

        $examResults = ExamAttempt::with(['quiz:id,title,description'])
            ->where('user_id', $studentId)
            ->completed()
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->paginate(10);

        $designSettings = $this->getDesignSettings();

        return view('admin.exam-results.student-results', compact('student', 'examResults', 'designSettings'));
    }

    /**
     * Export exam results to CSV
     */
    public function export(Request $request)
    {
        $query = $this->buildExamResultsQuery($request);

        $filename = 'exam_results_' . BusinessClock::now()->format('Y-m-d_H-i-s') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function() use ($query) {
            $file = fopen('php://output', 'w');

            // Add BOM for Arabic text
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));

            // Headers
            fputcsv($file, [
                'اسم الطالب',
                'البريد الإلكتروني',
                'اسم الامتحان',
                'تاريخ الإكمال',
                'المدة بالدقائق',
                'إجمالي الأسئلة',
                'الأسئلة المجابة',
                'الإجابات الصحيحة',
                'النتيجة %',
                'النقاط',
                'النجح/الرسوب'
            ]);

            // Keep export memory flat even after the attempt table grows into
            // the hundreds of thousands. Eager relations are loaded per chunk.
            foreach ($query->reorder()->lazyByIdDesc(500) as $result) {
                $quizTitle = $result->quiz && $result->quiz->title
                    ? $result->quiz->title
                    : "امتحان رقم {$result->quiz_id}";

                fputcsv($file, CsvCell::row([
                    $result->user->name,
                    $result->user->email,
                    $quizTitle,
                    BusinessClock::format($result->completed_at, 'Y-m-d H:i:s'),
                    $result->time_taken_minutes,
                    $result->total_questions,
                    $result->answered_questions,
                    $result->correct_answers,
                    $result->score_percentage,
                    $result->score_points,
                    $result->is_passed ? 'نجح' : 'راسب'
                ]));
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Get exam statistics
     */
    public function getStats(Request $request)
    {
        $aggregate = ExamAttempt::completed()
            ->selectRaw('COUNT(*) as total_attempts')
            ->selectRaw('SUM(CASE WHEN is_passed THEN 1 ELSE 0 END) as passed_attempts')
            ->selectRaw('SUM(CASE WHEN NOT is_passed THEN 1 ELSE 0 END) as failed_attempts')
            ->selectRaw('AVG(score_percentage) as average_score')
            ->selectRaw('COUNT(DISTINCT user_id) as total_students')
            ->selectRaw('COUNT(DISTINCT quiz_id) as total_exams')
            ->first();
        $stats = [
            'total_attempts' => (int) ($aggregate?->total_attempts ?? 0),
            'passed_attempts' => (int) ($aggregate?->passed_attempts ?? 0),
            'failed_attempts' => (int) ($aggregate?->failed_attempts ?? 0),
            'average_score' => (float) ($aggregate?->average_score ?? 0),
            'total_students' => (int) ($aggregate?->total_students ?? 0),
            'total_exams' => (int) ($aggregate?->total_exams ?? 0),
        ];

        $stats['pass_rate'] = $stats['total_attempts'] > 0
            ? round(($stats['passed_attempts'] / $stats['total_attempts']) * 100, 2)
            : 0;

        return response()->json($stats);
    }
}
