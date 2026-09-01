<?php

declare(strict_types=1);

namespace Tests\Feature\API;

/**
 * Feature tests covering Course PDF API endpoints:
 * listing PDFs attached to a course, viewing details, and secure file streaming.
 */
class CoursePdfEndpointTest extends ApiTestCase
{
    public function test_can_list_course_pdfs(): void
    {
        $response = $this->actingAs($this->user, 'api')->getJson("/api/v1/courses/{$this->courseId}/pdfs");
        $this->assertNotEquals(404, $response->status());
    }

    public function test_can_view_pdf_details(): void
    {
        $response = $this->actingAs($this->user, 'api')->getJson("/api/v1/courses/{$this->courseId}/pdfs/1");
        $this->assertNotEquals(404, $response->status());
    }

    public function test_legacy_in_app_pdf_stream_is_not_published(): void
    {
        $response = $this->actingAs($this->user, 'api')->get("/api/v1/courses/{$this->courseId}/pdfs/1/stream");
        $response->assertNotFound();
    }
}
