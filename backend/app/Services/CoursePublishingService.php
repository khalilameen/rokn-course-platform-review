<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Project;

final class CoursePublishingService
{
    /**
     * A coming-soon card may be announced before the course content exists,
     * but never before its public identity is complete.
     */
    public function auditCatalogCard(Course $course): array
    {
        $course->loadMissing(['photo', 'teachers', 'classifications']);
        $issues = [];

        if (trim((string) ($course->name_ar ?: $course->name_en)) === '') {
            $issues[] = 'أضف اسمًا واضحًا للكورس.';
        }
        if (trim((string) ($course->description_ar ?: $course->description_en)) === '') {
            $issues[] = 'أضف وصفًا مختصرًا للكورس.';
        }
        if (!$course->photo && empty($course->getRawOriginal('image'))) {
            $issues[] = 'أضف غلافًا للكورس.';
        }
        if ($course->teachers->isEmpty() && empty($course->teacher_id)) {
            $issues[] = 'اربط الكورس بمدرب واحد على الأقل.';
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
            'classifications',
            'modules.sections.sectionable',
            'sections.sectionable',
        ]);

        $issues = [];
        $warnings = [];
        $reelsCount = 0;
        $projectsCount = 0;
        $attachmentsCount = 0;

        if (trim((string) ($course->name_ar ?: $course->name_en)) === '') {
            $issues[] = 'أضف اسمًا واضحًا للكورس.';
        }
        if (trim((string) ($course->description_ar ?: $course->description_en)) === '') {
            $issues[] = 'أضف وصفًا مختصرًا يوضح نتيجة الكورس.';
        }
        if ($course->price === null) {
            $issues[] = 'حدد سعر الكورس بعملات ركن، ويمكن أن يكون صفرًا للكورس المجاني.';
        }
        if (!$course->photo && empty($course->getRawOriginal('image'))) {
            $issues[] = 'أضف غلافًا للكورس.';
        }
        if ($course->teachers->isEmpty() && empty($course->teacher_id)) {
            $issues[] = 'اربط الكورس بمدرب واحد على الأقل.';
        }
        if ($course->classifications->isEmpty()) {
            $issues[] = 'اختر تصنيفًا واحدًا على الأقل حتى يظهر الكورس في مكانه الصحيح.';
        }
        if ($course->modules->isEmpty()) {
            $issues[] = 'أنشئ وحدة واحدة على الأقل.';
        }

        $ungroupedSections = $course->sections->whereNull('module_id');
        if ($ungroupedSections->isNotEmpty()) {
            $issues[] = 'انقل كل أجزاء الكورس إلى وحدات؛ توجد أجزاء غير مرتبطة بوحدة.';
        }

        foreach ($course->modules->sortBy('order')->values() as $index => $module) {
            $moduleLabel = trim((string) ($module->title_ar ?: $module->title_en)) ?: 'الوحدة ' . ($index + 1);
            $sections = $module->sections->sortBy('order')->values();
            $reels = $sections->filter(fn ($section) => $section->getSectionType() === 'lesson');
            $projects = $sections->filter(fn ($section) => $section->getSectionType() === 'project');
            $reelsCount += $reels->count();
            $projectsCount += $projects->count();
            $attachmentsCount += $module->attachments()->count();
            if (filter_var($module->attachments_link, FILTER_VALIDATE_URL) !== false) {
                // A single external link may point to a bundle containing several files.
                $attachmentsCount++;
            }

            if ($reels->isEmpty()) {
                $issues[] = "{$moduleLabel}: أضف مقطعًا تعليميًا واحدًا على الأقل";
            }

            foreach ($reels as $reel) {
                $lesson = $reel->sectionable;
                if ($lesson instanceof Lesson && (int) $lesson->duration_minutes < 1) {
                    $reelTitle = trim((string) ($reel->title_ar ?: $reel->title_en)) ?: 'بلا عنوان';
                    $issues[] = "{$moduleLabel}: حدّد مدة المقطع «{$reelTitle}» قبل النشر";
                }
                if (!$lesson instanceof Lesson || !$this->lessonHasPlayableVideo($lesson)) {
                    $reelTitle = trim((string) ($reel->title_ar ?: $reel->title_en)) ?: 'بلا عنوان';
                    $issues[] = "{$moduleLabel}: المقطع «{$reelTitle}» لا يحتوي على فيديو صالح";
                }
                if ($lesson instanceof Lesson) {
                    $mediaState = $lesson->mediaState()->first();
                    if ($mediaState && $mediaState->status !== 'ready') {
                        $reelTitle = trim((string) ($reel->title_ar ?: $reel->title_en)) ?: 'بلا عنوان';
                        $issues[] = "{$moduleLabel}: الفيديو «{$reelTitle}» لم يكتمل تجهيزه بعد ({$mediaState->status}).";
                    }
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
}
