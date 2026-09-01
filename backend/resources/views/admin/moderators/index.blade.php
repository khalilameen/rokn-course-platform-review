@extends('admin.layouts.app')
@section('page.title', 'فريق المحتوى')
@section('content')
<div class="admin-page animated fadeIn">
    @include('admin.partials.page-header', [
        'pageTitle' => 'فريق المحتوى',
        'pageDescription' => 'حسابات مستقلة بصلاحيات تشغيل الكورسات دون بيانات الطلاب أو المالية.',
        'pageIcon' => 'fa-user-secret',
        'pageActionUrl' => route('admin.moderators.create'),
        'pageActionLabel' => 'إضافة مسؤول محتوى',
        'pageActionIcon' => 'fa-plus',
    ])
    <div class="card admin-card"><div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>الاسم</th><th>البريد</th><th>الحالة</th><th>آخر دخول</th><th></th></tr></thead>
            <tbody>
            @forelse($moderators as $moderator)
                <tr>
                    <td>{{ $moderator->name }}</td>
                    <td>{{ $moderator->email }}</td>
                    <td>{{ $moderator->active ? 'نشط' : 'موقوف' }}</td>
                    <td>{{ $moderator->last_dashboard_login_at ? \App\Support\BusinessClock::format($moderator->last_dashboard_login_at) : 'لم يدخل بعد' }}</td>
                    <td><a class="btn btn-sm btn-outline-primary" href="{{ route('admin.moderators.edit', $moderator) }}">تعديل</a></td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted py-4">لم تُضف حسابات محتوى بعد.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>@if($moderators->hasPages())<div class="card-footer">{{ $moderators->links() }}</div>@endif</div>
</div>
@endsection
