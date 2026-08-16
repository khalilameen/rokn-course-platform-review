@extends('admin.layouts.app')
@section('page.title', 'إدارة الفواتير')

@section('styles')
<link rel="stylesheet" href="{{ asset('admin/assets/css/bills-index.css') }}">
@endsection

@section('content')
<div class="bills-container">
    @include('admin.bills.partials.index-stats')

    @include('admin.bills.partials.index-filters')

    @include('admin.bills.partials.index-table')
</div>
@endsection

@section('scripts')
<script>
// Toggle filter section
function toggleFilterSection() {
    const filterBody = document.getElementById('filter-section-body');
    const toggleIcon = document.getElementById('filter-toggle-icon');

    filterBody.classList.toggle('show');
    toggleIcon.classList.toggle('rotated');

    // Save state to localStorage
    localStorage.setItem('billsFilterSectionOpen', filterBody.classList.contains('show'));
}

// Restore filter section state on page load
document.addEventListener('DOMContentLoaded', function() {
    const filterBody = document.getElementById('filter-section-body');
    const toggleIcon = document.getElementById('filter-toggle-icon');
    const isOpen = localStorage.getItem('billsFilterSectionOpen');

    // Open by default if there are active filters or if previously opened
    const hasActiveFilters = {{ request()->hasAny(['payment_status', 'payment_method', 'user_search', 'course_search', 'date_from', 'date_to', 'due_date_from', 'due_date_to', 'amount_min', 'amount_max']) ? 'true' : 'false' }};
    if (isOpen === 'true' || hasActiveFilters) {
        filterBody.classList.add('show');
        toggleIcon.classList.add('rotated');
    }

});

function updateBillStatus(billId, status) {
    if (confirm('هل أنت متأكد من تحديث حالة الفاتورة؟')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = `bills/${billId}/payment-status`;

        form.innerHTML = `
            @csrf
            @method('PATCH')
            <input type="hidden" name="payment_status" value="${status}">
        `;

        document.body.appendChild(form);
        form.submit();
    }
}
</script>
@endsection
