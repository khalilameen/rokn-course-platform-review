        <!-- Order Information -->
        <div class="col-lg-8">
            <!-- Main Order Details -->
            <div class="card order-detail-card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5>
                            <i class="fa fa-receipt"></i>
                            تفاصيل الطلب #{{ $order->id }}
                        </h5>
                        <div>
                            @if($order->status === 'pending')
                                <span class="status-badge-large badge-warning">
                                    <i class="fa fa-clock-o"></i> في الانتظار
                                </span>
                            @elseif($order->status === 'approved')
                                <span class="status-badge-large badge-success">
                                    <i class="fa fa-check-circle"></i> مُعتمد
                                </span>
                            @elseif($order->status === 'rejected')
                                <span class="status-badge-large badge-danger">
                                    <i class="fa fa-times-circle"></i> مرفوض
                                </span>
                            @elseif($order->status === 'cancelled')
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
                            <i class="fa fa-user"></i>
                            <span>العميل:</span>
                        </div>
                        <div class="info-value">
                            <a href="{{ route('admin.users.show', $order->user->id) }}" class="user-link">
                                {{ $order->user->name }}
                            </a>
                            <div>
                                <small class="text-muted">
                                    <i class="fa fa-envelope"></i> {{ $order->user->email }}
                                </small>
                            </div>
                            @if($order->user->phone)
                                <div>
                                    <small class="text-muted">
                                        <i class="fa fa-phone"></i> {{ $order->user->phone }}
                                    </small>
                                </div>
                            @endif
                        </div>
                    </div>

                    @if($order->course)
                    <div class="info-row">
                        <div class="info-label">
                            <i class="fa fa-book"></i>
                            <span>الدورة:</span>
                        </div>
                        <div class="info-value">
                            <strong>{{ $order->course->title }}</strong>
                            @if($order->course->price)
                                <div>
                                    <small class="text-muted">سعر فتح الكورس: {{ number_format($order->course->price) }} عملة ركن</small>
                                </div>
                            @endif
                        </div>
                    </div>
                    @elseif($order->package)
                    <div class="info-row">
                        <div class="info-label">
                            <i class="fa fa-database"></i>
                            <span>باقة العملات:</span>
                        </div>
                        <div class="info-value">
                            <strong>{{ $order->package->name_ar ?: $order->package->name_en }}</strong>
                            <div><small class="text-muted">{{ number_format($order->package_coins ?? $order->package->coins) }} عملة ركن</small></div>
                        </div>
                    </div>
                    @endif

                    @if($order->courseCode)
                    <div class="info-row">
                        <div class="info-label">
                            <i class="fa fa-ticket"></i>
                            <span>كود الدورة:</span>
                        </div>
                        <div class="info-value">
                            <span class="code-badge">{{ $order->courseCode->code }}</span>
                        </div>
                    </div>
                    @endif

                    <div class="info-row">
                        <div class="info-label">
                            <i class="fa fa-credit-card"></i>
                            <span>طريقة الدفع:</span>
                        </div>
                        <div class="info-value">
                            <span class="badge badge-secondary order-payment-method">
                                <i class="fa fa-money"></i> {{ $paymentMethodLabels[$order->payment_method] ?? $order->payment_method }}
                            </span>
                            @if($order->gateway_settlement_status === 'test_purchase')
                                <div class="mt-2"><span class="badge badge-info">اختبار — لا يدخل في الإيراد</span></div>
                            @endif
                        </div>
                    </div>

                    @if($order->transaction_id || $order->storePurchase)
                    <div class="info-row">
                        <div class="info-label"><i class="fa fa-hashtag"></i><span>مرجع المزود:</span></div>
                        <div class="info-value">
                            <code>{{ $order->transaction_id ?: $order->storePurchase?->external_transaction_id }}</code>
                            @if($order->storePurchase?->environment)
                                <div><small class="text-muted">البيئة: {{ $order->storePurchase->environment }}</small></div>
                            @endif
                        </div>
                    </div>
                    @endif

                    @if($order->coupon_id || $order->coupon_code)
                    <div class="info-row">
                        <div class="info-label">
                            <i class="fa fa-tag"></i>
                            <span>كوبون الخصم:</span>
                        </div>
                        <div class="info-value">
                            <span class="code-badge">{{ $order->coupon_code ?: 'كوبون #' . $order->coupon_id }}</span>
                            <div class="mt-2">
                                <small class="text-success">
                                    <i class="fa fa-coins"></i>
                                    خصم: {{ number_format((float) $order->discount_amount, 0) }} عملة
                                </small>
                            </div>
                        </div>
                    </div>
                    @endif

                    @if($order->approved_at && $order->approvedBy)
                    <div class="info-row">
                        <div class="info-label">
                            <i class="fa fa-user-check"></i>
                            <span>معتمد بواسطة:</span>
                        </div>
                        <div class="info-value">
                            <strong>{{ $order->approvedBy->name }}</strong>
                            <div>
                                <small class="text-muted">
                                    <i class="fa fa-calendar"></i>
                                    {{ \App\Support\BusinessClock::format($order->approved_at, 'Y-m-d H:i:s') }}
                                </small>
                            </div>
                        </div>
                    </div>
                    @endif

                    @if($order->notes)
                    <div class="info-row">
                        <div class="info-label">
                            <i class="fa fa-sticky-note"></i>
                            <span>ملاحظات:</span>
                        </div>
                        <div class="info-value">
                            <div class="alert alert-info order-notes mb-0">
                                {{ $order->notes }}
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
                            <strong>{{ \App\Support\BusinessClock::format($order->created_at, 'Y-m-d') }}</strong>
                            <small class="text-muted">{{ \App\Support\BusinessClock::format($order->created_at, 'H:i:s') }}</small>
                        </div>
                    </div>

                    @include('admin.orders.partials.show.amounts')
                </div>
            </div>

            <!-- Payment Screenshot Section -->
            @php($paymentEvidenceUrl = $order->payment_screenshot_url)
            @if($paymentEvidenceUrl)
            <div class="card order-detail-card">
                <div class="card-header order-receipt-header">
                    <h5>
                        <i class="fa fa-image"></i>
                        إيصال الدفع
                    </h5>
                </div>
                <div class="card-body">
                    <div class="payment-screenshot-box">
                        <img src="{{ $paymentEvidenceUrl }}"
                             alt="إيصال الدفع"
                             class="payment-screenshot-large"
                             onclick="showFullScreenshot(@js($paymentEvidenceUrl))">
                        <div class="mt-3">
                            <a href="{{ $paymentEvidenceUrl }}"
                               download
                               class="btn btn-primary">
                                <i class="fa fa-download"></i> تحميل الإيصال
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            @endif
        </div>
