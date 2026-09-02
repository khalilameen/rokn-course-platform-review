<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ModeratorProjectReviewPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_moderator_direct_requests_can_review_work_without_receiving_student_identity(): void
    {
        [$moderator, $student, $submission] = $this->records();
        $this->withoutMiddleware(RequireAdminMfa::class);

        $this->actingAs($moderator)
            ->get(route('admin.project-submissions.index'))
            ->assertOk()
            ->assertDontSee($student->name_ar)
            ->assertDontSee($student->email)
            ->assertSee($submission->public_id);

        $this->actingAs($moderator)
            ->get(route('admin.project-submissions.show', $submission))
            ->assertOk()
            ->assertDontSee($student->name_ar)
            ->assertDontSee($student->email)
            ->assertSee($submission->public_id);

        $this->actingAs($moderator)
            ->get(route('admin.project-submissions.index', ['search' => $student->email]))
            ->assertOk()
            ->assertDontSee($submission->public_id);
    }

    public function test_administrator_direct_request_keeps_identity_search_and_display(): void
    {
        [$moderator, $student, $submission] = $this->records();
        $administrator = $this->dashboardUser('admin', 'owner@example.test', 'مالك ركن');
        $this->withoutMiddleware(RequireAdminMfa::class);

        $this->actingAs($administrator)
            ->get(route('admin.project-submissions.index', ['search' => $student->email]))
            ->assertOk()
            ->assertSee($submission->public_id)
            ->assertSee($student->name_ar)
            ->assertSee($student->email);
    }

    /** @return array{User, User, ProjectSubmission} */
    private function records(): array
    {
        $moderator = $this->dashboardUser('moderator', 'reviewer@example.test', 'مراجع المحتوى');
        $student = $this->dashboardUser('client', 'private.student@example.test', 'اسم طالب خاص');

        $course = new Course();
        $course->forceFill([
            'tenant_id' => 1,
            'name_ar' => 'كورس الاختبار',
            'is_coming_soon' => false,
            'is_catalog_visible' => true,
            'authoring_version' => 1,
        ])->save();
        $project = Project::query()->create([
            'requirements_text' => 'نفذ المشروع',
            'ai_prompt' => 'قيّم النتيجة',
            'passing_score' => 50,
        ]);
        CourseSection::query()->create([
            'course_id' => $course->id,
            'title' => 'المشروع',
            'section_type' => 'project',
            'sectionable_type' => Project::class,
            'sectionable_id' => $project->id,
            'order' => 1,
        ]);
        $submission = ProjectSubmission::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $student->id,
            'project_id' => $project->id,
            'idempotency_key' => 'privacy-contract',
            'submission_text' => 'ناتج المشروع',
            'effort_status' => ProjectSubmission::EFFORT_VALID,
            'review_status' => ProjectSubmission::STATUS_PENDING,
            'submitted_at' => now(),
        ]);

        return [$moderator, $student, $submission];
    }

    private function dashboardUser(string $role, string $email, string $name): User
    {
        $user = new User();
        $user->forceFill([
            'name_ar' => $name,
            'email' => $email,
            'role' => $role,
            'active' => true,
            'social_provider' => $role === 'client' ? 'google' : null,
            'social_id' => $role === 'client' ? 'student-social-id' : null,
        ])->save();

        return $user;
    }
}
