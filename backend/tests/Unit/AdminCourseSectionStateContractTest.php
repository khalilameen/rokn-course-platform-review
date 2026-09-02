<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AdminCourseSectionStateContractTest extends TestCase
{
    public function test_bunny_resume_state_is_owned_expiring_and_terminal_errors_are_machine_readable(): void
    {
        $view = $this->source('resources/views/admin/course-sections/partials/bunny-direct-upload.blade.php');
        $service = $this->source('app/Services/BunnyDirectUploadService.php');
        $controller = $this->source('app/Http/Controllers/Admin/CourseSectionVideoUploadController.php');

        self::assertStringContainsString('const ownerId = @json((string) auth()->id());', $view);
        self::assertStringContainsString('rokn:bunny-upload:${ownerId}:${courseId}:${sectionId}', $view);
        self::assertStringContainsString('Number(saved.version) === recordVersion', $view);
        self::assertStringContainsString('String(saved.ownerId) === ownerId', $view);
        self::assertStringContainsString('claimExpiresAt <= Date.now()', $view);
        self::assertStringContainsString("'bunny_upload_claim_unavailable'", $view);
        self::assertStringContainsString("'bunny_upload_operation_unavailable'", $view);
        self::assertStringContainsString('serverRejectedClaim', $view);
        self::assertStringContainsString('const upload = async (file, restartCount = 0)', $view);
        self::assertStringContainsString('if (restartCount >= 1)', $view);
        self::assertStringNotContainsString("message || '').includes", $view);

        self::assertStringContainsString("'bunny_video_claim_terminal' => 'claim_unavailable'", $service);
        self::assertStringContainsString("'bunny_upload_operation_terminal' => 'operation_unavailable'", $service);
        self::assertStringContainsString("'claim_expires_at' => gmdate", $service);
        self::assertStringContainsString("'bunny_upload_claim_unavailable'", $controller);
        self::assertStringContainsString("'bunny_upload_operation_unavailable'", $controller);
        self::assertStringContainsString('], 410);', $controller);
    }

    public function test_quiz_edit_draft_builds_dynamic_rows_and_switching_type_keeps_unsaved_rows(): void
    {
        $edit = $this->source('resources/views/admin/course-sections/partials/edit/scripts.blade.php');

        self::assertStringContainsString("const flashedQuestions = @json(array_values(old('questions', [])));", $edit);
        self::assertStringContainsString("document.addEventListener('rokn:authoring-draft-prepare'", $edit);
        self::assertStringContainsString("name.match(/^questions\\[(\\d+)\\]/)", $edit);
        self::assertStringContainsString('while (questionCount <= maxIndex) addQuestion();', $edit);
        self::assertStringContainsString('question: question.question_text ?? question.question', $edit);
        self::assertStringContainsString('right_answer: question.correct_answer ?? question.right_answer', $edit);
        self::assertStringNotContainsString('Reset questionsLoaded flag when switching away from quiz', $edit);
        self::assertDoesNotMatchRegularExpression(
            "/form\.id === 'quiz-form'\s*&&\s*type !== 'quiz'[\\s\\S]{0,160}questionsLoaded = false/",
            $edit
        );

        self::assertLessThan(
            strpos($edit, '// Handle type selection'),
            strpos($edit, 'let questionsLoaded = false;'),
            'Quiz state must exist before the initial type click can inspect it.'
        );
    }

    public function test_publishing_a_draft_preserves_the_explicit_catalog_visibility_choice(): void
    {
        $controller = $this->source('app/Http/Controllers/Admin/CourseController.php');

        self::assertStringContainsString('$catalogAnnouncementRequested,', $controller);
        self::assertStringContainsString(
            "'is_coming_soon' => false,\n                        'is_catalog_visible' => \$catalogAnnouncementRequested,",
            str_replace("\r\n", "\n", $controller)
        );
    }

    private function source(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/'.$path);
        self::assertNotFalse($source, $path);

        return $source;
    }
}
