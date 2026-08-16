@extends('admin.layouts.app')

@section('page.title', 'ملاحظات التطبيق')

@section('content')
@php
    $categories = ['bug' => 'مشكلة', 'suggestion' => 'اقتراح', 'course_content' => 'محتوى', 'playback' => 'تشغيل'];
    $priorities = ['low' => 'منخفضة', 'normal' => 'عادية', 'high' => 'عالية', 'urgent' => 'عاجلة'];
@endphp

<div class="admin-page">
    @include('admin.partials.page-header', [
        'pageTitle' => 'ملاحظات التطبيق',
        'pageDescription' => 'متابعة البلاغات والاقتراحات القادمة من التطبيق.',
        'pageIcon' => 'fa-commenting-o',
    ])

    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif

    <div class="card admin-card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong>تصفية الملاحظات</strong>
            <span class="badge badge-primary">{{ number_format($reports->total()) }}</span>
        </div>
        <div class="card-body">
            <form method="GET">
                <div class="row">
                    <div class="col-md-2 mb-3"><label for="status">الحالة</label><select id="status" class="form-control" name="status"><option value="">الكل</option>@foreach(['new'=>'جديد','reviewing'=>'قيد المراجعة','resolved'=>'محلول','dismissed'=>'مغلق'] as $value=>$label)<option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
                    <div class="col-md-2 mb-3"><label for="category">النوع</label><select id="category" class="form-control" name="category"><option value="">الكل</option>@foreach($categories as $value=>$label)<option value="{{ $value }}" @selected(($filters['category'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
                    <div class="col-md-2 mb-3"><label for="priority">الأولوية</label><select id="priority" class="form-control" name="priority"><option value="">الكل</option>@foreach($priorities as $value=>$label)<option value="{{ $value }}" @selected(($filters['priority'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
                    <div class="col-md-2 mb-3"><label for="app-version">إصدار التطبيق</label><input id="app-version" class="form-control" name="app_version" value="{{ $filters['app_version'] ?? '' }}"></div>
                    <div class="col-md-2 mb-3"><label for="from">من</label><input id="from" class="form-control" type="date" name="from" value="{{ $filters['from'] ?? '' }}"></div>
                    <div class="col-md-2 mb-3"><label for="to">إلى</label><input id="to" class="form-control" type="date" name="to" value="{{ $filters['to'] ?? '' }}"></div>
                </div>
                <div class="admin-actions">
                    <button class="btn btn-primary" type="submit"><i class="fa fa-filter"></i> تطبيق</button>
                    <a href="{{ route('admin.feedback.index') }}" class="btn btn-light">مسح</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card admin-card">
        <div class="table-responsive">
            <table class="table table-hover admin-table mb-0">
                <thead class="thead-light"><tr><th>المرجع</th><th>النوع</th><th>الرسالة</th><th>الحالة</th><th>الأولوية</th><th>الإصدار</th><th>الوقت</th></tr></thead>
                <tbody>
                @forelse($reports as $report)
                    <tr>
                        <td><a class="admin-code" href="{{ route('admin.feedback.show', $report) }}">{{ $report->public_id }}</a></td>
                        <td>{{ $categories[$report->category] ?? $report->category }}</td>
                        <td>{{ IlluminateSupportStr::limit($report->message, 85) }}</td>
                        <td>@include('admin.partials.status-badge', ['badgeStatus' => $report->status])</td>
                        <td>@include('admin.partials.status-badge', ['badgeStatus' => $report->priority, 'badgeLabel' => $priorities[$report->priority] ?? $report->priority, 'badgeTone' => $report->priority === 'urgent' ? 'danger' : ($report->priority === 'high' ? 'warning' : 'muted')])</td>
                        <td>{{ $report->app_version ?: '—' }}</td>
                        <td>{{ $report->created_at->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-5">لا توجد ملاحظات مطابقة.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $reports->links() }}</div>
</div>
@endsection
