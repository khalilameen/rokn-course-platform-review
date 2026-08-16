<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AdminLoginSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('password');
            $table->string('role')->default('admin');
            $table->boolean('active')->default(true);
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    public function test_login_validates_email_and_password_before_authentication(): void
    {
        $this->post('/login', [
            'email' => 'not-an-email',
            'password' => '',
        ])->assertRedirect()->assertSessionHasErrors(['email', 'password']);

        $this->assertGuest('web');
    }

    public function test_failed_login_is_generic_and_limited_by_normalized_email_and_ip(): void
    {
        $this->createAdmin('admin@rokn.test', 'correct-password');

        $knownFailure = $this->post('/login', [
            'email' => ' ADMIN@ROKN.TEST ',
            'password' => 'wrong-password',
        ])->assertRedirect()->assertSessionHasErrors('email');
        $knownMessage = $knownFailure->getSession()->get('errors')->first('email');

        $unknownFailure = $this->post('/login', [
            'email' => 'missing@rokn.test',
            'password' => 'wrong-password',
        ])->assertRedirect()->assertSessionHasErrors('email');
        self::assertSame($knownMessage, $unknownFailure->getSession()->get('errors')->first('email'));
        self::assertSame('بيانات الدخول غير صحيحة.', $knownMessage);

        // The first known-account failure already consumed one attempt.
        for ($attempt = 2; $attempt <= 5; $attempt++) {
            $this->post('/login', [
                'email' => 'admin@rokn.test',
                'password' => 'wrong-password',
            ])->assertRedirect()->assertSessionHasErrors('email');
        }

        $key = $this->limiterKey('admin@rokn.test', '127.0.0.1');
        self::assertTrue(RateLimiter::tooManyAttempts($key, 5));
        $blocked = $this->post('/login', [
            'email' => 'admin@rokn.test',
            'password' => 'correct-password',
        ])->assertRedirect()->assertSessionHasErrors('email');
        self::assertSame(
            'محاولات كثيرة. حاول مرة أخرى بعد قليل.',
            $blocked->getSession()->get('errors')->first('email')
        );
        $this->assertGuest('web');
    }

    public function test_success_rotates_session_and_clears_credential_limiter(): void
    {
        $admin = $this->createAdmin('owner@rokn.test', 'correct-password');
        $key = $this->limiterKey('owner@rokn.test', '127.0.0.1');
        RateLimiter::hit($key, 60);

        $session = $this->app['session.store'];
        $session->setId(str_repeat('a', 40));
        $oldSessionId = $session->getId();

        $this->post('/login', [
            'email' => 'OWNER@ROKN.TEST',
            'password' => 'correct-password',
            'remember' => 'on',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin, 'web');
        self::assertSame(0, RateLimiter::attempts($key));
        self::assertNotSame($oldSessionId, $this->app['session.store']->getId());
    }

    public function test_inactive_administrator_cannot_login_or_keep_an_existing_session(): void
    {
        $admin = $this->createAdmin('inactive@rokn.test', 'correct-password');
        $admin->forceFill(['active' => false])->save();

        $this->post('/login', [
            'email' => 'inactive@rokn.test',
            'password' => 'correct-password',
        ])->assertRedirect()->assertSessionHasErrors('email');
        $this->assertGuest('web');

        $this->actingAs($admin, 'web')
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    private function createAdmin(string $email, string $password): User
    {
        return User::query()->forceCreate([
            'name' => 'Rokn Admin',
            'email' => $email,
            'password' => Hash::make($password),
            'role' => 'admin',
            'active' => true,
        ]);
    }

    private function limiterKey(string $email, string $ip): string
    {
        return 'admin-login:' . hash('sha256', strtolower(trim($email)) . '|' . $ip);
    }
}
