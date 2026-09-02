<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Http\Requests\Admin\CourseRequest;
use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Models\User;
use App\Services\CourseAccessPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ModeratorCourseCommercialPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private const COST_FIELDS = [
        'ai_budget_usd',
        'request_reserve_usd',
        'project_feedback_budget_usd',
        'project_feedback_reserve_usd',
        'project_followup_budget_usd',
        'project_followup_reserve_usd',
    ];

    public function test_direct_course_editor_hides_provider_cost_controls_from_moderator_only(): void
    {
        [$course, $moderator, $administrator] = $this->records();
        $this->withoutMiddleware(RequireAdminMfa::class);

        $this->actingAs($moderator)
            ->get(route('admin.courses.edit', $course))
            ->assertOk()
            ->assertDontSee('ai_budget_usd')
            ->assertDontSee('project_feedback_budget_usd')
            ->assertDontSee('project_followup_budget_usd')
            ->assertDontSee('حد تكلفة الخطة بالدولار')
            ->assertDontSee('احتياطي AI المقترح');

        $this->actingAs($administrator)
            ->get(route('admin.courses.edit', $course))
            ->assertOk()
            ->assertSee('ai_budget_usd')
            ->assertSee('project_feedback_budget_usd')
            ->assertSee('project_followup_budget_usd')
            ->assertSee('حد تكلفة الخطة بالدولار');
    }

    public function test_crafted_moderator_cost_fields_are_replaced_by_persisted_contract_values(): void
    {
        [$course, $moderator] = $this->records();
        $persisted = $course->accessPlans()->get()->keyBy('code');
        $submittedPlans = [];
        foreach (CourseAccessPlan::CODES as $code) {
            $submittedPlans[$code] = ['name_ar' => 'فئة '.$code];
            foreach (self::COST_FIELDS as $field) {
                $submittedPlans[$code][$field] = '9999.999999';
            }
        }

        $request = CourseRequest::create('/dashboard/courses/'.$course->id, 'PATCH', [
            'access_plans' => $submittedPlans,
        ]);
        $request->setContainer($this->app);
        $request->setUserResolver(fn (): User => $moderator);
        $request->setRouteResolver(fn () => new class($course) {
            public function __construct(private readonly Course $course) {}
            public function parameter(string $key, mixed $default = null): mixed
            {
                return $key === 'course' ? $this->course : $default;
            }
        });

        $prepare = new \ReflectionMethod(CourseRequest::class, 'prepareForValidation');
        $prepare->invoke($request);

        foreach (CourseAccessPlan::CODES as $code) {
            foreach (self::COST_FIELDS as $field) {
                self::assertSame(
                    (string) $persisted->get($code)->getAttribute($field),
                    (string) data_get($request->input('access_plans'), "{$code}.{$field}"),
                    "Moderator changed protected {$code}.{$field}"
                );
            }
        }
    }

    /** @return array{Course, User, User} */
    private function records(): array
    {
        $course = new Course();
        $course->forceFill([
            'tenant_id' => 1,
            'name_ar' => 'كورس تجاري',
            'price' => 1200,
            'is_coming_soon' => true,
            'is_catalog_visible' => false,
            'authoring_version' => 3,
        ])->save();
        app(CourseAccessPlanService::class)->createDefaults($course);
        $guided = $course->accessPlans()->where('code', CourseAccessPlan::GUIDED)->firstOrFail();
        $guided->forceFill([
            'ai_budget_usd' => '7.123456',
            'request_reserve_usd' => '0.123456',
            'project_feedback_budget_usd' => '6.123456',
            'project_feedback_reserve_usd' => '0.223456',
            'project_followup_budget_usd' => '5.123456',
            'project_followup_reserve_usd' => '0.323456',
        ])->save();

        return [
            $course,
            $this->dashboardUser('moderator', 'course-editor@example.test'),
            $this->dashboardUser('admin', 'course-owner@example.test'),
        ];
    }

    private function dashboardUser(string $role, string $email): User
    {
        $user = new User();
        $user->forceFill([
            'name_ar' => $role === 'admin' ? 'مالك ركن' : 'محرر المحتوى',
            'email' => $email,
            'role' => $role,
            'active' => true,
        ])->save();

        return $user;
    }
}
