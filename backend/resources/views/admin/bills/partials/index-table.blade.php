<!-- Bills Table -->
    <div class="row">
        <div class="col-12">
            <div class="card modern-card">
                <div class="card-header-modern">
                    <h4>
                        <i class="fa fa-list"></i>
                        قائمة الفواتير
                    </h4>
                    <div class="bills-count-badge">
                        <i class="fa fa-file-text"></i>
                        <span>{{ $bills->total() }} فاتورة</span>
                    </div>
                </div>
                <div class="card-body card-body-flush">
                    @if($bills->count() > 0)
                    <div class="table-responsive">
                        <table class="table table-modern">
                            <thead>
                                <tr>
                                    <th class="text-center">رقم الفاتورة</th>
                                    <th class="text-center">العميل</th>
                                    <th class="text-center">الدورة</th>
                                    <th class="text-center bills-col-amount">المبلغ</th>
                                    <th class="text-center bills-col-amount">الضريبة</th>
                                    <th class="text-center bills-col-total">المجموع</th>
                                    <th class="text-center bills-col-payment-method">طريقة الدفع</th>
                                    <th class="text-center bills-col-amount">حالة الدفع</th>
                                    <th class="text-center bills-col-total">تاريخ الاستحقاق</th>
                                    <th class="text-center bills-col-actions">الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($bills as $bill)
                                <tr>
                                    <td class="text-center">
                                        <a href="{{ route('admin.bills.show', $bill) }}" class="bill-number">
                                            {{ $bill->bill_number }}
                                        </a>
                                    </td>
                                    <td class="text-center">
                                        <div class="user-info">
                                            <h6>{{ $bill->user->name }}</h6>
                                            <small>{{ $bill->user->email }}</small>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <div class="course-info">
                                            <h6>{{ Str::limit($bill->course->title, 40) }}</h6>
                                            @if($bill->order)
                                                <small class="text-info"><i class="fa fa-shopping-cart"></i> طلب #{{ $bill->order->id }}</small>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <div class="bill-table-number">{{ number_format($bill->amount, 2) }}</div>
                                        <small class="text-muted">جنيه</small>
                                    </td>
                                    <td class="text-center">
                                        <div class="bill-table-number">{{ number_format($bill->tax_amount, 2) }}</div>
                                        <small class="text-muted">جنيه</small>
                                    </td>
                                    <td class="text-center">
                                        <div class="amount-display">{{ number_format($bill->total_amount, 2) }}</div>
                                        <small class="text-muted">جنيه</small>
                                    </td>
                                    <td class="text-center">
                                        <span class="payment-badge badge-secondary">
                                            <i class="fa fa-money"></i> {{ $bill->payment_method }}
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        @if($bill->payment_status === 'pending')
                                            <span class="bill-badge badge-warning">
                                                <i class="fa fa-clock-o"></i> انتظار
                                            </span>
                                        @elseif($bill->payment_status === 'paid')
                                            <span class="bill-badge badge-success">
                                                <i class="fa fa-check-circle"></i> مدفوع
                                            </span>
                                        @elseif($bill->payment_status === 'overdue')
                                            <span class="bill-badge badge-danger">
                                                <i class="fa fa-times-circle"></i> متأخر
                                            </span>
                                        @elseif($bill->payment_status === 'cancelled')
                                            <span class="bill-badge badge-secondary">
                                                <i class="fa fa-ban"></i> ملغي
                                            </span>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        <div class="bill-due-date">
                                            <div class="bill-table-number">{{ $bill->due_date ? $bill->due_date->format('Y-m-d') : 'غير محدد' }}</div>
                                            @if($bill->due_date && $bill->due_date < now() && $bill->payment_status === 'pending')
                                                <small class="text-danger"><i class="fa fa-exclamation-triangle"></i> متأخر</small>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <div class="dropdown action-dropdown">
                                            <button class="btn dropdown-toggle" type="button" data-toggle="dropdown" aria-expanded="false">
                                                <i class="fa fa-bars"></i>
                                            </button>
                                            <div class="dropdown-menu dropdown-menu-right">
                                                <a class="dropdown-item" href="{{ route('admin.bills.show', $bill) }}">
                                                    <i class="fa fa-eye text-info"></i> مشاهدة
                                                </a>
                                                @if($bill->payment_status === 'pending')
                                                    <a class="dropdown-item" href="#" onclick="updateBillStatus({{ $bill->id }}, 'paid'); return false;">
                                                        <i class="fa fa-check text-success"></i> اعتماد
                                                    </a>
                                                    <a class="dropdown-item" href="#" onclick="updateBillStatus({{ $bill->id }}, 'cancelled'); return false;">
                                                        <i class="fa fa-times text-danger"></i> رفض
                                                    </a>
                                                @endif
                                                @if($bill->payment_status === 'overdue')
                                                    <a class="dropdown-item" href="#" onclick="updateBillStatus({{ $bill->id }}, 'paid'); return false;">
                                                        <i class="fa fa-check text-success"></i> اعتماد
                                                    </a>
                                                @endif
                                                @if($bill->payment_status === 'paid')
                                                    <a class="dropdown-item" href="#" onclick="updateBillStatus({{ $bill->id }}, 'cancelled'); return false;">
                                                        <i class="fa fa-times text-danger"></i> إلغاء
                                                    </a>
                                                @endif
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div class="d-flex justify-content-center bills-pagination">
                        {{ $bills->links() }}
                    </div>
                    @else
                    <div class="empty-state">
                        <i class="fa fa-inbox fa-5x"></i>
                        <h5>لا توجد فواتير</h5>
                        <p class="text-muted">لم يتم العثور على أي فواتير تطابق معايير البحث.</p>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
