@extends('admin.layouts.app')

@section('page.title', 'إشعارات الطلاب')

@section('styles')
<link rel="stylesheet" href="{{ asset('admin/assets/css/notifications-dashboard.css') }}">
@endsection

@section('content')
<div class="admin-page notifications-page" dir="rtl">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div><h1 class="h3 mb-1">إشعارات الطلاب</h1><p class="text-muted mb-0">سجل الإرسال والوصول والقراءة لكل حملة</p></div>
        <a href="{{ route('admin.notifications.create') }}" class="btn btn-primary"><i class="fa fa-plus ml-1"></i> إشعار جديد</a>
    </div>

    <div class="card border-0 shadow-sm notifications-page__card">
        <div class="table-responsive"><table class="table mb-0 text-right">
            <thead class="thead-light"><tr><th>الإشعار</th><th>وقت الإرسال</th><th>المستلمون</th><th>محاولة Push</th><th>وصل Push</th><th>قُرئ داخل التطبيق</th><th>الوجهة</th></tr></thead>
            <tbody>
            @forelse($campaigns as $campaign)
                <tr>
                    <td><strong>{{ $campaign->title_ar }}</strong><br><small class="text-muted">{{ $campaign->notification_type }}</small></td>
                    <td>{{ \Illuminate\Support\Carbon::parse($campaign->sent_at)->format('Y-m-d H:i') }}</td>
                    <td>{{ number_format($campaign->recipients_count) }}</td>
                    <td>{{ number_format($campaign->attempted_count) }}</td>
                    <td>{{ number_format($campaign->sent_count) }}</td>
                    <td>{{ number_format($campaign->read_count) }}</td>
                    <td><code>{{ $campaign->link ?: 'الرئيسية' }}</code></td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-muted py-5">لم تُرسل حملات بعد</td></tr>
            @endforelse
            </tbody>
        </table></div>
        @if($campaigns->hasPages())<div class="card-footer bg-white">{{ $campaigns->links() }}</div>@endif
    </div>
</div>
@endsection
