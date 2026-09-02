<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Models\ItemList;
use App\Models\Lesson;
use App\Models\LessonMediaState;
use App\Models\Link;
use App\Models\Project;
use App\Models\Question;
use Illuminate\Support\Facades\Storage;

class CoursePublishingService
{
    /**
     * A coming-soon card may be announced before the course content exists,
     * but never before its public identity is complete.
     */
    public function auditCatalogCard(Course $course): array
    {
        $course->loadMissing(['photo', 'teachers', 'teacher', 'classifications']);
        $issues = [];

        if (trim((string) ($course->name_ar ?: $course->name_en)) === '') {
            $issues[] = 'أضف اسمًا واضحًا للكورس.';
        }
        if (trim((string) ($course->description_ar ?: $course->description_en)) === '') {
            $issues[] = 'أضف وصفًا مختصرًا للكورس.';
        }
        if (!$course->photo && empty($course->getRawOriginal('image'))) {
            $issues[] = 'أضف غلافًا للكورس.';
        } elseif ($course->photo && !$this->storedFileExists('public', (string) $course->photo->path)) {
            $issues[] = 'غلاف الكورس غير موجود في التخزين.';
        }
        $assignedTeachers = $course->teachers->isNotEmpty()
            ? $course->teachers
            : collect([$course->teacher])->filter();
        if ($assignedTeachers->isEmpty()) {
            $issues[] = 'اربط الكورس بمدرب واحد على الأقل.';
        } elseif (!$assignedTeachers->contains(fn ($teacher) => (bool) $teacher->active)) {
            $issues[] = 'فعّل محاضرًا واحدًا على الأقل قبل نشر الكورس.';
        } elseif (!$assignedTeachers->contains(fn ($teacher) =>
            (bool) $teacher->active
            && trim((string) (
                $teacher->name_ar ?: $teacher->name_en ?: $teacher->getRawOriginal('name')
            )) !== ''
        )) {
            $issues[] = 'أكمل اسم محاضر واحد على الأقل قبل إظهار الكورس.';
        }
        if ($course->classifications->isEmpty()) {
            $issues[] = 'اختر تصنيفًا واحدًا على الأقل.';
        }

        return [
            'ready' => $issues === [],
            'issues' => array_values(array_unique($issues)),
        ];
    }

