@extends('admin.layouts.app')

@section('page.title', 'تفاصيل الملاحظة')

@section('content')
@php
    $statuses = ['new' => 'جديد', 'reviewing' => 'قيد المراجعة', 'resolved' => 'محلول', 'dismissed' => 'مغلق'];
    $priorities = ['low' => 'منخفضة', 'normal' => 'عادية', 'high' => 'عالية', 'urgent' => 'عاجلة'];
@endphp

<div class="admin-page">
    @include('admin.partials.page-header', [
        'pageTitle' => 'تفاصيل الملاحظة',
        'pageDescription' => $feedback->public_id,
        'pageIcon' => 'fa-comment-o',
        'pageActionUrl' => route('admin.feedback.index'),
        'pageActionLabel' => 'العودة للملاحظات',
        'pageActionIcon' => 'fa-arrow-right',
        'pageActionClass' => 'btn-light',
    ])

    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif

    <div class="card admin-card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong>الملاحظة</strong>
            @include('admin.partials.status-badge', ['badgeStatus' => $feedback->status])
        </div>
        <div class="card-body">
            <div class="admin-copy mb-4">{{ $feedback->message }}</div>
            <div class="row">
                <div class="col-md-4 mb-3"><div class="admin-detail-label">النوع</div><div class="admin-detail-value">{{ $feedback->category }}</div></div>
                <div class="col-md-4 mb-3"><div class="admin-detail-label">الشاشة</div><div class="admin-detail-value">{{ $feedback->screen_key ?: '—' }}</div></div>
                <div class="col-md-4 mb-3"><div class="admin-detail-label">الإصدار</div><div class="admin-detail-value">{{ $feedback->app_version ?: '—' }} ({{ $feedback->build_number ?: '—' }})</div></div>
                <div class="col-md-4 mb-3"><div class="admin-detail-label">المستخدم</div><div class="admin-detail-value">{{ $feedback->user?->name ?: 'زائر' }}</div></div>
                <div class="col-md-4 mb-3"><div class="admin-detail-label">الكورس</div><div class="admin-detail-value">{{ $feedback->course?->title ?: '—' }}</div></div>
                <div class="col-md-4 mb-3"><div class="admin-detail-label">المنصة</div><div class="admin-detail-value">{{ $feedback->platform ?: '—' }}</div></div>
            </div>
            <div class="admin-actions">
                @foreach($feedback->attachments as $attachment)
                    <a class="btn btn-outline-primary" target="_blank" rel="noopener" href="{{ route('admin.feedback.attachment', [$feedback, $attachment]) }}"><i class="fa fa-image"></i> عرض لقطة الشاشة الخاصة</a>
                @endforeach
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.feedback.update', $feedback) }}" class="card admin-card mb-4">
        @csrf
        @method('PATCH')
        <div class="card-header"><strong>إدارة الملاحظة</strong></div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4 mb-3"><label for="status">الحالة</label><select id="status" class="form-control" name="status">@foreach($statuses as $value => $label)<option value="{{ $value }}" @selected($feedback->status === $value)>{{ $label }}</option>@endforeach</select></div>
                <div class="col-md-4 mb-3"><label for="priority">الأولوية</label><select id="priority" class="form-control" name="priority">@foreach($priorities as $value => $label)<option value="{{ $value }}" @selected($feedback->priority === $value)>{{ $label }}</option>@endforeach</select></div>
                <div class="col-md-4 mb-3"><label for="assigned-to">المسؤول</label><select id="assigned-to" class="form-control" name="assigned_to"><option value="">غير مسند</option>@foreach($admins as $admin)<option value="{{ $admin->id }}" @selected($feedback->assigned_to === $admin->id)>{{ $admin->name }}</option>@endforeach</select></div>
            </div>
            <button class="btn btn-primary"><i class="fa fa-save"></i> حفظ</button>
        </div>
    </form>

    <form method="POST" action="{{ route('admin.feedback.destroy', $feedback) }}" onsubmit="return confirm('حذف الملاحظة ومرفقاتها نهائيًا؟')">
        @csrf
        @method('DELETE')
        <button class="btn btn-danger"><i class="fa fa-trash"></i> حذف الملاحظة</button>
    </form>
</div>
@endsection
