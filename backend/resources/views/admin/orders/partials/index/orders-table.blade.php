    <!-- Orders Table -->
    <div class="row">
        <div class="col-12">
            <div class="card modern-card">
                <div class="card-header-modern">
                    <h4>
                        <i class="fa fa-list"></i>
                        قائمة الطلبات
                    </h4>
                    <div class="orders-count-badge">
                        <i class="fa fa-file-text"></i>
                        <span>{{ $orders->total() }} طلب</span>
                    </div>
                </div>
                <div class="card-body orders-table-card-body">
                    @if($orders->count() > 0)
                    <div class="table-responsive">
                        <table class="table table-modern">
                            <thead>
                                <tr>
                                    <th class="text-center orders-column--id">#</th>
                                    <th class="text-center">العميل</th>
                                    <th class="text-center">الدورة</th>
                                    <th class="text-center orders-column--amount">المبلغ النهائي</th>
                                    <th class="text-center orders-column--payment">طريقة الدفع</th>
                                    <th class="text-center orders-column--status">الحالة</th>
                                    <th class="text-center orders-column--date">تاريخ الإنشاء</th>
                                    <th class="text-center orders-column--actions">الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($orders as $order)
                                <tr>
                                    <td class="text-center">
                                        <a href="{{ route('admin.orders.show', $order) }}" class="order-id">
                                            #{{ $order->id }}
                                        </a>
                                    </td>
                                    <td class="text-center">
                                        <div class="user-info">
                                            <h6>{{ $order->user->name }}</h6>
                                            <small>{{ $order->user->email }}</small>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <div class="course-info">
                                            @if($order->course)
                                                <h6>{{ Str::limit($order->course->title, 40) }}</h6>
                                            @elseif($order->package)
                                                <h6>{{ Str::limit($order->package->name_ar ?: $order->package->name_en, 40) }}</h6>
                                                <small class="text-muted">{{ number_format($order->package_coins ?? $order->package->coins) }} عملة ركن</small>
                                            @else
                                                <h6>طلب بدون منتج مرتبط</h6>
                                            @endif
                                            @if($order->courseCode)
                                                <small class="text-info"><i class="fa fa-ticket"></i> كود: {{ $order->courseCode->code }}</small>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        @php
                                            $isCashChannel = in_array($order->payment_method, ['kashier', 'google_play', 'app_store'], true);
                                            $isWalletOrder = in_array($order->payment_method, ['wallet', 'wallet_coins'], true);
                                            $displayAmount = $isWalletOrder
                                                ? (int) ($order->total_coins ?? 0)
                                                : (float) ($isCashChannel ? ($order->gateway_gross_amount ?? $order->final_amount) : $order->final_amount);
                                            $displayUnit = $isWalletOrder ? 'عملة ركن' : ($isCashChannel ? ($order->gateway_currency ?: 'EGP') : 'جنيه');
                                        @endphp
                                        <div class="amount-display">{{ number_format($displayAmount, $isWalletOrder ? 0 : 2) }}</div>
                                        <small class="text-muted">{{ $displayUnit }}</small>
                                        @if($isCashChannel && ($order->gateway_gross_amount === null || $order->gateway_settlement_status === 'catalog_estimate'))
                                            <br><small class="text-warning">تقدير كتالوج</small>
                                        @endif
                                        @if($order->discount_amount > 0)
                                            <br><small class="discount-info"><i class="fa fa-tag"></i> خصم: {{ number_format($order->discount_amount, 2) }}</small>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        <span class="payment-badge badge-secondary">
                                            <i class="fa fa-money"></i> {{ $paymentMethodLabels[$order->payment_method] ?? $order->payment_method }}
                                        </span>
                                        @if($order->gateway_settlement_status === 'test_purchase')
                                            <br><span class="badge badge-info mt-1">عملية اختبار — خارج الإيراد</span>
                                        @elseif(in_array($order->payment_method, ['kashier', 'google_play', 'app_store'], true) && $order->gateway_net_amount === null)
                                            <br><span class="badge badge-warning mt-1">الصافي بانتظار التسوية</span>
                                        @endif
                                        @if($order->payment_screenshot)
                                            <br>
                                            <img src="{{ asset('storage/' . $order->payment_screenshot) }}"
                                                 alt="إيصال الدفع"
                                                 class="payment-screenshot-thumb mt-2"
                                                 onclick="showPaymentScreenshot('{{ asset('storage/' . $order->payment_screenshot) }}')"
                                                 title="عرض إيصال الدفع">
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        @if($order->status === 'pending')
                                            <span class="order-badge badge-warning">
                                                <i class="fa fa-clock-o"></i> انتظار
                                            </span>
                                        @elseif($order->status === 'approved')
                                            <span class="order-badge badge-success">
                                                <i class="fa fa-check-circle"></i> مُعتمد
                                            </span>
                                        @elseif($order->status === 'rejected')
                                            <span class="order-badge badge-danger">
                                                <i class="fa fa-times-circle"></i> مرفوض
                                            </span>
                                        @elseif($order->status === 'cancelled')
                                            <span class="order-badge badge-secondary">
                                                <i class="fa fa-ban"></i> ملغي
                                            </span>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        <div class="order-created-at">
                                            <div class="order-created-at__date">{{ \App\Support\BusinessClock::format($order->created_at, 'Y-m-d') }}</div>
                                            <small class="text-muted">{{ \App\Support\BusinessClock::format($order->created_at, 'H:i') }}</small>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <div class="dropdown action-dropdown">
                                            <button class="btn dropdown-toggle" type="button" data-toggle="dropdown" aria-expanded="false">
                                                <i class="fa fa-bars"></i>
                                            </button>
                                            <div class="dropdown-menu dropdown-menu-right">
                                                <a class="dropdown-item" href="{{ route('admin.orders.show', $order) }}">
                                                    <i class="fa fa-eye"></i> مشاهدة
                                                </a>
                                                @if($order->status === 'pending' && !$order->requiresProviderVerification())
                                                    <a class="dropdown-item" href="#" onclick="updateOrderStatus({{ $order->id }}, 'approved'); return false;">
                                                        <i class="fa fa-check text-success"></i> اعتماد
                                                    </a>
                                                    <a class="dropdown-item" href="#" onclick="updateOrderStatus({{ $order->id }}, 'rejected'); return false;">
                                                        <i class="fa fa-times text-danger"></i> رفض
                                                    </a>
                                                @endif
                                                @if($order->status === 'rejected' && !$order->requiresProviderVerification())
                                                    <a class="dropdown-item" href="#" onclick="updateOrderStatus({{ $order->id }}, 'approved'); return false;">
                                                        <i class="fa fa-check text-success"></i> اعتماد
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
                    <div class="d-flex justify-content-center orders-pagination">
                        {{ $orders->links() }}
                    </div>
                    @else
                    <div class="empty-state">
                        <i class="fa fa-inbox fa-5x"></i>
                        <h5>لا توجد طلبات</h5>
                        <p class="text-muted">لم يتم العثور على أي طلبات تطابق معايير البحث.</p>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
