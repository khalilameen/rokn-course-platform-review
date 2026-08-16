<?php

declare(strict_types=1);

namespace Tests\Feature\API;

/**
 * Feature tests covering Certificate API endpoints:
 * listing user earned certificates and retrieving specific course certificates.
 */
class CertificateEndpointTest extends ApiTestCase
{
    public function test_can_list_certificates(): void
    {
        $response = $this->actingAs($this->user, 'api')->getJson('/api/v1/certificates');
        $this->assertNotEquals(404, $response->status());
    }

    public function test_can_view_course_certificate(): void
    {
        $response = $this->actingAs($this->user, 'api')->getJson("/api/v1/certificates/{$this->courseId}");
        $this->assertNotEquals(404, $response->status());
    }
}
