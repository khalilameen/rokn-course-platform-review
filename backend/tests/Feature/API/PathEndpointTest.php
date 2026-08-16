<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feature tests covering Educational Path API endpoints:
 * listing available learning paths, viewing path details, and user enrolled paths.
 */
class PathEndpointTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::table('courses', function (Blueprint $table): void {
            $table->unsignedBigInteger('path_id')->nullable();
        });

        Schema::create('classification_path', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('path_id');
            $table->unsignedBigInteger('classification_id');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('classification_path');

        parent::tearDown();
    }

    public function test_can_list_paths(): void
    {
        $this->getJson('/api/v1/paths')
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Paths retrieved successfully');
    }

    public function test_can_view_path_details(): void
    {
        $this->getJson("/api/v1/paths/{$this->pathId}")
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Path retrieved successfully')
            ->assertJsonPath('data.id', $this->pathId);
    }

    public function test_authenticated_user_can_view_user_paths(): void
    {
        $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/user/paths')
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'User paths retrieved successfully');
    }
}
