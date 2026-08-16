@extends('admin.layouts.app')

@section('page.title', 'مراقبة تشغيل الفيديو')

@section('content')
@php
    $summary = data_get($operations, 'summary', []);
    $rollup = data_get($operations, 'rollup', []);
    $rollupOverall = data_get($rollup, 'overall', []);
    $errorLabels = [
        'manifest_unreachable' => 'تعذر الوصول إلى قائمة التشغيل',
        'manifest_http_error' => 'استجابة CDN غير ناجحة',
        'manifest_invalid' => 'قائمة التشغيل غير صالحة',
        'provider_unreachable' => 'تعذر الوصول إلى مزود الفيديو',
        'source_timeout' => 'انتهت مهلة مصدر الفيديو',
        'buffer_timeout' => 'توقف التحميل المؤقت',
        'network_error' => 'خطأ في اتصال الشبكة',
        'decode_error' => 'تعذر فك الفيديو على الجهاز',
        'client_error' => 'خطأ تشغيل من العميل',
    ];
    $formatDuration = static function ($seconds): string {
        if ($seconds === null || $seconds === '') {
            return '—';
        }
        $seconds = max(0, (int) $seconds);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remaining = $seconds % 60;
        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $remaining)
            : sprintf('%02d:%02d', $minutes, $remaining);
    };
@endphp

