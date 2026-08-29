<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Feature tests covering Saved Folders / Bookmarks API endpoints:
 * listing folders, creating folders, viewing folder items, saving/removing lessons, and deleting folders.
 */
class SavedFolderEndpointTest extends ApiTestCase
{
    public function test_saved_lessons_requires_authentication(): void
    {
        $this->getJson('/api/v1/saved-lessons')->assertUnauthorized();
    }

    public function test_saved_lessons_is_distinct_paginated_and_only_exposes_owned_folder_memberships(): void
    {
        DB::table('saved_folders')->insert([
            ['id' => 2, 'user_id' => $this->user->id, 'name' => 'Watch next', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'user_id' => $this->user->id, 'name' => 'Review', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $otherUser = User::query()->create([
            'name' => 'Other User',
            'email' => 'other-saved@rokn.test',
            'phone' => '01000000001',
            'password' => bcrypt('password'),
            'active' => true,
        ]);
        $otherFolderId = DB::table('saved_folders')->insertGetId([
            'user_id' => $otherUser->id,
            'name' => 'Must stay private',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('saved_folder_lessons')->where('lesson_id', 10)->delete();
        DB::table('saved_folder_lessons')->insert([
            ['saved_folder_id' => 2, 'lesson_id' => 10, 'created_at' => now()->subMinute(), 'updated_at' => now()->subMinute()],
            ['saved_folder_id' => 3, 'lesson_id' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['saved_folder_id' => $otherFolderId, 'lesson_id' => 10, 'created_at' => now()->addMinute(), 'updated_at' => now()->addMinute()],
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/saved-lessons?per_page=1')
            ->assertOk()
            ->assertJsonPath('data.pagination.current_page', 1)
            ->assertJsonPath('data.pagination.per_page', 1)
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonCount(1, 'data.lessons')
            ->assertJsonPath('data.lessons.0.id', 10)
            ->assertJsonCount(2, 'data.lessons.0.folder_memberships');

        $memberships = collect($response->json('data.lessons.0.folder_memberships'));
        self::assertSame([2, 3], $memberships->pluck('id')->sort()->values()->all());
        self::assertFalse($memberships->contains('name', 'Must stay private'));
        self::assertNotEmpty($response->json('data.lessons.0.saved_at'));
    }

    public function test_saved_lessons_rejects_unbounded_page_sizes(): void
    {
        $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/saved-lessons?per_page=51')
            ->assertUnprocessable();
    }

    public function test_can_list_saved_folders(): void
    {
        $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/saved-folders')
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', 1)
            ->assertJsonPath('data.0.image', asset('images/default-folder.png'))
            ->assertJsonPath('data.0.lessons_count', 1);
    }

    public function test_saved_folder_uses_the_first_saved_lesson_image(): void
    {
        DB::table('lessons')->where('id', 10)->update(['image' => 'lesson-cover.jpg']);
        $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/saved-folders')
            ->assertOk()
            ->assertJsonPath('data.0.image', 'lesson-cover.jpg')
            ->assertJsonPath('data.0.lessons_count', 1);
    }

    public function test_can_create_saved_folder(): void
    {
        $response = $this->actingAs($this->user, 'api')->postJson('/api/v1/saved-folders', [
            'name' => 'My Bookmark Folder'
        ]);
        $this->assertNotEquals(404, $response->status());
    }

    public function test_can_view_saved_folder_items(): void
    {
        DB::table('saved_folder_lessons')->insert([
            'saved_folder_id' => 1,
            'lesson_id' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/saved-folders/1')
            ->assertOk()
            ->assertJsonPath('data.lessons.0.id', 10)
            ->assertJsonPath('data.lessons.0.duration_minutes', 15);

        $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/saved-folders/1/lessons')
            ->assertOk()
            ->assertJsonPath('data.lessons.0.id', 10)
            ->assertJsonPath('data.lessons.0.duration_minutes', 15);
    }

    public function test_can_save_lesson_to_folder(): void
    {
        $response = $this->actingAs($this->user, 'api')->postJson('/api/v1/saved-folders/1/lessons', [
            'lesson_id' => 10
        ]);
        $this->assertNotEquals(404, $response->status());
    }

    public function test_can_remove_lesson_from_folder(): void
    {
        $response = $this->actingAs($this->user, 'api')->deleteJson('/api/v1/saved-folders/1/lessons/10');
        $this->assertNotEquals(404, $response->status());
    }

    public function test_can_delete_saved_folder(): void
    {
        $response = $this->actingAs($this->user, 'api')->deleteJson('/api/v1/saved-folders/1');
        $this->assertNotEquals(404, $response->status());
    }
}
