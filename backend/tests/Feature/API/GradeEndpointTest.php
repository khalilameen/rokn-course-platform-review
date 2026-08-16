<?php

declare(strict_types=1);

namespace Tests\Feature\API;

/**
 * Feature tests covering Grade/Level API endpoints:
 * listing grades, viewing specific grade details, and listing courses by grade.
 */
class GradeEndpointTest extends ApiTestCase
{
    public function test_can_list_grades(): void
    {
        $response = $this->getJson('/api/v1/grades');
        $this->assertNotEquals(404, $response->status());
    }

    public function test_can_view_grade_details(): void
    {
        $response = $this->getJson("/api/v1/grades/{$this->gradeId}");
        $this->assertNotEquals(404, $response->status());
    }

    public function test_can_list_courses_by_grade(): void
    {
        $response = $this->getJson("/api/v1/grades/{$this->gradeId}/courses");
        $this->assertNotEquals(404, $response->status());
    }
}
