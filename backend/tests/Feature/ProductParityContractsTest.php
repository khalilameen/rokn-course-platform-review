<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\AppFrontNameSpace;
use App\Http\Middleware\WebsiteVisitorCount;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonMediaState;
use App\Models\Path;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ProductParityContractsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([AppFrontNameSpace::class, WebsiteVisitorCount::class]);
    }

    public function test_playback_preferences_are_account_scoped_and_returned_by_profile(): void
    {
        $user = $this->user();

        $this->actingAs($user, 'api')->putJson('/api/v1/user/profile', [
            'autoplay_next_enabled' => false,
            'video_quality_preference' => '720p',
            'video_fit_mode' => 'contain',
            'playback_speed' => 1.5,
        ])->assertOk()
            ->assertJsonPath('data.autoplay_next_enabled', false)
            ->assertJsonPath('data.video_quality_preference', '720p')
            ->assertJsonPath('data.video_fit_mode', 'contain')
            ->assertJsonPath('data.playback_speed', 1.5);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'autoplay_next_enabled' => 0,
            'video_quality_preference' => '720p',
            'video_fit_mode' => 'contain',
        ]);
    }

    public function test_compact_search_uses_keywords_without_exposing_commercial_terms(): void
    {
        $course = $this->course([
            'name_ar' => 'أساسيات التصميم',
            'name_en' => 'Design Basics',
            'description_ar' => 'كورس عملي',
            'price' => 900,
            'is_coming_soon' => false,
            'search_keywords_ar' => 'هوية بصرية شعارات',
        ]);
        $lesson = Lesson::create([
            'list_id' => $course->id,
            'title' => 'الخطوة الأولى',
            'title_ar' => 'الخطوة الأولى',
            'is_opened' => true,
        ]);
        DB::table('course_sections')->insert([
            'course_id' => $course->id,
            'title' => 'البداية',
            'section_type' => 'lesson',
            'sectionable_type' => Lesson::class,
            'sectionable_id' => $lesson->id,
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/search/courses?q=شعارات')
            ->assertOk()
            ->assertJsonPath('data.items.0.course_id', $course->id)
            ->assertJsonPath('data.items.0.title', 'أساسيات التصميم')
            ->assertJsonMissingPath('data.items.0.price')
            ->assertJsonMissingPath('data.items.0.access_plans')
            ->assertJsonMissingPath('data.items.0.modules');
    }

    public function test_feedback_is_private_server_owned_and_accepts_anonymous_reports(): void
    {
        Storage::fake('feedback');

        $response = $this->postJson('/api/v1/feedback', [
            'category' => 'suggestion',
            'message' => 'Please add a clearer explanation to this screen.',
            'screen_key' => 'settings.feedback',
            'platform' => 'android',
            'app_version' => '1.0.22',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'new');

        $publicId = $response->json('data.public_id');
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $publicId);
        $this->assertDatabaseHas('feedback_reports', [
            'public_id' => $publicId,
            'user_id' => null,
            'category' => 'suggestion',
            'app_version' => '1.0.22',
        ]);
        $response->assertJsonMissingPath('data.user_id')
            ->assertJsonMissingPath('data.ip_hash');
    }

    public function test_course_details_exposes_the_verified_total_duration_in_minutes(): void
    {
        $path = Path::create([
            'title_ar' => 'مسار المدة',
            'title_en' => 'Duration path',
        ]);
        $course = $this->course([
            'name_ar' => 'Duration course',
            'price' => 300,
            'is_coming_soon' => false,
            'is_catalog_visible' => true,
            'is_main_course' => true,
            'path_id' => $path->id,
        ]);
        $declaredLesson = Lesson::create([
            'list_id' => $course->id,
            'title' => 'Declared duration',
            'duration_minutes' => 12,
            'is_opened' => true,
        ]);
        $providerLesson = Lesson::create([
            'list_id' => $course->id,
            'title' => 'Provider duration',
            'duration_minutes' => null,
            'is_opened' => false,
        ]);
        LessonMediaState::create([
            'lesson_id' => $providerLesson->id,
            'provider' => 'bunny',
            'provider_media_id' => 'duration-test',
            'status' => 'ready',
            'protocol' => 'hls',
            'duration_seconds' => 125,
        ]);

        foreach ([$declaredLesson, $providerLesson] as $index => $lesson) {
            DB::table('course_sections')->insert([
                'course_id' => $course->id,
                'title' => 'Lesson '.($index + 1),
                'section_type' => 'lesson',
                'sectionable_type' => Lesson::class,
                'sectionable_id' => $lesson->id,
                'order' => $index + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->getJson("/api/v1/courses/{$course->id}/details")
            ->assertOk()
            ->assertJsonPath('data.metadata.duration_minutes', 15);

        $this->getJson('/api/v1/courses?per_page=50')
            ->assertOk()
            ->assertJsonPath('data.0.id', $course->id)
            ->assertJsonPath('data.0.metadata.duration_minutes', 15);

        $this->getJson('/api/v1/courses/list?per_page=50')
            ->assertOk()
            ->assertJsonPath('data.courses.0.id', $course->id)
            ->assertJsonPath('data.courses.0.metadata.duration_minutes', 15);

        $this->getJson('/api/v1/main')
            ->assertOk()
            ->assertJsonPath('data.0.courses.0.id', $course->id)
            ->assertJsonPath('data.0.courses.0.metadata.duration_minutes', 15);

        $this->getJson('/api/v1/mobile-main-page')
            ->assertOk()
            ->assertJsonPath('data.main_courses.0.id', $course->id)
            ->assertJsonPath('data.main_courses.0.metadata.duration_minutes', 15);

        $this->getJson('/api/v1/paths')
            ->assertOk()
            ->assertJsonPath('data.0.courses.0.id', $course->id)
            ->assertJsonPath('data.0.courses.0.metadata.duration_minutes', 15);
    }

    public function test_learning_dashboard_returns_only_a_valid_resume_projection(): void
    {
        $user = $this->user([
            'watch_history_enabled' => true,
        ]);
        $course = $this->course([
            'name_ar' => 'كورس الاستئناف',
            'price' => 300,
            'is_coming_soon' => false,
        ]);
        $lesson = Lesson::create([
            'list_id' => $course->id,
            'title' => 'الدرس الحالي',
            'title_ar' => 'الدرس الحالي',
            'is_opened' => false,
        ]);
        $sectionId = DB::table('course_sections')->insertGetId([
            'course_id' => $course->id,
            'title' => 'القسم الحالي',
            'section_type' => 'lesson',
            'sectionable_type' => Lesson::class,
            'sectionable_id' => $lesson->id,
            'order' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('course_enrollments')->insert([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'course_id' => $course->id,
            'is_active' => true,
            'access_granted_at' => now()->subDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('watching_logs')->insert([
            'user_id' => $user->id,
            'lesson_id' => $lesson->id,
            'lesson_name' => 'الدرس الحالي',
            'course_id' => $course->id,
            'course_section_id' => $sectionId,
            'course_name' => 'كورس الاستئناف',
            'position_seconds' => 42,
            'duration_seconds' => 120,
            'watched_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user, 'api')->getJson('/api/v1/learning/courses')
            ->assertOk()
            ->assertJsonPath('data.items.0.resume.available', true)
            ->assertJsonPath('data.items.0.resume.lesson_id', $lesson->id)
            ->assertJsonPath('data.items.0.resume.position_seconds', 42)
            ->assertJsonPath('data.items.0.resume.progress_percentage', 35)
            ->assertJsonMissingPath('data.items.0.resume.video_url');
    }

    private function user(array $attributes = []): User
    {
        $user = new User();
        $user->forceFill(array_merge([
            'name' => 'Parity Student',
            'email' => 'parity-'.str()->uuid().'@example.test',
            'role' => 'client',
            'active' => true,
            'social_provider' => 'google',
            'social_id' => (string) str()->uuid(),
        ], $attributes));
        $user->save();

        return $user;
    }

    private function course(array $attributes): Course
    {
        $course = new Course();
        $course->forceFill(array_merge(['tenant_id' => 1], $attributes));
        $course->save();

        return $course;
    }
}
