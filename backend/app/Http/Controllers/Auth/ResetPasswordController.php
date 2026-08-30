<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Foundation\Auth\ResetsPasswords;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;

class ResetPasswordController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Password Reset Controller
    |--------------------------------------------------------------------------
    |
    | This controller is responsible for handling password reset requests
    | and uses a simple trait to include this behavior. You're free to
    | explore this trait and override any methods you wish to tweak.
    |
    */

    use ResetsPasswords;

    /**
     * Where to redirect users after resetting their password.
     *
     * @var string
     */
    protected $redirectTo = '/dashboard';

    public function __construct()
    {
        $this->middleware('guest');
    }

    protected function rules()
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'password' => ['required', 'confirmed', Rules\Password::min(10)],
        ];
    }

    protected function credentials(Request $request)
    {
        $email = Str::lower(trim((string) $request->input('email')));
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('active', true)
            ->whereRaw('LOWER(role) IN (?, ?)', ['admin', 'moderator'])
            ->first(['email', 'role']);

        return [
            'email' => $user?->email ?? $email,
            'role' => $user?->getRawOriginal('role') ?? '__dashboard_only__',
            'active' => true,
            'password' => $request->input('password'),
            'password_confirmation' => $request->input('password_confirmation'),
            'token' => $request->input('token'),
        ];
    }
}
