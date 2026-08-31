@extends('admin.layouts.app')

@section('page.title', 'مراجعة محاولة مشروع')

@section('content')
@php
    $section = optional($submission->project)->section;
    $course = optional($section)->course;
    $history = data_get($submission->submission_metadata, 'review_history', []);
@endphp

<div class="admin-page animated fadeIn">
    @include('admin.partials.page-header', [
        'pageTitle' => optional($section)->title ?: 'مشروع #' . $submission->project_id,
        'pageDescription' => 'مراجعة محاولة الطالب وسجل القرار المرتبط بها.',
        'pageIcon' => 'fa-file-text-o',
        'pageActionUrl' => route('admin.project-submissions.index'),
        'pageActionLabel' => 'العودة للمحاولات',
        'pageActionIcon' => 'fa-arrow-right',
        'pageActionClass' => 'btn-light',
    ])

    <div class="row">
        <div class="col-lg-8">
            <div class="card admin-card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong>بيانات المحاولة</strong>
                    @include('admin.partials.status-badge', ['badgeStatus' => $submission->review_status])
                </div>
                <div class="card-body">
                    <div class="row mb-4">
                        <div class="col-md-6 mb-3"><div class="admin-detail-label">الطالب</div><div class="admin-detail-value">{{ optional($submission->user)->name ?: 'حساب محذوف' }}</div><small class="text-muted">{{ optional($submission->user)->email }}</small></div>
                        <div class="col-md-6 mb-3"><div class="admin-detail-label">الكورس</div><div class="admin-detail-value">{{ optional($course)->title ?: 'غير متاح' }}</div></div>
                        <div class="col-md-6 mb-3"><div class="admin-detail-label">رقم المحاولة</div><div class="admin-detail-value admin-code">{{ $submission->public_id }}</div></div>
                        <div class="col-md-3 mb-3"><div class="admin-detail-label">حالة الجهد</div><div class="admin-detail-value">{{ $submission->effort_status }}</div></div>
                        <div class="col-md-3 mb-3"><div class="admin-detail-label">وقت الإرسال</div><div class="admin-detail-value">{{ optional($submission->submitted_at)->format('Y-m-d H:i') ?: '—' }}</div></div>
                    </div>

                    <h5>النص المرسل</h5>
                    <div class="admin-copy mb-4">{{ $submission->submission_text ?: 'لا يوجد نص مرفق.' }}</div>

                    <h5>المرفق</h5>
                    @if($submission->submission_file)
                        <div class="border rounded p-3 d-flex flex-wrap justify-content-between align-items-center admin-gap">
                            <div><strong>{{ $submission->original_file_name ?: 'ملف المحاولة' }}</strong><br><small class="text-muted">{{ $submission->mime_type ?: 'نوع غير معروف' }} @if($submission->file_size) · {{ number_format($submission->file_size / 1024, 1) }} KB @endif</small></div>
                            <a href="{{ route('admin.project-submissions.download', $submission) }}" class="btn btn-outline-primary"><i class="fa fa-download"></i> تنزيل آمن</a>
                        </div>
                    @else
                        <p class="text-muted">لا يوجد ملف مرفق.</p>
                    @endif
                </div>
            </div>

            @if($submission->review_status === 'pending' || ($submission->review_status === 'passed' && $submission->review_source === 'graceful_fallback'))
                <div class="card admin-card mb-4">
                    <div class="card-header"><strong>قرار المراجعة</strong></div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('admin.project-submissions.pass', $submission) }}" class="mb-4" onsubmit="return confirm('تأكيد قبول هذه المحاولة؟ سيتم تحديث تقدم الطالب.');">
                            @csrf
                            <div class="form-group"><label for="pass-feedback">ملاحظة القبول (اختيارية)</label><textarea id="pass-feedback" name="feedback" rows="3" maxlength="2000" class="form-control">{{ old('feedback') }}</textarea></div>
                            <button type="submit" class="btn btn-success"><i class="fa fa-check"></i> قبول المحاولة</button>
                        </form>
                        @if($submission->review_status === 'pending')
                            <hr>
                            <form method="POST" action="{{ route('admin.project-submissions.reject', $submission) }}" onsubmit="return confirm('تأكيد طلب إعادة إرسال المحاولة؟');">
                                @csrf
                                <div class="form-group"><label for="reject-feedback">سبب طلب إعادة الإرسال</label><textarea id="reject-feedback" name="feedback" rows="4" minlength="3" maxlength="2000" required class="form-control">{{ old('feedback') }}</textarea><small class="form-text text-muted">يظهر هذا النص للطالب، لذلك اكتب توجيهًا واضحًا وقابلًا للتنفيذ.</small></div>
                                <button type="submit" class="btn btn-danger"><i class="fa fa-repeat"></i> طلب إعادة الإرسال</button>
                            </form>
                        @else
                            <p class="text-muted mb-0">تم فتح المقطع التالي للطالب ويثبت قبولك المراجعة البشرية</p>
                        @endif
                    </div>
                </div>
            @endif
        </div>

        <div class="col-lg-4">
            <div class="card admin-card mb-4">
                <div class="card-header"><strong>سجل القرار</strong></div>
                <div class="card-body">
                    <div class="admin-detail-label">مصدر القرار</div><div class="admin-detail-value mb-3">{{ $submission->review_source ?: 'لم يصدر قرار بعد' }}</div>
                    <div class="admin-detail-label">المراجع</div><div class="admin-detail-value mb-3">{{ optional($submission->reviewer)->name ?: 'مراجعة آلية / غير محدد' }}</div>
                    <div class="admin-detail-label">وقت القرار</div><div class="admin-detail-value mb-3">{{ optional($submission->reviewed_at)->format('Y-m-d H:i:s') ?: '—' }}</div>
                    <div class="admin-detail-label">النتيجة</div><div class="admin-detail-value mb-3">{{ $submission->score !== null ? $submission->score . '/100' : '—' }}</div>
                    <div class="admin-detail-label">الملاحظة المسجلة</div><div class="mb-3">{{ $submission->feedback ?: '—' }}</div>
                    @if(is_array($history) && count($history))
                        <hr>
                        @foreach(array_reverse($history) as $item)
                            <div class="admin-audit-item">
                                @include('admin.partials.status-badge', ['badgeStatus' => $item['status'] ?? 'unknown'])
                                <br><small class="text-muted">{{ $item['source'] ?? 'unknown' }} · {{ $item['reviewed_at'] ?? '—' }}</small>
                            </div>
                        @endforeach
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
