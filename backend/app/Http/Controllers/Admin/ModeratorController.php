<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AdminAuthoringCreateIntentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class ModeratorController extends Controller
{
    public function index(): View
    {
        $moderators = User::query()
            ->where('role', 'moderator')
            ->latest('id')
            ->paginate(25);

        return view('admin.moderators.index', compact('moderators'));
    }

    public function create(): View
    {
        return view('admin.moderators.create');
    }

    public function store(
        Request $request,
        AdminAuthoringCreateIntentService $createIntents
    ): RedirectResponse
    {
        $data = $this->validated($request);
        DB::transaction(function () use ($request, $data, $createIntents): void {
            $moderator = new User();
            $moderator->forceFill([
                'name_ar' => trim((string) $data['name_ar']),
                'name_en' => filled($data['name_en'] ?? null) ? trim((string) $data['name_en']) : null,
                'email' => strtolower(trim((string) $data['email'])),
                'phone' => filled($data['phone'] ?? null) ? trim((string) $data['phone']) : null,
                'password' => Hash::make((string) $data['password']),
                'role' => 'moderator',
                'active' => $request->boolean('active'),
                'email_verified_at' => now(),
            ])->save();
            $createIntents->completeRedirect(
                $request,
                route('admin.moderators.index'),
                302,
                User::class,
                $moderator->id
            );
        }, 3);

        return redirect()->route('admin.moderators.index')
            ->with('success', 'تم إنشاء حساب مسؤول المحتوى. سيُطلب منه إعداد التحقق بخطوتين عند الدخول.');
    }

    public function edit(User $moderator): View
    {
        $this->assertModerator($moderator);

        return view('admin.moderators.edit', compact('moderator'));
    }

    public function update(Request $request, User $moderator): RedirectResponse
    {
        $this->assertModerator($moderator);
        $data = $this->validated($request, $moderator);
        $updates = [
            'name_ar' => trim((string) $data['name_ar']),
            'name_en' => filled($data['name_en'] ?? null) ? trim((string) $data['name_en']) : null,
            'email' => strtolower(trim((string) $data['email'])),
            'phone' => filled($data['phone'] ?? null) ? trim((string) $data['phone']) : null,
            'active' => $request->boolean('active'),
        ];
        if (filled($data['password'] ?? null)) {
            $updates['password'] = Hash::make((string) $data['password']);
        }
        $moderator->forceFill($updates)->save();

        return redirect()->route('admin.moderators.index')
            ->with('success', 'تم تحديث حساب مسؤول المحتوى.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?User $moderator = null): array
    {
        return $request->validate([
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'email' => [
                'required', 'email:rfc', 'max:255',
                Rule::unique('users', 'email')->ignore($moderator?->id),
            ],
            'phone' => [
                'nullable', 'string', 'max:20',
                Rule::unique('users', 'phone')->ignore($moderator?->id),
            ],
            'password' => [$moderator ? 'nullable' : 'required', 'string', 'min:10', 'confirmed'],
            'active' => ['nullable', 'boolean'],
            'authoring_request_id' => [$moderator ? 'nullable' : 'required', 'uuid'],
        ]);
    }

    private function assertModerator(User $user): void
    {
        abort_unless(strtolower((string) $user->role) === 'moderator', 404);
    }
}
