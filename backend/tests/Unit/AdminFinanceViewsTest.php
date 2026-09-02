<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdminFinanceViewsTest extends TestCase
{
    #[DataProvider('financeViews')]
    public function test_finance_views_do_not_embed_presentation_styles(
        string $view,
        ?string $stylesheet = null
    ): void {
        $source = $this->viewSource($view);

        self::assertStringNotContainsString('<style', $source, $view);
        self::assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $source, $view);

        if ($stylesheet === null) {
            return;
        }

        self::assertStringContainsString("admin/assets/css/{$stylesheet}", $source, $view);

        $css = $this->stylesheetSource($stylesheet);
        self::assertStringNotContainsString('{{', $css, $stylesheet);
        self::assertStringNotContainsString('<style', $css, $stylesheet);
    }

    public function test_bills_views_keep_filter_and_payment_status_contracts(): void
    {
        $indexTemplate = $this->viewSource('bills/index.blade.php');
        $showTemplate = $this->viewSource('bills/show.blade.php');
        $index = $indexTemplate
            .$this->viewSource('bills/partials/index-stats.blade.php')
            .$this->viewSource('bills/partials/index-filters.blade.php')
            .$this->viewSource('bills/partials/index-table.blade.php');
        $show = $showTemplate
            .$this->viewSource('bills/partials/show-details.blade.php')
            .$this->viewSource('bills/partials/show-actions.blade.php');

        foreach (['index-stats', 'index-filters', 'index-table'] as $partial) {
            self::assertStringContainsString(
                "@include('admin.bills.partials.{$partial}')",
                $indexTemplate
            );
        }

        foreach (['show-details', 'show-actions'] as $partial) {
            self::assertStringContainsString(
                "@include('admin.bills.partials.{$partial}')",
                $showTemplate
            );
        }

        self::assertStringContainsString("route('admin.bills.index')", $index);
        self::assertStringContainsString("route('admin.bills.show', \$bill)", $index);

        foreach ([
            'payment_status',
            'payment_method',
            'user_search',
            'course_search',
            'date_from',
            'date_to',
            'due_date_from',
            'due_date_to',
            'amount_min',
            'amount_max',
        ] as $field) {
            self::assertStringContainsString("name=\"{$field}\"", $index);
        }

        self::assertStringContainsString('updateBillStatus(', $index);
        self::assertStringContainsString('updatePaymentStatus(', $show);
        self::assertStringContainsString('admin.bills.update-payment-status', $show);
        self::assertStringContainsString("@method('PATCH')", $index.$show);
        self::assertStringContainsString('{{ $bills->links() }}', $index);
    }

    public function test_student_progress_views_keep_filters_navigation_and_progress_values(): void
    {
        $index = $this->viewSource('student-progress/index.blade.php');
        $show = $this->viewSource('student-progress/show.blade.php');

        self::assertStringContainsString('name="search"', $index);
        self::assertStringContainsString('name="course_id"', $index);
        self::assertStringContainsString("route('admin.student-progress.show'", $index);
        self::assertStringContainsString("route('admin.student-progress.index')", $show);
        self::assertStringContainsString('{{ $users->links() }}', $index);

        self::assertStringContainsString('data-progress="{{ $userProgress', $index);
        self::assertStringContainsString('data-progress="{{ $courseProgress', $show);
        self::assertStringContainsString(".data('progress')", $index);
        self::assertStringContainsString(".data('progress')", $show);
    }

    public function test_payment_method_views_keep_crud_and_field_contracts(): void
    {
        $index = $this->viewSource('payment-methods/index.blade.php');
        $create = $this->viewSource('payment-methods/create.blade.php');
        $edit = $this->viewSource('payment-methods/edit.blade.php');
        $form = $this->viewSource('payment-methods/_form.blade.php');

        self::assertStringContainsString("route('admin.payment-methods.store')", $create);
        self::assertStringContainsString("route('admin.payment-methods.update'", $edit);
        self::assertStringContainsString("route('admin.payment-methods.destroy'", $index);
        self::assertStringContainsString("@method('PUT')", $edit);
        self::assertStringContainsString('value="DELETE"', $index);

        foreach (['name', 'account_details', 'description', 'is_active'] as $field) {
            self::assertStringContainsString("name=\"{$field}\"", $form);
        }

        self::assertStringContainsString('name="confirm_account_details"', $edit);
        self::assertStringContainsString('name="editor_version"', $edit);
        self::assertStringContainsString('name="editor_version"', $index);
        self::assertStringContainsString('preview.textContent = newAccountDetails', $edit);
        self::assertStringNotContainsString('<strong>${newAccountDetails}</strong>', $edit);
    }

    /** @return array<string, array{string, ?string}> */
    public static function financeViews(): array
    {
        return [
            'bills list' => ['bills/index.blade.php', 'bills-index.css'],
            'bill details' => ['bills/show.blade.php', 'bills-show.css'],
            'bills statistics partial' => ['bills/partials/index-stats.blade.php', null],
            'bills filters partial' => ['bills/partials/index-filters.blade.php', null],
            'bills table partial' => ['bills/partials/index-table.blade.php', null],
            'bill detail partial' => ['bills/partials/show-details.blade.php', null],
            'bill actions partial' => ['bills/partials/show-actions.blade.php', null],
            'student progress list' => ['student-progress/index.blade.php', 'student-progress-index.css'],
            'student progress details' => ['student-progress/show.blade.php', 'student-progress-show.css'],
            'payment methods list' => ['payment-methods/index.blade.php', 'payment-methods-index.css'],
            'payment method create' => ['payment-methods/create.blade.php', 'payment-methods-form.css'],
            'payment method edit' => ['payment-methods/edit.blade.php', 'payment-methods-form.css'],
            'payment method form partial' => ['payment-methods/_form.blade.php', null],
        ];
    }

    private function viewSource(string $view): string
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/resources/views/admin/'.$view);

        self::assertNotFalse($source, $view);

        return $source;
    }

    private function stylesheetSource(string $stylesheet): string
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/public/admin/assets/css/'.$stylesheet);

        self::assertNotFalse($source, $stylesheet);

        return $source;
    }
}
