<!-- Bill Information -->
        <div class="col-lg-8">
            <!-- Main Bill Details -->
            <div class="card bill-detail-card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5>
                            <i class="fa fa-file-text"></i>
                            تفاصيل الفاتورة {{ $bill->bill_number }}
                        </h5>
                        <div>
                            @if($bill->payment_status === 'pending')
                                <span class="status-badge-large badge-warning">
                                    <i class="fa fa-clock-o"></i> في الانتظار
                                </span>
                            @elseif($bill->payment_status === 'paid')
                                <span class="status-badge-large badge-success">
                                    <i class="fa fa-check-circle"></i> مدفوع
                                </span>
                            @elseif($bill->payment_status === 'overdue')
                                <span class="status-badge-large badge-danger">
                                    <i class="fa fa-times-circle"></i> متأخر
                                </span>
                            @elseif($bill->payment_status === 'cancelled')
                                <span class="status-badge-large badge-secondary">
                                    <i class="fa fa-ban"></i> ملغي
                                </span>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="info-row">
                        <div class="info-label">
                            <i class="fa fa-hashtag"></i>
                            <span>رقم الفاتورة:</span>
                        </div>
                        <div class="info-value">
                            <span class="bill-number-display">{{ $bill->bill_number }}</span>
                        </div>
                    </div>

                    @if($bill->order)
                    <div class="info-row">
                        <div class="info-label">
                            <i class="fa fa-shopping-cart"></i>
                            <span>الطلب المرتبط:</span>
                        </div>
                        <div class="info-value">
                            <a href="{{ route('admin.orders.show', $bill->order) }}" class="order-link">
                                طلب #{{ $bill->order->id }}
                            </a>
                            <div>
                                <small class="text-muted">
                                    <i class="fa fa-calendar"></i> {{ $bill->order->created_at->format('Y-m-d') }}
                                </small>
                            </div>
                        </div>
                    </div>
                    @endif

                    <div class="info-row">
                        <div class="info-label">
                            <i class="fa fa-user"></i>
                            <span>العميل:</span>
                        </div>
                        <div class="info-value">
                            <a href="{{ route('admin.users.show', $bill->user->id) }}" class="user-link">
                                {{ $bill->user->name }}
                            </a>
                            <div>
                                <small class="text-muted">
                                    <i class="fa fa-envelope"></i> {{ $bill->user->email }}
                                </small>
                            </div>
                            @if($bill->user->phone)
                                <div>
                                    <small class="text-muted">
                                        <i class="fa fa-phone"></i> {{ $bill->user->phone }}
                                    </small>
                                </div>
                            @endif
                        </div>
                    </div>

                    <div class="info-row">
                        <div class="info-label">
                            <i class="fa fa-book"></i>
                            <span>الدورة:</span>
                        </div>
                        <div class="info-value">
                            <strong>{{ $bill->course->title }}</strong>
                            @if($bill->course->price)
                                <div>
                                    <small class="text-muted">سعر فتح الكورس: {{ number_format($bill->course->price) }} عملة ركن</small>
                                </div>
                            @endif
                        </div>
                    </div>

                    <div class="info-row">
                        <div class="info-label">
                            <i class="fa fa-credit-card"></i>
                            <span>طريقة الدفع:</span>
                        </div>
                        <div class="info-value">
                            @if($bill->payment_method)
                                <span class="badge badge-secondary bill-reference-badge">
                                    <i class="fa fa-money"></i> {{ $bill->payment_method }}
                                </span>
                            @else
                                <span class="badge badge-secondary bill-reference-badge">
                                    <i class="fa fa-question-circle"></i> غير محدد
                                </span>
                            @endif
                        </div>
                    </div>

                    <div class="info-row">
                        <div class="info-label">
                            <i class="fa fa-calendar-check-o"></i>
                            <span>تاريخ الاستحقاق:</span>
                        </div>
                        <div class="info-value">
                            <strong>{{ $bill->due_date ? $bill->due_date->format('Y-m-d') : 'غير محدد' }}</strong>
                            @if($bill->due_date && $bill->due_date < now() && $bill->payment_status === 'pending')
                                <div>
                                    <small class="text-danger">
                                        <i class="fa fa-exclamation-triangle"></i> الفاتورة متأخرة
                                    </small>
                                </div>
                            @endif
                        </div>
                    </div>

                    @if($bill->paid_at)
                    <div class="info-row">
                        <div class="info-label">
                            <i class="fa fa-check-square-o"></i>
                            <span>تاريخ الدفع:</span>
                        </div>
                        <div class="info-value">
                            <strong>{{ $bill->paid_at->format('Y-m-d') }}</strong>
                            <small class="text-muted">{{ $bill->paid_at->format('H:i:s') }}</small>
                        </div>
                    </div>
                    @endif

                    @if($bill->notes)
                    <div class="info-row">
                        <div class="info-label">
                            <i class="fa fa-sticky-note"></i>
                            <span>ملاحظات:</span>
                        </div>
                        <div class="info-value">
                            <div class="alert alert-info mb-0 bill-info-alert">
                                {{ $bill->notes }}
                            </div>
                        </div>
                    </div>
                    @endif

                    <div class="info-row">
                        <div class="info-label">
                            <i class="fa fa-calendar"></i>
                            <span>تاريخ الإنشاء:</span>
                        </div>
                        <div class="info-value">
                            <strong>{{ $bill->created_at->format('Y-m-d') }}</strong>
                            <small class="text-muted">{{ $bill->created_at->format('H:i:s') }}</small>
                        </div>
                    </div>

                    <!-- Amount Section -->
                    <div class="amount-section">
                        <h6 class="bill-section-title">
                            <i class="fa fa-money"></i> تفاصيل المبلغ
                        </h6>
                        <div class="amount-row">
                            <span class="amount-label">المبلغ الأساسي:</span>
                            <span class="amount-value">{{ number_format($bill->amount, 2) }} جنيه</span>
                        </div>
                        <div class="amount-row">
                            <span class="amount-label">مبلغ الضريبة:</span>
                            <span class="amount-value">{{ number_format($bill->tax_amount, 2) }} جنيه</span>
                        </div>
                        <div class="amount-row total">
                            <span class="amount-label">المجموع الإجمالي:</span>
                            <span class="amount-value">{{ number_format($bill->total_amount, 2) }} جنيه</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
