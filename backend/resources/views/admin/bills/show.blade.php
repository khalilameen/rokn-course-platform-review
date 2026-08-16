@extends('admin.layouts.app')
@section('page.title', 'مشاهدة الفاتورة ' . $bill->bill_number)

@section('styles')
<link rel="stylesheet" href="{{ asset('admin/assets/css/bills-show.css') }}">
@endsection

@section('content')
    <div class="mb-3">
        <a href="{{ route('admin.bills.index') }}" class="btn btn-outline-secondary">
            <i class="fa fa-arrow-right"></i> العودة للقائمة
        </a>
    </div>

    <div class="row">
        @include('admin.bills.partials.show-details')

        @include('admin.bills.partials.show-actions')
    </div>
@endsection

@section('scripts')
<script>
function updatePaymentStatus(status) {
    if (confirm('هل أنت متأكد من تحديث حالة الدفع؟')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '{{ route("admin.bills.update-payment-status", $bill) }}';

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
