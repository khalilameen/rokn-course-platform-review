<div id="commercial-report" class="tab-content">
    <div class="text-left mb-3">
        <a class="btn btn-outline-primary" href="{{ route('admin.courses.commercial-report.export', $course) }}">
            <i class="fa fa-download ml-1"></i> تصدير كشف الطلاب والتكلفة CSV
        </a>
    </div>
    <div class="alert alert-info mb-3">
        المبلغ النقدي منسوب للكورس بنسبة العملات المدفوعة التي خرجت من كل باقة شحن فعلية.
        العملات الترحيبية والمكتسبة تظهر منفصلة ولا تُحسب دخلًا نقديًا.
    </div>

    <div class="statistics-grid">
        <div class="stat-card">
            <span class="stat-counter">{{ number_format($commercialReport['active_students']) }}</span>
            <span class="stat-label">طلاب نشطون</span>
        </div>
        <div class="stat-card">
            <span class="stat-counter">{{ number_format($commercialReport['grant_students']) }}</span>
            <span class="stat-label">استفادوا من منحة</span>
        </div>
        <div class="stat-card">
            <span class="stat-counter">{{ number_format($commercialReport['code_students']) }}</span>
            <span class="stat-label">استفادوا من كود إتاحة</span>
        </div>
        <div class="stat-card">
            <span class="stat-counter">{{ number_format($commercialReport['paid_students']) }}</span>
            <span class="stat-label">شراء مباشر بالعملات</span>
        </div>
        <div class="stat-card">
            <span class="stat-counter">{{ number_format($commercialReport['paid_coins']) }}</span>
            <span class="stat-label">عملات مشتراة صُرفت</span>
        </div>
        <div class="stat-card">
            <span class="stat-counter">{{ number_format($commercialReport['reward_coins']) }}</span>
            <span class="stat-label">عملات مكافآت صُرفت</span>
        </div>
        <div class="stat-card">
            <span class="stat-counter">{{ number_format($commercialReport['cash_gross_egp'], 2) }} ج.م</span>
            <span class="stat-label">إجمالي نقدي منسوب للكورس</span>
        </div>
        <div class="stat-card">
            <span class="stat-counter">
                @if($commercialReport['cash_net_complete'])
                    {{ number_format($commercialReport['cash_net_egp'], 2) }} ج.م
                @else
                    بانتظار التسوية
                @endif
            </span>
            <span class="stat-label">صافي بوابة الدفع الفعلي</span>
        </div>
        <div class="stat-card">
            <span class="stat-counter">${{ number_format($commercialReport['ai_cost_usd'], 6) }}</span>
            <span class="stat-label">OpenRouter فعلي</span>
        </div>
        <div class="stat-card">
            <span class="stat-counter">
                {{ $commercialReport['service_cost_complete'] ? number_format($commercialReport['service_cost_actual_egp'], 2).' ج.م' : 'بيانات ناقصة' }}
            </span>
            <span class="stat-label">الخدمات والتشغيل الفعلي</span>
        </div>
        <div class="stat-card">
            <span class="stat-counter">
                {{ $commercialReport['contribution_margin_egp'] === null ? 'بانتظار الاكتمال' : number_format($commercialReport['contribution_margin_egp'], 2).' ج.م' }}
            </span>
            <span class="stat-label">هامش المساهمة</span>
        </div>
        <div class="stat-card">
            <span class="stat-counter">
                {{ $commercialReport['service_cost_with_estimates_egp'] === null ? 'بيانات ناقصة' : number_format($commercialReport['service_cost_with_estimates_egp'], 2).' ج.م' }}
            </span>
            <span class="stat-label">التكلفة شاملة التقديرات</span>
        </div>
        <div class="stat-card">
            <span class="stat-counter">
                {{ $commercialReport['estimated_contribution_margin_egp'] === null ? 'بيانات ناقصة' : number_format($commercialReport['estimated_contribution_margin_egp'], 2).' ج.م' }}
            </span>
            <span class="stat-label">الهامش بعد التقديرات</span>
        </div>
        <div class="stat-card">
            <span class="stat-counter">{{ number_format($commercialReport['playback_minutes'], 0) }} دقيقة</span>
            <span class="stat-label">مشاهدة · {{ number_format($commercialReport['playback_gb_estimated'], 3) }} GB مقدرة</span>
        </div>
    </div>

    @if(!$commercialReport['cash_net_complete'])
        <div class="alert alert-warning mt-3">
            كاشير لم تُرسل رسوم/صافي التسوية لكل عمليات الشحن القديمة بعد؛ المعروض كإجمالي صحيح،
            ولن يحوّله النظام إلى «صافي» بالتخمين.
        </div>
    @endif

    @if(!$commercialReport['service_cost_complete'])
        <div class="alert alert-warning mt-3">
            تكلفة الخدمات غير مكتملة، لذلك تم حجب هامش الربح بدل عرض رقم مضلل.
            <a href="{{ route('admin.operating-costs.index') }}">أكمل سعر تحويل OpenRouter وفواتير التشغيل</a>.
            @foreach($commercialReport['cost_warnings'] as $warning)<div>• {{ $warning }}</div>@endforeach
        </div>
    @endif

    <div class="info-section mt-4">
        <h3 class="section-title"><i class="fa fa-tags ml-2"></i> توزيع الباقات</h3>
        <div class="table-responsive">
            <table class="table table-striped">
                <thead><tr><th>الباقة</th><th>الطلاب</th><th>العملات</th><th>النقد المنسوب</th><th>OpenRouter</th><th>تكلفة فعلية</th><th>التكلفة مع التقديرات</th><th>الهامش الفعلي</th><th>الهامش التقديري</th></tr></thead>
                <tbody>
                @forelse($commercialReport['plan_breakdown'] as $planName => $plan)
                    <tr>
                        <td>{{ $planName }}</td>
                        <td>{{ number_format($plan['students']) }}</td>
                        <td>{{ number_format($plan['coins']) }}</td>
                        <td>{{ number_format($plan['gross_egp'], 2) }} ج.م</td>
                        <td>${{ number_format($plan['ai_cost_usd'], 6) }}</td>
                        <td>{{ $plan['service_cost_egp'] === null ? 'غير مكتمل' : number_format($plan['service_cost_egp'], 2).' ج.م' }}</td>
                        <td>{{ $plan['estimated_cost_egp'] === null ? 'غير مكتمل' : number_format($plan['estimated_cost_egp'], 2).' ج.م' }}</td>
                        <td>{{ $plan['margin_egp'] === null ? 'غير مكتمل' : number_format($plan['margin_egp'], 2).' ج.م' }}</td>
                        <td>{{ $plan['estimated_margin_egp'] === null ? 'غير مكتمل' : number_format($plan['estimated_margin_egp'], 2).' ج.م' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted">لا توجد اشتراكات بعد.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="info-section mt-4">
        <h3 class="section-title"><i class="fa fa-users ml-2"></i> كشف الطلاب</h3>
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                <tr>
                    <th>الطالب</th><th>الحالة</th><th>المصدر</th><th>الفئة الحالية</th>
                    <th>سعر العقد</th><th>المدفوع فعليًا</th><th>التوزيع</th><th>نقد كاشير</th><th>الصافي</th><th>الاستهلاك</th><th>تكلفة الخدمات</th><th>الهامش</th><th>شامل التقديرات</th>
                </tr>
                </thead>
                <tbody>
                @forelse($commercialReport['rows'] as $row)
                    <tr>
                        <td>
                            <strong>{{ $row['user']?->name ?? 'مستخدم محذوف' }}</strong><br>
                            <small class="text-muted">{{ $row['user']?->email }}</small>
                        </td>
                        <td>{{ $row['is_active'] ? 'نشط' : 'غير نشط' }}</td>
                        <td>{{ $row['source_label'] }}</td>
                        <td>{{ $row['plan_name'] }}</td>
                        <td>{{ $row['contract_price_coins'] === null ? 'قديم' : number_format($row['contract_price_coins']).' عملة' }}</td>
                        <td>{{ number_format($row['total_coins']) }} عملة</td>
                        <td>
                            {{ number_format($row['paid_coins']) }} مشتراة<br>
                            {{ number_format($row['reward_coins']) }} مكافآت
                        </td>
                        <td>{{ number_format($row['cash_gross_egp'], 2) }} ج.م</td>
                        <td>
                            @if($row['cash_net_complete'])
                                {{ number_format($row['cash_net_known_egp'], 2) }} ج.م
                            @else
                                <span class="text-warning">بانتظار التسوية</span>
                            @endif
                        </td>
                        <td>
                            {{ number_format($row['ai_requests']) }} طلب AI · {{ number_format($row['ai_tokens']) }} توكن<br>
                            ${{ number_format($row['ai_cost_usd'], 6) }} · {{ number_format($row['playback_minutes'], 0) }} دقيقة
                        </td>
                        <td>
                            {{ $row['service_cost_actual_egp'] === null ? 'غير مكتملة' : number_format($row['service_cost_actual_egp'], 2).' ج.م' }}
                            @if($row['ai_failed_requests'])<br><small class="text-warning">{{ number_format($row['ai_failed_requests']) }} طلب AI فاشل</small>@endif
                        </td>
                        <td>{{ $row['contribution_margin_egp'] === null ? '—' : number_format($row['contribution_margin_egp'], 2).' ج.م' }}</td>
                        <td>
                            {{ $row['service_cost_with_estimates_egp'] === null ? '—' : number_format($row['service_cost_with_estimates_egp'], 2).' ج.م تكلفة' }}
                            @if($row['estimated_contribution_margin_egp'] !== null)<br><small>{{ number_format($row['estimated_contribution_margin_egp'], 2) }} ج.م هامش</small>@endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="13" class="text-center text-muted">لا يوجد طلاب في الكورس بعد.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
