@extends('admin.layouts.app')
@section('page.title', ' الطلاب | '.$user->name )

@section('styles')
{{-- Include Dynamic Theme Styles --}}
@include('admin.users.partials._dynamic_styles')
<link rel="stylesheet" href="{{ asset('admin/assets/css/users-show.css') }}">
@endsection

@section('content')

<div class="user-detail-container animated fadeIn admin-page users-page">
    <div class="row">
        <!-- User Profile Card -->
        <div class="col-lg-4 col-md-12">
            <div class="profile-card-modern">
                <div class="profile-header">
                    <div class="profile-status">
                        <span class="status-badge-large {{ $user->active ? 'active' : 'inactive' }}">
                            <i class="fa {{ $user->active ? 'fa-check-circle' : 'fa-times-circle' }}"></i>
                            {{ $user->active ? 'مفعل' : 'غير مفعل' }}
                        </span>
                    </div>
                    <img class="profile-avatar" src="{{ $user->image ? $user->image : '/images/admin.jpg' }}" alt="{{ $user->name }}">
                    <h2 class="profile-name">{{ $user->name }}</h2>
                    <div class="profile-email">
                        <i class="fa fa-envelope"></i> {{ $user->email }}
                    </div>
                    <div class="profile-phone">
                        <i class="fa fa-phone"></i> {{ $user->phone }}
                    </div>
                    @if($user->socialAccounts->isNotEmpty())
                        @php($providerLabels = ['google' => 'Google', 'facebook' => 'Facebook', 'tiktok' => 'TikTok', 'apple' => 'Apple'])
                        <div class="profile-phone">
                            <i class="fa fa-sign-in"></i>
                            {{ $user->socialAccounts->map(fn ($account) => $providerLabels[$account->provider] ?? ucfirst($account->provider))->implode(' · ') }}
                        </div>
                    @endif
                </div>

                <div class="profile-body">
                    

                    @if($user->interests?->count() > 0)
                    <div class="study-info study-info--interests">
                        <h6 class="study-info__heading">
                            <i class="fa fa-tags"></i> الاهتمامات
                        </h6>
                        @foreach($user->interests as $interest)
                            <span class="study-badge-large study-badge-large--interest">
                                {{ $interest->name_ar }}
                            </span>
                        @endforeach
                    </div>
                    @endif

                    <div class="profile-actions">
                        <a href="{{ route('admin.users.edit', $user->id) }}" class="btn-action-modern btn-edit">
                            <i class="fa fa-pencil-square"></i> تعديل البيانات
                        </a>
                        <form action="{{ route('admin.users.deactive', $user->id) }}" method="POST" class="admin-inline-form">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="expected_active" value="{{ $user->active ? 1 : 0 }}">
                            <button type="submit" class="btn-action-modern btn-toggle {{ !$user->active ? 'activate' : '' }}">
                                <i class="fa {{ $user->active ? 'fa-ban' : 'fa-check-circle' }}"></i>
                                {{ $user->active ? 'تعطيل الحساب' : 'تفعيل الحساب' }}
                            </button>
                        </form>
                        @if($user->locked_device_id && $deviceLoginPolicy === \App\Services\DeviceLoginService::POLICY_SINGLE_PERMANENT)
                            <form action="{{ route('admin.users.reset-device', $user->id) }}" method="POST" class="admin-inline-form" onsubmit="return confirm('هل أنت متأكد من إعادة تعيين جهاز الطالب؟ سيتمكن الطالب من تسجيل الدخول من جهاز آخر.')">
                                @csrf
                                <button type="submit" class="btn-action-modern btn-reset-device">
                                    <i class="fa fa-refresh"></i> إعادة تعيين الجهاز
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-8 col-md-12">
            <!-- Send Notification Section -->
            <div class="section-card-modern">
                <div class="section-header-modern">
                    <h3 class="section-title">
                        <i class="fa fa-bell"></i>
                        إرسال إشعار
                    </h3>
                    <a href="{{ route('admin.notifications.create') }}" class="btn-action-modern btn-edit">
                        <i class="fa fa-users"></i> إشعار جماعي
                    </a>
                </div>
                <div class="section-body">
                    <div class="alert {{ $user->notifications_status && $user->device_tokens_count > 0 ? 'alert-success' : 'alert-warning' }}">
                        @if($user->notifications_status && $user->device_tokens_count > 0)
                            إشعارات الهاتف مفعلة على {{ $user->device_tokens_count }} جهاز. سيظهر الإشعار أيضًا داخل التطبيق.
                        @else
                            سيظهر الإشعار داخل التطبيق، لكن الهاتف غير مسجل لاستقبال Push حاليًا.
                        @endif
                    </div>
                    {!! Form::open(['method' => 'POST', 'route' => ['admin.users.send_notification', $user->id], 'files' => true]) !!}
                        <input type="hidden" name="authoring_request_id" value="{{ old('authoring_request_id', (string) \Illuminate\Support\Str::uuid()) }}">
                        <div class="notification-form">
                            <input name="title" maxlength="80" placeholder="عنوان قصير" class="notification-input notification-input--title" type="text" required>
                            <input name="message" maxlength="240" id="notifications-input" placeholder="اكتب المطلوب مباشرة" class="notification-input" type="text" required>
                            <input accept="image/jpeg,image/png,image/webp" aria-label="صورة الإشعار" name="image" type="file">
                            <button type="submit" class="btn-send-notification">
                                <i class="fa fa-paper-plane"></i> إرسال
                            </button>
                        </div>
                    {!! Form::close() !!}
                </div>
            </div>

            <!-- Notes Section -->
            <div class="section-card-modern">
                <div class="section-header-modern">
                    <h3 class="section-title">
                        <i class="fa fa-sticky-note"></i>
                        الملاحظات
                    </h3>
                    <button class="btn-action-modern btn-edit" data-toggle="modal" data-target="#addNoteModal">
                        <i class="fa fa-plus-circle"></i> إضافة ملاحظة
                    </button>
                </div>
                <div class="section-body">
                    @if($notes->count() > 0)
                        @foreach($notes as $note)
                            <div class="note-item">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="note-content">
                                        <p class="note-text">{{ $note->note }}</p>
                                        <div class="note-meta">
                                            <span>
                                                <i class="fa fa-clock-o"></i> {{ \App\Support\BusinessClock::format($note->created_at) }}
                                            </span>
                                            @if($note->createdBy)
                                                <span>
                                                    <i class="fa fa-user"></i> {{ $note->createdBy->name }}
                                                </span>
                                            @endif
                                        </div>
                                    </div>
                                    <div>
                                        <form method="POST" action="{{ route('admin.users.notes.delete', $note->id) }}"
                                              class="admin-inline-form"
                                              onsubmit="return confirm('هل أنت متأكد من حذف هذه الملاحظة؟')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn-delete-note">
                                                <i class="fa fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        @endforeach

                        <!-- Pagination -->
                        <div class="d-flex justify-content-center mt-3">
                            {{ $notes->links() }}
                        </div>
                    @else
                        <div class="empty-state-modern">
                            <i class="fa fa-sticky-note"></i>
                            <h4>لا توجد ملاحظات</h4>
                            <p>لم يتم إضافة أي ملاحظات لهذا المستخدم بعد</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

 <!--
