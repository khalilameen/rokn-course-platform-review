<div class="form-row-modern">
    <div class="form-group-modern">
        <label for="name" class="form-label-modern">
            <i class="fa fa-tag"></i>
            اسم طريقة الدفع <span class="text-danger">*</span>
        </label>
        <input type="text" id="name" name="name" placeholder="مثال: فودافون كاش، انستاباي" class="form-control-modern {{ isset($paymentMethod) && $paymentMethod->is_default ? 'readonly-field' : '' }}" value="{{ old('name', $paymentMethod->name ?? '') }}" required {{ isset($paymentMethod) && $paymentMethod->is_default ? 'readonly' : '' }}>
        @error('name')
            <small class="error-text"><i class="fa fa-exclamation-circle"></i> {{ $message }}</small>
        @enderror
        <small class="helper-text-modern">
            <i class="fa fa-info-circle"></i>
            @if(isset($paymentMethod) && $paymentMethod->is_default)
                لا يمكن تغيير اسم طريقة الدفع الافتراضية
            @else
                أدخل اسم طريقة الدفع كما تريد أن يظهر للطلاب
            @endif
        </small>
    </div>
</div>

<div class="form-row-modern">
    <div class="form-group-modern">
        <label for="account_details" class="form-label-modern">
            <i class="fa fa-info-circle"></i>
            تفاصيل الحساب <span class="text-danger">*</span>
        </label>
        <textarea id="account_details" name="account_details" rows="4" placeholder="رقم الهاتف، رقم الحساب، أو أي تفاصيل أخرى" class="form-control-modern" required>{{ old('account_details', $paymentMethod->account_details ?? '') }}</textarea>
        @error('account_details')
            <small class="error-text"><i class="fa fa-exclamation-circle"></i> {{ $message }}</small>
        @enderror
        <small class="helper-text-modern">
            <i class="fa fa-lightbulb-o"></i>
            أدخل المعلومات التي يحتاجها الطالب للدفع (رقم المحفظة، رقم الحساب، إلخ)
        </small>
    </div>
</div>

<div class="form-row-modern">
    <div class="form-group-modern">
        <label for="description" class="form-label-modern">
            <i class="fa fa-align-left"></i>
            وصف إضافي (اختياري)
        </label>
        <textarea id="description" name="description" rows="3" placeholder="أي ملاحظات أو تعليمات إضافية للطلاب" class="form-control-modern">{{ old('description', $paymentMethod->description ?? '') }}</textarea>
        @error('description')
            <small class="error-text"><i class="fa fa-exclamation-circle"></i> {{ $message }}</small>
        @enderror
        <small class="helper-text-modern">
            <i class="fa fa-comment-o"></i>
            يمكنك إضافة تعليمات أو ملاحظات إضافية تساعد الطلاب
        </small>
    </div>
</div>

<div class="form-row-modern">
    <div class="switch-container-modern">
        <label class="switch-toggle-modern">
            <input type="checkbox" id="is_active" name="is_active" value="1" {{ old('is_active', $paymentMethod->is_active ?? true) ? 'checked' : '' }}>
            <span class="switch-slider-modern"></span>
        </label>
        <div class="switch-label-modern">
            <strong><i class="fa fa-check-circle"></i> تفعيل طريقة الدفع</strong>
            <p>إذا كانت مفعلة، سيتمكن الطلاب من رؤيتها واستخدامها عند الدفع</p>
        </div>
    </div>
</div>

<div class="form-actions-modern">
    <button type="submit" class="btn-modern btn-primary-modern">
        <span class="icon-badge-modern">
            <i class="fa fa-save"></i>
        </span>
        <span>حفظ طريقة الدفع</span>
    </button>
    <a href="{{ route('admin.payment-methods.index') }}" class="btn-modern btn-secondary-modern">
        <span class="icon-badge-modern">
            <i class="fa fa-arrow-right"></i>
        </span>
        <span>رجوع</span>
    </a>
</div>
