<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class BunnySecretMigrationCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('bunny_api_key')->nullable();
            $table->string('bunny_storage_password')->nullable();
            $table->text('bunny_api_key_secret')->nullable();
            $table->text('bunny_storage_password_secret')->nullable();
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role');
            $table->boolean('active')->default(true);
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    public function test_dry_run_does_not_change_or_print_legacy_secrets(): void
    {
        DB::table('settings')->insert([
            'bunny_api_key' => 'legacy-api-secret',
            'bunny_storage_password' => 'legacy-storage-secret',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        self::assertSame(0, Artisan::call('bunny:migrate-secrets', ['--dry-run' => true]));
        $output = Artisan::output();
        self::assertStringNotContainsString('legacy-api-secret', $output);
        self::assertStringNotContainsString('legacy-storage-secret', $output);
        $row = DB::table('settings')->first();
        self::assertSame('legacy-api-secret', $row->bunny_api_key);
        self::assertNull($row->bunny_api_key_secret);
        self::assertNull(Setting::query()->firstOrFail()->bunny_api_key);
    }

    public function test_apply_encrypts_verifies_and_then_clears_plaintext_columns(): void
    {
        $id = DB::table('settings')->insertGetId([
            'bunny_api_key' => 'legacy-api-secret',
            'bunny_storage_password' => 'legacy-storage-secret',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        self::assertSame(0, Artisan::call('bunny:migrate-secrets', ['--apply' => true]));
        $output = Artisan::output();
        self::assertStringNotContainsString('legacy-api-secret', $output);
        self::assertStringNotContainsString('legacy-storage-secret', $output);

        $raw = DB::table('settings')->where('id', $id)->first();
        self::assertNull($raw->bunny_api_key);
        self::assertNull($raw->bunny_storage_password);
        self::assertNotSame('legacy-api-secret', $raw->bunny_api_key_secret);
        self::assertNotSame('legacy-storage-secret', $raw->bunny_storage_password_secret);

        $settings = Setting::query()->findOrFail($id);
        self::assertSame('legacy-api-secret', $settings->bunny_api_key_secret);
        self::assertSame('legacy-storage-secret', $settings->bunny_storage_password_secret);
    }

    public function test_conflict_fails_closed_without_clearing_or_overwriting_either_value(): void
    {
        $id = DB::table('settings')->insertGetId([
            'bunny_api_key' => 'legacy-api-secret',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $settings = Setting::query()->findOrFail($id);
        $settings->bunny_api_key_secret = 'different-encrypted-secret';
        $settings->save();
        $ciphertext = DB::table('settings')->where('id', $id)->value('bunny_api_key_secret');

        self::assertSame(1, Artisan::call('bunny:migrate-secrets', ['--apply' => true]));
        $raw = DB::table('settings')->where('id', $id)->first();
        self::assertSame('legacy-api-secret', $raw->bunny_api_key);
        self::assertSame($ciphertext, $raw->bunny_api_key_secret);
        self::assertStringNotContainsString('legacy-api-secret', Artisan::output());
    }

    public function test_matching_encrypted_value_allows_plaintext_cleanup_without_reencrypting(): void
    {
        $id = DB::table('settings')->insertGetId([
            'bunny_api_key' => 'same-secret',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $settings = Setting::query()->findOrFail($id);
        $settings->bunny_api_key_secret = 'same-secret';
        $settings->save();
        $ciphertext = DB::table('settings')->where('id', $id)->value('bunny_api_key_secret');

        self::assertSame(0, Artisan::call('bunny:migrate-secrets', ['--apply' => true]));
        $raw = DB::table('settings')->where('id', $id)->first();
        self::assertNull($raw->bunny_api_key);
        self::assertSame($ciphertext, $raw->bunny_api_key_secret);
        self::assertSame('same-secret', Setting::query()->findOrFail($id)->bunny_api_key_secret);
    }

    public function test_invalid_settings_form_never_flashes_submitted_bunny_secrets(): void
    {
        $admin = User::query()->forceCreate([
            'name' => 'Rokn Admin',
            'email' => 'admin@rokn.test',
            'password' => Hash::make('correct horse battery staple'),
            'role' => 'admin',
            'active' => true,
        ]);

        $response = $this->actingAs($admin)->post(route('admin.settings.update'), [
            'bunny_api_key' => 'must-not-enter-session',
            'bunny_storage_password' => 'must-not-enter-session-either',
            // Required reward fields are intentionally absent.
        ])->assertRedirect()->assertSessionHasErrors('welcome_bonus_coins');

        $oldInput = $response->getSession()->getOldInput();
        self::assertArrayNotHasKey('bunny_api_key', $oldInput);
        self::assertArrayNotHasKey('bunny_storage_password', $oldInput);
        self::assertStringNotContainsString('must-not-enter-session', serialize($oldInput));
    }
}