    /**
     * Audit the learner-facing course contract before a draft is published.
     *
     * Existing published courses are never changed automatically. This audit is
     * used only when an administrator explicitly moves a draft to published.
     */
    public function audit(Course $course): array
    {
        $course->loadMissing([
            'photo',
            'teachers',
            'teacher',
            'classifications',
            'accessPlans',
            'activePdfs',
            'modules.attachments',
            'modules.sections.attachments',
            'modules.sections.sectionable',
            'sections.attachments',
            'sections.sectionable',
        ]);

        $issues = [];
        $warnings = [];
        $reelsCount = 0;
        $projectsCount = 0;
        $attachmentsCount = $course->activePdfs->count();

        if (trim((string) ($course->name_ar ?: $course->name_en)) === '') {
            $issues[] = 'أضف اسمًا واضحًا للكورس.';
        }
        if (trim((string) ($course->description_ar ?: $course->description_en)) === '') {
            $issues[] = 'أضف وصفًا مختصرًا يوضح نتيجة الكورس.';
        }
        if (!$course->photo && empty($course->getRawOriginal('image'))) {
            $issues[] = 'أضف غلافًا للكورس.';
        } elseif ($course->photo && !$this->storedFileExists('public', (string) $course->photo->path)) {
            $issues[] = 'غلاف الكورس غير موجود في التخزين.';
        }
        $assignedTeachers = $course->teachers->isNotEmpty()
            ? $course->teachers
            : collect([$course->teacher])->filter();
        if ($assignedTeachers->isEmpty()) {
            $issues[] = 'اربط الكورس بمدرب واحد على الأقل.';
        } elseif (!$assignedTeachers->contains(fn ($teacher) => (bool) $teacher->active)) {
            $issues[] = 'فعّل محاضرًا واحدًا على الأقل قبل نشر الكورس.';
        } else {
            $activeTeachers = $assignedTeachers->filter(fn ($teacher) => (bool) $teacher->active);
            if (!$activeTeachers->contains(fn ($teacher) => trim((string) (
                $teacher->name_ar ?: $teacher->name_en ?: $teacher->getRawOriginal('name')
            )) !== '')) {
                $issues[] = 'أكمل اسم محاضر واحد على الأقل قبل النشر.';
            }
            if (!$activeTeachers->contains(fn ($teacher) => trim((string) (
                $teacher->bio_ar ?: $teacher->bio_en ?: $teacher->getRawOriginal('bio')
            )) !== '')) {
                $warnings[] = 'أضف نبذة قصيرة عن المحاضر لتكتمل صفحة الكورس.';
            }
        }
        if ($course->classifications->isEmpty()) {
            $issues[] = 'اختر تصنيفًا واحدًا على الأقل حتى يظهر الكورس في مكانه الصحيح.';
        }
        if ($course->modules->isEmpty()) {
            $issues[] = 'أنشئ وحدة واحدة على الأقل.';
        }

        $this->auditAccessPlans($course, $issues, $warnings);

        // A certificate wording is an editorial claim, not a cosmetic
        // fallback. If a configured key was removed or its text is empty,
        // stop publication instead of silently issuing the generic wording.
        $certificateTemplateKey = trim((string) $course->getRawOriginal(
            'certificate_text_template_key'
        ));
        $certificateTemplate = data_get(
            (array) config('certificate.text_templates', []),
            $certificateTemplateKey
        );
        if (
            !is_array($certificateTemplate)
            || trim((string) ($certificateTemplate['text'] ?? '')) === ''
        ) {
            $issues[] = 'اختر صياغة شهادة صالحة قبل النشر.';
        }

        $lessons = $course->modules
            ->flatMap(fn ($module) => $module->sections)
            ->merge($course->sections)
            ->map(fn ($section) => $section->sectionable)
            ->filter(fn ($sectionable) => $sectionable instanceof Lesson)
            ->unique(fn (Lesson $lesson) => (int) $lesson->id)
            ->values();
        $mediaStates = LessonMediaState::query()
            ->whereIn('lesson_id', $lessons->pluck('id'))
            ->get()
            ->keyBy('lesson_id');
        $lessons->each(fn (Lesson $lesson) => $lesson->setRelation(
            'mediaState',
            $mediaStates->get($lesson->id)
        ));
        $quizzes = $course->modules
            ->flatMap(fn ($module) => $module->sections)
            ->merge($course->sections)
            ->map(fn ($section) => $section->sectionable)
            ->filter(fn ($sectionable) => $sectionable instanceof ItemList && $sectionable->type === 'quiz')
            ->unique(fn (ItemList $quiz) => (int) $quiz->id)
            ->values();
        $questionsByQuiz = Question::query()
            ->whereIn('list_id', $quizzes->pluck('id'))
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->groupBy('list_id');

        foreach ($course->activePdfs as $pdf) {
            if (!$this->storedFileExists((string) $pdf->storage_disk, (string) $pdf->file_path)) {
                $issues[] = "ملف «{$pdf->title}» غير موجود في التخزين";
            }
        }

        $ungroupedSections = $course->sections->whereNull('module_id');
        if ($ungroupedSections->isNotEmpty()) {
            $issues[] = 'انقل كل أجزاء الكورس إلى وحدات؛ توجد أجزاء غير مرتبطة بوحدة.';
        }

        // The player keys progression, playback and submissions by these
        // immutable identities. Reusing one content row in two places (or
        // publishing an orphan) makes one paid step overwrite another.
        $moduleIds = [];
        $sectionIds = [];
        $contentIds = [];
        foreach ($course->modules as $module) {
            $moduleId = (int) $module->id;
            if ($moduleId < 1 || isset($moduleIds[$moduleId])) {
                $issues[] = 'توجد وحدة بهوية مفقودة أو مكررة؛ أعد حفظ خريطة الكورس.';
            }
            $moduleIds[$moduleId] = true;

            foreach ($module->sections as $section) {
                $sectionId = (int) $section->id;
                if ($sectionId < 1 || isset($sectionIds[$sectionId])) {
                    $issues[] = 'توجد خطوة بهوية مفقودة أو مكررة؛ أعد حفظ خريطة الكورس.';
                }
                $sectionIds[$sectionId] = true;

                $type = $section->getSectionType();
                $contentId = (int) $section->sectionable_id;
                $contentKey = $type . ':' . $contentId;
                if ($contentId < 1 || $section->sectionable === null) {
                    $issues[] = 'توجد خطوة غير مرتبطة بمحتواها؛ احذفها أو أعد إضافتها.';
                } elseif (isset($contentIds[$contentKey])) {
                    $issues[] = 'نفس المحتوى مضاف أكثر من مرة؛ احذف النسخة المكررة قبل النشر.';
                }
                $contentIds[$contentKey] = true;
            }
        }

        foreach ($course->modules->sortBy('order')->values() as $index => $module) {
            $moduleLabel = trim((string) ($module->title_ar ?: $module->title_en)) ?: 'الوحدة ' . ($index + 1);
            if (trim((string) ($module->title_ar ?: $module->title_en)) === '') {
                $issues[] = 'أضف عنوانًا للوحدة ' . ($index + 1);
            }
            $sections = $module->sections->sortBy('order')->values();
            $reels = $sections->filter(fn ($section) => $section->getSectionType() === 'lesson');
            $projects = $sections->filter(fn ($section) => $section->getSectionType() === 'project');
            $reelsCount += $reels->count();
            $projectsCount += $projects->count();
            $attachmentsCount += $module->attachments->count();
            foreach ($module->attachments as $attachment) {
                if (!$this->storedFileExists((string) $attachment->storage_disk, (string) $attachment->file_path)) {
                    $issues[] = "{$moduleLabel}: المرفق «{$attachment->title}» غير موجود في التخزين";
                }
            }
            if (
                trim((string) $module->attachments_link) !== ''
                && SafeExternalUrl::sanitize($module->attachments_link) === null
            ) {
                $issues[] = "{$moduleLabel}: رابط المرفقات غير صالح";
            }
            if (SafeExternalUrl::sanitize($module->attachments_link) !== null) {
                // A single external link may point to a bundle containing several files.
                $attachmentsCount++;
            }

            if ($reels->isEmpty()) {
                $issues[] = "{$moduleLabel}: أضف مقطعًا تعليميًا واحدًا على الأقل";
            }

            foreach ($sections as $section) {
                $attachmentsCount += $section->attachments->count();
                foreach ($section->attachments as $attachment) {
                    if (!$this->storedFileExists((string) $attachment->storage_disk, (string) $attachment->file_path)) {
                        $issues[] = "{$moduleLabel}: المرفق «{$attachment->title}» غير موجود في التخزين";
                    }
                }
                $sectionTitle = trim((string) ($section->title_ar ?: $section->title_en));
                if ($sectionTitle === '') {
                    $issues[] = "{$moduleLabel}: يوجد جزء بلا عنوان";
                }

                if ($section->getSectionType() === 'quiz') {
                    $quiz = $section->sectionable;
                    $quizQuestions = $quiz instanceof ItemList
                        ? $questionsByQuiz->get($quiz->id, collect())
                        : collect();
                    if (!$quiz instanceof ItemList || $quiz->type !== 'quiz') {
                        $issues[] = "{$moduleLabel}: الاختبار غير مرتبط بمحتواه";
                    } elseif (
                        trim((string) ($quiz->title_ar ?: $quiz->title_en ?: $quiz->getRawOriginal('title'))) === ''
                        || (int) $quiz->time_minutes < 1
                        || $quizQuestions->isEmpty()
                    ) {
                        $issues[] = "{$moduleLabel}: أكمل عنوان ومدة وأسئلة الاختبار «{$sectionTitle}»";
                    } elseif ($quizQuestions->contains(function (Question $question): bool {
                        $correctChoice = 'choice' . (int) $question->right_answer;
                        return trim((string) $question->question) === ''
                            || trim((string) $question->choice1) === ''
                            || trim((string) $question->choice2) === ''
                            || !in_array((int) $question->right_answer, [1, 2, 3, 4, 5, 6], true)
                            || trim((string) ($question->{$correctChoice} ?? '')) === '';
                    })) {
                        $issues[] = "{$moduleLabel}: راجع الأسئلة والإجابات الصحيحة في «{$sectionTitle}»";
                    }
                }

                if (in_array($section->getSectionType(), ['link', 'course', 'question'], true)) {
                    $issues[] = "{$moduleLabel}: المحتوى «{$sectionTitle}» لا يظهر داخل المشغل؛ استخدم مرفقات الكورس أو مقطع فيديو";
                }

                if ($section->getSectionType() === 'link') {
                    $link = $section->sectionable;
                    if (!$link instanceof Link || SafeExternalUrl::sanitize($link->link) === null) {
                        $issues[] = "{$moduleLabel}: الرابط «{$sectionTitle}» غير صالح";
                    }
                }

                if ($section->getSectionType() === 'pdf') {
                    $issues[] = "{$moduleLabel}: انقل الملف «{$sectionTitle}» إلى مرفقات الكورس بدل خريطة المشاهدة";
                }
            }

            $quizPositions = $sections
                ->filter(fn ($section) => $section->getSectionType() === 'quiz')
                ->keys();
            $lastLessonPosition = $sections
                ->filter(fn ($section) => $section->getSectionType() === 'lesson')
                ->keys()
                ->max();
            if ($quizPositions->isNotEmpty() && $lastLessonPosition !== null && $quizPositions->min() < $lastLessonPosition) {
                $issues[] = "{$moduleLabel}: ضع الاختبار بعد آخر مقطع في الوحدة";
            }

            foreach ($reels as $reel) {
                $lesson = $reel->sectionable;
                $reelTitle = trim((string) ($reel->title_ar ?: $reel->title_en));
                if ($reelTitle === '') {
                    $issues[] = "{$moduleLabel}: أضف عنوانًا للمقطع";
                    $reelTitle = 'بلا عنوان';
                }
                if (
                    $lesson instanceof Lesson
                    && (int) $lesson->duration_minutes < 1
                    && (int) ($lesson->mediaState?->duration_seconds ?? 0) < 1
                ) {
                    $issues[] = "{$moduleLabel}: حدّد مدة المقطع «{$reelTitle}» قبل النشر";
                }
                if (!$lesson instanceof Lesson || !$this->lessonHasPlayableVideo($lesson)) {
                    $issues[] = "{$moduleLabel}: المقطع «{$reelTitle}» لا يحتوي على فيديو صالح";
                }
                if ($lesson instanceof Lesson) {
                    $mediaState = $lesson->mediaState;
                    if (!$mediaState || !$mediaState->last_reconciled_at) {
                        $issues[] = "{$moduleLabel}: افحص تشغيل الفيديو «{$reelTitle}» فعليًا قبل النشر";
                    } elseif (
                        $mediaState->status !== 'ready'
                        || (string) $mediaState->provider_media_id !== (string) $lesson->bunny_video_id
                        || $this->hasBlockingMediaIssue((array) $mediaState->integrity_issues)
                    ) {
                        $issues[] = "{$moduleLabel}: الفيديو «{$reelTitle}» غير جاهز للمشاهدة";
                    } elseif ($mediaState->integrity_status !== 'healthy') {
                        $warnings[] = "{$moduleLabel}: راجع صورة ومدة وجودة الفيديو «{$reelTitle}»";
                    }
                }
            }

            foreach ($projects as $projectSection) {
                $project = $projectSection->sectionable;
                $projectTitle = trim((string) ($projectSection->title_ar ?: $projectSection->title_en)) ?: 'مشروع بلا عنوان';
                if (!$project instanceof Project) {
                    $issues[] = "{$moduleLabel}: المشروع «{$projectTitle}» غير مرتبط بمحتواه";
                    continue;
                }
                if (trim((string) ($project->requirements_text_ar ?: $project->requirements_text_en)) === '') {
                    $issues[] = "{$moduleLabel}: اكتب المطلوب في المشروع «{$projectTitle}»";
                }
                if (trim((string) $project->ai_prompt) === '') {
                    $issues[] = "{$moduleLabel}: أضف توجيه تقييم المشروع «{$projectTitle}»";
                }
                $projectModel = trim((string) $project->ai_model_type);
                $allowedModels = array_values(array_filter(config('openrouter.allowed_models', [])));
                if ($projectModel !== '' && !in_array($projectModel, $allowedModels, true)) {
                    $issues[] = "{$moduleLabel}: نموذج تقييم المشروع «{$projectTitle}» غير متاح";
                }
                $projectTokens = (int) ($project->tokens_number ?? 0);
                if ($projectTokens > 0 && (
                    $projectTokens < 80
                    || $projectTokens > max(80, (int) config('openrouter.max_tokens', 500))
                )) {
                    $issues[] = "{$moduleLabel}: حد رد تقييم المشروع «{$projectTitle}» غير صالح";
                }
            }

            if ($projects->count() > 1) {
                $issues[] = "{$moduleLabel}: يمكن إضافة مشروع عبور واحد فقط؛ قسّم المشاريع على وحدات مستقلة.";
            } elseif ($projects->isNotEmpty() && $sections->last()?->getSectionType() !== 'project') {
                $issues[] = "{$moduleLabel}: يجب أن يكون مشروع العبور آخر جزء في الوحدة.";
            }
        }

        $declaredReelsCount = (int) ($course->video_count ?? 0);
        if ($declaredReelsCount > 0 && $declaredReelsCount !== $reelsCount) {
            $issues[] = "عدد المقاطع المعلن {$declaredReelsCount} بينما الموجود {$reelsCount}";
        }
        if ((int) ($course->files_count ?? 0) > 0 && $attachmentsCount === 0) {
            $issues[] = 'الكورس يعلن وجود مرفقات، لكن لا يوجد رابط أو ملف مرفق بأي وحدة.';
        }
        if ($course->attachment_prompt_enabled && $attachmentsCount === 0) {
            $issues[] = 'نافذة المرفقات مفعلة لكن الكورس لا يحتوي على مرفقات.';
        }
        if ($course->attachment_prompt_enabled && $attachmentsCount > 0) {
            $promptFrequency = (string) ($course->attachment_prompt_frequency
                ?: config('course_attachments.prompt.default_frequency', 'once_per_course'));
            if (!array_key_exists(
                $promptFrequency,
                (array) config('course_attachments.prompt.frequencies', [])
            )) {
                $issues[] = 'اختر متى يتكرر تنبيه المرفقات.';
            }
            $orderedModules = $course->modules->sortBy('order')->values();
            $promptModule = $course->activePdfs->isNotEmpty()
                ? $orderedModules->first()
                : $orderedModules->first(fn ($module): bool =>
                    $module->attachments->isNotEmpty()
                    || SafeExternalUrl::sanitize($module->attachments_link) !== null
                );
            $firstLesson = $promptModule?->sections
                ->sortBy('order')
                ->first(fn ($section) => $section->getSectionType() === 'lesson')
                ?->sectionable;
            $firstDurationSeconds = $firstLesson instanceof Lesson
                ? max(
                    (int) $firstLesson->duration_minutes * 60,
                    (int) ($firstLesson->mediaState?->duration_seconds ?? 0)
                )
                : 0;
            if (
                $firstDurationSeconds > 0
                && (int) $course->attachment_prompt_at_seconds >= $firstDurationSeconds
            ) {
                $issues[] = 'موعد نافذة المرفقات يأتي بعد نهاية أول مقطع.';
            }
        }

        if ($course->awards_badge && !in_array($course->badge_track, ['professional', 'freelance'], true)) {
            $issues[] = 'الشارات متاحة فقط للمسار المهني أو الفريلانس؛ صحح سياسة الشارة.';
        }

        $graduationProjectsCount = $course->sections->filter(function ($section): bool {
            return $section->getSectionType() === 'project'
                && $section->sectionable instanceof Project
                && (bool) $section->sectionable->is_graduation_project;
        })->count();
        if ($graduationProjectsCount > 1) {
            $issues[] = 'حدد مشروع تخرج واحدًا فقط، وهو مشروع آخر وحدة.';
        }

        if ($graduationProjectsCount === 1) {
            $lastModule = $course->modules->sortBy('order')->last();
            $lastSection = $lastModule?->sections->sortBy('order')->last();
            $graduationProject = $lastSection?->sectionable;
            if (!$graduationProject instanceof Project || !$graduationProject->is_graduation_project) {
                $issues[] = 'مشروع التخرج - إن اخترته - يجب أن يكون آخر جزء في آخر وحدة.';
            }
        }

        if ($course->ai_chat_enabled && trim((string) $course->chat_ai_prompt) === '') {
            $warnings[] = 'أضف توجيهًا مختصرًا للشات عن اتجاه الكورس وأفكاره؛ سيستخدم الوصف كبديل حاليًا.';
        }

        return [
            'ready' => $issues === [],
            'issues' => array_values(array_unique($issues)),
            'warnings' => array_values(array_unique($warnings)),
            'counts' => [
                'modules' => $course->modules->count(),
                'reels' => $reelsCount,
                'projects' => $projectsCount,
                'attachments' => $attachmentsCount,
            ],
        ];
    }

