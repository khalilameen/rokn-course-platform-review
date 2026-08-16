@extends('admin.layouts.auth')

@section('page.title', 'التحقق بخطوتين')
@section('auth.title', 'التحقق بخطوتين')
@section('auth.description', 'أدخل الرمز الحالي من تطبيق المصادقة أو أحد رموز الاسترداد.')

@section('content')
<form method="POST" action="{{ route('admin.mfa.challenge.verify') }}" autocomplete="off">
    @csrf
    <div class="form-group">
        <label for="code">رمز التحقق</label>
        <input id="code" name="code" class="form-control admin-value--ltr" maxlength="32"
               autocomplete="one-time-code" required autofocus>
        @error('code')<div class="text-danger mt-2" role="alert">{{ $message }}</div>@enderror
    </div>
    <button type="submit" class="btn btn-primary btn-block">
        <i class="fa fa-shield"></i> متابعة
    </button>
</form>
@endsection
