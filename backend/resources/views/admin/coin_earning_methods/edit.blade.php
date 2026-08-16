@extends('admin.layouts.app')

@section('page.title', 'تعديل طريقة ربح عملات')

@section('styles')
<link rel="stylesheet" href="{{ asset('admin/assets/css/admin-learning-views.css') }}">
@endsection

@section('content')
<div class="card shadow-sm border-0 coin-panel admin-learning admin-learning--coins admin-page">
    <div class="card-header bg-white py-3">
        <h5 class="mb-0 font-weight-bold">تعديل طريقة ربح عملات: {{ $coinEarningMethod->title_ar }}</h5>
    </div>
    <div class="card-body p-4">
        <form action="{{ route('admin.coin-earning-methods.update', $coinEarningMethod->id) }}" method="POST">
            @csrf
            @method('PUT')
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">العنوان (بالعربية)</label>
                    <input type="text" name="title_ar" class="form-control @error('title_ar') is-invalid @enderror" value="{{ old('title_ar', $coinEarningMethod->title_ar) }}" required>
                    @error('title_ar')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">العنوان (بالانجليزية)</label>
                    <input type="text" name="title_en" class="form-control @error('title_en') is-invalid @enderror" value="{{ old('title_en', $coinEarningMethod->title_en) }}" required>
                    @error('title_en')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">عدد العملات</label>
                    <input type="number" name="coins_amount" class="form-control @error('coins_amount') is-invalid @enderror" value="{{ old('coins_amount', $coinEarningMethod->coins_amount) }}" required>
                    @error('coins_amount')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">مفتاح الإجراء (اختياري)</label>
                    <input type="text" name="action_key" class="form-control @error('action_key') is-invalid @enderror" value="{{ old('action_key', $coinEarningMethod->action_key) }}" placeholder="مثال: complete_course">
                    @error('action_key')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">الحالة</label>
                    <select name="is_active" class="form-control">
                        <option value="1" {{ old('is_active', $coinEarningMethod->is_active) == '1' ? 'selected' : '' }}>نشط</option>
                        <option value="0" {{ old('is_active', $coinEarningMethod->is_active) == '0' ? 'selected' : '' }}>غير نشط</option>
                    </select>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">قابل للتكرار</label>
                    <select name="is_repeatable" class="form-control">
                        <option value="0" selected>مرة واحدة لكل حساب</option>
                    </select>
                </div>
            </div>
            <div class="row mt-2">
                <div class="col-md-8 mb-3">
                    <label class="form-label">رابط المهمة الخارجية (مطلوب عند اختيار زيارة الرابط)</label>
                    <input type="url" name="action_url" class="form-control @error('action_url') is-invalid @enderror" value="{{ old('action_url', $coinEarningMethod->action_url) }}">
                    @error('action_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">طريقة المطالبة</label>
                    <select name="requires_external_visit" class="form-control">
                        <option value="0" {{ old('requires_external_visit', $coinEarningMethod->requires_external_visit) == '0' ? 'selected' : '' }}>مطالبة مباشرة</option>
                        <option value="1" {{ old('requires_external_visit', $coinEarningMethod->requires_external_visit) == '1' ? 'selected' : '' }}>زيارة الرابط ثم العودة</option>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">مهلة العودة بالثواني</label>
                    <input type="number" min="0" max="300" name="verification_delay_seconds" class="form-control" value="{{ old('verification_delay_seconds', $coinEarningMethod->verification_delay_seconds ?? 3) }}">
                </div>
            </div>
            <div class="alert alert-info py-2">المهمة الخارجية تعمل بخطوتين: يبدأها الطالب ويفتح الرابط، ثم يعود للتطبيق ويطالب بالمكافأة. المكافأة تُمنح مرة واحدة فقط لكل حساب.</div>
            <div class="coin-form-actions">
                <button type="submit" class="btn btn-primary px-5 coin-form-action">تحديث</button>
                <a href="{{ route('admin.coin-earning-methods.index') }}" class="btn btn-light px-5 coin-form-action">إلغاء</a>
            </div>
        </form>
    </div>
</div>
@endsection
