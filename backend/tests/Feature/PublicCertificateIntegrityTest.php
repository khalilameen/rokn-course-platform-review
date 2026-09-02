<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\AppFrontNameSpace;
use App\Http\Middleware\WebsiteVisitorCount;
use App\Http\Resources\CertificateResource;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\User;
use App\Services\PublicPortfolioService;
use App\Support\RoknPublicUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PublicCertificateIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([AppFrontNameSpace::class, WebsiteVisitorCount::class]);
        Storage::fake('public');
        config()->set('certificate.disk', 'public');
    }

    public function test_credential_url_and_issued_identity_survive_profile_and_course_edits(): void
    {
        [$user, $course, $certificate] = $this->credential();
        $permanentUrl = RoknPublicUrl::certificate((string) $certificate->public_id);

        self::assertSame($permanentUrl, $certificate->portfolio_url);

        $certificate->forceFill([
            'public_id' => (string) Str::uuid(),
            'holder_name' => 'اسم مستبدل',
            'course_name' => 'كورس مستبدل',
            'generated_at' => now()->addDay(),
        ])->save();
        self::assertSame($permanentUrl, $certificate->fresh()->portfolio_url);

        $user->forceFill([
            'name' => 'اسم حالي مختلف',
            'portfolio_slug' => 'new-profile-slug',
        ])->save();
        $course->forceFill(['name_ar' => 'اسم الكورس الحالي'])->save();

        $this->get('/c/'.$certificate->public_id)
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertSee('اسم حامل الشهادة')
            ->assertSee('اسم الكورس وقت الإصدار');

        $payload = (new CertificateResource($certificate->fresh()->load('course')))->resolve();
        self::assertSame('اسم حامل الشهادة', $payload['holder_name']);
        self::assertSame('اسم الكورس وقت الإصدار', $payload['course_name']);
        self::assertSame('اسم الكورس وقت الإصدار', $payload['course']['name']);
        self::assertSame($permanentUrl, $payload['verification_url']);
    }

    public function test_legacy_slug_qr_redirects_to_permanent_route_after_slug_changes(): void
    {
        [$user, , $certificate] = $this->credential();
        $user->forceFill(['portfolio_slug' => 'renamed-profile'])->save();

        $this->get('/@old-profile?certificate='.$certificate->public_id)
            ->assertMovedPermanently()
            ->assertRedirect(RoknPublicUrl::certificate((string) $certificate->public_id));
    }

    public function test_revoked_credential_is_verification_only_and_uses_snapshots(): void
    {
        [$user, $course, $certificate] = $this->credential();
        $user->forceFill([
            'name' => 'اسم خاص حالي',
            'bio' => 'سيرة خاصة لا ينبغي كشفها',
        ])->save();
        $course->forceFill(['name_ar' => 'اسم كورس جديد'])->save();
        $certificate->forceFill([
            'status' => 'revoked',
            'revoked_at' => now(),
        ])->save();

        $this->get(route('certificate.public', ['publicId' => $certificate->public_id]))
            ->assertOk()
            ->assertSee('اسم حامل الشهادة')
            ->assertSee('اسم الكورس وقت الإصدار')
            ->assertSee('ملغاة')
            ->assertDontSee('اسم خاص حالي')
            ->assertDontSee('سيرة خاصة لا ينبغي كشفها')
            ->assertDontSee('اسم كورس جديد');
    }

    public function test_pending_unknown_and_non_uuid_credentials_are_not_public(): void
    {
        [, , $certificate] = $this->credential();
        $certificate->forceFill(['image_path' => 'pending'])->save();

        $this->get('/c/'.$certificate->public_id)->assertNotFound();
        $this->get('/c/'.Str::uuid())->assertNotFound();
        $this->get('/c/123')->assertNotFound();
    }

    public function test_numeric_legacy_profile_alias_cannot_publish_an_unshared_portfolio(): void
    {
        [$user, , $certificate] = $this->credential();
        $user->forceFill(['portfolio_slug' => null])->save();
        $service = app(PublicPortfolioService::class);

        self::assertNull($service->find('student-'.$user->id));

        $verification = $service->findCredential((string) $certificate->public_id);
        self::assertNotNull($verification);
        self::assertNull($verification['profile']['slug']);
        self::assertSame('verification_only', $verification['profile']['share_mode']);
        self::assertSame(
            RoknPublicUrl::certificate((string) $certificate->public_id),
            $verification['profile']['public_url']
        );
    }

    public function test_retiring_a_course_keeps_issued_certificate_history_readable(): void
    {
        [, $course, $certificate] = $this->credential();

        $course->delete();

        self::assertNull(Course::query()->find($course->id));
        self::assertNotNull($certificate->fresh()->course);
        $this->get('/c/'.$certificate->public_id)
            ->assertOk()
            ->assertSee('اسم الكورس وقت الإصدار');
    }

    /** @return array{User, Course, Certificate} */
    private function credential(): array
    {
        $user = new User();
        $user->forceFill([
            'name' => 'اسم حامل الشهادة',
            'email' => 'credential-'.Str::uuid().'@example.test',
            'role' => 'client',
            'active' => true,
            'portfolio_slug' => 'old-profile',
        ])->save();

        $course = new Course();
        $course->forceFill([
            'tenant_id' => 1,
            'name_ar' => 'اسم الكورس وقت الإصدار',
            'name_en' => 'Course at issuance',
        ])->save();

        $certificate = Certificate::create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'course_id' => $course->id,
            'holder_name' => 'اسم حامل الشهادة',
            'course_name' => 'اسم الكورس وقت الإصدار',
            'image_path' => 'certificates/issued.png',
            'generated_at' => now(),
            'status' => 'active',
            'verification_level' => 'completion',
        ]);
        Storage::disk('public')->put('certificates/issued.png', 'issued certificate');

        return [$user, $course, $certificate];
    }
}