<div class="admin-page">
    <div class="admin-page__header">
        <div class="admin-page__heading">
            <span class="admin-page__icon" aria-hidden="true"><i class="fa fa-play-circle"></i></span>
            <div>
                <h1 class="admin-page__title">مراقبة تشغيل الفيديو</h1>
                <p class="admin-page__description">تشخيص فني بلا أسماء طلاب أو روابط موقعة أو بيانات شخصية.</p>
            </div>
        </div>
        <div class="admin-page__actions">
            <a href="{{ route('admin.user-sessions.index') }}" class="btn btn-outline-primary">إدارة الأجهزة والجلسات</a>
            <a href="{{ route('admin.product-operations.index') }}" class="btn btn-outline-secondary">مركز التشغيل</a>
        </div>
    </div>

    @if(!data_get($operations, 'available'))
        <div class="alert alert-warning">بيانات جلسات التشغيل ستظهر بعد تطبيق migrations الخاصة بلوحة الفيديو.</div>
    @else
        <div class="row mb-3">
            @foreach([
                ['نشطة الآن', data_get($summary, 'active', 0), 'success'],
                ['عالقة', data_get($summary, 'stale', 0), 'warning'],
                ['اكتملت', data_get($summary, 'completed', 0), 'primary'],
                ['بها خطأ', data_get($summary, 'errors', 0), 'danger'],
                ['احتاجت تعافيًا', data_get($summary, 'recovery_sessions', 0), 'info'],
            ] as [$label, $value, $tone])
                <div class="col-xl col-md-4 col-6 mb-3">
                    @include('admin.partials.metric-card', [
                        'metricLabel' => $label,
                        'metricValue' => number_format((int) $value),
                        'metricIcon' => $tone === 'danger' ? 'fa-exclamation-circle' : ($tone === 'warning' ? 'fa-clock-o' : 'fa-play-circle'),
                    ])
                </div>
            @endforeach
        </div>

        @if($rollup)
            <div class="card admin-card mb-4">
                <div class="card-body">
                    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 admin-gap">
                        <h2 class="h5 mb-0">جودة التجربة — آخر {{ data_get($operations, 'period_days', 7) }} أيام</h2>
                        <small class="text-muted">آخر تجميع: {{ data_get($rollup, 'last_rollup_at', 'لم يبدأ بعد') }}</small>
                    </div>
                    <div class="row">
                        @foreach([
                            ['اكتمال الجلسات', data_get($rollupOverall, 'completion_rate', 0) . '%'],
                            ['معدل الأخطاء', data_get($rollupOverall, 'error_rate', 0) . '%'],
                            ['جلسات بها تحميل مؤقت', data_get($rollupOverall, 'buffering_rate', 0) . '%'],
                            ['متوسط بدء الفيديو', data_get($rollupOverall, 'average_startup_latency_ms') === null ? '—' : number_format((int) data_get($rollupOverall, 'average_startup_latency_ms')) . ' ms'],
                            ['أحداث التعافي', number_format((int) data_get($rollupOverall, 'recoveries', 0))],
                            ['متوسط البت الفعلي', data_get($rollupOverall, 'average_effective_bitrate_kbps') === null ? '—' : number_format((int) data_get($rollupOverall, 'average_effective_bitrate_kbps')) . ' kbps'],
                        ] as [$label, $value])
                            <div class="col-xl-2 col-md-4 col-6 mb-3">
                                <div class="admin-metric">
                                    <small class="text-muted d-block mb-1">{{ $label }}</small>
                                    <strong>{{ $value }}</strong>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

        <div class="row mb-4">
            <div class="col-lg-7 mb-3"><div class="card admin-card h-100"><div class="card-body">
                <h2 class="h5 mb-3">أكثر الخطوات فشلًا</h2>
                @forelse(data_get($operations, 'top_failing_lessons', collect()) as $failure)
                    <div class="admin-panel-row">
                        <div><strong>{{ $failure['lesson_title'] }}</strong><br><small class="text-muted">{{ $failure['course_title'] }}</small></div>
                        <span class="badge badge-danger align-self-center">{{ number_format((int) $failure['failures']) }} جلسة</span>
                    </div>
                @empty
                    <p class="text-muted mb-0">لا توجد أعطال مجمعة في الفترة الحالية.</p>
                @endforelse
            </div></div></div>
            <div class="col-lg-5 mb-3"><div class="card admin-card h-100"><div class="card-body">
                <h2 class="h5 mb-3">الجودة الفعلية</h2>
                @forelse(data_get($operations, 'quality_mix', collect()) as $quality)
                    <div class="admin-panel-row">
                        <span>{{ $quality['quality'] }}</span><strong>{{ number_format((int) $quality['sessions']) }}</strong>
                    </div>
                @empty
                    <p class="text-muted mb-0">لم تصل عينات جودة بعد.</p>
                @endforelse
            </div></div></div>
        </div>

        @if($rollup)
            <div class="row mb-4">
                @foreach([
                    ['نوع الاتصال', data_get($rollup, 'by_network', [])],
                    ['نظام التشغيل', data_get($rollup, 'by_os', [])],
                    ['أكثر رموز الخطأ', data_get($rollup, 'errors', [])],
                ] as [$heading, $items])
                    <div class="col-lg-4 mb-3"><div class="card admin-card h-100"><div class="card-body">
                        <h2 class="h6 mb-3">{{ $heading }}</h2>
                        @forelse($items as $item)
                            <div class="admin-panel-row">
                                <span>{{ $heading === 'أكثر رموز الخطأ' ? ($errorLabels[$item['value']] ?? $item['value']) : $item['value'] }}</span>
                                <strong>{{ number_format((int) $item['sessions']) }}</strong>
                            </div>
                        @empty
                            <span class="text-muted">لا توجد بيانات بعد.</span>
                        @endforelse
                    </div></div></div>
                @endforeach
            </div>
        @endif

        <div class="card admin-card mb-4">
            <div class="card-header"><h2 class="h5 mb-0">أحدث أخطاء التشغيل</h2></div>
            <div class="table-responsive"><table class="table admin-table mb-0 text-right">
                <thead class="thead-light"><tr><th>الكورس</th><th>الخطوة</th><th>الخطأ</th><th>الجودة</th><th>التعافي</th><th>آخر نشاط</th></tr></thead>
                <tbody>
                @forelse(data_get($operations, 'latest_errors', collect()) as $error)
                    <tr>
                        <td>{{ $error['course_title'] }}</td>
                        <td>{{ $error['lesson_title'] }}</td>
                        <td><span class="badge badge-danger">{{ $errorLabels[$error['last_error_code']] ?? $error['last_error_code'] }}</span></td>
                        <td>{{ $error['effective_quality'] ?: 'unknown' }}</td>
                        <td>{{ number_format((int) $error['recovery_count']) }}</td>
                        <td>{{ $error['last_heartbeat_at'] ?: $error['started_at'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">لا توجد أخطاء مسجلة.</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </div>

        <div class="card admin-card">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center admin-gap">
                <h2 class="h5 mb-0">أحدث الجلسات</h2>
                <small class="text-muted">يمكن إنهاء الجلسة بعد {{ data_get($operations, 'stale_after_minutes', 10) }} دقائق بلا نشاط.</small>
            </div>
            <div class="table-responsive"><table class="table admin-table mb-0 text-right">
                <thead class="thead-light"><tr><th>مرجع مجهّل</th><th>الكورس / الخطوة</th><th>الحالة</th><th>التقدم</th><th>الجودة</th><th>التعافي</th><th>آخر نشاط</th><th></th></tr></thead>
                <tbody>
                @forelse(data_get($operations, 'recent_sessions', collect()) as $session)
                    @php
                        $ended = !empty($session['ended_at']);
                        $stale = (bool) ($session['is_stale'] ?? false);
                        $stateLabel = $ended ? (($session['end_reason'] ?? '') === 'completed' ? 'اكتملت' : 'انتهت') : ($stale ? 'عالقة' : 'نشطة');
                        $stateTone = $ended ? 'muted' : ($stale ? 'warning' : 'success');
                    @endphp
                    <tr>
                        <td><code class="admin-code">{{ $session['session_reference'] }}</code></td>
                        <td><strong>{{ $session['lesson_title'] }}</strong><br><small class="text-muted">{{ $session['course_title'] }}</small></td>
                        <td>
                            @include('admin.partials.status-badge', ['badgeStatus' => $ended ? 'ended' : 'active', 'badgeLabel' => $stateLabel, 'badgeTone' => $stateTone])
                            @if(!empty($session['last_error_code']))<br><small class="text-danger">{{ $errorLabels[$session['last_error_code']] ?? $session['last_error_code'] }}</small>@endif
                        </td>
                        <td>{{ $formatDuration($session['last_position_seconds']) }} / {{ $formatDuration($session['duration_seconds']) }}</td>
                        <td>{{ $session['effective_quality'] ?: 'unknown' }}</td>
                        <td>{{ number_format((int) $session['recovery_count']) }}</td>
                        <td>{{ $session['last_heartbeat_at'] ?: $session['started_at'] }}</td>
                        <td>
                            @if($stale && !$ended)
                                <form method="POST" action="{{ route('admin.playback-operations.terminate-stale', $session['session_key']) }}" onsubmit="return confirm('إنهاء هذه الجلسة العالقة؟')">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-danger">إنهاء</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-4">لا توجد جلسات تشغيل بعد.</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </div>
    @endif
</div>
@endsection
