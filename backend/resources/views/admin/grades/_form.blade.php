
<div class="form-modern">
    <!-- Basic Information Section -->
    <div class="form-section">
        <h3 class="section-title">
            <i class="fa fa-info-circle"></i>
            المعلومات الأساسية
        </h3>

        <div class="row">
            <div class="col-md-{{ isset($enableEnglish) && $enableEnglish ? '6' : '12' }}">
                <div class="input-group-modern">
                    <label for="name_ar">
                        <i class="fa fa-language"></i>
                        اسم المرحلة الدراسية
                        <span class="required-asterisk">*</span>
                    </label>
                    {!! Form::text('name_ar', isset($grade) ? $grade->name_ar : null, [
                        'class' => 'form-control form-control-modern' . ($errors->has('name_ar') ? ' is-invalid' : ''),
                        'required',
                        'id' => 'name_ar',
                        'placeholder' => 'مثال: الصف الأول الابتدائي'
                    ]) !!}
                    @if ($errors->has('name_ar'))
                        <div class="invalid-feedback">
                            <i class="fa fa-exclamation-circle ml-1"></i>
                            {{ $errors->first('name_ar') }}
                        </div>
                    @endif
                    <div class="form-help-text">أدخل اسم المرحلة الدراسية</div>
                </div>
            </div>

            @if(isset($enableEnglish) && $enableEnglish)
            <div class="col-md-6">
                <div class="input-group-modern">
                    <label for="name_en">
                        <i class="fa fa-globe"></i>
                        اسم المرحلة الدراسية (إنجليزي)
                    </label>
                    {!! Form::text('name_en', isset($grade) ? $grade->name_en : null, [
                        'class' => 'form-control form-control-modern' . ($errors->has('name_en') ? ' is-invalid' : ''),
                        'id' => 'name_en',
                        'placeholder' => 'Example: First Grade Primary'
                    ]) !!}
                    @if ($errors->has('name_en'))
                        <div class="invalid-feedback">
                            <i class="fa fa-exclamation-circle ml-1"></i>
                            {{ $errors->first('name_en') }}
                        </div>
                    @endif
                    <div class="form-help-text">أدخل اسم المرحلة الدراسية باللغة الإنجليزية (اختياري)</div>
                </div>
            </div>
            @endif
        </div>

        <div class="row">
            <div class="col-md-6">
                <div class="input-group-modern">
                    <div class="select-modern">
                        <label for="type">
                            <i class="fa fa-graduation-cap"></i>
                            نوع المرحلة
                            <span class="required-asterisk">*</span>
                        </label>
                        <select name="type" id="type" class="form-control form-control-modern{{ $errors->has('type') ? ' is-invalid' : '' }}" required>
                            <option value="">اختر نوع المرحلة</option>
                            <option value="primary" {{ (isset($grade) && $grade->type == 'primary') ? 'selected' : '' }}>
                                📚 المرحلة الابتدائية
                            </option>
                            <option value="preparatory" {{ (isset($grade) && $grade->type == 'preparatory') ? 'selected' : '' }}>
                                📚 المرحلة الإعدادية
                            </option>
                            <option value="secondary" {{ (isset($grade) && $grade->type == 'secondary') ? 'selected' : '' }}>
                                📚 المرحلة الثانوية
                            </option>
                            <option value="university" {{ (isset($grade) && $grade->type == 'university') ? 'selected' : '' }}>
                                🎓 المرحلة الجامعية
                            </option>
                            <option value="general" {{ (isset($grade) && $grade->type == 'general') ? 'selected' : '' }}>
                                📖 المرحلة العامة
                            </option>
                        </select>
                        @if ($errors->has('type'))
                            <div class="invalid-feedback">
                                <i class="fa fa-exclamation-circle ml-1"></i>
                                {{ $errors->first('type') }}
                            </div>
                        @endif
                    </div>
                    <div class="form-help-text">حدد نوع المرحلة الدراسية</div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="input-group-modern">
                    <div class="select-modern country-select">
                        <label for="country">
                            <i class="fa fa-flag"></i>
                            البلد
                            <span class="required-asterisk">*</span>
                        </label>
                        <select name="country" id="country" class="form-control form-control-modern{{ $errors->has('country') ? ' is-invalid' : '' }}" required>
                            <option value="egypt" {{ (isset($grade) && $grade->country == 'egypt') ? 'selected' : (!isset($grade) ? 'selected' : '') }}>
                                🇪🇬 مصر
                            </option>
                            <option value="saudi" {{ (isset($grade) && $grade->country == 'saudi') ? 'selected' : '' }}>
                                🇸🇦 السعودية
                            </option>
                            <option value="uae" {{ (isset($grade) && $grade->country == 'uae') ? 'selected' : '' }}>
                                🇦🇪 الإمارات العربية المتحدة
                            </option>
                            <option value="kuwait" {{ (isset($grade) && $grade->country == 'kuwait') ? 'selected' : '' }}>
                                🇰🇼 الكويت
                            </option>
                            <option value="qatar" {{ (isset($grade) && $grade->country == 'qatar') ? 'selected' : '' }}>
                                🇶🇦 قطر
                            </option>
                        </select>
                        @if ($errors->has('country'))
                            <div class="invalid-feedback">
                                <i class="fa fa-exclamation-circle ml-1"></i>
                                {{ $errors->first('country') }}
                            </div>
                        @endif
                    </div>
                    <div class="form-help-text">اختر البلد المناسب للمنهج</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Description Section -->
    <div class="form-section">
        <h3 class="section-title">
            <i class="fa fa-align-left"></i>
            الوصف والتفاصيل
        </h3>

        <div class="row">
            <div class="col-md-{{ isset($enableEnglish) && $enableEnglish ? '6' : '12' }}">
                <div class="input-group-modern">
                    <label for="description_ar">
                        <i class="fa fa-edit"></i>
                        وصف المرحلة
                    </label>
                    {!! Form::textarea('description_ar', isset($grade) ? $grade->description_ar : null, [
                        'class' => 'form-control form-control-modern' . ($errors->has('description_ar') ? ' is-invalid' : ''),
                        'rows' => 4,
                        'id' => 'description_ar',
                        'placeholder' => 'أدخل وصفاً مفصلاً للمرحلة الدراسية...'
                    ]) !!}
                    @if ($errors->has('description_ar'))
                        <div class="invalid-feedback">
                            <i class="fa fa-exclamation-circle ml-1"></i>
                            {{ $errors->first('description_ar') }}
                        </div>
                    @endif
                    <div class="form-help-text">وصف تفصيلي للمرحلة الدراسية وأهدافها</div>
                </div>
            </div>

            @if(isset($enableEnglish) && $enableEnglish)
            <div class="col-md-6">
                <div class="input-group-modern">
                    <label for="description_en">
                        <i class="fa fa-edit"></i>
                        وصف المرحلة (إنجليزي)
                    </label>
                    {!! Form::textarea('description_en', isset($grade) ? $grade->description_en : null, [
                        'class' => 'form-control form-control-modern' . ($errors->has('description_en') ? ' is-invalid' : ''),
                        'rows' => 4,
                        'id' => 'description_en',
                        'placeholder' => 'Enter detailed description for the grade level...'
                    ]) !!}
                    @if ($errors->has('description_en'))
                        <div class="invalid-feedback">
                            <i class="fa fa-exclamation-circle ml-1"></i>
                            {{ $errors->first('description_en') }}
                        </div>
                    @endif
                    <div class="form-help-text">تفاصيل المرحلة الدراسية باللغة الإنجليزية (اختياري)</div>
                </div>
            </div>
            @endif
        </div>
    </div>

    <!-- Form Actions -->
    <div class="form-actions">
        <button type="submit" class="btn btn-success-modern btn-modern" id="submitBtn">
            <i class="fa fa-save ml-1"></i>
            حفظ المرحلة الدراسية
        </button>
        <a href="{{ route('admin.grades.index') }}" class="btn btn-secondary-modern btn-modern">
            <i class="fa fa-arrow-right ml-1"></i>
            العودة للقائمة
        </a>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Form validation enhancement
    const form = document.querySelector('form');
    const submitBtn = document.getElementById('submitBtn');

    form.addEventListener('submit', function() {
        submitBtn.innerHTML = '<i class="fa fa-spinner fa-spin ml-1"></i> جاري الحفظ...';
        submitBtn.disabled = true;
    });

    // Real-time validation feedback
    const requiredFields = ['name_ar', 'type', 'country'];
    requiredFields.forEach(fieldName => {
        const field = document.getElementById(fieldName);
        if (field) {
            field.addEventListener('blur', function() {
                if (this.value.trim() === '') {
                    this.classList.add('is-invalid');
                } else {
                    this.classList.remove('is-invalid');
                    this.classList.add('is-valid');
                }
            });

            field.addEventListener('input', function() {
                if (this.value.trim() !== '') {
                    this.classList.remove('is-invalid');
                }
            });
        }
    });

    // Auto-generate English name from Arabic (optional)
    const nameAr = document.getElementById('name_ar');
    const nameEn = document.getElementById('name_en');

    @if(isset($enableEnglish) && $enableEnglish)
    if (nameAr && nameEn && !nameEn.value) {
        nameAr.addEventListener('blur', function() {
            if (this.value && !nameEn.value) {
                // Simple transliteration suggestions
                const arabicToEnglish = {
                    'الأول': 'First',
                    'الثاني': 'Second',
                    'الثالث': 'Third',
                    'الرابع': 'Fourth',
                    'الخامس': 'Fifth',
                    'السادس': 'Sixth',
                    'الابتدائي': 'Primary',
                    'الإعدادي': 'Preparatory',
                    'الثانوي': 'Secondary',
                    'ثانوي': 'Secondary',
                    'الجامعي': 'University',
                    'جامعي': 'University',
                    'الجامعية': 'University',
                    'جامعية': 'University',
                    'العام': 'General',
                    'عام': 'General',
                    'العامة': 'General',
                    'عامة': 'General'
                };

                let suggestion = this.value;
                Object.keys(arabicToEnglish).forEach(arabic => {
                    suggestion = suggestion.replace(arabic, arabicToEnglish[arabic]);
                });

                if (suggestion !== this.value) {
                    nameEn.value = suggestion;
                    nameEn.style.backgroundColor = '#e6fffa';
                    setTimeout(() => {
                        nameEn.style.backgroundColor = '';
                    }, 2000);
                }
            }
        });
    }
    @endif
});
</script>
