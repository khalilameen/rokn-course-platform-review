<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\User;
use App\Services\AccountDeletionService;
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
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

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

    public function executeAccountDeletion(
        Request $request,
        Contact $contact,
        AccountDeletionService $accounts
    ): RedirectResponse {
        if (!$contact->isAccountDeletionRequest() || $contact->isResolved()) {
            return redirect()->route('admin.contacts.show', $contact)
                ->with('error', 'طلب الحذف غير متاح للتنفيذ في حالته الحالية.');
        }
        if (!$contact->isProcessing()) {
            return redirect()->route('admin.contacts.show', $contact)
                ->with('error', 'ابدأ المعالجة وتحقق من صاحب الحساب أولًا.');
        }

        $matchedUser = $this->existingUserForEmail($contact->email);
        if (!$matchedUser || strtolower((string) $matchedUser->role) !== 'client') {
            return redirect()->route('admin.contacts.show', $contact)
                ->with('error', 'لا يوجد حساب طالب نشط مطابق لهذا الطلب.');
        }

        $validated = $request->validate([
            'account_email' => ['required', 'string', 'max:255'],
            'verification_note' => ['required', 'string', 'min:8', 'max:500'],
            'confirm_identity' => ['accepted'],
            'confirm_delete' => ['accepted'],
        ], [
            'account_email.required' => 'اكتب بريد الحساب للتأكيد.',
            'verification_note.required' => 'سجّل طريقة التحقق من صاحب الحساب.',
            'verification_note.min' => 'اكتب ملاحظة تحقق أوضح.',
            'confirm_identity.accepted' => 'أكد أنك تحققت من صاحب الحساب.',
            'confirm_delete.accepted' => 'أكد تنفيذ الحذف النهائي.',
        ]);
        $confirmedEmail = Str::lower(trim((string) $validated['account_email']));
        if (!hash_equals(Str::lower(trim((string) $matchedUser->email)), $confirmedEmail)) {
            return redirect()->route('admin.contacts.show', $contact)
                ->withInput()
                ->with('error', 'بريد التأكيد لا يطابق الحساب المطلوب حذفه.');
        }

        $cleanup = $accounts->delete($matchedUser);
        $cleanupPending = (bool) (
            $cleanup['local_cleanup_pending']
            || $cleanup['remote_portfolio_cleanup_pending']
        );
        $metadata = (array) ($contact->resolution_metadata ?? []);
        $metadata['outcome'] = 'manual_verified_deletion';
        $metadata['note'] = trim((string) $validated['verification_note']);
        $metadata['cleanup_pending'] = $cleanupPending;

        $contact->forceFill([
            'read' => true,
            'resolution_status' => Contact::RESOLUTION_CLOSED,
            'resolved_at' => now(),
            'resolved_by' => (int) auth()->id(),
            'resolved_user_id' => $matchedUser->id,
            'resolution_metadata' => $metadata,
        ])->save();

        return redirect()->route('admin.contacts.show', $contact)
            ->with(
                'success',
                $cleanupPending
                    ? 'تم إغلاق الحساب وبدأ حذف ملفاته من التخزين.'
                    : 'تم حذف الحساب وبياناته الشخصية.'
            );
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
