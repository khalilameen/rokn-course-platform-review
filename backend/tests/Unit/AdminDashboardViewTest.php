<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AdminDashboardViewTest extends TestCase
{
    #[DataProvider('dashboardViews')]
    public function test_dashboard_views_use_the_shared_stylesheet(string $view): void
    {
        $source = $this->viewSource($view);

        self::assertStringNotContainsString('<style', $source);
        self::assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $source);
        self::assertStringContainsString('admin-', $source);
    }

    #[DataProvider('mfaViews')]
    public function test_mfa_pages_use_the_admin_auth_layout(string $view): void
    {
        $source = $this->viewSource($view);

        self::assertStringContainsString("@extends('admin.layouts.auth')", $source);
        self::assertStringNotContainsString('<style', $source);
    }

    public function test_course_editor_keeps_its_styles_and_sections_out_of_the_page_template(): void
    {
        $editor = $this->viewSource('courses/edit.blade.php');
        $partials = [
            'basic-information',
            'course-settings',
            'ai-settings',
            'access-plans',
            'course-image',
        ];

        self::assertStringContainsString('admin/assets/css/course-editor.css', $editor);
        self::assertLessThanOrEqual(350, substr_count($editor, "\n") + 1);

        $formSource = $editor;
        foreach ($partials as $partial) {
            $view = "courses/partials/edit/{$partial}.blade.php";
            $source = $this->viewSource($view);

            self::assertStringContainsString(
                "@include('admin.courses.partials.edit.{$partial}')",
                $editor
            );
            $this->assertNoInlineStyles($source, $view);
            self::assertLessThanOrEqual(250, substr_count($source, "\n") + 1, $view);
            $formSource .= $source;
        }

        $this->assertNoInlineStyles($editor, 'courses/edit.blade.php');
        foreach ([
            'classification_ids[]',
            'teacher_ids[]',
            'ai_chat_enabled',
            'access_plans[{{ $code }}][price_coins]',
            'access_plans[{{ $code }}][ai_budget_usd]',
            'access_plans[{{ $code }}][project_feedback_level]',
        ] as $fieldName) {
            self::assertStringContainsString($fieldName, $formSource);
        }

        self::assertStringContainsString("document.addEventListener('DOMContentLoaded'", $editor);
        self::assertStringContainsString('function handleFileSelect(e)', $editor);
        self::assertStringContainsString('function checkForChanges()', $editor);
        self::assertStringContainsString('<label class="file-upload-area" for="image">', $formSource);
        self::assertStringNotContainsString('onclick="document.getElementById(\'image\').click()"', $formSource);

        $stylesheet = file_get_contents(
            dirname(__DIR__, 2).'/public/admin/assets/css/course-editor.css'
        );
        self::assertNotFalse($stylesheet);
        self::assertStringContainsString('.course-editor', $stylesheet);
    }

    #[DataProvider('courseSectionEditors')]
    public function test_course_section_editors_keep_fields_and_scripts_in_partials(
        string $mode,
        array $expectedFields
    ): void {
        $editor = $this->viewSource("course-sections/{$mode}.blade.php");
        $partials = [
            'basic-information',
            'type-selection',
            'lesson-form',
            'link-form',
            'quiz-form',
            'project-form',
            'course-form',
            'scripts',
        ];

        self::assertLessThanOrEqual(150, substr_count($editor, "\n") + 1);
        self::assertStringContainsString('admin/assets/css/course-sections-editor.css', $editor);
        $this->assertNoInlineStyles($editor, "course-sections/{$mode}.blade.php");

        $source = $editor;
        foreach ($partials as $partial) {
            $view = "course-sections/partials/{$mode}/{$partial}.blade.php";
            $partialSource = $this->viewSource($view);

            self::assertStringContainsString(
                "@include('admin.course-sections.partials.{$mode}.{$partial}')",
                $editor
            );
            $this->assertNoInlineStyles($partialSource, $view);
            $source .= $partialSource;
        }

        foreach ($expectedFields as $fieldName) {
            self::assertStringContainsString($fieldName, $source);
        }

        self::assertStringContainsString("document.addEventListener('DOMContentLoaded'", $source);
        self::assertStringContainsString('function updateRequiredFields', $source);
        self::assertStringContainsString('function createQuestionTemplate', $source);
    }

    #[DataProvider('orderScreens')]
    public function test_order_screens_keep_their_contracts_in_small_partials(
        string $screen,
        array $partials,
        array $expectedContracts
    ): void {
        $view = "orders/{$screen}.blade.php";
        $screenSource = $this->viewSource($view);

        self::assertLessThanOrEqual(70, substr_count($screenSource, "\n") + 1);
        $this->assertNoInlineStyles($screenSource, $view);

        $source = $screenSource;
        foreach ($partials as $partial) {
            $partialView = "orders/partials/{$screen}/{$partial}.blade.php";
            $partialSource = $this->viewSource($partialView);

            self::assertStringContainsString(
                "@include('admin.orders.partials.{$screen}.{$partial}')",
                $screenSource
            );
            $this->assertNoInlineStyles($partialSource, $partialView);
            self::assertLessThanOrEqual(250, substr_count($partialSource, "\n") + 1, $partialView);
            $source .= $partialSource;
        }

        foreach ($expectedContracts as $contract) {
            self::assertStringContainsString($contract, $source);
        }
    }

    public function test_course_create_keeps_fields_and_behavior_in_partials(): void
    {
        $editor = $this->viewSource('courses/create.blade.php');
        $partials = [
            'basic-information',
            'course-settings',
            'ai-settings',
            'course-image',
            'scripts',
        ];

        self::assertLessThanOrEqual(100, substr_count($editor, "\n") + 1);
        $this->assertNoInlineStyles($editor, 'courses/create.blade.php');

        $source = $editor;
        foreach ($partials as $partial) {
            $view = "courses/partials/create/{$partial}.blade.php";
            $partialSource = $this->viewSource($view);

            self::assertStringContainsString(
                "@include('admin.courses.partials.create.{$partial}')",
                $editor
            );
            $this->assertNoInlineStyles($partialSource, $view);
            self::assertLessThanOrEqual(200, substr_count($partialSource, "\n") + 1, $view);
            $source .= $partialSource;
        }

        foreach ([
            "Form::text('name_ar'",
            "Form::checkbox('is_main_course'",
            "Form::checkbox('is_coming_soon'",
            "Form::checkbox('ai_chat_enabled'",
            'name="image"',
        ] as $contract) {
            self::assertStringContainsString($contract, $source);
        }

        self::assertStringContainsString("document.addEventListener('DOMContentLoaded'", $source);
        self::assertStringContainsString('function updateProgress()', $source);
        self::assertStringContainsString('<label class="file-upload-area" for="image">', $source);
        self::assertStringNotContainsString('onclick="document.getElementById(\'image\').click()"', $source);

        $stylesheet = file_get_contents(
            dirname(__DIR__, 2).'/public/admin/assets/css/course-create.css'
        );
        self::assertNotFalse($stylesheet);
        self::assertStringNotContainsString('-9999px', $stylesheet);
    }

    #[DataProvider('courseScreens')]
    public function test_course_screens_keep_large_regions_in_partials(
        string $screen,
        array $partials,
        array $expectedContracts
    ): void {
        $view = "courses/{$screen}.blade.php";
        $screenSource = $this->viewSource($view);
        $this->assertNoInlineStyles($screenSource, $view);

        $source = $screenSource;
        foreach ($partials as $partial) {
            $partialView = "courses/partials/{$screen}/{$partial}.blade.php";
            $partialSource = $this->viewSource($partialView);

            self::assertStringContainsString(
                "@include('admin.courses.partials.{$screen}.{$partial}')",
                $screenSource
            );
            $this->assertNoInlineStyles($partialSource, $partialView);
            self::assertLessThanOrEqual(300, substr_count($partialSource, "\n") + 1, $partialView);
            $source .= $partialSource;
        }

        foreach ($expectedContracts as $contract) {
            self::assertStringContainsString($contract, $source);
        }
    }

    /** @return array<string, array{string}> */
    public static function dashboardViews(): array
    {
        return [
            'product operations' => ['product_operations.blade.php'],
            'playback operations' => ['playback_operations.blade.php'],
            'playback summary' => ['partials/playback-operations-summary.blade.php'],
            'feedback list' => ['feedback/index.blade.php'],
            'feedback details' => ['feedback/show.blade.php'],
            'project list' => ['project-submissions/index.blade.php'],
            'project review' => ['project-submissions/show.blade.php'],
            'device sessions' => ['user_sessions/index.blade.php'],
            'payment reconciliation queue' => ['payment-reconciliation-findings/index.blade.php'],
            'payment reconciliation action' => ['payment-reconciliation-findings/partials/action-form.blade.php'],
            'settings dashboard' => ['settings/index.blade.php'],
            'home dashboard' => ['home/index.blade.php'],
            'moderator workspace' => ['home/moderator.blade.php'],
            'course codes list' => ['course-codes/index.blade.php'],
            'course code details' => ['course-codes/show.blade.php'],
            'course code create' => ['course-codes/create.blade.php'],
            'course code edit' => ['course-codes/edit.blade.php'],
            'urgent tasks dashboard' => ['urgent-tasks/index.blade.php'],
            'urgent pending orders' => ['urgent-tasks/pending-orders.blade.php'],
            'urgent inactive students' => ['urgent-tasks/inactive-students.blade.php'],
            'urgent courses without quiz' => ['urgent-tasks/courses-without-quiz.blade.php'],
            'course sections list' => ['course-sections/index.blade.php'],
            'course section details' => ['course-sections/show.blade.php'],
            'course section create' => ['course-sections/create.blade.php'],
            'course section edit' => ['course-sections/edit.blade.php'],
            'orders list' => ['orders/index.blade.php'],
            'order details' => ['orders/show.blade.php'],
            'courses list' => ['courses/index.blade.php'],
            'course details' => ['courses/show.blade.php'],
            'course create' => ['courses/create.blade.php'],
            'course edit' => ['courses/edit.blade.php'],
        ];
    }

    /** @return array<string, array{string, array<int, string>}> */
    public static function courseSectionEditors(): array
    {
        $sharedFields = [
            'section_type',
            'lesson_title_ar',
            'lesson_duration_minutes',
            'video_source_type',
            'bunny_video',
            'quiz_title_ar',
            'questions[${index}][question_text]',
            'project_requirements_ar',
            'course_name_ar',
        ];

        return [
            'create section' => ['create', $sharedFields],
            'edit section' => ['edit', $sharedFields],
        ];
    }

    /** @return array<string, array{string, array<int, string>, array<int, string>}> */
    public static function orderScreens(): array
    {
        return [
            'orders list' => [
                'index',
                ['statistics', 'filters', 'orders-table', 'payment-modal', 'scripts'],
                ['name="status"', 'name="payment_method"', 'name="date_from"', 'updateOrderStatus', 'showPaymentScreenshot'],
            ],
            'order details' => [
                'show',
                ['order-information', 'actions-panel', 'screenshot-modal', 'scripts'],
                ['name="resolution"', 'name="note"', 'admin.orders.resolve-financial-review', 'updateOrderStatus', 'showFullScreenshot'],
            ],
        ];
    }

    /** @return array<string, array{string, array<int, string>, array<int, string>}> */
    public static function courseScreens(): array
    {
        return [
            'courses list' => [
                'index',
                ['course-grid', 'scripts'],
                ['admin.courses.destroy', 'classificationFilter', 'deleteCourse'],
            ],
            'course details' => [
                'show',
                ['statistics', 'commercial-report', 'scripts'],
                ['courseStudio', 'publishingAudit', 'course-studio__preview-toggle', 'admin.courses.sections.reorder'],
            ],
        ];
    }

    /** @return array<string, array{string}> */
    public static function mfaViews(): array
    {
        return [
            'setup' => ['auth/mfa-setup.blade.php'],
            'challenge' => ['auth/mfa-challenge.blade.php'],
            'recovery codes' => ['auth/mfa-backup-codes.blade.php'],
        ];
    }

    private function viewSource(string $view): string
    {
        $path = dirname(__DIR__, 2).'/resources/views/admin/'.$view;
        $source = file_get_contents($path);

        self::assertNotFalse($source);

        return $source;
    }

    private function assertNoInlineStyles(string $source, string $view): void
    {
        self::assertStringNotContainsString('<style', $source, $view);
        self::assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $source, $view);
        self::assertDoesNotMatchRegularExpression('/[\'\"]style[\'\"]\s*=>/i', $source, $view);
    }
}
