<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FeedbackAttachment;
use App\Models\FeedbackReport;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FeedbackController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['new', 'reviewing', 'resolved', 'dismissed'])],
            'category' => ['nullable', Rule::in(['bug', 'suggestion', 'course_content', 'playback'])],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'app_version' => 'nullable|string|max:32',
            'course_id' => 'nullable|integer',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        $reports = FeedbackReport::query()
            ->with(['user:id,name', 'course:id,name_ar,name_en', 'assignee:id,name'])
            ->when($filters['status'] ?? null, fn ($q, $value) => $q->where('status', $value))
            ->when($filters['category'] ?? null, fn ($q, $value) => $q->where('category', $value))
            ->when($filters['priority'] ?? null, fn ($q, $value) => $q->where('priority', $value))
            ->when($filters['app_version'] ?? null, fn ($q, $value) => $q->where('app_version', $value))
            ->when($filters['course_id'] ?? null, fn ($q, $value) => $q->where('course_id', $value))
            ->when($filters['from'] ?? null, fn ($q, $value) => $q->whereDate('created_at', '>=', $value))
            ->when($filters['to'] ?? null, fn ($q, $value) => $q->whereDate('created_at', '<=', $value))
            ->latest()->paginate(30)->withQueryString();

        return view('admin.feedback.index', compact('reports', 'filters'));
    }

    public function show(FeedbackReport $feedback): View
    {
        $feedback->load(['user', 'course', 'lesson', 'assignee', 'attachments']);
        $admins = User::query()->where('role', 'admin')->orderBy('name')->get(['id', 'name']);

        return view('admin.feedback.show', compact('feedback', 'admins'));
    }

    public function update(Request $request, FeedbackReport $feedback): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['new', 'reviewing', 'resolved', 'dismissed'])],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'assigned_to' => ['nullable', Rule::exists('users', 'id')->where(fn ($q) => $q->where('role', 'admin'))],
        ]);
        $validated['resolved_at'] = in_array($validated['status'], ['resolved', 'dismissed'], true)
            ? ($feedback->resolved_at ?: now()) : null;
        $feedback->update($validated);

        return back()->with('success', 'تم تحديث حالة الملاحظة.');
    }

    public function attachment(FeedbackReport $feedback, FeedbackAttachment $attachment): StreamedResponse
    {
        abort_unless((int) $attachment->feedback_report_id === (int) $feedback->id, 404);
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        return Storage::disk($attachment->disk)->response(
            $attachment->path,
            $feedback->public_id.'.jpg',
            ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']
        );
    }

    public function destroy(FeedbackReport $feedback): RedirectResponse
    {
        $feedback->load('attachments');
        foreach ($feedback->attachments as $attachment) {
            Storage::disk($attachment->disk)->delete($attachment->path);
        }
        $feedback->delete();

        return redirect()->route('admin.feedback.index')->with('success', 'تم حذف الملاحظة ومرفقاتها.');
    }
}
