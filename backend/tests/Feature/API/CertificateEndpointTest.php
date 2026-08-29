<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Feature tests covering Certificate API endpoints:
 * listing user earned certificates and retrieving specific course certificates.
 */
class CertificateEndpointTest extends ApiTestCase
{
    public function test_can_list_certificates(): void
    {
        $publicId = (string) Str::uuid();
        DB::table('certificates')->insert([
            'public_id' => $publicId,
            'user_id' => $this->user->id,
            'course_id' => $this->courseId,
            'image_path' => 'certificates/test-certificate.png',
            'status' => 'active',
            'generated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $otherUser = User::query()->create([
            'name' => 'Other Student',
            'email' => 'certificate-owner@example.test',
            'phone' => '01000000009',
            'password' => bcrypt('password'),
            'active' => true,
        ]);
        DB::table('certificates')->insert([
            'public_id' => (string) Str::uuid(),
            'user_id' => $otherUser->id,
            'course_id' => $this->courseId,
            'image_path' => 'certificates/private.png',
            'status' => 'active',
            'generated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/certificates')
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.public_id', $publicId)
            ->assertJsonPath('data.0.status', 'active')
            ->assertJsonPath('data.0.course.id', $this->courseId)
            ->assertJsonPath('data.0.course.name', 'دورة تجريبية')
            ->assertJsonStructure([
                'data' => [[
                    'id', 'certificate_id', 'public_id', 'certificate_url',
                    'portfolio_url', 'status', 'verification_level',
                    'generated_at', 'course' => ['id', 'name', 'image'],
                ]],
            ]);
    }

    public function test_can_view_course_certificate(): void
    {
        $publicId = (string) Str::uuid();
        DB::table('certificates')->insert([
            'public_id' => $publicId,
            'user_id' => $this->user->id,
            'course_id' => $this->courseId,
            'image_path' => 'certificates/test-certificate.png',
            'status' => 'active',
            'generated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->user, 'api')
            ->getJson("/api/v1/certificates/{$this->courseId}")
            ->assertOk()
            ->assertJsonPath('data.public_id', $publicId)
            ->assertJsonPath('data.course.id', $this->courseId);
    }

    public function test_certificate_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/certificates')->assertUnauthorized();
        $this->getJson("/api/v1/certificates/{$this->courseId}")->assertUnauthorized();
    }
}
