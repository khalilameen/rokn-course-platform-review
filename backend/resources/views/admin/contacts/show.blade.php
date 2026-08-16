@extends('admin.layouts.app')
@section('page.title', $contact->isAccountDeletionRequest() ? 'طلب حذف حساب' : 'تفاصيل الرسالة')

@section('styles')
<link rel="stylesheet" href="{{ asset('admin/assets/css/admin-learning-views.css') }}">
@endsection

@section('content')

<div class="contact-page admin-learning admin-learning--contacts">
    <div class="contact-top">
        <div>
            <h1>{{ $contact->isAccountDeletionRequest() ? 'طلب حذف حساب' : 'تفاصيل الرسالة' }}</h1>
        </div>
        <a class="contact-back" href="{{ route('admin.contacts.index') }}"><i class="fa fa-arrow-right"></i> كل الرسائل</a>
    </div>

    @if(session('success'))<div class="flash flash-success">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="flash flash-error">{{ session('error') }}</div>@endif
    @if($errors->any())
        <div class="flash flash-error"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    @unless($contact->read)
        <form method="POST" action="{{ route('admin.contacts.read', $contact) }}" class="mb-3">
            @csrf
            <button class="btn-rokn btn-muted-rokn" type="submit">تحديد كمقروءة</button>
        </form>
    @endunless

    <section class="contact-card">
        <div class="contact-grid">
            <div class="contact-field"><span>الاسم</span><strong>{{ $contact->name ?: '—' }}</strong></div>
            <div class="contact-field"><span>تاريخ الطلب</span><strong>{{ optional($contact->created_at)->format('Y-m-d H:i') ?: '—' }}</strong></div>
            <div class="contact-field"><span>البريد الإلكتروني</span><a href="mailto:{{ $contact->email }}">{{ $contact->email ?: '—' }}</a></div>
            <div class="contact-field"><span>رقم الهاتف</span><a href="tel:{{ $contact->phone }}">{{ $contact->phone ?: '—' }}</a></div>
        </div>
    </section>

    @if($contact->isAccountDeletionRequest())
        @php
            $status = $contact->resolution_status ?: \App\Models\Contact::RESOLUTION_PENDING;
            $statusLabel = $status === \App\Models\Contact::RESOLUTION_PROCESSING ? 'قيد المعالجة' : ($contact->isResolved() ? 'مغلق' : 'جديد');
            $statusClass = $status === \App\Models\Contact::RESOLUTION_PROCESSING ? 'status-processing' : ($contact->isResolved() ? 'status-closed' : 'status-pending');
            $outcomeLabels = [
                'self_service_completed' => 'حذف صاحب الحساب حسابه من التطبيق',
                'no_account_found' => 'لا يوجد حساب مطابق بعد التحقق',
                'duplicate' => 'طلب مكرر',
                'withdrawn' => 'صاحب الطلب تراجع عنه',
            ];
            $outcome = data_get($contact->resolution_metadata, 'outcome');
        @endphp
        <section class="contact-card">
            <div class="request-head">
                <div>
                    <h2>مسار معالجة واضح وقابل للمراجعة</h2>
                    <p>لا يحذف هذا المسار أي حساب تلقائيًا. الحذف يتم من داخل حساب المستخدم بعد التحقق من هويته، ثم تُغلق التذكرة بالنتيجة الصحيحة.</p>
                </div>
                <span class="status-pill {{ $statusClass }}">{{ $statusLabel }}</span>
            </div>

            <div class="request-note">{{ $contact->message }}</div>

            @if($deletionUser)
                <div class="account-match">
                    <strong>يوجد حساب مطابق للبريد</strong>
                    <div class="mt-2"><a href="{{ route('admin.users.show', $deletionUser) }}">{{ $deletionUser->name }} · رقم {{ $deletionUser->id }}</a></div>
                    <small class="text-muted">تحقق من صاحب الطلب ثم وجّهه إلى «حذف الحساب» داخل التطبيق. لا تشارك بيانات الحساب عبر البريد.</small>
                </div>
            @elseif(!$contact->isResolved())
                <div class="account-match"><strong>لا يوجد حساب نشط مطابق للبريد حاليًا</strong><div class="text-muted mt-1">راجع البريد مع صاحب الطلب قبل اختيار نتيجة الإغلاق.</div></div>
            @endif

            @if($contact->isResolved())
                <div class="resolved-grid">
                    <div class="contact-field"><span>النتيجة</span><strong>{{ $outcomeLabels[$outcome] ?? 'مغلقة بعد المراجعة' }}</strong></div>
                    <div class="contact-field"><span>أغلقها</span><strong>{{ optional($contact->resolver)->name ?: 'مسؤول غير متاح' }}</strong></div>
                    <div class="contact-field"><span>وقت الإغلاق</span><strong>{{ optional($contact->resolved_at)->format('Y-m-d H:i') ?: '—' }}</strong></div>
                </div>
                @if(data_get($contact->resolution_metadata, 'note'))
                    <div class="account-match"><strong>ملاحظة المعالجة</strong><div class="mt-1">{{ data_get($contact->resolution_metadata, 'note') }}</div></div>
                @endif
            @else
                <div class="workflow-actions">
                    <div class="workflow-box">
                        <h3>ابدأ المعالجة</h3>
                        <p>سجّل أن أحد أفراد الفريق استلم الطلب وبدأ التحقق منه.</p>
                        <form method="POST" action="{{ route('admin.contacts.processing', $contact) }}">
                            @csrf
                            <button class="btn-rokn btn-primary-rokn" type="submit" {{ $contact->isProcessing() ? 'disabled' : '' }}>
                                {{ $contact->isProcessing() ? 'الطلب قيد المعالجة' : 'بدء المعالجة' }}
                            </button>
                        </form>
                    </div>
                    <div class="workflow-box">
                        <h3>إغلاق الطلب بعد المراجعة</h3>
                        <form method="POST" action="{{ route('admin.contacts.close-deletion-request', $contact) }}">
                            @csrf
                            <select name="outcome" required>
                                <option value="">اختر النتيجة</option>
                                @foreach($outcomeLabels as $value => $label)<option value="{{ $value }}" {{ old('outcome') === $value ? 'selected' : '' }}>{{ $label }}</option>@endforeach
                            </select>
                            <textarea name="resolution_note" maxlength="500" placeholder="ملاحظة داخلية مختصرة (اختياري)">{{ old('resolution_note') }}</textarea>
                            <label class="confirm-row"><input type="checkbox" name="confirm_close" value="1" required><span>راجعت الطلب واخترت نتيجة تعكس ما حدث فعلًا.</span></label>
                            <button class="btn-rokn btn-muted-rokn" type="submit">حفظ النتيجة وإغلاق الطلب</button>
                        </form>
                    </div>
                </div>
            @endif
        </section>
    @else
        <section class="contact-card">
            <div class="request-head"><div><h2>محتوى الرسالة</h2></div></div>
            <div class="request-note">{{ $contact->message }}</div>
            <div class="normal-actions">
                <a class="btn-rokn btn-primary-rokn" href="mailto:{{ $contact->email }}">الرد عبر البريد</a>
                <form method="POST" action="{{ route('admin.contacts.destroy', $contact) }}" onsubmit="return confirm('هل تريد حذف هذه الرسالة؟')">
                    @csrf @method('DELETE')
                    <button class="btn-rokn btn-danger-rokn" type="submit">حذف الرسالة</button>
                </form>
            </div>
        </section>
    @endif
</div>
@endsection
