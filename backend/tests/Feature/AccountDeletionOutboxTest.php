<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DeleteAccountFile;
use App\Models\AccountFileDeletion;
use App\Models\User;
use App\Services\AccountDeletionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AccountDeletionOutboxTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            $table->string('profile_image')->nullable();
            $table->boolean('active')->default(true);
            $table->string('gender')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('account_file_deletions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('disk', 64);
            $table->string('path_hash', 64);
            $table->text('path')->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('last_error', 190)->nullable();
            $table->timestamps();
            $table->unique(['disk', 'path_hash']);
        });
        Schema::create('social_accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('provider', 32);
            $table->string('provider_user_id', 191);
            $table->timestamps();
        });
        Schema::create('deleted_social_reward_tombstones', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->char('identity_hmac', 64);
            $table->json('consumed_reward_keys');
            $table->timestamps();
            $table->unique(['provider', 'identity_hmac']);
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('deleted_social_reward_tombstones');
        Schema::dropIfExists('social_accounts');
        Schema::dropIfExists('account_file_deletions');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    public function test_file_reference_is_committed_to_durable_outbox_before_account_path_is_cleared(): void
    {
        Storage::fake('public');
        Queue::fake();
        Storage::disk('public')->put('profiles/private.jpg', 'personal bytes');
        $user = User::query()->create([
            'name' => 'Delete Me',
            'email' => 'delete@example.test',
            'phone' => '01000000000',
            'password' => bcrypt('password'),
            'profile_image' => 'profiles/private.jpg',
            'active' => true,
            'gender' => 'other',
        ]);

        $result = app(AccountDeletionService::class)->delete($user);

        self::assertTrue($result['local_cleanup_pending']);
        Storage::disk('public')->assertExists('profiles/private.jpg');
        $outbox = AccountFileDeletion::query()->firstOrFail();
        self::assertSame('profiles/private.jpg', $outbox->path);
        self::assertNotSame('profiles/private.jpg', $outbox->getRawOriginal('path'));
        self::assertSame(AccountFileDeletion::STATUS_PENDING, $outbox->status);
        self::assertNotNull(User::withTrashed()->findOrFail($user->id)->deleted_at);
        self::assertNull(User::withTrashed()->findOrFail($user->id)->profile_image);
        Queue::assertPushed(DeleteAccountFile::class, fn ($job) => $job->deletionId === $outbox->id);

        app(DeleteAccountFile::class, ['deletionId' => $outbox->id])->handle();
        Storage::disk('public')->assertMissing('profiles/private.jpg');
        $outbox->refresh();
        self::assertSame(AccountFileDeletion::STATUS_COMPLETED, $outbox->status);
        self::assertNull($outbox->path);

        // The worker is safely idempotent after completion.
        app(DeleteAccountFile::class, ['deletionId' => $outbox->id])->handle();
        self::assertSame(AccountFileDeletion::STATUS_COMPLETED, $outbox->fresh()->status);
    }
}
