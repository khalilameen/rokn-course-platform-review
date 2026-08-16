<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\LessonMediaState;
use App\Models\Question;
use App\Models\Link;
use App\Models\ItemList;
use App\Models\Project;
use App\Models\CourseModule;
use App\Models\DesignSetting;
use App\Services\NotificationService;
use App\Services\BunnyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

class CourseSectionController extends Controller
{
    private BunnyService $bunnyService;

    public function __construct(BunnyService $bunnyService)
    {
        $this->bunnyService = $bunnyService;
    }

    /**
     * Get design settings for the views
     */
    private function getDesignSettings()
    {
        return DesignSetting::getDefaultSettings();
    }

    /**
     * Display sections for a specific course
     */
    public function index(Course $course)
    {
        // Get modules with their sections
        $modules = $course->modules()->with(['sections.sectionable' => function($q) {
            $q->orderBy('order');
        }])->orderBy('order')->get();

        // Get ungrouped sections (not in any module)
        $ungroupedSections = $course->sections()->whereNull('module_id')->with('sectionable')->orderBy('order')->get();
        
        $designSettings = $this->getDesignSettings();
        return view('admin.course-sections.index', compact('course', 'modules', 'ungroupedSections', 'designSettings'));
    }

    /**
     * Show the form for creating a new section
     */
    public function create(Course $course)
    {
        // Get all available content for this course
        $lessons = Lesson::all(); // Get all lessons for now
        $questions = Question::all(); // Get all questions for now
        $links = Link::all(); // Get all links for now
        $quizzes = ItemList::where('type', 'quiz')->get();
        $subCourses = Course::where('id', '!=', $course->id)->get();
        $modules = $course->modules()->orderBy('order')->get();
        $designSettings = $this->getDesignSettings();

        return view('admin.course-sections.create', compact('course', 'lessons', 'questions', 'links', 'quizzes', 'subCourses', 'modules', 'designSettings'));
    }

    /**
     * Auto-save quiz section and question (AJAX)
     */
    public function autoSaveQuiz(Request $request, Course $course)
    {
        $request->validate([
            'title_ar' => 'required|string|max:255',
            'title_en' => 'nullable|string|max:255',
            'quiz_title_ar' => 'required|string|max:255',
            'quiz_title_en' => 'nullable|string|max:255',
            'time_minutes' => 'required|integer|min:1',
            'order' => 'nullable|integer|min:0',
            'section_id' => 'nullable|integer|exists:course_sections,id',
            'question' => 'nullable|array',
            'question.question_id' => 'nullable|integer|exists:questions,id',
            'question.question_text' => 'required_with:question|string',
            'question.choice1' => 'required_with:question|string',
            'question.choice2' => 'required_with:question|string',
            'question.choice3' => 'required_with:question|string',
            'question.choice4' => 'required_with:question|string',
            'question.choice5' => 'nullable|string',
            'question.choice6' => 'nullable|string',
            'question.correct_answer' => 'required_with:question|integer|min:1|max:6',
        ]);

        try {
            $maxOrder = $course->sections()->max('order') ?? 0;
            $order = $request->input('order', $maxOrder + 1);

            // Check if section already exists (update scenario)
            if ($request->section_id) {
                $section = CourseSection::find($request->section_id);

                if (!$section || $section->course_id != $course->id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'القسم غير موجود'
                    ], 404);
                }

                $quiz = $section->sectionable;

                // Update section title
                $section->update([
                    'title_ar' => $request->title_ar,
                    'title_en' => $request->title_en,
                    'order' => $order
                ]);

                // Update quiz
                $quiz->update([
                    'title_ar' => $request->quiz_title_ar,
                    'title_en' => $request->quiz_title_en,
                    'description_ar' => $request->quiz_description_ar ?? '',
                    'description_en' => $request->quiz_description_en ?? '',
                    'time_minutes' => $request->time_minutes,
                    'priority' => $order,
                ]);
            } else {
                // Create new quiz
                $quiz = ItemList::create([
                    'title_ar' => $request->quiz_title_ar,
                    'title_en' => $request->quiz_title_en,
                    'description_ar' => $request->quiz_description_ar ?? '',
                    'description_en' => $request->quiz_description_en ?? '',
                    'type' => 'quiz',
                    'priority' => $order,
                    'course_id' => $course->id,
                    'time_minutes' => $request->time_minutes,
                ]);

                // Create the section
                $section = CourseSection::create([
                    'title_ar' => $request->title_ar,
                    'title_en' => $request->title_en,
                    'course_id' => $course->id,
                    'order' => $order,
                    'sectionable_type' => ItemList::class,
                    'sectionable_id' => $quiz->id
                ]);
            }

