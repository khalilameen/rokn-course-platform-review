<div class="form-section dynamic-form" id="project-form" data-type="project">
    <div class="section-header">
        <div class="section-icon"><i class="fa fa-project-diagram"></i></div>
        <h3 class="section-title">تفاصيل المشروع</h3>
    </div>
    <div class="alert alert-info">
        مشروع العبور اختياري
        أضفه فقط للوحدات التي تحتاج تطبيقًا عمليًا
        عند وجوده يصبح آخر عنصر في الوحدة ولا تفتح الوحدة التالية قبل اجتيازه
    </div>
    <div class="row">
        <div class="col-md-6"><div class="form-group">
            <label class="form-label">أقصى عدد لملفات التسليم</label>
            <input type="number" name="submission_max_files" class="form-control" value="{{ old('submission_max_files', 3) }}" min="1" max="5">
        </div></div>
        <div class="col-md-6"><div class="form-group">
            <label class="form-label">الصيغ المتاحة</label>
            <select name="submission_allowed_mime_types[]" class="form-control" multiple>
                @foreach(app(\App\Services\AiInputAttachmentService::class)->allowedMimeTypes() as $mime)
                    <option value="{{ $mime }}" selected>{{ $mime }}</option>
                @endforeach
            </select>
        </div></div>
    </div>
    <div class="row">
        <div class="col-md-6"><div class="form-group">
            <label class="form-label">متطلبات المشروع بالعربية *</label>
            <textarea name="project_requirements_ar" class="form-control" rows="5" placeholder="اكتب المطلوب من الطالب بوضوح" data-required="true">{{ old('project_requirements_ar') }}</textarea>
        </div></div>
        <div class="col-md-6"><div class="form-group">
            <label class="form-label">متطلبات المشروع بالإنجليزية</label>
            <textarea name="project_requirements_en" class="form-control" rows="5">{{ old('project_requirements_en') }}</textarea>
        </div></div>
    </div>
    <div class="custom-control custom-checkbox mt-2">
        <input type="checkbox" name="is_graduation_project" class="custom-control-input" id="is_graduation_project" {{ old('is_graduation_project') ? 'checked' : '' }}>
        <label class="custom-control-label" for="is_graduation_project">مشروع تخرج في نهاية الكورس</label>
    </div>
</div>