<div class="row">
        <div class="col-12">
            
            <div class="section-card-modern">
                <div class="section-header-modern">
                    <h3 class="section-title">
                        <i class="fa fa-graduation-cap"></i>
                        نتائج الامتحانات
                    </h3>
                    <div class="stats-container">
                        <span class="stat-badge stat-badge-light">
                            <i class="fa fa-file-text"></i> {{ $examResults->total() }} امتحان
                        </span>
                        @if($examResults->count() > 0)
                            <span class="stat-badge stat-badge-success">
                                <i class="fa fa-check"></i> نجح: {{ $examStats['passed'] }}
                            </span>
                            <span class="stat-badge stat-badge-danger">
                                <i class="fa fa-times"></i> رسب: {{ $examStats['failed'] }}
                            </span>
                            <span class="stat-badge stat-badge-info">
                                <i class="fa fa-star"></i> متوسط: {{ $examStats['average_score'] }}%
                            </span>
                            <span class="stat-badge stat-badge-primary">
                                <i class="fa fa-trophy"></i> معدل النجاح: {{ $examStats['pass_rate'] }}%
                            </span>
                        @endif
                    </div>
                </div>
                <div class="section-body">
                    @if($examResults->count() > 0)
                        <div class="table-responsive">
                            <table class="detail-table">
                                <thead>
                                    <tr>
                                        <th>الامتحان</th>
                                        <th>الدورة</th>
                                        <th>الدرجة</th>
                                        <th>النتيجة</th>
                                        <th>الوقت</th>
                                        <th>التاريخ</th>
                                        <th>العمليات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($examResults as $result)
                                        <tr>
                                            <td><strong>#{{ $loop->iteration }}</strong></td>
                                            <td>
                                                @if($result->course)
                                                    @if($result->course->name_ar)
                                                        {{ $result->course->name_ar }}
                                                    @elseif($result->course->name_en)
                                                        {{ $result->course->name_en }}
                                                    @else
                                                        <span class="text-muted">-</span>
                                                    @endif
                                                @else
                                                    <span class="text-muted">غير محدد</span>
                                                @endif
                                            </td>
                                            <td>
                                                <strong class="{{ $result->is_passed ? 'text-success' : 'text-danger' }}">
                                                    {{ number_format($result->score_percentage, 1) }}%
                                                </strong>
                                                <br>
                                                <small class="text-muted">
                                                    {{ $result->correct_answers }}/{{ $result->total_questions }}
                                                </small>
                                            </td>
                                            <td>
                                                @if($result->is_passed)
                                                    <span class="stat-badge stat-badge-success">
                                                        <i class="fa fa-check"></i> نجح
                                                    </span>
                                                @else
                                                    <span class="stat-badge stat-badge-danger">
                                                        <i class="fa fa-times"></i> رسب
                                                    </span>
                                                @endif
                                            </td>
                                            <td>
                                                @if($result->time_taken_minutes)
                                                    <i class="fa fa-clock-o"></i> {{ gmdate('H:i:s', $result->time_taken_minutes * 60) }}
                                                @else
                                                    <span class="text-muted">-</span>
                                                @endif
                                            </td>
                                            <td>{{ \App\Support\BusinessClock::format($result->completed_at ?: $result->created_at) }}</td>
                                            <td>
                                                <a href="{{ route('admin.exam-results.show', $result->id) }}"
                                                   class="btn btn-modern btn-modern-primary btn-result-view">
                                                    <i class="fa fa-eye"></i> عرض
                                                </a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="d-flex justify-content-center mt-3">
                            {{ $examResults->appends(request()->query())->links() }}
                        </div>
                    @else
                        <div class="empty-state-modern">
                            <i class="fa fa-graduation-cap"></i>
                            <h4>لا توجد نتائج امتحانات</h4>
                            <p>لم يقم المستخدم بإجراء أي امتحانات بعد</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>-->

    <div class="row">
        <div class="col-12">
            <!-- Orders Section -->
            <div class="section-card-modern">
                <div class="section-header-modern">
                    <h3 class="section-title">
                        <i class="fa fa-shopping-cart"></i>
                        المشتريات
                    </h3>
                    <div class="stats-container">
                        <span class="stat-badge stat-badge-light">
                            <i class="fa fa-shopping-bag"></i> {{ $orders->total() }} طلب
                        </span>
                        @if($orders->count() > 0)
                            @php
                                $approvedCount = $orders->where('status', 'approved')->count();
                                $pendingCount = $orders->where('status', 'pending')->count();
                                $totalAmount = $orders->where('status', 'approved')->sum('final_amount');
                            @endphp
                            <span class="stat-badge stat-badge-success">
                                <i class="fa fa-check"></i> مُعتمد: {{ $approvedCount }}
                            </span>
                            <span class="stat-badge stat-badge-danger">
                                <i class="fa fa-hourglass"></i> معلق: {{ $pendingCount }}
                            </span>
                            <span class="stat-badge stat-badge-info">
                                <i class="fa fa-money"></i> {{ number_format($totalAmount) }} جنيه
                            </span>
                        @endif
                    </div>
                </div>
                <div class="section-body">
                    @if($orders->count() > 0)
                        <div class="table-responsive">
                            <table class="detail-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>الدورة</th>
                                        <th>المبلغ</th>
                                        <th>النهائي</th>
                                        <th>طريقة الدفع</th>
                                        <th>الحالة</th>
                                        <th>التاريخ</th>
                                        <th>الموافقة</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($orders as $order)
                                        <tr>
                                            <td>
                                                <a href="{{ route('admin.orders.show', $order->id) }}" class="user-detail-link">
                                                    <strong>#{{ $order->id }}</strong>
                                                </a>
                                            </td>
                                            <td>
                                                @if($order->course)
                                                    <a href="{{ route('admin.orders.show', $order->id) }}" class="user-detail-link">
                                                        <strong>{{ $order->course->name_ar }}</strong>
                                                    </a>
                                                    @if($order->courseCode)
                                                        <br><small class="text-info"><i class="fa fa-ticket"></i> {{ $order->courseCode->code }}</small>
                                                    @endif
                                                @else
                                                    <span class="text-muted">غير محدد</span>
                                                @endif
                                            </td>
                                            <td>{{ $order->amount }} {{ $order->course->currency ?? 'جنيه' }}</td>
                                            <td>
                                                <strong>{{ $order->final_amount }}</strong>
                                                @if($order->discount_amount > 0)
                                                    <br><small class="text-success"><i class="fa fa-tag"></i> خصم: {{ $order->discount_amount }}</small>
                                                @endif
                                            </td>
                                            <td>
                                                @switch($order->payment_method)
                                                    @case('online')
                                                        <span class="stat-badge stat-badge-info"><i class="fa fa-credit-card"></i> أونلاين</span>
                                                        @break
                                                    @case('course_code')
                                                        <span class="stat-badge stat-badge-success"><i class="fa fa-ticket"></i> كود</span>
                                                        @break
                                                    @case('wallet')
                                                        <span class="stat-badge stat-badge-primary"><i class="fa fa-wallet"></i> محفظة</span>
                                                        @break
                                                    @default
                                                        <span class="stat-badge stat-badge-light"><i class="fa fa-money"></i> {{ $order->paymentMethod->name ?? $order->payment_method }}</span>
                                                @endswitch
                                            </td>
                                            <td>
                                                @switch($order->status)
                                                    @case('pending')
                                                        <span class="stat-badge stat-badge-danger"><i class="fa fa-hourglass"></i> معلق</span>
                                                        @break
                                                    @case('approved')
                                                        <span class="stat-badge stat-badge-success"><i class="fa fa-check"></i> مُعتمد</span>
                                                        @break
                                                    @case('rejected')
                                                        <span class="stat-badge stat-badge-danger"><i class="fa fa-times"></i> مرفوض</span>
                                                        @break
                                                    @case('cancelled')
                                                        <span class="stat-badge stat-badge-light"><i class="fa fa-ban"></i> ملغي</span>
                                                        @break
                                                    @default
                                                        <span class="stat-badge stat-badge-light">{{ $order->status }}</span>
                                                @endswitch
                                            </td>
                                            <td>{{ \App\Support\BusinessClock::format($order->created_at) }}</td>
                                            <td>
                                                @if($order->approved_at)
                                                    <small>
                                                        {{ \App\Support\BusinessClock::format($order->approved_at, 'Y-m-d') }}
                                                        @if($order->approvedBy)
                                                            <br><i class="fa fa-user"></i> {{ $order->approvedBy->name }}
                                                        @endif
                                                    </small>
                                                @else
                                                    <span class="text-muted">-</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <!-- Orders Pagination -->
                        <div class="d-flex justify-content-center mt-3">
                            {{ $orders->appends(request()->query())->links() }}
                        </div>
                    @else
                        <div class="empty-state-modern">
                            <i class="fa fa-shopping-cart"></i>
                            <h4>لا توجد طلبات</h4>
                            <p>لم يقم المستخدم بشراء أي دورات بعد</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <!-- Bills Section -->
            <div class="section-card-modern">
                <div class="section-header-modern">
                    <h3 class="section-title">
                        <i class="fa fa-file-text"></i>
                        الفواتير
                    </h3>
                    <div class="stats-container">
                        <span class="stat-badge stat-badge-light">
                            <i class="fa fa-file"></i> {{ $bills->total() }} فاتورة
                        </span>
                        @if($bills->count() > 0)
                            @php
                                $paidCount = $bills->where('payment_status', 'paid')->count();
                                $pendingCount = $bills->where('payment_status', 'pending')->count();
                                $totalPaid = $bills->where('payment_status', 'paid')->sum('total_amount');
                                $totalPending = $bills->where('payment_status', 'pending')->sum('total_amount');
                            @endphp
                            <span class="stat-badge stat-badge-success">
                                <i class="fa fa-check"></i> مدفوع: {{ $paidCount }}
                            </span>
                            <span class="stat-badge stat-badge-danger">
                                <i class="fa fa-hourglass"></i> معلق: {{ $pendingCount }}
                            </span>
                            <span class="stat-badge stat-badge-info">
                                <i class="fa fa-money"></i> {{ number_format($totalPaid) }} جنيه
                            </span>
                            @if($totalPending > 0)
                                <span class="stat-badge stat-badge-danger">
                                    <i class="fa fa-exclamation"></i> معلق: {{ number_format($totalPending) }} جنيه
                                </span>
                            @endif
                        @endif
                    </div>
                </div>
                <div class="section-body">
                    @if($bills->count() > 0)
                        <div class="table-responsive">
                            <table class="detail-table">
                                <thead>
                                    <tr>
                                        <th>رقم الفاتورة</th>
                                        <th>الطلب</th>
                                        <th>الدورة</th>
                                        <th>المبلغ</th>
                                        <th>الضريبة</th>
                                        <th>الإجمالي</th>
                                        <th>حالة الدفع</th>
                                        <th>الطريقة</th>
                                        <th>التاريخ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($bills as $bill)
                                        <tr>
                                            <td>
                                                <a href="{{ route('admin.bills.show', $bill->id) }}" class="user-detail-link">
                                                    <strong>{{ $bill->bill_number }}</strong>
                                                </a>
                                            </td>
                                            <td>
                                                @if($bill->order)
                                                    <strong>#{{ $bill->order->id }}</strong>
                                                @else
                                                    <span class="text-muted">-</span>
                                                @endif
                                            </td>
                                            <td>
                                                @if($bill->order && $bill->order->course)
                                                    {{ $bill->order->course->name_ar }}
                                                @elseif($bill->course)
                                                    {{ $bill->course->name_ar }}
                                                @else
                                                    <span class="text-muted">غير محدد</span>
                                                @endif
                                            </td>
                                            <td>{{ $bill->amount }}</td>
                                            <td>{{ $bill->tax_amount }}</td>
                                            <td><strong>{{ $bill->total_amount }}</strong></td>
                                            <td>
                                                @switch($bill->payment_status)
                                                    @case('pending')
                                                        <span class="stat-badge stat-badge-danger"><i class="fa fa-hourglass"></i> معلق</span>
                                                        @break
                                                    @case('paid')
                                                        <span class="stat-badge stat-badge-success"><i class="fa fa-check"></i> مدفوع</span>
                                                        @break
                                                    @case('overdue')
                                                        <span class="stat-badge stat-badge-danger"><i class="fa fa-exclamation"></i> متأخر</span>
                                                        @break
                                                    @case('cancelled')
                                                        <span class="stat-badge stat-badge-light"><i class="fa fa-ban"></i> ملغي</span>
                                                        @break
                                                    @default
                                                        <span class="stat-badge stat-badge-light">{{ $bill->payment_status }}</span>
                                                @endswitch
                                            </td>
                                            <td>
                                                @if($bill->payment_method)
                                                    @switch($bill->payment_method)
                                                        @case('online')
                                                            <span class="stat-badge stat-badge-info"><i class="fa fa-credit-card"></i> أونلاين</span>
                                                            @break
                                                        @case('course_code')
                                                            <span class="stat-badge stat-badge-success"><i class="fa fa-ticket"></i> كود</span>
                                                            @break
                                                        @case('wallet')
                                                            <span class="stat-badge stat-badge-primary"><i class="fa fa-wallet"></i> محفظة</span>
                                                            @break
                                                        @default
                                                            <span class="stat-badge stat-badge-light"><i class="fa fa-money"></i> {{ $bill->order->paymentMethod->name ?? $bill->payment_method }}</span>
                                                    @endswitch
                                                @else
                                                    <span class="text-muted">-</span>
                                                @endif
                                            </td>
                                            <td>
                                                @if($bill->paid_at)
                                                    {{ \App\Support\BusinessClock::format($bill->paid_at) }}
                                                @else
                                                    <span class="text-muted">لم يدفع</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <!-- Bills Pagination -->
                        <div class="d-flex justify-content-center mt-3">
                            {{ $bills->appends(request()->query())->links() }}
                        </div>
                    @else
                        <div class="empty-state-modern">
                            <i class="fa fa-file-text"></i>
                            <h4>لا توجد فواتير</h4>
                            <p>لم يتم إصدار أي فواتير لهذا المستخدم بعد</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div> -->

<!-- Add Note Modal -->
<div class="modal fade" id="addNoteModal" tabindex="-1" role="dialog" aria-labelledby="addNoteModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content modal-content-modern">
            <form method="POST" action="{{ route('admin.users.notes.store', $user->id) }}">
                @csrf
                <div class="modal-header modal-header-modern">
                    <h5 class="modal-title" id="addNoteModalLabel">
                        <i class="fa fa-sticky-note"></i> إضافة ملاحظة جديدة
                    </h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body modal-body-modern">
                    <div class="form-group">
                        <label for="note" class="font-weight-bold">
                            <i class="fa fa-pencil"></i> الملاحظة
                        </label>
                        <textarea name="note" id="note" class="form-control" rows="5" required
                                  placeholder="اكتب الملاحظة هنا..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <i class="fa fa-times"></i> إلغاء
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fa fa-save"></i> حفظ الملاحظة
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

@endsection
