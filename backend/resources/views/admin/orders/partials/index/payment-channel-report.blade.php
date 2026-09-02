<div class="row mb-4">
    <div class="col-12">
        <div class="card modern-card">
            <div class="card-header-modern d-flex flex-wrap align-items-center justify-content-between">
                <h4 class="mb-0"><i class="fa fa-credit-card"></i> تحصيل باقات العملات حسب القناة</h4>
                <small class="text-muted">عمليات الاختبار لا تدخل في أي مبلغ مالي</small>
            </div>
            <div class="table-responsive">
                <table class="table table-modern mb-0">
                    <thead>
                        <tr>
                            <th>القناة</th>
                            <th>عمليات حقيقية</th>
                            <th>عملات مُصدرة</th>
                            <th>الإجمالي المؤكد</th>
                            <th>رسوم مؤكدة</th>
                            <th>صافي مؤكد</th>
                            <th>الصافي الحالي</th>
                            <th>اختبار</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($paymentChannelReport['rows'] as $channel)
                            <tr>
                                <td>
                                    <a href="{{ route('admin.orders.index', ['payment_method' => $channel['method']]) }}">
                                        <strong>{{ $channel['label'] }}</strong>
                                    </a>
                                    <br><small class="text-muted">{{ $channel['currency'] === 'PENDING' ? 'العملة بانتظار كشف المزود' : $channel['currency'] }}</small>
                                </td>
                                <td>{{ number_format($channel['live_count']) }}</td>
                                <td>{{ number_format($channel['live_coins']) }}</td>
                                <td>
                                    {{ number_format($channel['confirmed_gross_amount'], 2) }}
                                    @if($channel['catalog_estimated_gross_count'] > 0)
                                        <br><small class="text-warning">+ {{ number_format($channel['catalog_estimated_gross_amount'], 2) }} تقدير كتالوج خارج الإجمالي · {{ $channel['catalog_estimated_gross_count'] }} عملية</small>
                                    @endif
                                </td>
                                <td>
                                    {{ number_format($channel['confirmed_fee_amount'], 2) }}
                                    @if($channel['pending_settlement_count'] > 0)
                                        <br><small class="text-warning">{{ $channel['pending_settlement_count'] }} بلا كشف تسوية</small>
                                    @endif
                                </td>
                                <td>
                                    {{ number_format($channel['confirmed_net_amount'], 2) }}
                                    <br><small class="text-muted">{{ $channel['confirmed_net_count'] }} عملية مؤكدة</small>
                                </td>
                                <td>
                                    {{ number_format($channel['estimated_net_amount'], 2) }}
                                    <br><small class="text-muted">مؤكد، أو تقديري لحين التسوية</small>
                                </td>
                                <td>
                                    {{ number_format($channel['test_count']) }}
                                    @if($channel['test_coins'] > 0)
                                        <br><small class="text-muted">{{ number_format($channel['test_coins']) }} عملة</small>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <th>الإجمالي بالجنيه</th>
                            <th>{{ number_format($paymentChannelReport['egp']['live_count']) }}</th>
                            <th>{{ number_format($paymentChannelReport['egp']['live_coins']) }}</th>
                            <th>
                                {{ number_format($paymentChannelReport['egp']['confirmed_gross_amount'], 2) }}
                                @if($paymentChannelReport['egp']['catalog_estimated_gross_count'] > 0)<br><small>+ {{ number_format($paymentChannelReport['egp']['catalog_estimated_gross_amount'], 2) }} تقديري خارج الإجمالي</small>@endif
                            </th>
                            <th>{{ number_format($paymentChannelReport['egp']['confirmed_fee_amount'], 2) }}</th>
                            <th>{{ number_format($paymentChannelReport['egp']['confirmed_net_amount'], 2) }}</th>
                            <th>{{ number_format($paymentChannelReport['egp']['estimated_net_amount'], 2) }}</th>
                            <th>{{ number_format($paymentChannelReport['egp']['test_count']) }}</th>
                        </tr>
                    </tfoot>
                </table>
            </div>
            @if($paymentChannelReport['has_other_currencies'])
                <div class="card-footer text-muted">
                    لا تُجمع العملات المختلفة أو العمليات التي لم يصل كشف عملتها مع الجنيه.
                </div>
            @endif
        </div>
    </div>
</div>
