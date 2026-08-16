        <!-- Actions Panel -->
        <div class="col-lg-4">
            <div class="card actions-card">
                <div class="card-header">
                    <h5 class="actions-card__heading">
                        <i class="fa fa-cogs"></i>
                        إجراءات الطلب
                    </h5>
                </div>
                <div class="card-body">
                    @if($order->financial_status === \App\Models\Order::FINANCIAL_REVIEW_REQUIRED && $order->package_id)
                        <div class="alert alert-warning">
                            <strong>مراجعة مالية مطلوبة</strong>
                            <div>تم استرداد {{ $order->recovered_coins }} عملة، والمتبقي للمراجعة {{ $order->unrecovered_coins }}.</div>
                        </div>
                        <form method="POST" action="{{ route('admin.orders.resolve-financial-review', $order) }}">
                            @csrf
                            <div class="form-group">
                                <label for="financial-resolution">القرار</label>
                                <select id="financial-resolution" name="resolution" class="form-control" required>
                                    <option value="repaid">تم السداد أو قبول الاعتراض</option>
                                    <option value="waived">إعفاء موثق</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="financial-note">سبب القرار ورقم المرجع</label>
                                <textarea id="financial-note" name="note" class="form-control" rows="3" minlength="5" maxlength="1000" required>{{ old('note') }}</textarea>
                            </div>
                            <button type="submit" class="btn btn-warning btn-block action-btn" onclick="return confirm('هل راجعت مستندات القرار؟ سيؤثر هذا في رصيد الطالب واستحقاقاته.')">
                                <i class="fa fa-balance-scale"></i> اعتماد قرار المراجعة
                            </button>
                        </form>
                        <hr class="order-action-separator">
                    @endif
                    @if($order->status === 'pending')
                        <button type="button" class="btn btn-success btn-block action-btn" onclick="updateOrderStatus('approved')">
                            <i class="fa fa-check-circle"></i> اعتماد الطلب
                        </button>
                        <button type="button" class="btn btn-danger btn-block action-btn" onclick="updateOrderStatus('rejected')">
                            <i class="fa fa-times-circle"></i> رفض الطلب
                        </button>
                    @endif

                    @if($order->status === 'rejected')
                        <button type="button" class="btn btn-success btn-block action-btn" onclick="updateOrderStatus('approved')">
                            <i class="fa fa-check-circle"></i> اعتماد الطلب
                        </button>
                    @endif

                    @if($order->status === 'approved')
                        <button type="button" class="btn btn-danger btn-block action-btn" onclick="updateOrderStatus('rejected')">
                            <i class="fa fa-times-circle"></i> رفض الطلب
                        </button>
                    @endif

                    @if($order->bill)
                        <a href="{{ route('admin.bills.show', $order->bill) }}" class="btn btn-info btn-block action-btn">
                            <i class="fa fa-file-text"></i> مشاهدة الفاتورة
                        </a>
                    @endif

                    <hr class="order-action-separator">

                    <div class="text-center">
                        <small class="text-muted">معلومات إضافية</small>
                    </div>

                    <div class="order-summary">
                        <div class="order-summary__row">
                            <span class="order-summary__label">رقم الطلب:</span>
                            <strong class="order-summary__value">#{{ $order->id }}</strong>
                        </div>
                        <div class="order-summary__row">
                            <span class="order-summary__label">تاريخ الطلب:</span>
                            <strong class="order-summary__value">{{ $order->created_at->format('Y-m-d') }}</strong>
                        </div>
                        <div class="order-summary__row order-summary__row--last">
                            <span class="order-summary__label">الوقت:</span>
                            <strong class="order-summary__value">{{ $order->created_at->format('H:i:s') }}</strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>
