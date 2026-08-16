@php
    $statusLabels = [
        'new' => 'جديد',
        'reviewing' => 'قيد المراجعة',
        'resolved' => 'محلول',
        'dismissed' => 'مغلق',
        'pending' => 'بانتظار المراجعة',
        'passed' => 'مقبولة',
        'needs_resubmission' => 'تحتاج إعادة إرسال',
        'ready' => 'جاهز',
        'processing' => 'قيد المعالجة',
        'failed' => 'فشل',
        'active' => 'نشطة',
        'ended' => 'منتهية',
        'unknown' => 'غير معروف',
    ];
    $statusTones = [
        'new' => 'new',
        'reviewing' => 'reviewing',
        'resolved' => 'resolved',
        'dismissed' => 'dismissed',
        'pending' => 'pending',
        'passed' => 'passed',
        'needs_resubmission' => 'needs_resubmission',
        'ready' => 'ready',
        'processing' => 'processing',
        'failed' => 'failed',
        'active' => 'active',
        'ended' => 'ended',
        'unknown' => 'unknown',
    ];
    $statusValue = (string) ($badgeStatus ?? 'unknown');
    $statusTone = $badgeTone ?? ($statusTones[$statusValue] ?? 'muted');
@endphp

<span class="admin-status admin-status--{{ $statusTone }}">{{ $badgeLabel ?? ($statusLabels[$statusValue] ?? $statusValue) }}</span>
