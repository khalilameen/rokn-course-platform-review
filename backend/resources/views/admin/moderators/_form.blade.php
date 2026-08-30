@csrf
@if(isset($moderator)) @method('PUT') @endif
<div class="form-group">
    <label for="name_ar">الاسم بالعربية</label>
    <input id="name_ar" name="name_ar" class="form-control" required maxlength="255" value="{{ old('name_ar', $moderator->name_ar ?? '') }}">
</div>
<div class="form-group">
    <label for="name_en">الاسم بالإنجليزية</label>
    <input id="name_en" name="name_en" class="form-control" maxlength="255" value="{{ old('name_en', $moderator->name_en ?? '') }}">
</div>
<div class="form-row">
    <div class="form-group col-md-6">
        <label for="email">البريد المستخدم للدخول</label>
        <input id="email" name="email" type="email" class="form-control" required value="{{ old('email', $moderator->email ?? '') }}">
    </div>
    <div class="form-group col-md-6">
        <label for="phone">الهاتف (اختياري)</label>
        <input id="phone" name="phone" class="form-control" maxlength="20" value="{{ old('phone', $moderator->phone ?? '') }}">
    </div>
</div>
<div class="form-row">
    <div class="form-group col-md-6">
        <label for="password">كلمة المرور {{ isset($moderator) ? '(اتركها فارغة دون تغيير)' : '' }}</label>
        <input id="password" name="password" type="password" class="form-control" minlength="10" {{ isset($moderator) ? '' : 'required' }} autocomplete="new-password">
    </div>
    <div class="form-group col-md-6">
        <label for="password_confirmation">تأكيد كلمة المرور</label>
        <input id="password_confirmation" name="password_confirmation" type="password" class="form-control" minlength="10" {{ isset($moderator) ? '' : 'required' }} autocomplete="new-password">
    </div>
</div>
<input type="hidden" name="active" value="0">
<label class="mb-3"><input type="checkbox" name="active" value="1" {{ old('active', $moderator->active ?? true) ? 'checked' : '' }}> الحساب نشط</label>

@if($errors->any())
    <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif
