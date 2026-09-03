<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CourseAuthoringPolicyTest extends TestCase
{
    public function test_publishing_allows_theory_modules_without_crossing_projects(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/app/Services/CoursePublishingService.php');

        self::assertNotFalse($source);
        self::assertStringNotContainsString('أضف مشروع العبور بعد آخر خطوة', $source);
        self::assertStringContainsString('$projects->count() > 1', $source);
        self::assertStringContainsString('$projects->isNotEmpty()', $source);
        self::assertStringContainsString('$graduationProjectsCount === 1', $source);
    }

    public function test_authoring_ui_exposes_one_visible_reel_title_and_a_separate_caption(): void
    {
        $basic = file_get_contents(dirname(__DIR__, 2).'/resources/views/admin/course-sections/partials/create/basic-information.blade.php');
        $lesson = file_get_contents(dirname(__DIR__, 2).'/resources/views/admin/course-sections/partials/create/lesson-form.blade.php');
        $scripts = file_get_contents(dirname(__DIR__, 2).'/resources/views/admin/course-sections/partials/create/scripts.blade.php');

        self::assertStringContainsString('العنوان الذي سيظهر للطالب', $basic);
        self::assertStringContainsString('كابشن المقطع', $lesson);
        self::assertStringContainsString('lesson-title-sync-fields', $lesson);
        self::assertStringContainsString('syncLessonTitles', $scripts);
    }

    public function test_modules_only_ask_moderators_for_a_title_and_attachments(): void
    {
        foreach (['create', 'edit'] as $screen) {
            $source = file_get_contents(dirname(__DIR__, 2)."/resources/views/admin/course-modules/{$screen}.blade.php");

            self::assertNotFalse($source);
            self::assertStringContainsString("Form::text('title_ar'", $source);
            self::assertStringNotContainsString("Form::textarea('description_ar'", $source);
            self::assertStringNotContainsString("Form::textarea('description_en'", $source);
        }

        foreach (['CourseResource.php', 'BaseCourseResource.php'] as $resource) {
            $source = file_get_contents(dirname(__DIR__, 2)."/app/Http/Resources/{$resource}");

            self::assertNotFalse($source);
            self::assertStringNotContainsString("'description' => \$module->description", $source);
        }
    }

    public function test_project_authoring_cannot_submit_ai_runtime_policy(): void
    {
        $controller = file_get_contents(
            dirname(__DIR__, 2).'/app/Http/Controllers/Admin/CourseSectionController.php'
        );
        self::assertNotFalse($controller);
        foreach (['$request->ai_prompt', '$request->ai_model_type', '$request->temperature', '$request->tokens_number', '$request->passing_score'] as $input) {
            self::assertStringNotContainsString($input, $controller);
        }

        foreach (['create', 'edit'] as $screen) {
            $project = file_get_contents(
                dirname(__DIR__, 2)."/resources/views/admin/course-sections/partials/{$screen}/project-form.blade.php"
            );
            self::assertNotFalse($project);
            self::assertStringContainsString('project_requirements_ar', $project);
            self::assertStringNotContainsString('ai_prompt', $project);
            self::assertStringNotContainsString('tokens_number', $project);
        }
    }

    public function test_ai_runtime_never_reads_legacy_moderator_model_fields(): void
    {
        $sources = [
            file_get_contents(dirname(__DIR__, 2).'/app/Http/Controllers/API/CourseChatController.php'),
            file_get_contents(dirname(__DIR__, 2).'/app/Jobs/GenerateProjectFeedback.php'),
            file_get_contents(dirname(__DIR__, 2).'/app/Jobs/GenerateProjectFeedbackReply.php'),
        ];

        foreach ($sources as $source) {
            self::assertNotFalse($source);
            self::assertStringNotContainsString("['model_override']", $source);
        }

        self::assertStringContainsString(
            'currentLearnerEntityMap(',
            (string) $sources[1]
        );
        self::assertStringContainsString(
            'currentLearnerEntityMap(',
            (string) $sources[2]
        );
    }

    public function test_unknown_provider_outcome_is_not_exposed_as_a_retryable_generic_failure(): void
    {
        $controller = file_get_contents(
            dirname(__DIR__, 2).'/app/Http/Controllers/API/CourseChatController.php'
        );

        self::assertNotFalse($controller);
        self::assertStringContainsString(
            "'chat_provider_outcome_unknown'",
            $controller
        );
        self::assertStringContainsString(
            '$this->terminalFailureResponse($this->activeTurn, $clientRequestId)',
            $controller
        );
        self::assertStringContainsString("'can_retry' => \$canRetry", $controller);
    }
}
