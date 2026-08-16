@extends('admin.layouts.auth')

@section('page.title', 'إعداد التحقق بخطوتين')
@section('auth.title', 'إعداد التحقق بخطوتين')
@section('auth.description', 'أضف حساب ركن إلى تطبيق المصادقة ثم أكّد الرمز الظاهر لديك.')

@section('content')
<div class="alert alert-info">
    احتفظ بالمفتاح في مكان آمن ولا ترسله لأي شخص.
</div>
<div class="form-group">
    <label>مفتاح الإعداد</label>
    <code class="admin-auth-secret">{{ $secret }}</code>
</div>
<p><a href="{{ $otpauthUri }}" rel="noreferrer"><i class="fa fa-external-link"></i> فتح تطبيق المصادقة على هذا الجهاز</a></p>

<form method="POST" action="{{ route('admin.mfa.setup.confirm') }}" autocomplete="off">
    @csrf
    <div class="form-group">
        <label for="code">الرمز المكوّن من 6 أرقام</label>
        <input id="code" name="code" class="form-control admin-value--ltr" inputmode="numeric"
               pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required autofocus>
        @error('code')<div class="text-danger mt-2" role="alert">{{ $message }}</div>@enderror
    </div>
    <button type="submit" class="btn btn-primary btn-block">
        <i class="fa fa-check"></i> تأكيد وتفعيل التحقق بخطوتين
    </button>
</form>
@endsection
