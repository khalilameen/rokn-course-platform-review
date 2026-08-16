@extends('admin.layouts.app')

@section('page.title', 'طرق ربح العملات')

@section('styles')
<link rel="stylesheet" href="{{ asset('admin/assets/css/admin-learning-views.css') }}">
@endsection

@section('content')
<div class="fade-in admin-learning admin-learning--coins admin-page">

    {{-- How to Use Coins Settings --}}
    <div class="card shadow-sm border-0 mb-4 coin-panel">
        <div class="card-header bg-white py-3 d-flex align-items-center">
            <i class="fa fa-info-circle text-warning ml-2"></i>
            <h6 class="mb-0 font-weight-bold">نص "كيفية استخدام العملات"</h6>
        </div>
        <div class="card-body p-4">
            <form action="{{ route('admin.coin-earning-methods.update-settings') }}" method="POST">
                @csrf
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label font-weight-bold">بالعربية</label>
                        <textarea name="how_to_use_coins_ar" rows="4"
                            class="form-control @error('how_to_use_coins_ar') is-invalid @enderror"
                            placeholder="اشرح للطالب كيف يمكنه استخدام عملاته...">{{ old('how_to_use_coins_ar', $setting?->how_to_use_coins_ar) }}</textarea>
                        @error('how_to_use_coins_ar')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label font-weight-bold">بالإنجليزية</label>
                        <textarea name="how_to_use_coins_en" rows="4"
                            class="form-control @error('how_to_use_coins_en') is-invalid @enderror"
                            placeholder="Explain to the student how they can use their coins...">{{ old('how_to_use_coins_en', $setting?->how_to_use_coins_en) }}</textarea>
                        @error('how_to_use_coins_en')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
                <button type="submit" class="btn btn-warning px-4 coin-form-action">
                    <i class="fa fa-save ml-1"></i> حفظ
                </button>
            </form>
        </div>
    </div>

    <div class="methods-header d-flex justify-content-between align-items-center">
        <div>
            <h1><i class="fa fa-coins ml-2"></i> طرق ربح العملات</h1>
            <p class="mb-0">إدارة الطرق التي يمكن للطلاب من خلالها كسب العملات</p>
        </div>
        <a href="{{ route('admin.coin-earning-methods.create') }}" class="btn btn-light btn-modern">
            <i class="fa fa-plus"></i> إضافة طريقة جديدة
        </a>
    </div>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('success') }}
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    @endif

    <div class="row">
        @forelse($methods as $method)
            <div class="col-md-6 col-lg-4">
                <div class="method-card">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <h5 class="mb-0 font-weight-bold">{{ $method->title_ar }}</h5>
                        @include('admin.partials.status-badge', [
                            'badgeStatus' => $method->is_active ? 'active' : 'unknown',
                            'badgeLabel' => $method->is_active ? 'نشط' : 'غير نشط',
                            'badgeTone' => $method->is_active ? 'success' : 'danger',
                        ])
                        @include('admin.partials.status-badge', [
                            'badgeStatus' => $method->is_repeatable ? 'active' : 'unknown',
                            'badgeLabel' => $method->is_repeatable ? 'متكرر' : 'مرة واحدة',
                            'badgeTone' => $method->is_repeatable ? 'success' : 'muted',
                        ])
                    </div>
                    <p class="text-muted mb-2">{{ $method->title_en }}</p>
                    <div class="d-flex justify-content-between align-items-center mt-4">
                        <div class="h4 mb-0 text-warning">
                            <i class="fa fa-coins"></i> {{ $method->coins_amount }}
                        </div>
                        <div class="btn-group">
                            <a href="{{ route('admin.coin-earning-methods.edit', $method->id) }}" class="btn btn-sm btn-outline-primary">
                                <i class="fa fa-edit"></i>
                            </a>
                            <form action="{{ route('admin.coin-earning-methods.destroy', $method->id) }}" method="POST" onsubmit="return confirm('هل أنت متأكد من الحذف؟')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="fa fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                    @if($method->action_key)
                        <div class="mt-2 small text-muted">
                            <code class="bg-light px-2 py-1 rounded">{{ $method->action_key }}</code>
                        </div>
                    @endif
                    <div class="mt-2 small text-muted">
                        @if($method->requires_external_visit)
                            <i class="fa fa-external-link ml-1"></i>
                            خطوتان · عودة بعد {{ $method->verification_delay_seconds }} ثوانٍ
                            @if($method->action_url)
                                <a href="{{ $method->action_url }}" target="_blank" rel="noopener noreferrer" class="mr-2">فحص الرابط</a>
                            @endif
                        @else
                            <i class="fa fa-check-circle ml-1"></i> مطالبة مباشرة داخل التطبيق
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12 text-center py-5">
                <div class="text-muted">
                    <i class="fa fa-info-circle fa-3x mb-3"></i>
                    <h4>لا توجد طرق ربح مضافة حالياً</h4>
                </div>
            </div>
        @endforelse
    </div>

    <div class="d-flex justify-content-center mt-4">
        {{ $methods->links() }}
    </div>
</div>
@endsection
