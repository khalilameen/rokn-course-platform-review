@extends('admin.layouts.app')

@section('page.title', 'مركز تشغيل المنتج')

@section('content')
<div class="admin-page">
    <div class="card admin-hero mb-4">
        <div class="card-body">
            <h1 class="h3 mb-2">مركز تشغيل ركن</h1>
            <p class="admin-hero__description">صورة واحدة لما يراه الطالب وما يحتاج متابعة من الفريق</p>
        </div>
    </div>

    <div class="card admin-card mb-4"><div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 admin-gap">
            <div><h2 class="h5 mb-1">جاهزية الوسائط</h2><small class="text-muted">حالة تجهيز الفيديو قبل النشر، لا مجرد وجود رابط.</small></div>
            <div>
                <span class="badge badge-success p-2 ml-2">جاهز {{ number_format($counts['media_ready']) }}</span>
                <span class="badge badge-warning p-2 ml-2">يحتاج متابعة {{ number_format($counts['media_attention']) }}</span>
                <span class="badge badge-primary p-2">جلسات اليوم {{ number_format($counts['playback_sessions_today']) }}</span>
            </div>
        </div>
        @if($mediaAttention->isEmpty())
            <div class="alert alert-success mb-0">كل الفيديوهات المسجلة جاهزة.</div>
        @else
            <div class="table-responsive"><table class="table table-sm admin-table mb-0">
                <thead><tr><th>الكورس</th><th>الخطوة</th><th>الحالة</th><th>آخر فحص</th><th></th></tr></thead>
                <tbody>@foreach($mediaAttention as $lesson)
                    <tr>
                        <td>{{ $lesson->course?->name_ar ?: $lesson->course?->name_en }}</td>
                        <td>{{ $lesson->title }}</td>
                        @php
                            $runtimeStatus = $lesson->mediaState?->status ?: 'unknown';
                            $integrityStatus = $lesson->mediaState?->integrity_status;
                            $displayStatus = in_array($integrityStatus, ['attention', 'quarantined'], true)
                                ? $integrityStatus
                                : $runtimeStatus;
                        @endphp
                        <td>@include('admin.partials.status-badge', ['badgeStatus' => $displayStatus, 'badgeTone' => in_array($displayStatus, ['failed', 'quarantined'], true) ? 'danger' : 'warning'])</td>
                        <td>{{ optional($lesson->mediaState?->last_probe_at)->diffForHumans() ?: '—' }}</td>
                        <td><form method="POST" action="{{ route('admin.media-health.probe', $lesson) }}">@csrf<button class="btn btn-sm btn-outline-primary">فحص الآن</button></form></td>
                    </tr>
                @endforeach</tbody>
            </table></div>
        @endif
    </div></div>

    @include('admin.partials.playback-operations-summary')

    <div class="card admin-card mb-4">
        <div class="card-header">
            <h2 class="h5 mb-1">بوابات تشغيل المنتج</h2>
            <small class="text-muted">إيقاف آمن أو طرح تدريجي دون إصدار نسخة تطبيق جديدة. كل تغيير يتطلب سببًا ويُسجّل باسم المسؤول.</small>
        </div>
        <div class="table-responsive">
            <table class="table admin-table mb-0 text-right">
                <thead class="thead-light"><tr><th>الميزة</th><th>الحالة الحالية</th><th>آخر قرار</th><th class="admin-table__wide-action">تغيير مضبوط</th></tr></thead>
                <tbody>
                @foreach($featureFlags as $key => $feature)
                    @php
                        $labels = [
                            'checkout' => 'شحن العملات والدفع',
                            'playback' => 'تشغيل الفيديو المحمي',
                            'project_uploads' => 'رفع المشاريع',
                            'ai_chat' => 'Rokn AI',
                        ];
                    @endphp
                    <tr>
                        <td>
                            <strong>{{ $labels[$key] ?? $key }}</strong><br>
                            <small class="text-muted">{{ $feature['description'] }}</small>
                        </td>
                        <td>
                            <span class="badge {{ $feature['enabled'] ? 'badge-success' : 'badge-danger' }}">
                                {{ $feature['enabled'] ? 'مفعّلة' : 'متوقفة' }}
                            </span>
                            <div class="small mt-1">{{ $feature['rollout_percentage'] }}٪ من المستخدمين</div>
                            @if($feature['expires_at'])
                                <small class="text-muted">تنتهي: {{ $feature['expires_at'] }}</small>
                            @endif
                        </td>
                        <td>
                            <small class="d-block"><strong>{{ $feature['owner'] ?: 'إعداد النشر الافتراضي' }}</strong></small>
                            <small class="text-muted">{{ $feature['reason'] ?: 'لا يوجد تجاوز تشغيلي محفوظ' }}</small>
                        </td>
                        <td>
                            <form method="POST" action="{{ route('admin.product-operations.features.update', $key) }}" onsubmit="return confirm('تطبيق هذا التغيير على المستخدمين؟')">
                                @csrf
                                <div class="form-row align-items-end">
                                    <div class="col-md-3 mb-2">
                                        <label class="small">الحالة</label>
                                        <select class="form-control form-control-sm" name="enabled" required>
                                            <option value="1" {{ $feature['enabled'] ? 'selected' : '' }}>تشغيل</option>
                                            <option value="0" {{ !$feature['enabled'] ? 'selected' : '' }}>إيقاف</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3 mb-2">
                                        <label class="small">نسبة الطرح</label>
                                        <input class="form-control form-control-sm" name="rollout_percentage" type="number" min="0" max="100" value="{{ $feature['rollout_percentage'] }}" required>
                                    </div>
                                    <div class="col-md-6 mb-2">
                                        <label class="small">ينتهي تلقائيًا (اختياري)</label>
                                        <input class="form-control form-control-sm" name="expires_at" type="datetime-local">
                                    </div>
                                    <div class="col-md-9 mb-2">
                                        <label class="small">سبب تشغيلي واضح</label>
                                        <input class="form-control form-control-sm" name="reason" minlength="8" maxlength="255" placeholder="مثال: عطل مزود الدفع INC-204" required>
                                    </div>
                                    <div class="col-md-3 mb-2">
                                        <button class="btn btn-sm btn-primary btn-block" type="submit">تطبيق موثّق</button>
                                    </div>
                                </div>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="row mb-4">
        @foreach([
            ['الكورسات المنشورة', $counts['published'], 'fa-play-circle'],
            ['بطاقات قريبًا', $counts['coming_soon'], 'fa-clock-o'],
            ['الباقات', $counts['packages'], 'fa-cubes'],
            ['مهام ربح فعالة', $counts['reward_tasks'], 'fa-gift'],
            ['منح مؤسسية', $counts['grants'], 'fa-university'],
            ['طلاب فعّلوا منحة', $counts['grant_claims'], 'fa-graduation-cap'],
            ['ترقيات المسار الكامل', $counts['grant_upgrades'], 'fa-certificate'],
            ['مشاريع تنتظر المراجعة', $counts['pending_projects'], 'fa-tasks'],
            ['شهادات صادرة', $counts['certificates'], 'fa-certificate'],
            ['أعمال Portfolio', $counts['portfolio_items'], 'fa-briefcase'],
        ] as [$label, $value, $icon])
            <div class="col-xl-3 col-md-4 col-sm-6 mb-3">
                @include('admin.partials.metric-card', [
                    'metricLabel' => $label,
                    'metricValue' => number_format($value),
                    'metricIcon' => $icon,
                ])
            </div>
        @endforeach
    </div>

    <div class="row mb-4">
        <div class="col-lg-7 mb-3"><div class="card admin-card h-100"><div class="card-body">
            <h2 class="h5 mb-3">جاهزية التشغيل</h2><div class="row">
            @foreach([
                'hero' => 'كورس رئيسي واحد', 'published_course' => 'كورس منشور للتجربة',
                'packages' => 'باقات قابلة للشراء', 'reward_tasks' => 'مهام ربح فعالة',
                'support' => 'دعم واتساب',
                'private_attachments' => 'المرفقات الداخلية خارج المسار العام',
            ] as $key => $label)
                <div class="col-md-6 mb-2"><span class="badge {{ $readiness[$key] ? 'badge-success' : 'badge-danger' }} ml-2">{{ $readiness[$key] ? 'جاهز' : 'ناقص' }}</span>{{ $label }}</div>
            @endforeach
            </div>
            @if(($counts['legacy_public_attachments'] ?? 0) > 0)
                <div class="alert alert-danger mt-3 mb-2">
                    توجد {{ number_format($counts['legacy_public_attachments']) }} مرفقات قديمة على التخزين العام.
                    شغّل <code>php artisan attachments:privatize --execute</code> بعد تجهيز التخزين المشترك، ثم أعد التشغيل مع <code>--delete-public</code> بعد التحقق.
                </div>
            @endif
            @if(($counts['external_attachment_links'] ?? 0) > 0)
                <div class="alert alert-warning mt-2 mb-2">
                    توجد {{ number_format($counts['external_attachment_links']) }} روابط مرفقات خارجية؛ ركن لا يستطيع منع مشاركتها أو إبطالها بعد فتحها.
                </div>
            @endif
            @php
                $infrastructure = [
                    ['Bunny Stream', data_get($capabilityReport, 'capabilities.bunny.stream')],
                    ['رفع الفيديو إلى Bunny', data_get($capabilityReport, 'capabilities.bunny.upload')],
                    ['تشغيل الفيديو من CDN', data_get($capabilityReport, 'capabilities.bunny.playback')],
                    ['توقيع روابط التشغيل', data_get($capabilityReport, 'capabilities.bunny.signing')],
                    ['صور وملفات Bunny', data_get($capabilityReport, 'capabilities.bunny.assets')],
                    ['الدفع عبر Kashier', data_get($capabilityReport, 'capabilities.payment')],
                    ['Rokn AI', data_get($capabilityReport, 'capabilities.ai')],
                    ['البريد التشغيلي', data_get($capabilityReport, 'capabilities.mail')],
                    ['إشعارات Firebase', data_get($capabilityReport, 'capabilities.push')],
                    ['تسجيل Google', data_get($capabilityReport, 'capabilities.social.google')],
                    ['تسجيل Facebook', data_get($capabilityReport, 'capabilities.social.facebook')],
                    ['تسجيل TikTok', data_get($capabilityReport, 'capabilities.social.tiktok')],
                    ['تسجيل Apple', data_get($capabilityReport, 'capabilities.social.apple')],
                    ['روابط عودة تسجيل الدخول', data_get($capabilityReport, 'capabilities.social.callbacks')],
                    ['فتح التطبيق على Android', data_get($capabilityReport, 'capabilities.app_links.android')],
                    ['فتح التطبيق على Apple', data_get($capabilityReport, 'capabilities.app_links.apple')],
                    ['عامل المهام Queue', data_get($capabilityReport, 'capabilities.queue')],
                ];
            @endphp
            <hr>
            <h3 class="h6 mb-3">جاهزية البنية — كل قدرة مستقلة</h3>
            @foreach($infrastructure as [$label, $capability])
                <div class="d-flex align-items-start mb-3 admin-gap">
                    <span class="badge {{ data_get($capability, 'ready') ? 'badge-success' : 'badge-danger' }} mt-1">
                        {{ data_get($capability, 'ready') ? 'الإعداد مكتمل' : 'ناقص' }}
                    </span>
                    <div><strong class="d-block">{{ $label }}</strong><small class="text-muted">{{ data_get($capability, 'reason', 'لم يتم الفحص') }}</small></div>
                </div>
            @endforeach
            <hr><small class="text-muted">هذه فحوص إعداد محلية وليست بديلًا عن smoke test حقيقي لمزوّد الفيديو والدفع والذكاء الاصطناعي. نبضة Queue وحدها تثبت أن عاملًا نفّذ مهمة فعلًا.</small>
        </div></div></div>
        <div class="col-lg-5 mb-3"><div class="card admin-card h-100"><div class="card-body">
            <h2 class="h5 mb-3">فصل الإيراد عن المكافآت</h2>
            <p class="mb-2">دخل Kashier المستقر <strong class="float-left">{{ number_format($finance['cash_revenue'], 2) }} جنيه</strong></p>
            <p class="mb-2">عملات مشتراة استُهلكت <strong class="float-left">{{ number_format($finance['course_paid_coins']) }}</strong></p>
            <p class="mb-2">عملات مكافآت استُهلكت <strong class="float-left">{{ number_format($finance['course_reward_coins']) }}</strong></p>
            <p class="mb-2">ترقيات المنح — مدفوعة / مكافآت <strong class="float-left">{{ number_format($finance['grant_upgrade_paid_coins']) }} / {{ number_format($finance['grant_upgrade_reward_coins']) }}</strong></p>
            <p class="mb-0">استرداد أو مراجعة <strong class="float-left">{{ number_format($finance['refunds']) }}</strong></p>
        </div></div></div>
    </div>

    <div class="card admin-card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h2 class="h5 mb-0">الكورسات كما يديرها المنتج</h2><a href="{{ route('admin.courses.index') }}" class="btn btn-sm btn-primary">إدارة الكورسات</a>
        </div>
        <div class="table-responsive"><table class="table admin-table mb-0 text-right">
            <thead class="thead-light"><tr><th>الكورس</th><th>الحالة</th><th>الخريطة</th><th>الطلاب</th><th>التقييم</th><th>مدفوعة / مكافآت</th><th>Rokn AI</th></tr></thead>
            <tbody>@forelse($courses as $course)<tr>
                <td><a href="{{ route('admin.courses.edit', $course) }}"><strong>{{ $course->name_ar }}</strong></a>@if($course->is_main_course)<br><small class="text-primary">الكورس الرئيسي</small>@endif</td>
                <td>{{ $course->is_coming_soon ? ($course->is_catalog_visible ? 'قريبًا ظاهر' : 'مسودة مخفية') : 'منشور' }}<br><small>{{ (int)$course->price === 0 ? 'مجاني' : number_format($course->price).' عملة' }}</small></td>
                <td>{{ number_format($course->modules_count) }} موديول<br><small>{{ number_format($course->sections_count) }} عنصرًا</small></td>
                <td>{{ number_format($course->active_enrollments_count) }} فعلي<br><small>{{ number_format((int)$course->students_count) }} رصيد سابق</small></td>
                <td>{{ $course->ratings_count ? number_format((float)$course->ratings_avg_rating, 1).' / ٥' : 'لا يوجد' }}<br><small>{{ number_format($course->ratings_count) }} تقييم</small></td>
                <td>{{ number_format((int)$course->paid_coins_spent) }} / {{ number_format((int)$course->reward_coins_spent) }}</td>
                <td>{{ $course->ai_chat_enabled ? 'للمدفوع فقط' : 'متوقف' }}</td>
            </tr>@empty<tr><td colspan="7" class="text-center text-muted py-5">لا توجد كورسات بعد</td></tr>@endforelse</tbody>
        </table></div>
    </div>

    <div class="card admin-card"><div class="card-body">
        <h2 class="h5 mb-3">اختصارات التشغيل</h2><div class="admin-actions">
            <a class="btn btn-outline-primary" href="{{ route('admin.orders.index') }}">الطلبات والاسترداد</a>
            <a class="btn btn-outline-primary" href="{{ route('admin.packages.index') }}">باقات العملات</a>
            <a class="btn btn-outline-primary" href="{{ route('admin.coin-earning-methods.index') }}">مهام الربح وروابطها</a>
            <a class="btn btn-outline-primary" href="{{ route('admin.course-codes.index') }}">الأكواد والمنح</a>
            <a class="btn btn-outline-primary" href="{{ route('admin.project-submissions.index') }}">المشاريع</a>
            <a class="btn btn-outline-primary" href="{{ route('admin.levels.index') }}">الشارات</a>
            <a class="btn btn-outline-primary" href="{{ route('admin.notifications.index') }}">الإشعارات</a>
            <a class="btn btn-outline-primary" href="{{ route('admin.settings') }}">الدعم والقواعد وRokn AI</a>
        </div>
    </div></div>

    <div class="card admin-card mt-3"><div class="card-body">
        <h2 class="h5 mb-3">جلسات التشغيل والحساب</h2>
        <div class="admin-actions">
            <a class="btn btn-outline-primary" href="{{ route('admin.playback-operations.index') }}">مراقبة تشغيل الفيديو</a>
            <a class="btn btn-outline-primary" href="{{ route('admin.user-sessions.index') }}">أجهزة وجلسات المستخدمين</a>
        </div>
    </div></div>
</div>
@endsection
