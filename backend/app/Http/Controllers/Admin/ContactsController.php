<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ContactsController extends Controller
{
    public function index()
    {
        $contacts = Contact::query()
            ->with(['resolver', 'resolvedUser'])
            ->orderBy('read')
            ->latest()
            ->get();

        return view('admin.contacts.index', compact('contacts'));
    }

    public function show(Contact $contact)
    {
        $contact->load(['resolver', 'resolvedUser']);
        $deletionUser = $contact->isAccountDeletionRequest() && !$contact->isResolved()
            ? $this->existingUserForEmail($contact->email)
            : null;

        return view('admin.contacts.show', compact('contact', 'deletionUser'));
    }

    public function markRead(Contact $contact): RedirectResponse
    {
        if (!$contact->read) {
            $contact->forceFill(['read' => true])->save();
        }

        return redirect()->route('admin.contacts.show', $contact);
    }

    public function destroy(Contact $contact)
    {
        if ($contact->isAccountDeletionRequest()) {
            return redirect()
                ->route('admin.contacts.show', $contact)
                ->with('error', 'لا يمكن حذف سجل طلب حذف حساب؛ يجب الاحتفاظ بحالة الطلب وسجل معالجته.');
        }

        $contact->delete();

        return redirect()
            ->route('admin.contacts.index')
            ->with('success', 'تم الحذف بنجاح');
    }

    public function markProcessing(Contact $contact): RedirectResponse
    {
        if (!$contact->isAccountDeletionRequest() || $contact->isResolved()) {
            return redirect()->route('admin.contacts.show', $contact)
                ->with('error', 'لا يمكن بدء معالجة هذا الطلب في حالته الحالية.');
        }

        $metadata = (array) ($contact->resolution_metadata ?? []);
        $metadata['processing_started_at'] = now()->toIso8601String();
        $metadata['processing_started_by'] = (int) auth()->id();

        $contact->forceFill([
            'read' => true,
            'resolution_status' => Contact::RESOLUTION_PROCESSING,
            'resolution_metadata' => $metadata,
        ])->save();

        return redirect()->route('admin.contacts.show', $contact)
            ->with('success', 'تم نقل الطلب إلى المعالجة. تحقق من ملكية الحساب قبل أي إجراء على بياناته.');
    }

    public function closeDeletionRequest(Request $request, Contact $contact): RedirectResponse
    {
        if (!$contact->isAccountDeletionRequest() || $contact->isResolved()) {
            return redirect()->route('admin.contacts.show', $contact)
                ->with('error', 'لا يمكن إغلاق هذا الطلب في حالته الحالية.');
        }
        if (!$contact->isProcessing()) {
            return redirect()->route('admin.contacts.show', $contact)
                ->with('error', 'ابدأ المعالجة وتحقق من بيانات الطلب قبل إغلاقه.');
        }

        $validated = $request->validate([
            'outcome' => ['required', 'in:self_service_completed,no_account_found,duplicate,withdrawn'],
            'resolution_note' => ['nullable', 'string', 'max:500'],
            'confirm_close' => ['accepted'],
        ], [
            'outcome.required' => 'اختر نتيجة المعالجة.',
            'outcome.in' => 'نتيجة المعالجة غير صالحة.',
            'confirm_close.accepted' => 'أكد أنك راجعت الطلب قبل إغلاقه.',
        ]);

        $matchedUser = $this->existingUserForEmail($contact->email);
        if (in_array($validated['outcome'], ['self_service_completed', 'no_account_found'], true) && $matchedUser) {
            return redirect()->route('admin.contacts.show', $contact)
                ->withInput()
                ->with('error', 'لا يمكن تسجيل هذه النتيجة لأن الحساب المطابق ما زال موجودًا. وجّه صاحبه إلى حذف الحساب من التطبيق بعد التحقق من هويته.');
        }

        $metadata = (array) ($contact->resolution_metadata ?? []);
        $metadata['outcome'] = $validated['outcome'];
        $metadata['note'] = trim((string) ($validated['resolution_note'] ?? '')) ?: null;

        $contact->forceFill([
            'read' => true,
            'resolution_status' => Contact::RESOLUTION_CLOSED,
            'resolved_at' => now(),
            'resolved_by' => (int) auth()->id(),
            'resolved_user_id' => $matchedUser?->id,
            'resolution_metadata' => $metadata,
        ])->save();

        return redirect()->route('admin.contacts.show', $contact)
            ->with('success', 'تم إغلاق الطلب مع حفظ النتيجة وسجل المعالجة.');
    }

    private function existingUserForEmail(?string $email): ?User
    {
        $normalizedEmail = Str::lower(trim((string) $email));
        if ($normalizedEmail === '') {
            return null;
        }

        return User::query()
            ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
            ->first();
    }
}
