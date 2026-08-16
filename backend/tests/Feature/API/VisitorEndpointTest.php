<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class VisitorEndpointTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('visitors', function (Blueprint $table): void {
            $table->id();
            $table->string('ip_address');
            $table->string('user_agent')->nullable();
            $table->string('browser')->nullable();
            $table->string('operating_system')->nullable();
            $table->string('device_type')->nullable();
            $table->timestamp('visited_at');
            $table->timestamps();
        });

        $this->user->forceFill(['role' => 'admin'])->save();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('visitors');

        parent::tearDown();
    }

    public function test_recent_visitors_never_exposes_ip_address_or_user_agent(): void
    {
        DB::table('visitors')->insert([
            'ip_address' => '203.0.113.91',
            'user_agent' => 'Sensitive-UA-Value/1.0',
            'browser' => 'Firefox',
            'operating_system' => 'Windows',
            'device_type' => 'desktop',
            'visited_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/visitors/recent')
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Recent visitor activity retrieved successfully')
            ->assertJsonPath('data.0.browser', 'Firefox')
            ->assertJsonMissingPath('data.0.ip_address')
            ->assertJsonMissingPath('data.0.user_agent')
            ->assertDontSee('203.0.113.91')
            ->assertDontSee('Sensitive-UA-Value/1.0');
    }

    public function test_recent_visitors_rejects_unbounded_limits(): void
    {
        $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/visitors/recent?limit=101')
            ->assertUnprocessable();
    }
}
