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
use App\Models\ExamAttempt;
use App\Models\PlaybackSession;
use App\Models\PortfolioItem;
use App\Models\ProjectSubmission;
use App\Models\StudentSectionProgress;
use App\Models\WatchingLog;
use App\Jobs\ProbeLessonMedia;
use App\Support\DurableJobDispatch;
use App\Services\BunnyService;
use App\Services\BunnyDirectUploadService;
use App\Services\CoursePublishingService;
use App\Services\CourseAuthoringConcurrencyService;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\SafeExternalUrl;
use App\Services\StoredFileDeletionService;
use App\Support\UnicodeText;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class CourseSectionController extends Controller
{
    private BunnyService $bunnyService;
    private CoursePublishingService $publishingService;
    private BunnyDirectUploadService $directUploads;
    private CourseAuthoringConcurrencyService $authoring;
    private AdminAuthoringCreateIntentService $createIntents;

    public function __construct(
        BunnyService $bunnyService,
        CoursePublishingService $publishingService,
        BunnyDirectUploadService $directUploads,
        CourseAuthoringConcurrencyService $authoring,
        AdminAuthoringCreateIntentService $createIntents
    )
    {
        $this->bunnyService = $bunnyService;
        $this->publishingService = $publishingService;
        $this->directUploads = $directUploads;
        $this->authoring = $authoring;
        $this->createIntents = $createIntents;
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
        if (!$course->is_coming_soon) {
            return redirect()->route('admin.courses.show', $course)->with(
                'error',
                'حوّل الكورس إلى مسودة قبل إضافة محتوى جديد'
            );
        }
        $modules = $course->modules()->orderBy('order')->get();
        if ($modules->isEmpty()) {
            return redirect()->route('admin.courses.modules.create', [
                $course,
                'return_to' => request('return_to') === 'studio' ? 'studio' : null,
            ])->with('error', 'أنشئ الوحدة أولًا ثم أضف محتواها');
        }
        $designSettings = $this->getDesignSettings();

        return view('admin.course-sections.create', compact('course', 'modules', 'designSettings'));
    }

    /**
     * Store a newly created section
     */
    public function store(Request $request, Course $course)
    {
        $bunnyService = $this->bunnyService;
        $this->assertDraftForStagedAuthoring($course);
        $this->normalizeAuthoringText($request);
        $this->validateSectionRequest($request, $course);
        if ($request->section_type === 'quiz') {
            $this->validateQuizRequest($request);
        }
        $this->configureUploadRuntime();

        if ($request->section_type === 'lesson') {
            // Lesson playback in the mobile app uses Bunny.
            $request->merge(['video_source_type' => 'bunny']);
            $this->validateLessonRequest($request, true);
        }

        $stagedVideoGuid = null;
        $directVideoCandidate = false;
        $stagedThumbnailPath = null;
        $stagedQuestionImages = [];
        $transactionStarted = false;

        try {
            if ($request->section_type === 'quiz') {
                $stagedQuestionImages = $this->stageQuestionImages(
                    $request,
                    'course-section-create|'.strtolower((string) $request->input('authoring_request_id'))
                );
            }
            // Finish slow remote uploads before opening a short DB transaction.
            if ($request->section_type === 'lesson') {
                if ($request->filled('bunny_video_claim')) {
                    /** @var User $admin */
                    $admin = $request->user();
                    $claim = $this->directUploads->verifyForAttach(
                        $course,
                        $admin,
                        (string) $request->input('bunny_video_claim'),
                        null
                    );
                    $stagedVideoGuid = (string) $claim['video_id'];
                    $directVideoCandidate = true;
                } elseif ($request->hasFile('bunny_video')) {
                    $stagedVideoGuid = $bunnyService->uploadVerifiedVideo(
                        (string) $request->lesson_title_ar,
                        $request->file('bunny_video'),
                        null,
                        $request->string('authoring_request_id')->toString() ?: null
                    );
                }
                if (!$stagedVideoGuid) {
                    throw new RuntimeException('تعذر رفع الفيديو والتحقق منه ولم يتم نشر الدرس');
                }

                if ($request->hasFile('lesson_thumbnail')) {
                    $stagedThumbnailPath = $bunnyService->uploadFileToStorage(
                        $request->file('lesson_thumbnail'),
                        'lessons/thumbnails',
                        $request->string('authoring_request_id')->toString() ?: null,
                        'section_thumbnail_unpublished'
                    );
                    if (!$stagedThumbnailPath) {
                        throw new RuntimeException('تعذر رفع صورة الدرس ولم يتم نشر الدرس');
                    }
                }
            }

            DB::beginTransaction();
            $transactionStarted = true;
            $lockedCourse = $this->authoring->lock($request, $course);
            $this->assertDraftForStagedAuthoring($lockedCourse);

            // Get the highest order for this course
            $maxOrder = $course->sections()->max('order') ?? 0;
            $order = $request->input('order', $maxOrder + 1);

            $sectionable = null;
            $sectionableType = null;
            if ($directVideoCandidate && $stagedVideoGuid) {
                $this->directUploads->consume($stagedVideoGuid);
            } elseif ($stagedVideoGuid) {
                $bunnyService->consumeVideoCleanupCandidate($stagedVideoGuid);
            }
            if ($stagedThumbnailPath) {
                $bunnyService->consumeStorageCleanupCandidate($stagedThumbnailPath);
            }

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
                        'list_id' => $course->id,
                        'priority' => $order,
                        'is_opened' => $request->has('is_opened') ? 1 : 0,
                        'duration_minutes' => $request->lesson_duration_minutes,
                    ]);
                    LessonMediaState::query()->create([
                        'lesson_id' => $sectionable->id,
                    ] + LessonMediaState::resetForGeneration((string) $stagedVideoGuid));

                    $sectionableType = Lesson::class;
                    break;

                case 'link':
                    $request->validate([
                        'link_title_ar' => 'required|string|max:255',
                        'link_title_en' => 'string|max:255',
                        'link_url' => ['required', 'string', 'max:2000', SafeExternalUrl::validationRule()],
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
                    $this->syncQuizQuestions(
                        $sectionable,
                        (array) $request->questions,
                        $stagedQuestionImages
                    );

                    $sectionableType = ItemList::class;
                    break;

                case 'project':
                    $request->validate([
                        'project_requirements_ar' => 'required|string',
                        'project_requirements_en' => 'nullable|string',
                        'submission_max_files' => 'nullable|integer|min:1|max:5',
                        'submission_allowed_mime_types' => 'nullable|array|min:1',
                        'submission_allowed_mime_types.*' => ['string', Rule::in(app(\App\Services\AiInputAttachmentService::class)->allowedMimeTypes())],
                    ]);

                    $sectionable = Project::create([
                        'requirements_text_ar' => $request->project_requirements_ar,
                        'requirements_text_en' => $request->project_requirements_en,
                        // Legacy columns remain readable for old rows only
                        // Runtime evaluation is derived from published content
                        // and the global plan policy
                        'ai_prompt' => '',
                        'passing_score' => 50,
                        'is_graduation_project' => $request->has('is_graduation_project'),
                        'submission_max_files' => (int) ($request->submission_max_files ?: 3),
                        'submission_allowed_mime_types' => array_values((array) $request->submission_allowed_mime_types),
                    ]);
                    $sectionableType = Project::class;
                    break;
            }

            $sectionData = [
                'title_ar' => $request->title_ar,
                'title_en' => $request->title_en,
                'course_id' => $course->id,
                'order' => $order,
                'sectionable_type' => $sectionableType,
                'sectionable_id' => $sectionable->id,
                'module_id' => $request->module_id,
                'section_type' => $request->section_type,
            ];
            $section = CourseSection::create($sectionData);
            $this->normalizeModuleOrder($course, $section->module_id);
            $this->assertLiveCourseReady($course);
            $authoringVersion = $this->authoring->advance($lockedCourse);

            // Publish the resource and the exact browser/API receipt in the
            // same transaction. A killed worker after commit can replay it
            // without allocating or uploading a second section.
            if ($request->expectsJson()) {
                $this->createIntents->completeJson(
                    $request,
                    [
                        'success' => true,
                        'message' => 'تم إضافة القسم بنجاح',
                        'section' => $section,
                        'authoring_version' => $authoringVersion,
                    ],
                    200,
                    CourseSection::class,
                    $section->id
                );
            } else {
                $this->createIntents->completeRedirect(
                    $request,
                    route(
                        $request->input('return_to') === 'studio'
                            ? 'admin.courses.show'
                            : 'admin.courses.sections.index',
                        $course
                    ),
                    302,
                    CourseSection::class,
                    $section->id
                );
            }

            DB::commit();
            $transactionStarted = false;
            if ($sectionable instanceof Lesson) {
                $this->dispatchMediaProbe($sectionable);
            }
            $stagedVideoGuid = null;
            $stagedThumbnailPath = null;

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'تم إضافة القسم بنجاح',
                    'section' => $section,
                    'authoring_version' => $authoringVersion,
                ]);
            }

            return $this->authoringRedirect($request, $course)
                ->with('success', 'تم إضافة القسم بنجاح');
        } catch (Throwable $e) {
            if ($transactionStarted) {
                DB::rollBack();
            }
            if ($stagedVideoGuid) {
                $bunnyService->queueVideoCleanup($stagedVideoGuid, null, 'section_create_rollback', 24, true);
            }
            if ($stagedThumbnailPath) {
                $bunnyService->queueStorageCleanup($stagedThumbnailPath, 'section_create_rollback');
            }
            if ($e instanceof ValidationException) {
                throw $e;
            }
            report($e);
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'تعذر إضافة القسم الآن'
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
        if (!$course->is_coming_soon) {
            return redirect()->route('admin.courses.show', $course)
                ->with('error', 'حوّل الكورس إلى مسودة قبل تعديل محتواه');
        }
        // Load the sectionable relationship with its questions if it's a quiz
        $section->load(['sectionable' => function ($query) use ($section) {
            if ($section->getSectionType() === 'quiz') {
                $query->with('questions.photo');
            }
        }]);

        $modules = $course->modules()->orderBy('order')->get();
        $designSettings = $this->getDesignSettings();

        return view('admin.course-sections.edit', compact('course', 'section', 'modules', 'designSettings'));
    }

    /**
     * Update the specified section
     */
    public function update(Request $request, Course $course, CourseSection $section)
    {
        $bunnyService = $this->bunnyService;
        $this->assertDraftForStagedAuthoring($course);
        $this->normalizeAuthoringText($request);
        $this->ensureSectionBelongsToCourse($course, $section);
        $this->validateSectionRequest($request, $course);
        if ($request->section_type === 'quiz') {
            $this->validateQuizRequest($request);
        }
        $this->configureUploadRuntime();

        $oldSectionType = $section->getSectionType();
        $oldSectionable = $section->sectionable;
        $oldLesson = $oldSectionType === 'lesson' && $oldSectionable instanceof Lesson
            ? $oldSectionable
            : null;
        $oldVideoGuid = $oldLesson?->bunny_video_id ? (string) $oldLesson->bunny_video_id : null;
        $oldThumbnailPath = $oldLesson?->thumbnail_path ? (string) $oldLesson->thumbnail_path : null;
        $stagedVideoGuid = null;
        $directVideoCandidate = false;
        $stagedThumbnailPath = null;
        $stagedQuestionImages = [];
        $transactionStarted = false;

        if ($request->section_type === 'lesson') {
            $request->merge(['video_source_type' => 'bunny']);
            $needsVideo = !$oldLesson || !$oldVideoGuid;
            $this->validateLessonRequest($request, $needsVideo);
        }

        try {
            if ($request->section_type === 'quiz') {
                $stagedQuestionImages = $this->stageQuestionImages(
                    $request,
                    implode('|', [
                        'course-section-update',
                        (string) $section->id,
                        (string) $request->input('authoring_version'),
                    ])
                );
            }
            if ($request->section_type === 'lesson' && $request->filled('bunny_video_claim')) {
                /** @var User $admin */
                $admin = $request->user();
                $claim = $this->directUploads->verifyForAttach(
                    $course,
                    $admin,
                    (string) $request->input('bunny_video_claim'),
                    $section
                );
                $stagedVideoGuid = (string) $claim['video_id'];
                $directVideoCandidate = true;
            } elseif ($request->section_type === 'lesson' && $request->hasFile('bunny_video')) {
                $stagedVideoGuid = $bunnyService->uploadVerifiedVideo(
                    (string) $request->lesson_title_ar,
                    $request->file('bunny_video'),
                    $oldLesson,
                    $request->string('authoring_request_id')->toString() ?: null
                );
            }
            if ($request->section_type === 'lesson' && ($request->filled('bunny_video_claim') || $request->hasFile('bunny_video'))) {
                if (!$stagedVideoGuid) {
                    throw new RuntimeException('تعذر رفع الفيديو الجديد والتحقق منه والفيديو السابق لم يتغير');
                }
            }

            if ($request->section_type === 'lesson' && $request->hasFile('lesson_thumbnail')) {
                $stagedThumbnailPath = $bunnyService->uploadFileToStorage(
                    $request->file('lesson_thumbnail'),
                    'lessons/thumbnails',
                    $request->string('authoring_request_id')->toString() ?: null,
                    'section_thumbnail_unpublished'
                );
                if (!$stagedThumbnailPath) {
                    throw new RuntimeException('تعذر رفع صورة الدرس والصورة السابقة لم تتغير');
                }
            }

            DB::beginTransaction();
            $transactionStarted = true;
            $lockedCourse = $this->authoring->lock($request, $course);
            $this->assertDraftForStagedAuthoring($lockedCourse);
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
                $this->assertSectionCanChangeType($section, $sectionable);
                // For quiz, also delete all associated questions
                if ($oldSectionType === 'quiz' && method_exists($sectionable, 'questions')) {
                    // Model deletion retires any question image only after the
                    // last database reference is gone. A bulk delete bypasses
                    // that lifecycle and leaves permanent orphaned files.
                    $sectionable->questions()->get()->each->delete();
                }
                // Delete the old sectionable entity
                $sectionable->delete();
                $sectionable = null;
            }

            if ($directVideoCandidate && $stagedVideoGuid) {
                $this->directUploads->consume($stagedVideoGuid);
            } elseif ($stagedVideoGuid) {
                $bunnyService->consumeVideoCleanupCandidate($stagedVideoGuid);
            }
            if ($stagedThumbnailPath) {
                $bunnyService->consumeStorageCleanupCandidate($stagedThumbnailPath);
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
                            LessonMediaState::resetForGeneration((string) $stagedVideoGuid)
                        );
                    }
                    break;

                case 'link':
                    $request->validate([
                        'link_title_ar' => 'required|string|max:255',
                        'link_title_en' => 'string|max:255',
                        'link_url' => ['required', 'string', 'max:2000', SafeExternalUrl::validationRule()],
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
                    } else {
                        $sectionable = ItemList::create($quizData);
                    }

                    $this->syncQuizQuestions(
                        $sectionable,
                        (array) $request->questions,
                        $stagedQuestionImages
                    );
                    break;

                case 'project':
                    $request->validate([
                        'project_requirements_ar' => 'required|string',
                        'project_requirements_en' => 'nullable|string',
                        'submission_max_files' => 'nullable|integer|min:1|max:5',
                        'submission_allowed_mime_types' => 'nullable|array|min:1',
                        'submission_allowed_mime_types.*' => ['string', Rule::in(app(\App\Services\AiInputAttachmentService::class)->allowedMimeTypes())],
                    ]);

                    $projectData = [
                        'requirements_text_ar' => $request->project_requirements_ar,
                        'requirements_text_en' => $request->project_requirements_en,
                        'is_graduation_project' => $request->has('is_graduation_project'),
                        'submission_max_files' => (int) ($request->submission_max_files ?: 3),
                        'submission_allowed_mime_types' => array_values((array) $request->submission_allowed_mime_types),
                    ];

                    if ($section->getSectionType() == 'project' && $sectionable) {
                        $sectionable->update($projectData);
                    } else {
                        $sectionable = Project::create($projectData);
                    }
                    break;
            }

            // Update the section
            $previousModuleId = $section->module_id;
            $section->update([
                'title_ar' => $request->title_ar,
                'title_en' => $request->title_en,
                'order' => $order,
                'sectionable_type' => $this->getSectionableType($sectionType),
                'sectionable_id' => $sectionable->id,
                'module_id' => $request->module_id,
                'section_type' => $sectionType,
            ]);
            foreach (array_unique(array_filter([$previousModuleId, $section->module_id])) as $moduleId) {
                $this->normalizeModuleOrder($course, (int) $moduleId);
            }
            if ($oldVideoGuid && ($sectionType !== 'lesson' || $stagedVideoGuid)) {
                $candidate = $bunnyService->queueVideoCleanup(
                    $oldVideoGuid,
                    $oldLesson,
                    $sectionType !== 'lesson' ? 'section_type_changed' : 'superseded_video',
                    168,
                    true
                );
                if (!$candidate) {
                    throw new RuntimeException('تعذر تسجيل تقاعد الفيديو السابق بأمان');
                }
            }
            if ($oldThumbnailPath && ($sectionType !== 'lesson' || $stagedThumbnailPath)) {
                if (!$bunnyService->queueStorageCleanup($oldThumbnailPath, 'superseded_lesson_thumbnail')) {
                    throw new RuntimeException('تعذر تأمين تقاعد صورة الدرس السابقة');
                }
            }
            $this->assertLiveCourseReady($course);
            $authoringVersion = $this->authoring->advance($lockedCourse);

            DB::commit();
            $transactionStarted = false;

            if ($sectionable instanceof Lesson && ($stagedVideoGuid || $stagedThumbnailPath)) {
                $this->dispatchMediaProbe($sectionable);
            }

            $stagedVideoGuid = null;
            $stagedThumbnailPath = null;

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'تم تحديث القسم بنجاح',
                    'section' => $section,
                    'authoring_version' => $authoringVersion,
                ]);
            }

            return $this->authoringRedirect($request, $course)
                ->with('success', 'تم تحديث القسم بنجاح');
        } catch (Throwable $e) {
            if ($transactionStarted) {
                DB::rollBack();
            }
            if ($stagedVideoGuid) {
                $bunnyService->queueVideoCleanup($stagedVideoGuid, $oldLesson, 'section_update_rollback', 24, true);
            }
            if ($stagedThumbnailPath) {
                $bunnyService->queueStorageCleanup($stagedThumbnailPath, 'section_update_rollback');
            }
            if ($e instanceof ValidationException) {
                throw $e;
            }
            report($e);
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'تعذر تحديث القسم الآن'
                ], 500);
            }

            return redirect()->back()
                ->with('error', 'تعذر تحديث القسم الآن')
                ->withInput();
        }
    }

    /**
     * Remove the specified section
     */
    public function destroy(Request $request, Course $course, CourseSection $section)
    {
        $bunnyService = $this->bunnyService;
        $this->ensureSectionBelongsToCourse($course, $section);
        $this->assertDraftForStagedAuthoring($course);
        $lesson = $section->getSectionType() === 'lesson' && $section->sectionable instanceof Lesson
            ? $section->sectionable
            : null;
        $videoGuid = $lesson?->bunny_video_id ? (string) $lesson->bunny_video_id : null;
        $thumbnailPath = $lesson?->thumbnail_path ? (string) $lesson->thumbnail_path : null;

        // The content row is intentionally retained as a recovery snapshot.
        // Only the published section pointer is removed atomically; irreversible
        // remote retirement is queued strictly after that commit.
        $request->validate(['authoring_version' => 'required|integer|min:1']);
        DB::transaction(function () use (
            $request,
            $course,
            $section,
            $videoGuid,
            $thumbnailPath,
            $lesson,
            $bunnyService
        ): void {
            $lockedCourse = $this->authoring->lock($request, $course);
            $this->assertDraftForStagedAuthoring($lockedCourse);
            $lockedSection = CourseSection::query()
                ->whereKey($section->id)
                ->where('course_id', $course->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedSection->delete();
            if ($videoGuid) {
                $candidate = $bunnyService->queueVideoCleanup(
                    $videoGuid,
                    $lesson,
                    'section_deleted',
                    168,
                    true
                );
                if (!$candidate) {
                    throw new RuntimeException('تعذر تسجيل تقاعد الفيديو بأمان');
                }
            }
            if ($thumbnailPath) {
                if (!$bunnyService->queueStorageCleanup($thumbnailPath, 'section_deleted')) {
                    throw new RuntimeException('تعذر تأمين حذف صورة الدرس');
                }
            }
            $this->assertLiveCourseReady($course);
            $this->authoring->advance($lockedCourse);
        });

        return redirect()->route('admin.courses.sections.index', $course)
            ->with('success', 'تم حذف القسم بنجاح');
    }

    /**
     * Reorder sections
     */
    public function reorder(Request $request, Course $course)
    {
        $this->assertDraftForStagedAuthoring($course);
        $request->validate([
            'sections' => 'required|array',
            'sections.*.id' => [
                'required',
                'integer',
                'distinct',
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
            'authoring_version' => 'required|integer|min:1',
        ], [
            'module_id.required' => 'اختر الوحدة التي سيظهر فيها المحتوى',
            'module_id.exists' => 'الوحدة المختارة لم تعد متاحة',
        ]);

        $requestedModules = collect($request->input('sections', []))
            ->filter(fn (array $section): bool => array_key_exists('module_id', $section))
            ->mapWithKeys(fn (array $section): array => [
                (int) $section['id'] => $section['module_id'] === null
                    ? null
                    : (int) $section['module_id'],
            ]);
        $learnerLayout = CourseSection::query()
            ->where('course_id', $course->id)
            ->where(function ($query): void {
                $query->whereIn('section_type', ['lesson', 'quiz', 'project'])
                    ->orWhereIn('sectionable_type', [Lesson::class, ItemList::class, Project::class]);
            })
            ->get(['id', 'module_id', 'section_type', 'sectionable_type'])
            ->map(fn (CourseSection $section): array => [
                'id' => $section->id,
                'is_project' => $section->getSectionType() === 'project',
                'module_id' => $requestedModules->has($section->id)
                    ? $requestedModules->get($section->id)
                    : $section->module_id,
            ]);

        if ($learnerLayout->contains(fn (array $section): bool =>
            $requestedModules->has($section['id']) && $section['module_id'] === null
        )) {
            throw ValidationException::withMessages([
                'sections' => 'يجب أن يبقى كل مقطع أو اختبار أو مشروع داخل وحدة',
            ]);
        }
        if ($learnerLayout->where('is_project', true)->groupBy('module_id')->contains(fn ($projects): bool => $projects->count() > 1)) {
            throw ValidationException::withMessages([
                'sections' => 'يمكن لكل وحدة أن تحتوي مشروع عبور واحدًا فقط.',
            ]);
        }

        $sectionIds = collect($request->input('sections', []))->pluck('id')->map(fn ($id): int => (int) $id);
        $affectedModuleIds = CourseSection::query()
            ->where('course_id', $course->id)
            ->whereIn('id', $sectionIds)
            ->pluck('module_id')
            ->merge($requestedModules->values())
            ->filter()
            ->map(fn ($moduleId): int => (int) $moduleId)
            ->unique()
            ->values();

        $version = DB::transaction(function () use ($request, $course, $affectedModuleIds): int {
            $lockedCourse = $this->authoring->lock($request, $course);
            $this->assertDraftForStagedAuthoring($lockedCourse);
            CourseSection::query()->where('course_id', $course->id)
                ->whereIn('id', collect($request->sections)->pluck('id'))
                ->orderBy('id')->lockForUpdate()->get();
            foreach ($request->sections as $sectionData) {
                $updateData = ['order' => $sectionData['order']];
                if (array_key_exists('module_id', $sectionData)) {
                    $updateData['module_id'] = $sectionData['module_id'];
                }

                CourseSection::where('id', $sectionData['id'])
                    ->where('course_id', $course->id)
                    ->update($updateData);
            }
            foreach ($affectedModuleIds as $moduleId) {
                $this->normalizeModuleOrder($course, $moduleId);
            }
            $this->assertLiveCourseReady($course);
            return $this->authoring->advance($lockedCourse);
        });

        return response()->json(['success' => true, 'authoring_version' => $version]);
    }

    private function assertDraftForStagedAuthoring(Course $course): void
    {
        if (!$course->is_coming_soon) {
            throw ValidationException::withMessages([
                'course' => [
                    'حوّل الكورس إلى مسودة قبل تغيير بنية المحتوى أو الفيديو ثم أعد نشره بعد الفحص',
                ],
            ]);
        }
    }

    private function assertLiveCourseReady(Course $course): void
    {
        if ($course->is_coming_soon) {
            return;
        }

        $audit = $this->publishingService->audit($course->fresh());
        if (!$audit['ready']) {
            throw ValidationException::withMessages([
                'course' => $audit['issues'],
            ]);
        }
    }

    private function normalizeAuthoringText(Request $request): void
    {
        $singleLine = [
            'title_ar', 'title_en', 'lesson_title_ar', 'lesson_title_en',
            'quiz_title_ar', 'quiz_title_en', 'link_title_ar', 'link_title_en',
            'course_name_ar', 'course_name_en',
        ];
        $multiline = [
            'lesson_description_ar', 'lesson_description_en',
            'quiz_description_ar', 'quiz_description_en',
            'course_description_ar', 'course_description_en',
            'project_requirements_ar', 'project_requirements_en',
        ];
        $normalized = [];
        foreach ($singleLine as $field) {
            if ($request->input($field) !== null) {
                $normalized[$field] = UnicodeText::clean($request->input($field), false);
            }
        }
        foreach ($multiline as $field) {
            if ($request->input($field) !== null) {
                $normalized[$field] = UnicodeText::clean($request->input($field));
            }
        }
        foreach (['question', 'questions'] as $field) {
            $value = $request->input($field);
            if (!is_array($value)) continue;
            $rows = $field === 'question' ? [$value] : $value;
            foreach ($rows as &$row) {
                if (!is_array($row)) continue;
                foreach (['question_title', 'question_text', 'choice1', 'choice2', 'choice3', 'choice4', 'choice5', 'choice6'] as $key) {
                    if (array_key_exists($key, $row) && is_string($row[$key])) {
                        $row[$key] = UnicodeText::clean($row[$key], $key !== 'question_title');
                    }
                }
            }
            unset($row);
            $normalized[$field] = $field === 'question' ? ($rows[0] ?? []) : $rows;
        }
        if ($normalized !== []) $request->merge($normalized);
    }

    private function validateSectionRequest(Request $request, Course $course): void
    {
        $request->validate([
            'title_ar' => 'required|string|max:255',
            'title_en' => 'nullable|string|max:255',
            'section_type' => 'required|in:lesson,quiz,project',
            'module_id' => [
                'required',
                'integer',
                Rule::exists('course_modules', 'id')->where(
                    fn ($query) => $query->where('course_id', $course->id)
                ),
            ],
            'order' => 'nullable|integer|min:0',
            'authoring_version' => 'required|integer|min:1',
            'authoring_request_id' => $request->isMethod('post') ? 'required|uuid' : 'nullable|uuid',
        ], [
            'module_id.required' => 'اختر الوحدة التي سيظهر فيها المحتوى',
            'module_id.exists' => 'الوحدة المختارة لم تعد متاحة',
        ]);

        if ($request->input('section_type') !== 'project') {
            return;
        }

        if (!$request->filled('module_id')) {
            throw ValidationException::withMessages([
                'module_id' => 'اختر الوحدة التي سيغلق مشروع العبور نهايتها.',
            ]);
        }

        $currentSection = $request->route('section');
        $projectAlreadyExists = CourseSection::query()
            ->where('course_id', $course->id)
            ->where('module_id', $request->integer('module_id'))
            ->where(function ($query): void {
                $query->where('section_type', 'project')
                    ->orWhere('sectionable_type', Project::class);
            })
            ->when($currentSection instanceof CourseSection, fn ($query) => $query->where('id', '!=', $currentSection->id))
            ->exists();

        if ($projectAlreadyExists) {
            throw ValidationException::withMessages([
                'module_id' => 'هذه الوحدة لها مشروع عبور بالفعل. يمكن لكل وحدة أن تحتوي مشروع عبور واحدًا فقط.',
            ]);
        }
    }

    private function validateLessonRequest(Request $request, bool $videoRequired): void
    {
        $request->validate([
            'lesson_title_ar' => 'required|string|max:255',
            'lesson_title_en' => 'nullable|string|max:255',
            'bunny_video' => 'nullable|file|mimetypes:video/mp4,video/quicktime,video/x-msvideo,video/webm|max:5242880',
            'bunny_video_claim' => 'nullable|string|max:4096',
            'lesson_thumbnail' => 'nullable|file|mimes:jpeg,jpg,png,webp,gif|max:2048',
            'lesson_duration_minutes' => 'nullable|integer|min:1',
        ]);
        if ($request->hasFile('bunny_video') && $request->filled('bunny_video_claim')) {
            throw ValidationException::withMessages([
                'bunny_video' => 'اختر رفعًا واحدًا فقط',
            ]);
        }
        if ($videoRequired && !$request->hasFile('bunny_video') && !$request->filled('bunny_video_claim')) {
            throw ValidationException::withMessages([
                'bunny_video' => 'اختر ملف الفيديو وانتظر اكتمال رفعه',
            ]);
        }
    }

    private function validateQuizRequest(Request $request): void
    {
        $request->validate([
            'quiz_title_ar' => 'required|string|max:255',
            'quiz_title_en' => 'nullable|string|max:255',
            'time_minutes' => 'required|integer|min:1',
            'questions' => 'required|array|min:1|max:200',
            'questions.*.id' => 'nullable|integer|exists:questions,id',
            'questions.*.question_title' => 'nullable|string|max:255',
            'questions.*.question_text' => 'required|string|max:3000',
            'questions.*.choice1' => 'required|string|max:1000',
            'questions.*.choice2' => 'required|string|max:1000',
            'questions.*.choice3' => 'required|string|max:1000',
            'questions.*.choice4' => 'required|string|max:1000',
            'questions.*.choice5' => 'nullable|string|max:1000',
            'questions.*.choice6' => 'nullable|string|max:1000',
            'questions.*.correct_answer' => 'required|integer|min:1|max:6',
            'questions.*.question_image' => 'nullable|image|mimes:jpeg,jpg,png,webp,gif|max:4096',
        ]);
    }

    private function configureUploadRuntime(): void
    {
        set_time_limit(0);
        ini_set('max_execution_time', '0');
        ini_set('memory_limit', '1024M');
    }

    private function dispatchMediaProbe(Lesson $lesson): void
    {
        try {
            DurableJobDispatch::afterCommit(new ProbeLessonMedia((int) $lesson->id));
        } catch (Throwable $exception) {
            // The durable processing row remains visible in the dashboard and
            // the scheduled reconciliation will recover it if queue dispatch
            // is briefly unavailable.
            Log::warning('Lesson media probe remains pending after dispatch failure.', [
                'lesson_id' => $lesson->id,
                'exception' => $exception::class,
            ]);
        }
    }

    private function ensureSectionBelongsToCourse(Course $course, CourseSection $section): void
    {
        abort_unless((int) $section->course_id === (int) $course->id, 404);
    }

    private function normalizeModuleOrder(Course $course, int|string|null $moduleId): void
    {
        if (!$moduleId) {
            return;
        }

        $sections = CourseSection::query()
            ->where('course_id', $course->id)
            ->where('module_id', $moduleId)
            ->orderBy('order')
            ->orderBy('id')
            ->get()
            ->sortBy(fn (CourseSection $section): int => $section->isProject() ? 1 : 0)
            ->values();

        foreach ($sections as $index => $section) {
            $normalizedOrder = $index + 1;
            if ((int) $section->order !== $normalizedOrder) {
                $section->updateQuietly(['order' => $normalizedOrder]);
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $questions
     * @param array<int|string, string> $stagedImages
     */
    private function syncQuizQuestions(
        ItemList $quiz,
        array $questions,
        array $stagedImages = []
    ): void
    {
        $keptQuestionIds = [];
        foreach ($questions as $index => $questionData) {
            $correctChoice = 'choice' . (int) $questionData['correct_answer'];
            if (trim((string) ($questionData[$correctChoice] ?? '')) === '') {
                throw ValidationException::withMessages([
                    "questions.{$index}.correct_answer" => 'اختر إجابة مكتوبة',
                ]);
            }
            $question = !empty($questionData['id'])
                ? Question::query()
                    ->whereKey($questionData['id'])
                    ->where('list_id', $quiz->id)
                    ->lockForUpdate()
                    ->first()
                : new Question(['list_id' => $quiz->id]);
            if (!$question) {
                throw ValidationException::withMessages([
                    "questions.{$index}.id" => 'السؤال لا يتبع هذا الاختبار',
                ]);
            }
            $question->fill([
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
            ])->save();
            $keptQuestionIds[] = (int) $question->id;

            $imagePath = $stagedImages[$index] ?? null;
            if (is_string($imagePath) && $imagePath !== '') {
                $oldPhotos = $question->allPhotos()->where('type', 'featured')->get();
                $newPhoto = $question->allPhotos()->firstOrCreate([
                    'path' => $imagePath,
                    'type' => 'featured',
                ]);
                $oldPhotos->where('id', '!=', $newPhoto->id)->each->delete();
            }
        }

        $quiz->questions()
            ->whereNotIn('id', $keptQuestionIds ?: [0])
            ->get()
            ->each->delete();
    }

    /** @return array<int|string, string> */
    private function stageQuestionImages(Request $request, string $operation): array
    {
        $paths = [];
        foreach ((array) $request->file('questions', []) as $index => $questionFiles) {
            $image = is_array($questionFiles) ? ($questionFiles['question_image'] ?? null) : null;
            if (!$image instanceof UploadedFile) {
                continue;
            }
            $sha = hash_file('sha256', $image->getRealPath());
            if (!is_string($sha) || $sha === '') {
                throw new RuntimeException('Question image could not be fingerprinted.');
            }
            $paths[$index] = app(StoredFileDeletionService::class)->storeTrackedUpload(
                $image,
                'questions',
                'public',
                60,
                $operation.'|'.$index.'|'.$sha
            );
        }

        return $paths;
    }

    private function assertSectionCanChangeType(CourseSection $section, object $content): void
    {
        $hasActivity = StudentSectionProgress::query()
            ->where('course_section_id', $section->id)->exists()
            || WatchingLog::query()->where('course_section_id', $section->id)->exists()
            || PlaybackSession::query()->where('course_section_id', $section->id)->exists();

        if ($content instanceof ItemList) {
            $hasActivity = $hasActivity || ExamAttempt::withTrashed()
                ->where(fn ($attempts) => $attempts
                    ->where('section_id', $section->id)
                    ->orWhere('quiz_id', $content->id))
                ->exists();
        } elseif ($content instanceof Project) {
            $hasActivity = $hasActivity
                || ProjectSubmission::query()->where('project_id', $content->id)->exists()
                || PortfolioItem::query()->where('source_project_id', $content->id)->exists();
        }

        if ($hasActivity) {
            throw ValidationException::withMessages([
                'section_type' => [
                    'هذا المحتوى مرتبط بتقدم طلاب محفوظ\nيمكنك تعديله أو حذفه لكن لا تغيّر نوعه',
                ],
            ]);
        }
    }

    private function authoringRedirect(Request $request, Course $course)
    {
        return redirect()->route(
            $request->input('return_to') === 'studio'
                ? 'admin.courses.show'
                : 'admin.courses.sections.index',
            $course
        );
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