    private function lessonHasPlayableVideo(Lesson $lesson): bool
    {
        // The current mobile player and signed-delivery contract are Bunny-only.
        // A syntactically valid legacy YouTube URL is not production-playable.
        return $lesson->video_source_type === 'bunny'
            && trim((string) $lesson->bunny_video_id) !== '';
    }

    private function storedFileExists(string $disk, string $path): bool
    {
        if ($disk === '' || $path === '') return false;
        try {
            return Storage::disk($disk)->exists($path);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<int, string> $issues @param array<int, string> $warnings */
    private function auditAccessPlans(Course $course, array &$issues, array &$warnings): void
    {
        $plans = $course->accessPlans->keyBy('code');
        $missing = array_diff(CourseAccessPlan::CODES, $plans->keys()->all());
        if ($missing !== [] || $plans->count() !== count(CourseAccessPlan::CODES)) {
            $issues[] = 'أكمل فئات الكورس الثلاث قبل النشر.';
            return;
        }

        $previousPrice = null;
        $chatPlans = 0;
        foreach (CourseAccessPlan::CODES as $code) {
            $plan = $plans->get($code);
            if (!$plan?->is_active) {
                $issues[] = 'فعّل فئات الكورس الثلاث قبل النشر.';
                continue;
            }
            if (trim((string) $plan->name_ar) === '') {
                $issues[] = 'أضف اسمًا واضحًا لكل فئة سعرية.';
            }

            $price = max(0, (int) $plan->price_coins);
            if ($previousPrice !== null && $price < $previousPrice) {
                $issues[] = 'رتّب أسعار الفئات من الأقل إلى الأعلى.';
            }
            $previousPrice = $price;

            $feedback = (string) $plan->project_feedback_level;
            $hasProjectCost = in_array($feedback, [
                CourseAccessPlan::FEEDBACK_REPORT,
                CourseAccessPlan::FEEDBACK_ENHANCED,
            ], true);
            $hasVariableCost = (bool) $plan->chat_enabled || $hasProjectCost;
            if (
                (int) $plan->minimum_paid_coins > $price
                || ($hasVariableCost && (int) $plan->minimum_paid_coins <= 0)
            ) {
                $issues[] = "الفئة «{$plan->name_ar}» تحتاج حدًا مدفوعًا صالحًا لتغطية خدماتها";
            }

            if ($plan->chat_enabled) {
                $chatPlans++;
                if (
                    (int) $plan->chat_message_limit < 1
                    || (int) $plan->chat_token_budget < (int) $plan->max_output_tokens
                    || (float) $plan->ai_budget_usd <= 0
                    || (float) $plan->request_reserve_usd <= 0
                    || (float) $plan->request_reserve_usd > (float) $plan->ai_budget_usd
                ) {
                    $issues[] = "ميزانية المحادثة في الفئة «{$plan->name_ar}» غير صالحة";
                }
                if ((bool) $plan->chat_attachments_enabled
                    && ((int) $plan->chat_attachment_max_files < 1
                        || !(bool) $course->chat_attachments_enabled)) {
                    $issues[] = "مرفقات المحادثة في الفئة «{$plan->name_ar}» غير مكتملة الإعداد";
                }
            }

            if ($hasProjectCost && (
                (int) $plan->project_feedback_token_budget < (int) $plan->max_output_tokens
                || (float) $plan->project_feedback_budget_usd <= 0
                || (float) $plan->project_feedback_reserve_usd <= 0
                || (float) $plan->project_feedback_reserve_usd > (float) $plan->project_feedback_budget_usd
            )) {
                $issues[] = "ميزانية تقييم المشاريع في الفئة «{$plan->name_ar}» غير صالحة";
            }
            if ($feedback === CourseAccessPlan::FEEDBACK_ENHANCED && (
                (int) $plan->project_followup_message_limit < 1
                || (int) $plan->project_followup_token_budget < (int) $plan->max_output_tokens
                || (float) $plan->project_followup_budget_usd <= 0
                || (float) $plan->project_followup_reserve_usd <= 0
                || (float) $plan->project_followup_reserve_usd > (float) $plan->project_followup_budget_usd
            )) {
                $issues[] = "ميزانية متابعة تقرير المشروع في الفئة «{$plan->name_ar}» غير صالحة";
            }
            if ((bool) $plan->project_followup_attachments_enabled && (
                $feedback !== CourseAccessPlan::FEEDBACK_ENHANCED
                || (int) $plan->project_followup_attachment_max_files < 1
            )) {
                $issues[] = "مرفقات متابعة المشروع في الفئة «{$plan->name_ar}» غير مكتملة الإعداد";
            }
        }

        if ($chatPlans > 0 && !$course->ai_chat_enabled) {
            $issues[] = 'الفئات تتضمن المحادثة لكن شات الكورس غير مفعل.';
        } elseif ($course->ai_chat_enabled && $chatPlans === 0) {
            $warnings[] = 'شات الكورس مفعل لكن لا توجد فئة تمنح الوصول إليه.';
        }
    }

    /** @param array<int, mixed> $integrityIssues */
    private function hasBlockingMediaIssue(array $integrityIssues): bool
    {
        $blockingCodes = [
            'missing_secure_source',
            'provider_unreachable',
            'provider_encode_failed',
            'provider_still_processing',
            'signed_manifest_unavailable',
            'manifest_http_error',
            'manifest_invalid',
            'manifest_unreachable',
        ];

        return collect($integrityIssues)->contains(
            fn ($issue) => is_array($issue)
                && in_array((string) ($issue['code'] ?? ''), $blockingCodes, true)
        );
    }
}
