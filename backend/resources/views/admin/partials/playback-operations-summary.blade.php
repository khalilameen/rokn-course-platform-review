@php
    $playbackSummary = data_get($playbackOperations, 'summary', []);
    $rollupOverall = data_get($playbackOperations, 'rollup.overall', []);
    $reconcileState = data_get($mediaReconcileStatus, 'state');
    $reconcileBadge = in_array($reconcileState, ['completed'], true)
        ? 'badge-success'
        : (in_array($reconcileState, ['failed'], true) ? 'badge-danger' : 'badge-warning');
    $reconcileLabels = [
        'running' => 'جارٍ الفحص',
        'completed' => 'اكتمل',
        'attention' => 'تحتاج متابعة',
        'failed' => 'تعذر الفحص',
    ];
@endphp

<div class="card admin-card mb-4">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center admin-gap">
        <div>
            <h2 class="h5 mb-1">غرفة تشغيل الفيديو</h2>
            <small class="text-muted">مؤشرات مجمعة بلا أسماء طلاب أو روابط تشغيل أو بيانات أجهزة.</small>
        </div>
        <div class="admin-actions">
            <a class="btn btn-sm btn-primary" href="{{ route('admin.playback-operations.index') }}">تفاصيل التشغيل</a>
            <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.user-sessions.index') }}">إدارة الأجهزة والجلسات</a>
        </div>
    </div>
    <div class="card-body">
        @if(!data_get($playbackOperations, 'available'))
            <div class="alert alert-warning mb-0">بيانات التشغيل ستظهر بعد تطبيق migrations الخاصة بلوحة الفيديو.</div>
        @else
            <div class="row">
                @foreach([
                    ['نشطة الآن', (int) data_get($playbackSummary, 'active', 0), 'success'],
                    ['عالقة', (int) data_get($playbackSummary, 'stale', 0), 'warning'],
                    ['اكتملت', (int) data_get($playbackSummary, 'completed', 0), 'primary'],
                    ['بها خطأ', (int) data_get($playbackSummary, 'errors', 0), 'danger'],
                    ['احتاجت تعافيًا', (int) data_get($playbackSummary, 'recovery_sessions', 0), 'info'],
                ] as [$label, $value, $tone])
                    <div class="col-xl col-md-4 col-6 mb-3">
                        <div class="admin-metric">
                            <span class="badge badge-{{ $tone }} mb-2">{{ $label }}</span>
                            <strong class="h4 d-block mb-0">{{ number_format($value) }}</strong>
                        </div>
                    </div>
                @endforeach
            </div>

            @if(data_get($playbackOperations, 'rollup'))
                <div class="row mt-1 mb-3">
                    @foreach([
                        ['اكتمال الجلسات', data_get($rollupOverall, 'completion_rate', 0) . '%'],
                        ['معدل الأخطاء', data_get($rollupOverall, 'error_rate', 0) . '%'],
                        ['جلسات بها تحميل مؤقت', data_get($rollupOverall, 'buffering_rate', 0) . '%'],
                        ['متوسط بدء الفيديو', data_get($rollupOverall, 'average_startup_latency_ms') === null ? '—' : number_format((int) data_get($rollupOverall, 'average_startup_latency_ms')) . ' ms'],
                    ] as [$label, $value])
                        <div class="col-lg-3 col-6 mb-2">
                            <small class="text-muted d-block">{{ $label }}</small>
                            <strong>{{ $value }}</strong>
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="row mt-2">
                <div class="col-lg-7 mb-3">
                    <h3 class="h6">أكثر المقاطع فشلًا — آخر {{ data_get($playbackOperations, 'period_days', 7) }} أيام</h3>
                    @forelse(data_get($playbackOperations, 'top_failing_lessons', collect())->take(5) as $failure)
                        <div class="admin-panel-row">
                            <div><strong>{{ $failure['lesson_title'] }}</strong><br><small class="text-muted">{{ $failure['course_title'] }}</small></div>
                            <span class="badge badge-danger align-self-center">{{ number_format((int) $failure['failures']) }}</span>
                        </div>
                    @empty
                        <p class="text-muted mb-0">لا توجد أخطاء تشغيل مسجلة في الفترة الحالية.</p>
                    @endforelse
                </div>
                <div class="col-lg-5 mb-3">
                    <h3 class="h6">توزيع الجودة الفعلية</h3>
                    <div class="admin-gap">
                        @forelse(data_get($playbackOperations, 'quality_mix', collect()) as $quality)
                            <span class="badge badge-light border p-2">{{ $quality['quality'] }} · {{ number_format((int) $quality['sessions']) }}</span>
                        @empty
                            <span class="text-muted">لم تصل عينات جودة بعد.</span>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif

        <hr>
        <div class="row">
            <div class="col-lg-6 mb-3">
                <h3 class="h6">فحص سلامة الوسائط</h3>
                @if($mediaReconcileStatus)
                    <p class="mb-1">
                        <span class="badge {{ $reconcileBadge }} ml-2">{{ $reconcileLabels[$reconcileState] ?? $reconcileState }}</span>
                        {{ number_format((int) data_get($mediaReconcileStatus, 'processed_courses', 0)) }} من
                        {{ number_format((int) data_get($mediaReconcileStatus, 'total_courses', 0)) }} كورس
                    </p>
                    <small class="text-muted">آخر انتهاء: {{ data_get($mediaReconcileStatus, 'finished_at', 'لم ينتهِ بعد') }}</small>
                    <div class="admin-gap mt-2">
                        <span class="badge badge-success">سليم {{ number_format((int) data_get($mediaReconcileStatus, 'summary.healthy', 0)) }}</span>
                        <span class="badge badge-warning">متابعة {{ number_format((int) data_get($mediaReconcileStatus, 'summary.attention', 0)) }}</span>
                        <span class="badge badge-danger">معزول للمراجعة {{ number_format((int) data_get($mediaReconcileStatus, 'summary.quarantined', 0)) }}</span>
                    </div>
                @else
                    <p class="text-muted mb-0">لم يعمل الفحص الدوري للوسائط بعد.</p>
                @endif
            </div>
            <div class="col-lg-6 mb-3">
                <h3 class="h6">جاهزية النسخ والاستعادة</h3>
                <p class="mb-1">
                    <span class="badge {{ data_get($backupReadiness, 'ready') ? 'badge-success' : 'badge-warning' }} ml-2">
                        {{ data_get($backupReadiness, 'ready') ? 'موثقة' : 'تحتاج استكمالًا' }}
                    </span>
                    {{ data_get($backupReadiness, 'provider', 'لم يُحدد مزود النسخ') }}
                </p>
                @php
                    $backupCheckLabels = [
                        'runbook' => 'دليل التشغيل',
                        'provider' => 'مزود النسخ',
                        'recent_backup' => 'نسخة حديثة',
                        'recent_restore_drill' => 'تجربة استعادة',
                    ];
                @endphp
                <div class="admin-gap mb-1">
                    @foreach(data_get($backupReadiness, 'checks', []) as $check => $passed)
                        <span class="badge {{ $passed ? 'badge-success' : 'badge-light border' }}">{{ $backupCheckLabels[$check] ?? $check }}</span>
                    @endforeach
                </div>
                <small class="text-muted">اللوحة تعرض دليل الجاهزية فقط ولا تنفذ Restore.</small>
            </div>
        </div>
    </div>
</div>
