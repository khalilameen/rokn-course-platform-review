@extends('admin.layouts.app')

@section('page.title', 'تعديل طريقة الدفع')

@section('styles')
<link rel="stylesheet" href="{{ asset('admin/assets/css/payment-methods-form.css') }}">
@endsection

@section('content')
<div class="payment-form-wrapper">
    <div class="payment-form-card">
        <div class="payment-form-header">
            <div class="payment-form-header-icon">
                <i class="fa fa-edit"></i>
            </div>
            <div class="payment-flex-content">
                <h2>تعديل طريقة الدفع</h2>
                <p>قم بتحديث معلومات طريقة الدفع {{ $paymentMethod->name }}</p>
                @if($paymentMethod->is_default)
                    <span class="default-badge">
                        <i class="fa fa-star"></i>
                        طريقة دفع افتراضية
                    </span>
                @endif
            </div>
        </div>
        <div class="payment-form-body">
            @if($paymentMethod->is_default)
                <div class="default-notice">
                    <i class="fa fa-info-circle"></i>
                    <div class="default-notice-content">
                        <h4>ملاحظة هامة</h4>
                        <p>
                            هذه طريقة دفع افتراضية. يجب تحديث تفاصيل الحساب الخاصة بك قبل تفعيلها.
                            @if($paymentMethod->hasDefaultAccountDetails())
                                <strong>تفاصيل الحساب الحالية افتراضية ويجب تحديثها.</strong>
                            @endif
                        </p>
                    </div>
                </div>
            @endif

            <form id="paymentMethodForm" action="{{ route('admin.payment-methods.update', $paymentMethod->id) }}" method="post" enctype="multipart/form-data">
                @csrf
                @method('PUT')
                <input type="hidden" name="confirm_account_details" id="confirmAccountDetails" value="">
                @include('admin.payment-methods._form')
            </form>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.26.25/dist/sweetalert2.all.min.js" integrity="sha384-nLoOnA/BDh8A/jxqtckg4DumuCGOBYUnNJLZdQz/zfYNp3wcjGSoWTAzgko06G/2" crossorigin="anonymous"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('paymentMethodForm');
    const isDefault = {{ $paymentMethod->is_default ? 'true' : 'false' }};
    const originalAccountDetails = @json($paymentMethod->account_details);
    const hasDefaultAccountDetails = {{ $paymentMethod->hasDefaultAccountDetails() ? 'true' : 'false' }};

    form.addEventListener('submit', function(e) {
        const accountDetailsField = document.getElementById('account_details');
        const isActiveCheckbox = document.getElementById('is_active');
        const confirmField = document.getElementById('confirmAccountDetails');
        const newAccountDetails = accountDetailsField.value;
        const accountDetailsChanged = newAccountDetails !== originalAccountDetails;
        const isActivating = isActiveCheckbox && isActiveCheckbox.checked;

        // For default methods
        if (isDefault) {
            // Check if trying to activate without updating account details
            if (isActivating && hasDefaultAccountDetails && !accountDetailsChanged) {
                e.preventDefault();
                Swal.fire({
                    title: 'تحديث تفاصيل الحساب مطلوب',
                    text: 'يجب تحديث تفاصيل الحساب قبل تفعيل طريقة الدفع الافتراضية',
                    icon: 'warning',
                    confirmButtonColor: '#2563eb',
                    confirmButtonText: 'حسناً'
                });
                return false;
            }

            // If account details changed, ask for confirmation
            if (accountDetailsChanged && confirmField.value !== '1') {
                e.preventDefault();
                Swal.fire({
                    title: 'تأكيد تحديث تفاصيل الحساب',
                    html: `
                        <p>هل أنت متأكد من تحديث تفاصيل الحساب إلى:</p>
                        <div class="payment-account-preview">
                            <strong id="paymentAccountPreview"></strong>
                        </div>
                    `,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#2563eb',
                    cancelButtonColor: '#e53e3e',
                    confirmButtonText: 'نعم، تأكيد التحديث',
                    cancelButtonText: 'إلغاء',
                    reverseButtons: true,
                    didOpen: () => {
                        const preview = document.getElementById('paymentAccountPreview');
                        if (preview) {
                            preview.textContent = newAccountDetails;
                        }
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        confirmField.value = '1';
                        form.submit();
                    }
                });
                return false;
            }
        }
    });
});
</script>
@endsection
