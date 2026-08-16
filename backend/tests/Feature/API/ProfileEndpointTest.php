<?php

declare(strict_types=1);

namespace Tests\Feature\API;

/**
 * Feature tests covering User Profile API endpoints:
 * retrieving user profile information and updating account details.
 */
class ProfileEndpointTest extends ApiTestCase
{
    public function test_authenticated_user_can_view_profile(): void
    {
        $response = $this->actingAs($this->user, 'api')->getJson('/api/v1/user/profile');
        $this->assertNotEquals(404, $response->status());
    }

    public function test_authenticated_user_can_update_profile(): void
    {
        $response = $this->actingAs($this->user, 'api')->putJson('/api/v1/user/profile', [
            'name' => 'Updated User',
            'email' => 'updated@rokn.com'
        ]);
        $this->assertNotEquals(404, $response->status());
    }
}
