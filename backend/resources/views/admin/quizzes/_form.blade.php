
<div class="form-section">
    <div class="row form-group">
        <div class="col-md-6">
            <label for="title_ar" class="form-label-modern">
                <i class="fa fa-heading"></i>
                أسم الاختبار (بالعربية) *
            </label>
            {!! Form::text('title_ar', null, ['class' => 'form-control form-control-modern', 'required', 'id'=>"title_ar", 'placeholder' => 'أدخل عنوان الاختبار بالعربية'] )!!}
        </div>
        <div class="col-md-6">
            <label for="title_en" class="form-label-modern">
                <i class="fa fa-heading"></i>
                أسم الاختبار (بالإنجليزية)
            </label>
            {!! Form::text('title_en', null, ['class' => 'form-control form-control-modern', 'id'=>"title_en", 'placeholder' => 'Enter quiz title in English'] )!!}
        </div>
    </div>
</div>

<input type="hidden" name="type" value="quiz">

<div class="form-section">
    <div class="row form-group">
        <div class="col-12">
            <label for="image" class="form-label-modern">
                <i class="fa fa-image"></i>
                صورة الاختبار
            </label>
            @if(isset($quiz->image))
                <div class="image-preview-wrapper">
                    <img src="{{ $quiz->image }}" class="image-preview" alt="صورة الاختبار" />
                </div>
            @endif
            <div class="file-input-wrapper">
                <input id="image" name="image" class="form-control-file" type="file" accept="image/*">
                <label class="file-input-label">
                    <i class="fa fa-cloud-upload"></i>
                    اختر صورة للاختبار
                </label>
            </div>
        </div>
    </div>
</div>

<div class="form-section">
    <div class="row form-group">
        <div class="col-12">
            <label for="category_id" class="form-label-modern">
                <i class="fa fa-question-circle"></i>
                الأسئلة
            </label>
            <select class="questions form-control form-control-modern" name="questions[]" multiple="multiple">
                @foreach($questions as $question)
                    <option value="{{ $question->id }}" {{ isset($quizQuestions) ? (@in_array($question->id,$quizQuestions))? 'selected':'' : '' }}>{{ $question->title }}</option>
                @endforeach
            </select>
            <small class="help-text">
                <i class="fa fa-info-circle"></i>
                يمكنك اختيار أسئلة متعددة من القائمة
            </small>
        </div>
    </div>
</div>

<div class="form-section">
    <div class="row">
        <div class="col-md-6">
            <div class="form-group">
                <label for="priority" class="form-label-modern">
                    <i class="fa fa-sort-numeric-asc"></i>
                    درجة الظهور
                </label>
                {!! Form::number('priority', null, ['class' => 'form-control form-control-modern', 'id'=>"priority", 'placeholder' => 'الأعلى يظهر أولاً'] )!!}
                <small class="help-text">
                    <i class="fa fa-info-circle"></i>
                    الاختبارات ذات الأولوية الأعلى تظهر أولاً
                </small>
            </div>
        </div>

        <div class="col-md-6">
            <div class="form-group">
                <label for="time_minutes" class="form-label-modern">
                    <i class="fa fa-clock-o"></i>
                    مدة الاختبار (بالدقائق)
                </label>
                {!! Form::number('time_minutes', null, ['class' => 'form-control form-control-modern', 'min' => '1', 'max' => '300', 'id'=>"time_minutes", 'placeholder' => 'مثال: 30'] )!!}
                <small class="help-text">
                    <i class="fa fa-info-circle"></i>
                    أدخل مدة الاختبار من 1 إلى 300 دقيقة
                </small>
            </div>
        </div>
    </div>
</div>

<div class="form-section">
    <div class="row form-group">
        <div class="col-md-6">
            <label for="description_ar" class="form-label-modern">
                <i class="fa fa-align-right"></i>
                وصف الاختبار (بالعربية)
            </label>
            {!! Form::textarea('description_ar', null, ['class' => 'form-control form-control-modern', 'id'=>"description_ar", 'rows' => 4, 'placeholder' => 'أدخل وصفاً تفصيلياً للاختبار بالعربية'] )!!}
        </div>
        <div class="col-md-6">
            <label for="description_en" class="form-label-modern">
                <i class="fa fa-align-left"></i>
                وصف الاختبار (بالإنجليزية)
            </label>
            {!! Form::textarea('description_en', null, ['class' => 'form-control form-control-modern', 'id'=>"description_en", 'rows' => 4, 'placeholder' => 'Enter detailed quiz description in English'] )!!}
        </div>
    </div>
</div>

<div class="form-actions-modern">
    <button type="submit" class="btn-save-modern">
        <i class="fa fa-check-circle"></i>
        حفظ الاختبار
    </button>
    @if(request('course_id'))
        <a href="{{ route('admin.courses.sections.index', request('course_id')) }}" class="btn-cancel-modern">
            <i class="fa fa-times-circle"></i>
            إلغاء
        </a>
    @else
        <a href="{{ route('admin.courses.index') }}" class="btn-cancel-modern">
            <i class="fa fa-times-circle"></i>
            إلغاء
        </a>
    @endif
</div>

@section('scripts')
<script>
    $(document).ready(function() {
        $('.questions').select2({
            placeholder: 'اختر الأسئلة',
            allowClear: true
        });

        // File input preview
        $('#image').change(function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const preview = $('.image-preview');
                    if (preview.length) {
                        preview.attr('src', e.target.result);
                    } else {
                        $('.file-input-wrapper').before(
                            '<div class="image-preview-wrapper"><img src="' + e.target.result + '" class="image-preview" alt="معاينة الصورة" /></div>'
                        );
                    }
                }
                reader.readAsDataURL(file);
            }
        });

        // Delegated so existing and dynamically added question images share one handler.
        $(document).on('change', '.q_image', function(e) {
            const file = e.target.files[0];
            if (!file) {
                return;
            }

            const image = $(this).closest('.image-upload-section').find('.ico_cat');
            const reader = new FileReader();
            reader.onload = function(event) {
                image.removeClass('question-image-preview--empty').attr('src', event.target.result).show();
            };
            reader.readAsDataURL(file);
        });
    });
</script>
@endsection
