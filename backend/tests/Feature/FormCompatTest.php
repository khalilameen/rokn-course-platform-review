<?php

declare(strict_types=1);

namespace Tests\Feature;

use Collective\Html\FormFacade as Form;
use Collective\Html\FormBuilder;
use Tests\TestCase;

final class FormCompatTest extends TestCase
{
    public function test_current_admin_form_surface_preserves_model_binding(): void
    {
        self::assertInstanceOf(FormBuilder::class, app('form'));

        $model = (object) [
            'title_ar' => 'عنوان الكورس',
            'status' => 'published',
            'featured' => 1,
        ];

        $opening = Form::model($model, [
            'method' => 'PATCH',
            'url' => '/admin/courses/42',
            'files' => true,
            'class' => 'course-form',
        ]);

        $openingHtml = $opening->toHtml();
        self::assertStringContainsString('method="POST"', $openingHtml);
        self::assertStringContainsString('enctype="multipart/form-data"', $openingHtml);
        self::assertStringContainsString('name="_method"', $openingHtml);
        self::assertStringContainsString('value="PATCH"', $openingHtml);
        self::assertStringContainsString('name="_token"', $openingHtml);

        self::assertStringContainsString(
            'value="عنوان الكورس"',
            Form::text('title_ar', null, ['required'])->toHtml()
        );
        self::assertStringContainsString(
            '<option value="published" selected="selected">Published</option>',
            Form::select('status', ['draft' => 'Draft', 'published' => 'Published'])->toHtml()
        );
        self::assertStringContainsString(
            'checked="checked"',
            Form::checkbox('featured', 1)->toHtml()
        );
        self::assertSame('</form>', Form::close()->toHtml());
    }
}
