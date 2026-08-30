@extends('admin.layouts.app')

@section('page.title', 'طرق الدفع')

@section('styles')
<link rel="stylesheet" href="{{ asset('admin/assets/css/payment-methods-index.css') }}">
@endsection

@section('content')
<div class="payment-methods-wrapper">
    <div class="page-header-payment">
        <div class="header-icon-wrapper">
            <i class="fa fa-credit-card"></i>
        </div>
        <div class="payment-flex-content">
            <h1>طرق الدفع</h1>
            <p>إدارة وتنظيم طرق الدفع المتاحة للطلاب</p>
        </div>
        <a href="{{ route('admin.payment-methods.create') }}" class="btn-add-payment">
            <i class="fa fa-plus"></i>
            <span>إضافة طريقة دفع</span>
        </a>
    </div>

    <div class="payment-card">
        <div class="payment-card-header">
            <h3>
                <i class="fa fa-list"></i>
                قائمة طرق الدفع
            </h3>
            <span class="payment-methods-count">
                <i class="fa fa-info-circle"></i>
                {{ $paymentMethods->count() }} طريقة دفع
            </span>
        </div>

        <div class="payment-card-body">
            @if($paymentMethods->isEmpty())
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <i class="fa fa-credit-card"></i>
                    </div>
                    <h3>لا توجد طرق دفع مضافة</h3>
                    <p>ابدأ بإضافة طرق الدفع المتاحة لطلابك مثل فودافون كاش، انستاباي، وغيرها</p>
                    <a href="{{ route('admin.payment-methods.create') }}" class="btn-add-payment">
                        <i class="fa fa-plus"></i>
                        <span>إضافة أول طريقة دفع</span>
                    </a>
                </div>
            @else
                <div class="payment-methods-grid">
                    @foreach($paymentMethods as $paymentMethod)
                        <div class="payment-method-item">
                            <div class="payment-method-header">
                                <h4 class="payment-method-name">
                                    <i class="fa fa-money"></i>
                                    {{ $paymentMethod->name }}
                                </h4>
                                <div class="payment-method-statuses">
                                    <span class="status-badge {{ $paymentMethod->is_active ? 'active' : 'inactive' }}">
                                        {{ $paymentMethod->is_active ? 'نشط' : 'غير نشط' }}
                                    </span>
                                    @if($paymentMethod->is_default)
                                        <span class="status-badge default">
                                            <i class="fa fa-star"></i> افتراضي
                                        </span>
                                    @endif
                                </div>
                            </div>

                            @if($paymentMethod->account_details || $paymentMethod->description)
                                <div class="payment-method-details">
                                    @if($paymentMethod->account_details)
                                        <div class="detail-row">
                                            <i class="fa fa-info-circle"></i>
                                            <div class="payment-flex-content">
                                                <div class="detail-label">تفاصيل الحساب</div>
                                                <div class="detail-value">{{ $paymentMethod->account_details }}</div>
                                            </div>
                                        </div>
                                    @endif

                                    @if($paymentMethod->description)
                                        <div class="detail-row">
                                            <i class="fa fa-align-left"></i>
                                            <div class="payment-flex-content">
                                                <div class="detail-label">الوصف</div>
                                                <div class="detail-value">{{ $paymentMethod->description }}</div>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            @endif

                            <div class="payment-method-actions">
                                <a href="{{ route('admin.payment-methods.edit', $paymentMethod->id) }}" class="btn-action btn-edit">
                                    <i class="fa fa-edit"></i>
                                    تعديل
                                </a>
                                @if(!$paymentMethod->is_default)
                                    <button type="button" onclick="confirmDelete({{ $paymentMethod->id }}, '{{ addslashes($paymentMethod->name) }}')" class="btn-action btn-delete">
                                        <i class="fa fa-trash"></i>
                                        حذف
                                    </button>
                                @endif
                            </div>

                            @if(!$paymentMethod->is_default)
                                <form class="payment-method-delete-form" id="deleteForm{{$paymentMethod->id}}" action="{{ route('admin.payment-methods.destroy', $paymentMethod->id) }}" method="post">
                                    <input name="_method" type="hidden" value="DELETE">
                                    @csrf
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.26.25/dist/sweetalert2.all.min.js" integrity="sha384-nLoOnA/BDh8A/jxqtckg4DumuCGOBYUnNJLZdQz/zfYNp3wcjGSoWTAzgko06G/2" crossorigin="anonymous"></script>
<script>
function confirmDelete(id, name) {
    Swal.fire({
        title: 'تأكيد الحذف',
        text: `هل أنت متأكد من حذف طريقة الدفع "${name}"؟`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e53e3e',
        cancelButtonColor: '#2563eb',
        confirmButtonText: 'نعم، احذف',
        cancelButtonText: 'إلغاء',
        reverseButtons: true
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById('deleteForm' + id).submit();
        }
    });
}

// Add animation on scroll
const observerOptions = {
    threshold: 0.1,
    rootMargin: '0px 0px -50px 0px'
};

const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            entry.target.style.animation = 'fadeInUp 0.6s ease forwards';
        }
    });
}, observerOptions);

document.querySelectorAll('.payment-method-item').forEach(item => {
    observer.observe(item);
});
</script>
@endsection
