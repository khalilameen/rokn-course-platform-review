@extends('admin.layouts.app')

@section('page.title', 'إضافة طريقة دفع')

@section('styles')
<link rel="stylesheet" href="{{ asset('admin/assets/css/payment-methods-form.css') }}">
@endsection

@section('content')
<div class="payment-form-wrapper">
    <div class="payment-form-card">
        <div class="payment-form-header">
            <div class="payment-form-header-icon">
                <i class="fa fa-plus-circle"></i>
            </div>
            <div class="payment-flex-content">
                <h2>إضافة طريقة دفع جديدة</h2>
                <p>أضف طريقة دفع جديدة لتمكين الطلاب من الدفع بها</p>
            </div>
        </div>
        <div class="payment-form-body">
            <form action="{{ route('admin.payment-methods.store') }}" method="post" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="authoring_request_id" value="{{ old('authoring_request_id', (string) \Illuminate\Support\Str::uuid()) }}">
                @include('admin.payment-methods._form')
            </form>
        </div>
    </div>
</div>
@endsection
