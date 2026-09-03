<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\CourseController;
use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Models\User;
use App\Services\CourseAccessPlanService;
use App\Services\CourseAuthoringConcurrencyService;
use App\Services\CoursePublishingService;
use App\Services\CourseStagedAuthoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\View\View;
use Tests\TestCase;

final class CourseAccessPlanEditorContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_course_defaults_are_previewed_without_writing_commercial_rows(): void
    {
        $course = new Course();
        $course->forceFill([
            'tenant_id' => 1,
            'name_ar' => 'كورس قديم',
            'price' => 1750,
            'authoring_version' => 9,
            'is_coming_soon' => true,
            'is_catalog_visible' => false,
        ])->save();

        self::assertSame(0, $course->accessPlans()->count());

        $plans = app(CourseAccessPlanService::class)->plansForEditor($course);

        self::assertSame(CourseAccessPlan::CODES, $plans->pluck('code')->all());
        self::assertSame(1750, (int) $plans->firstWhere('code', CourseAccessPlan::BASIC)?->price_coins);
        self::assertTrue($plans->every(fn (CourseAccessPlan $plan): bool => !$plan->exists));
        self::assertSame(0, $course->accessPlans()->count());
        self::assertSame(9, (int) $course->fresh()->authoring_version);
    }

    public function test_existing_plan_rows_are_returned_without_replacing_their_values(): void
    {
        $course = new Course();
        $course->forceFill([
            'tenant_id' => 1,
            'name_ar' => 'كورس بفئات',
            'price' => 900,
            'authoring_version' => 4,
            'is_coming_soon' => true,
            'is_catalog_visible' => false,
        ])->save();

        app(CourseAccessPlanService::class)->createDefaults($course);
        $guided = $course->accessPlans()->where('code', CourseAccessPlan::GUIDED)->firstOrFail();
        $guided->forceFill([
            'name_ar' => 'فئة مخصصة',
            'price_coins' => 4321,
        ])->save();

        $plans = app(CourseAccessPlanService::class)->plansForEditor($course);
        $returnedGuided = $plans->firstWhere('code', CourseAccessPlan::GUIDED);

        self::assertTrue((bool) $returnedGuided?->exists);
        self::assertSame('فئة مخصصة', $returnedGuided?->name_ar);
        self::assertSame(4321, (int) $returnedGuided?->price_coins);
        self::assertSame(4, (int) $course->fresh()->authoring_version);
    }

    public function test_opening_course_editor_does_not_persist_missing_plans_or_advance_revision(): void
    {
        $moderator = new User();
        $moderator->forceFill([
            'name_ar' => 'محرر المحتوى',
            'email' => 'moderator-editor@example.test',
            'role' => 'moderator',
            'active' => true,
            'social_provider' => 'google',
            'social_id' => 'moderator-editor',
        ])->save();
        $this->actingAs($moderator);

        $course = new Course();
        $course->forceFill([
            'tenant_id' => 1,
            'name_ar' => 'كورس بلا فئات',
            'price' => 2100,
            'authoring_version' => 12,
            'is_coming_soon' => true,
            'is_catalog_visible' => false,
        ])->save();

        $response = app(CourseController::class)->edit(
            $course,
            app(CoursePublishingService::class),
            app(CourseAccessPlanService::class),
            app(CourseAuthoringConcurrencyService::class),
            app(CourseStagedAuthoringService::class)
        );

        self::assertInstanceOf(View::class, $response);
        self::assertSame(0, $course->accessPlans()->count());
        self::assertSame(12, (int) $course->fresh()->authoring_version);
        self::assertSame(2100, (int) $response->getData()['course']
            ->accessPlans->firstWhere('code', CourseAccessPlan::BASIC)?->price_coins);
    }
}