            // Add or update question if provided
            if ($request->has('question')) {
                $questionData = $request->question;

                // Check if this is an update (question_id provided)
                if (isset($questionData['question_id'])) {
                    $question = Question::find($questionData['question_id']);

                    if (!$question || $question->list_id != $quiz->id) {
                        return response()->json([
                            'success' => false,
                            'message' => 'السؤال غير موجود'
                        ], 404);
                    }

                    // Update existing question
                    $question->update([
                        'title' => $questionData['question_title'] ?? null,
                        'question' => $questionData['question_text'],
                        'choice1' => $questionData['choice1'],
                        'choice2' => $questionData['choice2'],
                        'choice3' => $questionData['choice3'],
                        'choice4' => $questionData['choice4'],
                        'choice5' => $questionData['choice5'] ?? null,
                        'choice6' => $questionData['choice6'] ?? null,
                        'right_answer' => $questionData['correct_answer'],
                    ]);

                    return response()->json([
                        'success' => true,
                        'message' => 'تم تحديث السؤال تلقائياً',
                        'section_id' => $section->id,
                        'quiz_id' => $quiz->id,
                        'question_id' => $question->id,
                        'questions_count' => $quiz->questions()->count()
                    ]);
                } else {
                    // Create new question
                    $questionCount = $quiz->questions()->count();

                    $question = Question::create([
                        'title' => $questionData['question_title'] ?? null,
                        'question' => $questionData['question_text'],
                        'choice1' => $questionData['choice1'],
                        'choice2' => $questionData['choice2'],
                        'choice3' => $questionData['choice3'],
                        'choice4' => $questionData['choice4'],
                        'choice5' => $questionData['choice5'] ?? null,
                        'choice6' => $questionData['choice6'] ?? null,
                        'right_answer' => $questionData['correct_answer'],
                        'priority' => $questionCount + 1,
                        'list_id' => $quiz->id,
                    ]);

                    return response()->json([
                        'success' => true,
                        'message' => 'تم حفظ السؤال تلقائياً',
                        'section_id' => $section->id,
                        'quiz_id' => $quiz->id,
                        'question_id' => $question->id,
                        'questions_count' => $quiz->questions()->count()
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'تم حفظ الاختبار تلقائياً',
                'section_id' => $section->id,
                'quiz_id' => $quiz->id,
                'questions_count' => $quiz->questions()->count()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء الحفظ التلقائي: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a quiz question (AJAX)
     */
    public function deleteQuizQuestion(Request $request, Course $course)
    {
        $request->validate([
            'question_id' => 'required|integer|exists:questions,id',
            'section_id' => 'required|integer|exists:course_sections,id'
        ]);

        try {
            // Verify section belongs to course
            $section = CourseSection::find($request->section_id);
            if (!$section || $section->course_id != $course->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'القسم غير موجود'
                ], 404);
            }

            // Verify question belongs to the quiz
            $question = Question::find($request->question_id);
            if (!$question || $question->list_id != $section->sectionable_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'السؤال غير موجود'
                ], 404);
            }

            // Delete the question
            $question->delete();

            // Reorder remaining questions
            $quiz = $section->sectionable;
            $remainingQuestions = $quiz->questions()->orderBy('priority')->get();
            foreach ($remainingQuestions as $index => $q) {
                $q->update(['priority' => $index + 1]);
            }

            return response()->json([
                'success' => true,
                'message' => 'تم حذف السؤال بنجاح',
                'questions_count' => $quiz->questions()->count()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء حذف السؤال: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created section
     */
    public function store(Request $request, Course $course)
    {
        $bunnyService = $this->bunnyService;
        $this->validateSectionRequest($request, $course);
        $this->configureUploadRuntime();

        if ($request->section_type === 'lesson') {
            // Lesson playback in the mobile app uses Bunny.
            $request->merge(['video_source_type' => 'bunny']);
            $this->validateLessonRequest($request, true);
        }

        $stagedVideoGuid = null;
        $stagedThumbnailPath = null;
        $transactionStarted = false;

        try {
            // Finish slow remote uploads before opening a short DB transaction.
            if ($request->section_type === 'lesson') {
                $stagedVideoGuid = $bunnyService->uploadVerifiedVideo(
                    (string) $request->lesson_title_ar,
                    $request->file('bunny_video')
                );
                if (!$stagedVideoGuid) {
                    throw new RuntimeException('تعذر رفع الفيديو والتحقق منه ولم يتم نشر الدرس');
                }

                if ($request->hasFile('lesson_thumbnail')) {
                    $stagedThumbnailPath = $bunnyService->uploadFileToStorage(
                        $request->file('lesson_thumbnail'),
                        'lessons/thumbnails'
                    );
                    if (!$stagedThumbnailPath) {
                        throw new RuntimeException('تعذر رفع صورة الدرس ولم يتم نشر الدرس');
                    }
                }
            }

            DB::beginTransaction();
            $transactionStarted = true;
            Course::query()->whereKey($course->id)->lockForUpdate()->firstOrFail();

            // Get the highest order for this course
            $maxOrder = $course->sections()->max('order') ?? 0;
            $order = $request->input('order', $maxOrder + 1);

            $sectionable = null;
            $sectionableType = null;

            // Create the appropriate content based on section type
            switch ($request->section_type) {
                case 'lesson':
                    $videoSourceType = 'bunny';

                    $sectionable = Lesson::create([
                        'title_ar' => $request->lesson_title_ar,
                        'title_en' => $request->lesson_title_en,
                        'description_ar' => $request->lesson_description_ar ?? '',
                        'description_en' => $request->lesson_description_en ?? '',
                        'video_link' => null,
                        'video_source_type' => $videoSourceType,
                        'bunny_video_id' => $stagedVideoGuid,
                        'thumbnail_path' => $stagedThumbnailPath,
                        'file_link1' => $request->file_link1,
                        'file_link2' => $request->file_link2,
                        'list_id' => $course->id,
                        'priority' => $order,
                        'is_opened' => $request->has('is_opened') ? 1 : 0,
                        'duration_minutes' => $request->lesson_duration_minutes,
                    ]);
                    LessonMediaState::query()->create([
                        'lesson_id' => $sectionable->id,
                        'provider' => 'bunny',
                        'provider_media_id' => $stagedVideoGuid,
                        'status' => 'processing',
                        'protocol' => 'hls',
                        'available_qualities' => ['auto'],
                    ]);

                    $sectionableType = Lesson::class;
                    break;

                case 'link':
                    $request->validate([
                        'link_title_ar' => 'required|string|max:255',
                        'link_title_en' => 'string|max:255',
                        'link_url' => 'required|url',
                        'link_type' => 'string'
                    ]);

                    $sectionable = Link::create([
                        'title_ar' => $request->link_title_ar,
                        'title_en' => $request->link_title_en,
                        'description_ar' => $request->link_title_ar,
                        'description_en' => $request->link_title_en,
                        'link' => $request->link_url,
                        'type' => $request->link_type,
                        'list_id' => $course->id
                    ]);
                    $sectionableType = Link::class;
                    break;

                case 'course':
                    $request->validate([
                        'course_name_ar' => 'required|string|max:255',
                        'course_name_en' => 'string|max:255',
                        'course_grade_id' => 'required|exists:grades,id',
                        'course_type' => 'nullable|in:online'
                    ]);

                    $sectionable = Course::create([
                        'name_ar' => $request->course_name_ar,
                        'name_en' => $request->course_name_en ?? '',
                        'description_ar' => $request->course_description_ar ?? '',
                        'description_en' => $request->course_description_en ?? '',
                        'grade_id' => $request->course_grade_id,
                        'course_type' => $request->course_type ?? 'online',
                        'teacher_id' => $course->teacher_id,
                        'parent_id' => $course->id,
                    ]);
                    $sectionableType = Course::class;
                    break;

                case 'quiz':
                    $request->validate([
                        'quiz_title_ar' => 'required|string|max:255',
                        'quiz_title_en' => 'nullable|string|max:255',
                        'time_minutes' => 'required|integer|min:1',
                        'section_id' => 'nullable|integer|exists:course_sections,id',
                        'questions' => 'required|array|min:1',
                        'questions.*.question_title' => 'nullable|string|max:255',
                        'questions.*.question_text' => 'required|string',
                        'questions.*.choice1' => 'required|string',
                        'questions.*.choice2' => 'required|string',
                        'questions.*.choice3' => 'required|string',
                        'questions.*.choice4' => 'required|string',
                        'questions.*.choice5' => 'nullable|string',
                        'questions.*.choice6' => 'nullable|string',
                        'questions.*.correct_answer' => 'required|integer|min:1|max:6',
                    ]);

                    // Check if section already exists (from auto-save)
                    if ($request->section_id) {
                        $existingSection = CourseSection::find($request->section_id);
                        if ($existingSection && $existingSection->course_id == $course->id) {
                            $sectionable = $existingSection->sectionable;

                            // Update quiz details
                            $sectionable->update([
                                'title_ar' => $request->quiz_title_ar,
                                'title_en' => $request->quiz_title_en,
                                'description_ar' => $request->quiz_description_ar ?? '',
                                'description_en' => $request->quiz_description_en ?? '',
                                'time_minutes' => $request->time_minutes,
                                'priority' => $order,
                            ]);

                            // Questions are already saved via auto-save, so we skip creating them again
                            $sectionableType = ItemList::class;

                            // Update section title and order
                            $existingSection->update([
                                'title_ar' => $request->title_ar,
                                'title_en' => $request->title_en,
                                'order' => $order,
                            ]);

                            DB::commit();
                            $transactionStarted = false;

                            // Redirect immediately since section already exists
                            return redirect()->route('admin.courses.sections.index', $course)
                                ->with('success', 'تم حفظ القسم بنجاح');
                        }
                    }

                    // Create new quiz if not from auto-save
                    $sectionable = ItemList::create([
                        'title_ar' => $request->quiz_title_ar,
                        'title_en' => $request->quiz_title_en,
                        'description_ar' => $request->quiz_description_ar ?? '',
                        'description_en' => $request->quiz_description_en ?? '',
                        'type' => 'quiz',
                        'priority' => $order,
                        'course_id' => $course->id,
                        'time_minutes' => $request->time_minutes,
                    ]);

                    // Create questions for the quiz
                    foreach ($request->questions as $index => $questionData) {
                        Question::create([
                            'title' => $questionData['question_title'] ?? null,
                            'question' => $questionData['question_text'],
                            'choice1' => $questionData['choice1'],
                            'choice2' => $questionData['choice2'],
                            'choice3' => $questionData['choice3'],
                            'choice4' => $questionData['choice4'],
                            'choice5' => $questionData['choice5'] ?? null,
                            'choice6' => $questionData['choice6'] ?? null,
                            'right_answer' => $questionData['correct_answer'],
                            'priority' => $index + 1,
                            'list_id' => $sectionable->id,
                        ]);
                    }

                    $sectionableType = ItemList::class;
                    break;

                case 'project':
                    $request->validate([
                        'project_requirements_ar' => 'required|string',
                        'project_requirements_en' => 'nullable|string',
                        'ai_prompt' => 'required|string',
                        'ai_model_type' => 'nullable|string|max:255',
                        'temperature' => 'nullable|numeric|min:0|max:2',
                        'tokens_number' => 'nullable|integer|min:1',
                        'passing_score' => 'required|integer|min:0|max:100',
                        'fallback_review_delay_seconds' => 'nullable|integer|min:30|max:300',
                    ]);

                    $sectionable = Project::create([
                        'requirements_text_ar' => $request->project_requirements_ar,
                        'requirements_text_en' => $request->project_requirements_en,
                        'ai_prompt' => $request->ai_prompt,
                        'ai_model_type' => $request->ai_model_type,
                        'temperature' => $request->temperature,
                        'tokens_number' => $request->tokens_number,
                        'passing_score' => $request->passing_score,
                        'fallback_review_delay_seconds' => $request->fallback_review_delay_seconds,
                        'is_graduation_project' => $request->has('is_graduation_project'),
                    ]);
                    $sectionableType = Project::class;
                    break;
            }

            // Create the section
            $section = new CourseSection([
                'title_ar' => $request->title_ar,
                'title_en' => $request->title_en,
                'course_id' => $course->id,
                'order' => $order,
                'sectionable_type' => $sectionableType,
                'sectionable_id' => $sectionable->id,
                'module_id' => $request->module_id,
                'section_type' => $request->section_type,
            ]);

            $section->save();

            DB::commit();
            $transactionStarted = false;
            $stagedVideoGuid = null;
            $stagedThumbnailPath = null;

            // Send notification for new lesson
            if ($request->section_type === 'lesson' && $sectionable) {
                try {
                    NotificationService::notifyNewCourseLesson($sectionable, $course);
                } catch (Throwable $notificationError) {
                    Log::warning('Lesson saved but notification delivery failed', [
                        'lesson_id' => $sectionable->getKey(),
                        'error' => $notificationError->getMessage(),
                    ]);
                }
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'تم إضافة القسم بنجاح',
                    'section' => $section
                ]);
            }

            return redirect()->route('admin.courses.sections.index', $course)
                ->with('success', 'تم إضافة القسم بنجاح');
        } catch (Throwable $e) {
            if ($transactionStarted) {
                DB::rollBack();
            }
            if ($stagedVideoGuid) {
                $bunnyService->queueVideoCleanup($stagedVideoGuid, null, 'section_create_rollback', 24);
            }
            if ($stagedThumbnailPath) {
                $bunnyService->deleteFileFromStorage($stagedThumbnailPath);
            }
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'حدث خطأ أثناء إضافة القسم: ' . $e->getMessage()
                ], 500);
            }

            return redirect()->back()
                ->with('error', 'حدث خطأ أثناء إضافة القسم')
                ->withInput();
        }
    }

    /**
     * Display the specified section
     */
    public function show(Course $course, CourseSection $section)
    {
        $this->ensureSectionBelongsToCourse($course, $section);
        $section->load('sectionable');
        $designSettings = $this->getDesignSettings();
        return view('admin.course-sections.show', compact('course', 'section', 'designSettings'));
    }

    /**
     * Show the form for editing a section
     */
    public function edit(Course $course, CourseSection $section)
    {
        $this->ensureSectionBelongsToCourse($course, $section);
        // Load the sectionable relationship with its questions if it's a quiz
        $section->load(['sectionable' => function ($query) use ($section) {
            if ($section->getSectionType() === 'quiz') {
                $query->with('questions');
            }
        }]);

        // Get all available content for this course
        $lessons = Lesson::all(); // Get all lessons for now
        $questions = Question::all(); // Get all questions for now
        $links = Link::all(); // Get all links for now
        $quizzes = ItemList::where('type', 'quiz')->get();
        $subCourses = Course::where('id', '!=', $course->id)->get();
        $modules = $course->modules()->orderBy('order')->get();
        $designSettings = $this->getDesignSettings();

        return view('admin.course-sections.edit', compact('course', 'section', 'lessons', 'questions', 'links', 'quizzes', 'subCourses', 'modules', 'designSettings'));
    }

    /**
     * Update the specified section
     */
    public function update(Request $request, Course $course, CourseSection $section)
    {
        $bunnyService = $this->bunnyService;
        $this->ensureSectionBelongsToCourse($course, $section);
        $this->validateSectionRequest($request, $course);
        $this->configureUploadRuntime();

        $oldSectionType = $section->getSectionType();
        $oldSectionable = $section->sectionable;
        $oldLesson = $oldSectionType === 'lesson' && $oldSectionable instanceof Lesson
            ? $oldSectionable
            : null;
        $oldVideoGuid = $oldLesson?->bunny_video_id ? (string) $oldLesson->bunny_video_id : null;
        $oldThumbnailPath = $oldLesson?->thumbnail_path ? (string) $oldLesson->thumbnail_path : null;
        $stagedVideoGuid = null;
        $stagedThumbnailPath = null;
        $transactionStarted = false;

        if ($request->section_type === 'lesson') {
            $request->merge(['video_source_type' => 'bunny']);
            $needsVideo = !$oldLesson || !$oldVideoGuid;
            $this->validateLessonRequest($request, $needsVideo);
        }

        try {
            if ($request->section_type === 'lesson' && $request->hasFile('bunny_video')) {
                $stagedVideoGuid = $bunnyService->uploadVerifiedVideo(
                    (string) $request->lesson_title_ar,
                    $request->file('bunny_video'),
                    $oldLesson
                );
                if (!$stagedVideoGuid) {
                    throw new RuntimeException('تعذر رفع الفيديو الجديد والتحقق منه والفيديو السابق لم يتغير');
                }
            }

            if ($request->section_type === 'lesson' && $request->hasFile('lesson_thumbnail')) {
                $stagedThumbnailPath = $bunnyService->uploadFileToStorage(
                    $request->file('lesson_thumbnail'),
                    'lessons/thumbnails'
                );
                if (!$stagedThumbnailPath) {
                    throw new RuntimeException('تعذر رفع صورة الدرس والصورة السابقة لم تتغير');
                }
            }

            DB::beginTransaction();
            $transactionStarted = true;
            $section = CourseSection::query()
                ->whereKey($section->id)
                ->where('course_id', $course->id)
                ->lockForUpdate()
                ->firstOrFail();

            $order = $request->input('order', $section->order);
            $sectionType = $request->section_type;
            $sectionable = $section->sectionable;
            $oldSectionType = $section->getSectionType();

            // If section type changed, delete the old sectionable
            if ($oldSectionType !== $sectionType && $sectionable) {
                // For quiz, also delete all associated questions
                if ($oldSectionType === 'quiz' && method_exists($sectionable, 'questions')) {
                    $sectionable->questions()->delete();
                }
                // Delete the old sectionable entity
                $sectionable->delete();
                $sectionable = null;
            }

            // Update or create the appropriate content based on section type
            switch ($sectionType) {
                case 'lesson':
                    $videoSourceType = 'bunny';

                    $lessonData = [
                        'title_ar' => $request->lesson_title_ar,
                        'title_en' => $request->lesson_title_en,
                        'description_ar' => $request->lesson_description_ar ?? '',
                        'description_en' => $request->lesson_description_en ?? '',
                        'video_link' => null,
                        'video_source_type' => $videoSourceType,
                        'bunny_video_id' => $stagedVideoGuid ?: ($oldVideoGuid ?: null),
                        'thumbnail_path' => $stagedThumbnailPath ?: ($oldThumbnailPath ?: null),
                        'file_link1' => $request->file_link1,
                        'file_link2' => $request->file_link2,
                        'list_id' => $course->id,
                        'priority' => $order,
                        'is_opened' => $request->has('is_opened') ? 1 : 0,
                        'duration_minutes' => $request->lesson_duration_minutes,
                    ];

                    if ($section->getSectionType() == 'lesson' && $sectionable) {
                        $sectionable->update($lessonData);
                    } else {
                        $sectionable = Lesson::create($lessonData);
                    }
                    if ($stagedVideoGuid) {
                        LessonMediaState::query()->updateOrCreate(
                            ['lesson_id' => $sectionable->id],
                            ['provider' => 'bunny', 'provider_media_id' => $stagedVideoGuid, 'status' => 'processing', 'protocol' => 'hls', 'available_qualities' => ['auto'], 'last_error_code' => null, 'last_error_message' => null, 'retry_count' => 0]
                        );
                    }
                    break;

                case 'link':
                    $request->validate([
                        'link_title_ar' => 'required|string|max:255',
                        'link_title_en' => 'string|max:255',
                        'link_url' => 'required|url',
                        'link_type' => 'string'
                    ]);

                    $linkData = [
                        'title_ar' => $request->link_title_ar,
                        'title_en' => $request->link_title_en,
                        'description_ar' => $request->link_title_ar,
                        'description_en' => $request->link_title_en,
                        'link' => $request->link_url,
                        'type' => $request->link_type,
                        'list_id' => $course->id
                    ];

                    if ($section->getSectionType() == 'link' && $sectionable) {
                        $sectionable->update($linkData);
                    } else {
                        $sectionable = Link::create($linkData);
                    }
                    break;

                case 'course':
                    $request->validate([
                        'course_name_ar' => 'required|string|max:255',
                        'course_name_en' => 'string|max:255',
                        'course_grade_id' => 'required|exists:grades,id',
                        'course_type' => 'nullable|in:online'
                    ]);

                    $courseData = [
                        'name_ar' => $request->course_name_ar,
                        'name_en' => $request->course_name_en ?? '',
                        'description_ar' => $request->course_description_ar ?? '',
                        'description_en' => $request->course_description_en ?? '',
                        'grade_id' => $request->course_grade_id,
                        'course_type' => $request->course_type ?? 'online',
                        'teacher_id' => $course->teacher_id,
                        'parent_id' => $course->id,
                    ];

                    if ($section->getSectionType() == 'course' && $sectionable) {
                        $sectionable->update($courseData);
                    } else {
                        $sectionable = Course::create($courseData);
                    }
                    break;

                case 'quiz':
                    $request->validate([
                        'quiz_title_ar' => 'required|string|max:255',
                        'quiz_title_en' => 'nullable|string|max:255',
                        'time_minutes' => 'required|integer|min:1',
                        'questions' => 'required|array|min:1',
                        'questions.*.question_title' => 'nullable|string|max:255',
                        'questions.*.question_text' => 'required|string',
                        'questions.*.choice1' => 'required|string',
                        'questions.*.choice2' => 'required|string',
                        'questions.*.choice3' => 'required|string',
                        'questions.*.choice4' => 'required|string',
                        'questions.*.choice5' => 'nullable|string',
                        'questions.*.choice6' => 'nullable|string',
                        'questions.*.correct_answer' => 'required|integer|min:1|max:6',
                    ]);

                    $quizData = [
                        'title_ar' => $request->quiz_title_ar,
                        'title_en' => $request->quiz_title_en,
                        'description_ar' => $request->quiz_description_ar ?? '',
                        'description_en' => $request->quiz_description_en ?? '',
                        'type' => 'quiz',
                        'priority' => $order,
                        'course_id' => $course->id,
                        'time_minutes' => $request->time_minutes,
                    ];

                    if ($section->getSectionType() == 'quiz' && $sectionable) {
                        $sectionable->update($quizData);

                        // Delete existing questions
                        $sectionable->questions()->delete();
                    } else {
                        $sectionable = ItemList::create($quizData);
                    }

                    // Create new questions
                    foreach ($request->questions as $index => $questionData) {
                        Question::create([
                            'title' => $questionData['question_title'] ?? null,
                            'question' => $questionData['question_text'],
                            'choice1' => $questionData['choice1'],
                            'choice2' => $questionData['choice2'],
                            'choice3' => $questionData['choice3'],
                            'choice4' => $questionData['choice4'],
                            'choice5' => $questionData['choice5'] ?? null,
                            'choice6' => $questionData['choice6'] ?? null,
                            'right_answer' => $questionData['correct_answer'],
                            'priority' => $index + 1,
                            'list_id' => $sectionable->id,
                        ]);
                    }
                    break;

                case 'project':
                    $request->validate([
                        'project_requirements_ar' => 'required|string',
                        'project_requirements_en' => 'nullable|string',
                        'ai_prompt' => 'required|string',
                        'ai_model_type' => 'nullable|string|max:255',
                        'temperature' => 'nullable|numeric|min:0|max:2',
                        'tokens_number' => 'nullable|integer|min:1',
                        'passing_score' => 'required|integer|min:0|max:100',
                        'fallback_review_delay_seconds' => 'nullable|integer|min:30|max:300',
                    ]);

                    $projectData = [
                        'requirements_text_ar' => $request->project_requirements_ar,
                        'requirements_text_en' => $request->project_requirements_en,
                        'ai_prompt' => $request->ai_prompt,
                        'ai_model_type' => $request->ai_model_type,
                        'temperature' => $request->temperature,
                        'tokens_number' => $request->tokens_number,
                        'passing_score' => $request->passing_score,
                        'fallback_review_delay_seconds' => $request->fallback_review_delay_seconds,
                        'is_graduation_project' => $request->has('is_graduation_project'),
                    ];

                    if ($section->getSectionType() == 'project' && $sectionable) {
                        $sectionable->update($projectData);
                    } else {
                        $sectionable = Project::create($projectData);
                    }
                    break;
            }

            // Update the section
            $section->update([
                'title_ar' => $request->title_ar,
                'title_en' => $request->title_en,
                'order' => $order,
                'sectionable_type' => $this->getSectionableType($sectionType),
                'sectionable_id' => $sectionable->id,
                'module_id' => $request->module_id,
                'section_type' => $sectionType,
            ]);

            DB::commit();
            $transactionStarted = false;

            // Remote retirement starts only after the replacement pointer (or
            // deletion/type change) is durably committed.
            if ($oldVideoGuid && ($sectionType !== 'lesson' || $stagedVideoGuid)) {
                $bunnyService->queueVideoCleanup(
                    $oldVideoGuid,
                    $oldLesson,
                    $sectionType !== 'lesson' ? 'section_type_changed' : 'superseded_video',
                    168
                );
            }
            if ($oldThumbnailPath && ($sectionType !== 'lesson' || $stagedThumbnailPath)) {
                if (!$bunnyService->deleteFileFromStorage($oldThumbnailPath)) {
                    Log::warning('Superseded lesson thumbnail could not be removed', [
                        'path' => $oldThumbnailPath,
                        'section_id' => $section->getKey(),
                    ]);
                }
            }
            $stagedVideoGuid = null;
            $stagedThumbnailPath = null;

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'تم تحديث القسم بنجاح',
                    'section' => $section
                ]);
            }

            return redirect()->route('admin.courses.sections.index', $course)
                ->with('success', 'تم تحديث القسم بنجاح');
        } catch (Throwable $e) {
            if ($transactionStarted) {
                DB::rollBack();
            }
            if ($stagedVideoGuid) {
                $bunnyService->queueVideoCleanup($stagedVideoGuid, $oldLesson, 'section_update_rollback', 24);
            }
            if ($stagedThumbnailPath) {
                $bunnyService->deleteFileFromStorage($stagedThumbnailPath);
            }
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'حدث خطأ أثناء تحديث القسم: ' . $e->getMessage()
                ], 500);
            }

            return redirect()->back()
                ->with('error', 'حدث خطأ أثناء تحديث القسم: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Remove the specified section
     */
    public function destroy(Course $course, CourseSection $section)
    {
        $bunnyService = $this->bunnyService;
        $this->ensureSectionBelongsToCourse($course, $section);
        $lesson = $section->getSectionType() === 'lesson' && $section->sectionable instanceof Lesson
            ? $section->sectionable
            : null;
        $videoGuid = $lesson?->bunny_video_id ? (string) $lesson->bunny_video_id : null;
        $thumbnailPath = $lesson?->thumbnail_path ? (string) $lesson->thumbnail_path : null;

        // The content row is intentionally retained as a recovery snapshot.
        // Only the published section pointer is removed atomically; irreversible
        // remote retirement is queued strictly after that commit.
        DB::transaction(function () use ($course, $section): void {
            $lockedSection = CourseSection::query()
                ->whereKey($section->id)
                ->where('course_id', $course->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedSection->delete();
        });

        if ($videoGuid) {
            $bunnyService->queueVideoCleanup($videoGuid, $lesson, 'section_deleted', 168);
        }
        if ($thumbnailPath && !$bunnyService->deleteFileFromStorage($thumbnailPath)) {
            Log::warning('Deleted lesson thumbnail could not be removed', [
                'path' => $thumbnailPath,
                'section_id' => $section->getKey(),
            ]);
        }

        return redirect()->route('admin.courses.sections.index', $course)
            ->with('success', 'تم حذف القسم بنجاح');
    }

    /**
     * Reorder sections
     */
    public function reorder(Request $request, Course $course)
    {
        $request->validate([
            'sections' => 'required|array',
            'sections.*.id' => [
                'required',
                'integer',
                Rule::exists('course_sections', 'id')->where(
                    fn ($query) => $query->where('course_id', $course->id)->whereNull('deleted_at')
                ),
            ],
            'sections.*.order' => 'required|integer|min:0',
            'sections.*.module_id' => [
                'nullable',
                'integer',
                Rule::exists('course_modules', 'id')->where(
                    fn ($query) => $query->where('course_id', $course->id)
                ),
            ],
        ]);

        DB::transaction(function () use ($request, $course): void {
            Course::query()->whereKey($course->id)->lockForUpdate()->firstOrFail();
            foreach ($request->sections as $sectionData) {
                $updateData = ['order' => $sectionData['order']];
                if (array_key_exists('module_id', $sectionData)) {
                    $updateData['module_id'] = $sectionData['module_id'];
                }

                CourseSection::where('id', $sectionData['id'])
                    ->where('course_id', $course->id)
                    ->update($updateData);
            }
        });

        return response()->json(['success' => true]);
    }

    private function validateSectionRequest(Request $request, Course $course): void
    {
        $request->validate([
            'title_ar' => 'required|string|max:255',
            'title_en' => 'nullable|string|max:255',
            'section_type' => 'required|in:lesson,link,quiz,course,project',
            'module_id' => [
                'nullable',
                'integer',
                Rule::exists('course_modules', 'id')->where(
                    fn ($query) => $query->where('course_id', $course->id)
                ),
            ],
            'order' => 'nullable|integer|min:0',
        ]);
    }

    private function validateLessonRequest(Request $request, bool $videoRequired): void
    {
        $request->validate([
            'lesson_title_ar' => 'required|string|max:255',
            'lesson_title_en' => 'nullable|string|max:255',
            'bunny_video' => ($videoRequired ? 'required' : 'nullable')
                . '|file|mimetypes:video/mp4,video/quicktime,video/x-msvideo,video/webm|max:5242880',
            'file_link1' => 'nullable|url',
            'file_link2' => 'nullable|url',
            'lesson_thumbnail' => 'nullable|file|mimes:jpeg,jpg,png,webp,gif|max:2048',
            'lesson_duration_minutes' => 'nullable|integer|min:1',
        ]);
    }

    private function configureUploadRuntime(): void
    {
        set_time_limit(0);
        ini_set('max_execution_time', '0');
        ini_set('memory_limit', '1024M');
    }

    private function ensureSectionBelongsToCourse(Course $course, CourseSection $section): void
    {
        abort_unless((int) $section->course_id === (int) $course->id, 404);
    }

    /**
     * Get sectionable type based on section type
     */
    private function getSectionableType($sectionType)
    {
        $types = [
            'lesson' => Lesson::class,
            'question' => Question::class,
            'link' => Link::class,
            'quiz' => ItemList::class,
            'course' => Course::class,
            'project' => Project::class
        ];

        return $types[$sectionType] ?? Lesson::class;
    }

}
