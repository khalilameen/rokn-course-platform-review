@extends('admin.layouts.app')

@section('page.title', 'إشعارات الطلاب')

@section('styles')
<link rel="stylesheet" href="{{ asset('admin/assets/css/notifications-dashboard.css') }}">
@endsection

@section('content')
<div class="admin-page notifications-page" dir="rtl">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div><h1 class="h3 mb-1">إشعارات الطلاب</h1><p class="text-muted mb-0">سجل صندوق الوارد ومحاولات Push والقراءة لكل حملة</p></div>
        <div><a href="{{ route('admin.admin_notifications.index') }}" class="btn btn-light">قوالب ونبرة الإشعارات</a> <a href="{{ route('admin.notifications.create') }}" class="btn btn-primary"><i class="fa fa-plus ml-1"></i> إشعار جديد</a></div>
    </div>

    <div class="card border-0 shadow-sm notifications-page__card">
        <div class="table-responsive"><table class="table mb-0 text-right">
            <thead class="thead-light"><tr><th>الإشعار</th><th>الحالة</th><th>وقت الإضافة</th><th>صندوق الوارد</th><th>محاولة Push</th><th>قبله مزود Push</th><th>قُرئ</th><th>الوجهة</th><th>الإجراء</th></tr></thead>
            <tbody>
            @forelse($campaigns as $campaign)
                <tr>
                    <td>
                        <div class="notification-campaign-copy">
                            @if($campaign->image_url)<img alt="" src="{{ $campaign->image_url }}">@endif
                            <div><strong>{{ $campaign->title_ar }}</strong><br><span>{{ $campaign->message_ar }}</span><br><small class="text-muted">{{ $campaign->notification_type }}</small></div>
                        </div>
                    </td>
                    <td>
                        @php($status = $campaign->status ?? 'completed')
                        @php($withdrawn = ($campaign->failure_code ?? null) === 'course_withdrawn_before_delivery')
                        {{ $withdrawn ? 'لم يُرسل — الكورس غير متاح' : (['scheduled' => 'في موعده', 'queued' => 'في القائمة', 'delivering' => 'جارٍ التوزيع', 'completed' => 'اكتمل', 'failed' => 'تعذّر الإرسال'][$status] ?? $status) }}
                        @if($status === 'failed' && $campaign->failure_code && !$withdrawn)
                            <br><small class="text-muted">{{ $campaign->failure_code }}</small>
                        @endif
                    </td>
                    <td>{{ data_get($campaign, 'scheduled_at') ? \App\Support\BusinessClock::format(data_get($campaign, 'scheduled_at')) : ($campaign->queued_at ? \App\Support\BusinessClock::format($campaign->queued_at) : 'الآن') }}</td>
                    <td>
                        {{ number_format($campaign->inbox_count ?? $campaign->recipients_count) }}@if(isset($campaign->recipients_count) && $campaign->recipients_count > 0) / {{ number_format($campaign->recipients_count) }}@endif
                        @if(($campaign->skipped_count ?? 0) > 0)<br><small class="text-muted">استُبعد {{ number_format($campaign->skipped_count) }} بعد تغيّر الأهلية</small>@endif
                    </td>
                    <td>{{ number_format($campaign->attempted_count) }}</td>
                    <td>
                        {{ number_format($campaign->provider_accepted_count) }}
                        @if(($campaign->push_failed_count ?? 0) > 0)<br><small class="text-danger">تعذّر {{ number_format($campaign->push_failed_count) }}</small>@endif
                        @if(($campaign->push_partial_count ?? 0) > 0)<br><small class="text-warning">جزئي {{ number_format($campaign->push_partial_count) }}</small>@endif
                    </td>
                    <td>{{ number_format($campaign->read_count) }}</td>
                    <td><code>{{ $campaign->link ?: 'الرئيسية' }}</code></td>
                    <td>
                        @if($status === 'failed' && isset($campaign->id))
                            <form method="POST" action="{{ route('admin.notifications.retry', $campaign) }}" onsubmit="const button=this.querySelector('button[type=submit]'); if(button.disabled) return false; button.disabled=true;">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-primary">إعادة المحاولة</button>
                            </form>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="text-center text-muted py-5">لا توجد حملات بعد</td></tr>
            @endforelse
            </tbody>
        </table></div>
        @if($campaigns->hasPages())<div class="card-footer bg-white">{{ $campaigns->links() }}</div>@endif
    </div>
</div>
@endsection
