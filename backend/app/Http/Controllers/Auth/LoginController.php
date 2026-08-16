<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Providers\RouteServiceProvider;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class LoginController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Login Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles authenticating users for the application and
    | redirecting them to your home screen. The controller uses a trait
    | to conveniently provide its functionality to your applications.
    |
    */

    use AuthenticatesUsers;

    /**
     * Where to redirect users after login.
     *
     * @var string
     */
    protected $redirectTo = '/';

    /**
     * Get the login username to be used by the controller.
     *
     * @return string
     */
    public function username()
    {
        return 'phone';
    }

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('guest')->except('logout');
        $this->middleware('throttle:admin-login-route')->only('login');
    }

    /**
     * @param Request $request
     * @param $user
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    protected function authenticated(Request $request, $user)
    {
        if (!$request->user()) {
            return redirect()->route('login');
        } elseif ($request->user()->role == 'admin' || $request->user()->role == 'moderator' ) {
            return redirect()->route('admin.dashboard');
        } else {
            return redirect()->route('home');
        }
    }


    /**
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', 'max:1024'],
        ]);
        $credentials['email'] = Str::lower(trim($credentials['email']));
        $credentials['active'] = true;
        $key = $this->loginRateLimitKey($credentials['email'], (string) $request->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return redirect()->back()
                ->withErrors(['email' => 'محاولات كثيرة. حاول مرة أخرى بعد قليل.'])
                ->onlyInput('email');
        }

        if (!auth()->attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);

            // Keep the response identical for an unknown email and a wrong
            // password so the dashboard cannot be used to enumerate accounts.
            return redirect()->back()
                ->withErrors(['email' => 'بيانات الدخول غير صحيحة.'])
                ->onlyInput('email');
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        // Password authentication is only the first factor for dashboard
        // roles. Never inherit a prior user's MFA state through a reused
        // browser session.
        if (in_array(strtolower((string) auth()->user()?->role), ['admin', 'moderator'], true)) {
            $request->session()->forget([
                'admin_mfa_verified_user_id',
                'admin_mfa_verified_at',
                'admin_mfa_secret_fingerprint',
                'admin_mfa_setup_secret_ciphertext',
                'admin_mfa_setup_user_id',
                'admin_mfa_setup_started_at',
                'admin_mfa_new_recovery_codes_ciphertext',
            ]);
        }

        return $this->authenticated($request, auth()->user());
    }

    private function loginRateLimitKey(string $email, string $ip): string
    {
        return 'admin-login:' . hash('sha256', Str::lower(trim($email)) . '|' . $ip);
    }

    /**
     * The user has logged out of the application.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return mixed
     */
    protected function loggedOut(Request $request)
    {
        return redirect()->route('login');
    }
}
