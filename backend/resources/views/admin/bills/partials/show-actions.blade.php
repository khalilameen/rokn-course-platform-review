<!-- Actions Panel -->
        <div class="col-lg-4">
            <div class="card actions-card">
                <div class="card-header">
                    <h5 class="actions-card-title">
                        <i class="fa fa-cogs"></i>
                        إجراءات الفاتورة
                    </h5>
                </div>
                <div class="card-body">
                    @if($bill->payment_status === 'pending')
                        <button type="button" class="btn btn-success btn-block action-btn" onclick="updatePaymentStatus('paid')">
                            <i class="fa fa-check-circle"></i> تأكيد الدفع
                        </button>
                        <button type="button" class="btn btn-danger btn-block action-btn" onclick="updatePaymentStatus('overdue')">
                            <i class="fa fa-clock-o"></i> تعليم كمتأخر
                        </button>
                    @endif

                    @if($bill->payment_status === 'overdue')
                        <button type="button" class="btn btn-success btn-block action-btn" onclick="updatePaymentStatus('paid')">
                            <i class="fa fa-check-circle"></i> اعتماد الدفع
                        </button>
                    @endif

                    @if($bill->payment_status === 'paid')
                        <button type="button" class="btn btn-danger btn-block action-btn" onclick="updatePaymentStatus('cancelled')">
                            <i class="fa fa-ban"></i> إلغاء الفاتورة
                        </button>
                    @endif

                    @if($bill->order)
                        <a href="{{ route('admin.orders.show', $bill->order) }}" class="btn btn-primary btn-block action-btn btn-view-order">
                            <i class="fa fa-receipt"></i> مشاهدة الطلب
                        </a>
                    @endif

                    <hr class="actions-card-divider">

                    <div class="text-center">
                        <small class="text-muted-bills">معلومات إضافية</small>
                    </div>

                    <div class="info-box-additional">
                        <div class="info-box-row">
                            <span class="info-box-label">رقم الفاتورة:</span>
                            <strong class="info-box-value">{{ $bill->bill_number }}</strong>
                        </div>
                        <div class="info-box-row">
                            <span class="info-box-label">تاريخ الإنشاء:</span>
                            <strong class="info-box-value">{{ \App\Support\BusinessClock::format($bill->created_at, 'Y-m-d') }}</strong>
                        </div>
                        <div class="info-box-row">
                            <span class="info-box-label">الوقت:</span>
                            <strong class="info-box-value">{{ \App\Support\BusinessClock::format($bill->created_at, 'H:i:s') }}</strong>
                        </div>
                        @if($bill->due_date)
                        <div class="info-box-row info-box-row-last">
                            <span class="info-box-label">تاريخ الاستحقاق:</span>
                            <strong class="info-box-value {{ $bill->due_date < now() && $bill->payment_status === 'pending' ? 'info-box-value-overdue' : '' }}">
                                {{ $bill->due_date->format('Y-m-d') }}
                            </strong>
                        </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
