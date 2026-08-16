@extends('admin.layouts.auth')

@section('page.title', 'رموز استرداد الإدارة')
@section('auth.title', 'احفظ رموز الاسترداد الآن')
@section('auth.description', 'لن تظهر هذه الرموز مرة أخرى، وكل رمز صالح للاستخدام مرة واحدة فقط.')

@section('content')
<div class="alert alert-warning">
    خزّن نسخة آمنة خارج هذا الجهاز قبل متابعة العمل.
</div>
<ol class="admin-recovery-list">
    @foreach($codes as $code)
        <li>{{ $code }}</li>
    @endforeach
</ol>
<a href="{{ route('admin.dashboard') }}" class="btn btn-primary btn-block mt-4">
    <i class="fa fa-dashboard"></i> الانتقال إلى لوحة التحكم
</a>
@endsection
