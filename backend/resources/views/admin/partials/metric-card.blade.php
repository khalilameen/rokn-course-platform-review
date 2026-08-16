@if(!empty($metricHref))
    <a href="{{ $metricHref }}" class="admin-metric">
        <span class="admin-metric__icon" aria-hidden="true"><i class="fa {{ $metricIcon ?? 'fa-bar-chart' }}"></i></span>
        <span><span class="admin-metric__value">{{ $metricValue }}</span><span class="admin-metric__label">{{ $metricLabel }}</span></span>
    </a>
@else
    <div class="admin-metric">
        <span class="admin-metric__icon" aria-hidden="true"><i class="fa {{ $metricIcon ?? 'fa-bar-chart' }}"></i></span>
        <span><span class="admin-metric__value">{{ $metricValue }}</span><span class="admin-metric__label">{{ $metricLabel }}</span></span>
    </div>
@endif
