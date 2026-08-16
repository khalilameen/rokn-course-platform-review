<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class UserSessionController extends Controller
{
    public function index(): View
    {
        $table = (new ApiToken())->getTable();
        $ready = Schema::hasColumn($table, 'session_id');
        $sessions = $ready
            ? ApiToken::query()
                ->with('user:id,name,email')
                ->whereHasNotExpired()
                ->whereNotNull('session_id')
                ->orderByDesc('last_used_at')
                ->orderByDesc('issued_at')
                ->paginate(50)
            : null;

        $platforms = $ready
            ? ApiToken::query()
                ->whereHasNotExpired()
                ->selectRaw("COALESCE(platform, 'other') AS platform, COUNT(*) AS total")
                ->groupBy('platform')
                ->pluck('total', 'platform')
            : collect();

        return view('admin.user_sessions.index', compact('sessions', 'platforms', 'ready'));
    }

    public function destroy(string $sessionId): RedirectResponse
    {
        $session = ApiToken::query()->where('session_id', $sessionId)->firstOrFail();
        $session->revoke();

        return back()->with('success', 'تم إنهاء جلسة الجهاز.');
    }
}
