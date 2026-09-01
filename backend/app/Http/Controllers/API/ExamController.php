<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\EndExamRequest;
use App\Http\Requests\API\StartExamRequest;
use App\Http\Requests\API\SubmitAnswerRequest;
use App\Models\ExamAttempt;
use App\Models\ExamSecurityLog;
use App\Models\User;
use App\Services\ExamLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Throwable;

final class ExamController extends Controller
{
    public function __construct(
        private readonly ExamLifecycleService $examLifecycle
    ) {
    }

    /**
     * Start a new exam attempt
     */
    public function startExam(StartExamRequest $request): JsonResponse
    {
        try {
            /** @var User $user */
            $user = auth('api')->user();
            $result = $this->examLifecycle->start(
                $user,
                (int) $request->quiz_id,
                $request->course_id === null ? null : (int) $request->course_id,
                $request->section_id === null ? null : (int) $request->section_id
            );

            if (!$result['authorized']) {
                return response()->json([
                    'status' => 403,
                    'success' => false,
                    'message' => 'هذا الاختبار غير متاح لحسابك',
                    'data' => null,
                ], 403);
            }

            $attempt = $result['attempt'];
            if (!$attempt instanceof ExamAttempt) {
                throw new LogicException('Exam lifecycle did not return an attempt.');
            }
            $answeredQuestionIds = $result['resumed']
                ? $attempt->answers()
                    ->orderBy('answered_at')
                    ->pluck('question_id')
                    ->map(fn ($id): int => (int) $id)
                    ->values()
                    ->all()
                : [];

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => $result['resumed']
                    ? 'تم استكمال المحاولة'
                    : 'بدأ الاختبار',
                'data' => [
                    'exam_attempt_id' => $attempt->id,
                    'status' => $attempt->status,
                    'started_at' => $attempt->started_at,
                    'total_questions' => $attempt->total_questions,
                    'answered_questions' => $result['resumed']
                        ? $attempt->answered_questions
                        : 0,
                    'answered_question_ids' => $answeredQuestionIds,
                ],
            ]);
        } catch (Throwable $exception) {
            $this->rethrowExpectedRequestException($exception);
            report($exception);

            return response()->json([
                'status' => 500,
                'success' => false,
                'message' => "تعذّر بدء الاختبار\nحاول مرة أخرى",
                'data' => null,
            ], 500);
        }
    }

    /**
     * Submit an answer for a specific question
     */
    public function submitAnswer(SubmitAnswerRequest $request): JsonResponse
    {
        try {
            /** @var User $user */
            $user = auth('api')->user();
            $result = $this->examLifecycle->submitAnswer(
                $user,
                (int) $request->exam_attempt_id,
                (int) $request->question_id,
                (int) $request->selected_answer
            );

            if ($result['state'] === 'attempt_missing') {
                return response()->json([
                    'status' => 404,
                    'success' => false,
                    'message' => 'انتهت هذه المحاولة أو لم تعد متاحة',
                    'data' => null,
                ], 404);
            }

            if ($result['state'] === 'question_missing') {
                return response()->json([
                    'status' => 404,
                    'success' => false,
                    'message' => 'السؤال غير متاح',
                    'data' => null,
                ], 404);
            }

            if ($result['state'] === 'answer_conflict') {
                return response()->json([
                    'status' => 409,
                    'success' => false,
                    'message' => 'تم حفظ إجابة أخرى لهذا السؤال بالفعل',
                    'data' => [
                        'code' => 'quiz_answer_conflict',
                        'selected_answer' => $result['answer']?->selected_answer,
                    ],
                ], 409);
            }

            $examAttempt = $result['attempt'];
            $examAnswer = $result['answer'];
            if (!$examAttempt instanceof ExamAttempt || $examAnswer === null) {
                throw new LogicException('Exam lifecycle did not return the submitted answer.');
            }

            $progress = $examAttempt->total_questions > 0
                ? round(($examAttempt->answered_questions / $examAttempt->total_questions) * 100, 2)
                : 0.0;

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'تم حفظ الإجابة',
                'data' => [
                    'answer_id' => $examAnswer->id,
                    'answered_questions' => $examAttempt->answered_questions,
                    'total_questions' => $examAttempt->total_questions,
                    'progress_percentage' => $progress,
                ],
            ]);
        } catch (Throwable $exception) {
            $this->rethrowExpectedRequestException($exception);
            report($exception);

            return response()->json([
                'status' => 500,
                'success' => false,
                'message' => "تعذّر حفظ الإجابة\nحاول مرة أخرى",
                'data' => null,
            ], 500);
        }
    }

    /**
     * Get current exam progress
     */
    public function getExamProgress(Request $request, int $examAttemptId): JsonResponse
    {
        try {
            $user = auth('api')->user();

            $examAttempt = ExamAttempt::with(['answers' => function ($query) {
                $query->select('id', 'exam_attempt_id', 'question_id', 'selected_answer', 'answered_at');
            }])->where('id', $examAttemptId)
                ->where('user_id', $user->id)
                ->first();

            if (!$examAttempt) {
                return response()->json([
                    'status' => 404,
                    'success' => false,
                    'message' => 'المحاولة غير متاحة',
                    'data' => null,
                ], 404);
            }

            $answeredQuestions = $examAttempt->answers->pluck('question_id')->toArray();

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'تم تحميل تقدم الاختبار',
                'data' => [
                    'exam_attempt_id' => $examAttempt->id,
                    'status' => $examAttempt->status,
                    'started_at' => $examAttempt->started_at,
                    'total_questions' => $examAttempt->total_questions,
                    'answered_questions' => $examAttempt->answered_questions,
                    'progress_percentage' => $examAttempt->total_questions > 0
                        ? round(($examAttempt->answered_questions / $examAttempt->total_questions) * 100, 2)
                        : 0.0,
                    'answered_question_ids' => $answeredQuestions,
                    'can_continue' => $examAttempt->canContinue(),
                ],
            ]);
        } catch (\Exception $e) {
            $this->rethrowExpectedRequestException($e);
            report($e);

            return response()->json([
                'status' => 500,
                'success' => false,
                'message' => "تعذّر تحميل الاختبار\nحاول مرة أخرى",
                'data' => null,
            ], 500);
        }
    }

    /**
     * End exam and calculate final results
     */
    public function endExam(EndExamRequest $request): JsonResponse
    {
        try {
            /** @var User $user */
            $user = auth('api')->user();
            $examAttempt = $this->examLifecycle->end(
                $user,
                (int) $request->exam_attempt_id,
                $request->security_summary ?? null
            );

            if ($examAttempt === null) {
                return response()->json([
                    'status' => 404,
                    'success' => false,
                    'message' => 'انتهت هذه المحاولة أو لم تعد متاحة',
                    'data' => null,
                ], 404);
            }

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'اكتمل الاختبار',
                'data' => [
                    'exam_attempt_id' => $examAttempt->id,
                    'status' => $examAttempt->status,
                    'completed_at' => $examAttempt->completed_at,
                    'time_taken_minutes' => $examAttempt->time_taken_minutes,
                    'total_questions' => $examAttempt->total_questions,
                    'answered_questions' => $examAttempt->answered_questions,
                    'correct_answers' => $examAttempt->correct_answers,
                    'score_percentage' => $examAttempt->score_percentage,
                    'score_points' => $examAttempt->score_points,
                    'is_passed' => $examAttempt->is_passed,
                ],
            ]);
        } catch (Throwable $exception) {
            $this->rethrowExpectedRequestException($exception);
            report($exception);

            return response()->json([
                'status' => 500,
                'success' => false,
                'message' => "تعذّر إنهاء الاختبار\nحاول مرة أخرى",
                'data' => null,
            ], 500);
        }
    }

    /**
     * Get user's exam history
     */
    public function getExamHistory(Request $request): JsonResponse
    {
        try {
            $user = auth('api')->user();
            $validated = $request->validate([
                'per_page' => 'nullable|integer|min:1|max:50',
            ]);

            $exams = ExamAttempt::with(['quiz:id,title,description,image'])
                ->where('user_id', $user->id)
                ->completed()
                ->orderBy('completed_at', 'desc')
                ->orderByDesc('id')
                ->paginate($validated['per_page'] ?? 15);

            $exams->getCollection()->transform(function ($exam) {
                $quiz = $exam->quizSnapshot();
                return [
                    'id' => $exam->id,
                    'quiz_id' => $exam->quiz_id,
                    'quiz_title' => $quiz['title'],
                    'quiz_description' => $quiz['description'],
                    'quiz_image' => $quiz['image'],
                    'attempt_number' => $exam->attempt_number,
                    'started_at' => $exam->started_at,
                    'completed_at' => $exam->completed_at,
                    'time_taken_minutes' => $exam->time_taken_minutes,
                    'total_questions' => $exam->total_questions,
                    'answered_questions' => $exam->answered_questions,
                    'correct_answers' => $exam->correct_answers,
                    'score_percentage' => $exam->score_percentage,
                    'score_points' => $exam->score_points,
                    'is_passed' => $exam->is_passed,
                ];
            });

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'تم تحميل سجل الاختبارات',
                'data' => [
                    'exams' => $exams->items(),
                    'pagination' => [
                        'current_page' => $exams->currentPage(),
                        'last_page' => $exams->lastPage(),
                        'per_page' => $exams->perPage(),
                        'total' => $exams->total(),
                        'from' => $exams->firstItem(),
                        'to' => $exams->lastItem(),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            $this->rethrowExpectedRequestException($e);
            report($e);

            return response()->json([
                'status' => 500,
                'success' => false,
                'message' => "تعذّر تحميل سجل الاختبارات\nحاول مرة أخرى",
                'data' => null,
            ], 500);
        }
    }

    /**
     * Get detailed exam results with questions and answers
     */
    public function getExamResults(Request $request, int $examAttemptId): JsonResponse
    {
        try {
            $user = auth('api')->user();

            $examAttempt = ExamAttempt::with(['answers' => function ($query) {
                $query->orderBy('answered_at');
            }, 'quiz:id,title,description,image'])
                ->where('id', $examAttemptId)
                ->where('user_id', $user->id)
                ->completed()
                ->first();

            if (!$examAttempt) {
                return response()->json([
                    'status' => 404,
                    'success' => false,
                    'message' => 'نتيجة الاختبار غير متاحة',
                    'data' => null,
                ], 404);
            }

            $questionsWithAnswers = collect($examAttempt->exam_data)->map(function ($questionData) use ($examAttempt) {
                $answer = $examAttempt->answers->where('question_id', $questionData['id'])->first();

                return [
                    'question_id' => $questionData['id'],
                    'title' => $questionData['title'],
                    'question' => $questionData['question'],
                    'question_image' => $questionData['question_image'],
                    'description' => $questionData['description'],
                    'choices' => [
                        'choice1' => $questionData['choice1'],
                        'choice2' => $questionData['choice2'],
                        'choice3' => $questionData['choice3'],
                        'choice4' => $questionData['choice4'],
                        'choice5' => $questionData['choice5'],
                        'choice6' => $questionData['choice6'],
                    ],
                    'right_answer' => $questionData['right_answer'],
                    'priority' => $questionData['priority'],
                    'student_answer' => $answer ? $answer->selected_answer : null,
                    'is_correct' => $answer ? $answer->is_correct : null,
                    'points_earned' => $answer ? $answer->points_earned : 0,
                    'max_points' => $answer ? $answer->max_points : 10,
                    'answered_at' => $answer ? $answer->answered_at : null,
                ];
            });
            $quiz = $examAttempt->quizSnapshot();

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'تم تحميل نتيجة الاختبار',
                'data' => [
                    'exam_attempt_id' => $examAttempt->id,
                    'quiz_id' => $examAttempt->quiz_id,
                    'quiz_title' => $quiz['title'],
                    'quiz_description' => $quiz['description'],
                    'quiz_image' => $quiz['image'],
                    'attempt_number' => $examAttempt->attempt_number,
                    'started_at' => $examAttempt->started_at,
                    'completed_at' => $examAttempt->completed_at,
                    'time_taken_minutes' => $examAttempt->time_taken_minutes,
                    'total_questions' => $examAttempt->total_questions,
                    'answered_questions' => $examAttempt->answered_questions,
                    'correct_answers' => $examAttempt->correct_answers,
                    'score_percentage' => $examAttempt->score_percentage,
                    'score_points' => $examAttempt->score_points,
                    'is_passed' => $examAttempt->is_passed,
                    'questions' => $questionsWithAnswers,
                ],
            ]);
        } catch (\Exception $e) {
            $this->rethrowExpectedRequestException($e);
            report($e);

            return response()->json([
                'status' => 500,
                'success' => false,
                'message' => "تعذّر تحميل النتيجة\nحاول مرة أخرى",
                'data' => null,
            ], 500);
        }
    }

    public function getAllExamResults(Request $request): JsonResponse
    {
        try {
            $user = auth('api')->user();
            $validated = $request->validate([
                'per_page' => 'nullable|integer|min:1|max:50',
            ]);

            $exams = ExamAttempt::with(['answers' => function ($query) {
                $query->orderBy('answered_at');
            }, 'quiz:id,title,description,image'])
                ->where('user_id', $user->id)
                ->completed()
                ->orderBy('completed_at', 'desc')
                ->orderByDesc('id')
                ->paginate($validated['per_page'] ?? 5);

            $exams->getCollection()->transform(function ($examAttempt) {
                $quiz = $examAttempt->quizSnapshot();
                $questionsWithAnswers = collect($examAttempt->exam_data)->map(function (
                    $questionData
                ) use ($examAttempt) {
                    $answer = $examAttempt->answers->where('question_id', $questionData['id'])->first();

                    return [
                        'question' => $questionData['question'],
                        'question_image' => $questionData['question_image'],
                        'choices' => [
                            'choice1' => $questionData['choice1'],
                            'choice2' => $questionData['choice2'],
                            'choice3' => $questionData['choice3'],
                            'choice4' => $questionData['choice4'],
                            'choice5' => $questionData['choice5'],
                            'choice6' => $questionData['choice6'],
                        ],
                        'right_answer' => $questionData['right_answer'],
                        'student_answer' => $answer ? $answer->selected_answer : null,
                        'points_earned' => $answer ? $answer->points_earned : 0,
                        'max_points' => $answer ? $answer->max_points : 10,
                    ];
                });

                return [
                    'exam_attempt_id' => $examAttempt->id,
                    'quiz_id' => $examAttempt->quiz_id,
                    'quiz_title' => $quiz['title'],
                    'quiz_description' => $quiz['description'],
                    'quiz_image' => $quiz['image'],
                    'attempt_number' => $examAttempt->attempt_number,
                    'started_at' => $examAttempt->started_at,
                    'completed_at' => $examAttempt->completed_at,
                    'time_taken_minutes' => $examAttempt->time_taken_minutes,
                    'total_questions' => $examAttempt->total_questions,
                    'answered_questions' => $examAttempt->answered_questions,
                    'correct_answers' => $examAttempt->correct_answers,
                    'score_percentage' => $examAttempt->score_percentage,
                    'score_points' => $examAttempt->score_points,
                    'is_passed' => $examAttempt->is_passed,
                    'questions' => $questionsWithAnswers,
                ];
            });

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'تم تحميل نتائج الاختبارات',
                'data' => [
                    'exams' => $exams->items(),
                    'pagination' => [
                        'current_page' => $exams->currentPage(),
                        'last_page' => $exams->lastPage(),
                        'per_page' => $exams->perPage(),
                        'total' => $exams->total(),
                        'from' => $exams->firstItem(),
                        'to' => $exams->lastItem(),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            $this->rethrowExpectedRequestException($e);
            report($e);

            return response()->json([
                'status' => 500,
                'success' => false,
                'message' => "تعذّر تحميل النتائج\nحاول مرة أخرى",
                'data' => null,
            ], 500);
        }
    }

    /**
     * Log security event
     */
    public function logSecurityEvent(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'exam_attempt_id' => 'required|integer|exists:exam_attempts,id',
                'event_type' => 'required|string|max:64',
                'details' => [
                    'nullable',
                    static function (string $attribute, mixed $value, \Closure $fail): void {
                        if (!is_array($value) && !is_string($value)) {
                            $fail('تفاصيل المتابعة غير صالحة');
                            return;
                        }

                        $encoded = json_encode($value);
                        if ($encoded === false || strlen($encoded) > 8192) {
                            $fail('تفاصيل المتابعة طويلة جدًا');
                        }
                    },
                ],
                'timestamp' => 'nullable|date',
            ]);
            $user = auth('api')->user();
            $examAttemptId = (int) $validated['exam_attempt_id'];
            $details = $validated['details'] ?? [];
            if (is_string($details)) {
                $details = ['message' => $details];
            }

            $examAttempt = ExamAttempt::where('id', $examAttemptId)
                ->where('user_id', $user->id)
                ->first();

            if (!$examAttempt) {
                return response()->json([
                    'status' => 404,
                    'success' => false,
                    'message' => 'المحاولة غير متاحة',
                    'data' => null,
                ], 404);
            }

            $securityLog = ExamSecurityLog::create([
                'exam_attempt_id' => $examAttemptId,
                'event_type' => $validated['event_type'],
                'details' => $details,
                'timestamp' => $validated['timestamp'] ?? now(),
            ]);

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'تم حفظ حالة الاختبار',
                'data' => [
                    'security_log_id' => $securityLog->id,
                ],
            ]);
        } catch (\Exception $e) {
            $this->rethrowExpectedRequestException($e);
            report($e);

            return response()->json([
                'status' => 500,
                'success' => false,
                'message' => 'تعذّر حفظ حالة الاختبار',
                'data' => null,
            ], 500);
        }
    }

}
