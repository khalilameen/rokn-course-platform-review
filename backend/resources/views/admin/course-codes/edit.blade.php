@extends('admin.layouts.app')

@section('page.title', 'تعديل الكود')

@section('styles')
{{-- Include Dynamic Theme Styles --}}
@include('admin.course-codes.partials._dynamic_styles')

<link rel="stylesheet" href="{{ asset('admin/assets/css/course-codes-edit.css') }}">
@endsection

@section('content')
<div class="admin-page content course-codes-page">
    <div class="animated fadeIn">
        <!-- Page Header -->
        <div class="page-header modern-header">
            <h1><i class="fa fa-edit"></i> تعديل الكود: {{ $courseCode->code }}</h1>
        </div>

        <div class="modern-card">
            <div class="modern-card-header">
                <h4><i class="fa fa-pencil"></i> تحديث معلومات الكود</h4>
            </div>
            <div class="modern-card-body">
                <form method="POST" action="{{ route('admin.course-codes.update', $courseCode) }}" id="edit-code-form">
                            @csrf
                            @method('PUT')

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="name">اسم الكود (اختياري)</label>
                                        <input type="text" name="name" id="name" class="form-control" value="{{ old('name', $courseCode->name) }}" placeholder="مثال: كود خاص للطلاب المتفوقين">
                                        @error('name')
                                            <span class="text-danger"><small>{{ $message }}</small></span>
                                        @enderror
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="max_uses">عدد مرات الاستخدام</label>
                                        <input type="number" name="max_uses" id="max_uses" class="form-control" value="{{ old('max_uses', $courseCode->max_uses) }}" min="1" max="10000">
                                        <small class="text-muted">الاستخدامات الحالية: <strong>{{ $courseCode->used_count }}</strong></small>
                                        @error('max_uses')
                                            <span class="text-danger"><small>{{ $message }}</small></span>
                                        @enderror
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="type">نوع الكود</label>
                                        <select name="type" id="type" class="form-control" required>
                                            <option value="">اختر النوع</option>
                                            <option value="course" {{ old('type', $courseCode->type) == 'course' ? 'selected' : '' }}>دورة</option>
                                            <option value="lesson" {{ old('type', $courseCode->type) == 'lesson' ? 'selected' : '' }}>درس</option>
                                            <option value="multiple_lessons" {{ old('type', $courseCode->type) == 'multiple_lessons' ? 'selected' : '' }}>دروس متعددة</option>
                                        </select>
                                        @error('type')
                                            <span class="text-danger"><small>{{ $message }}</small></span>
                                        @enderror
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="is_active">الحالة</label>
                                        <select name="is_active" id="is_active" class="form-control">
                                            <option value="1" {{ old('is_active', $courseCode->is_active) ? 'selected' : '' }}>مفعل</option>
                                            <option value="0" {{ old('is_active', $courseCode->is_active) ? '' : 'selected' }}>معطل</option>
                                        </select>
                                        @error('is_active')
                                            <span class="text-danger"><small>{{ $message }}</small></span>
                                        @enderror
                                    </div>
                                </div>
                            </div>

                            <!-- Course Selection -->
                            <div class="selection-section" id="course-selection">
                                <div class="row">
                                    <div class="col-md-12">
                                        <div class="form-group">
                                            <label for="course_id"><i class="fa fa-graduation-cap"></i> اختر الدورة</label>
                                            <select name="course_id" id="course_id" class="form-control">
                                                <option value="">اختر الدورة</option>
                                                @foreach($courses as $course)
                                                    <option value="{{ $course->id }}" {{ old('course_id', $courseCode->course_id) == $course->id ? 'selected' : '' }}>
                                                        {{ $course->name_ar }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            @error('course_id')
                                                <span class="text-danger"><small>{{ $message }}</small></span>
                                            @enderror
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Lesson Selection -->
                            <div class="selection-section" id="lesson-selection">
                                <div class="row">
                                    <div class="col-md-12">
                                        <div class="form-group">
                                            <label for="lesson_id"><i class="fa fa-book"></i> اختر الدرس</label>
                                            <select name="lesson_id" id="lesson_id" class="form-control">
                                                <option value="">اختر الدرس</option>
                                                @foreach($lessons as $lesson)
                                                    <option value="{{ $lesson->id }}" {{ old('lesson_id', $courseCode->lesson_id) == $lesson->id ? 'selected' : '' }}>
                                                        {{ $lesson->title }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            @error('lesson_id')
                                                <span class="text-danger"><small>{{ $message }}</small></span>
                                            @enderror
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Multiple Lessons Selection -->
                            <div class="selection-section" id="multiple-lessons-selection">
                                <div class="row">
                                    <div class="col-md-12">
                                        <div class="form-group">
                                            <label><i class="fa fa-list"></i> اختر الدروس</label>
                                            <div id="lessons-container" class="mt-3">
                                                <!-- Lessons will be loaded here via AJAX -->
                                            </div>
                                            @error('lesson_ids')
                                                <span class="text-danger"><small>{{ $message }}</small></span>
                                            @enderror
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="start_date"><i class="fa fa-calendar"></i> تاريخ البداية (اختياري)</label>
                                        <input type="datetime-local" name="start_date" id="start_date" class="form-control"
                                               value="{{ old('start_date', $courseCode->start_date ? $courseCode->start_date->format('Y-m-d\TH:i') : '') }}">
                                        @error('start_date')
                                            <span class="text-danger"><small>{{ $message }}</small></span>
                                        @enderror
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="expiry_date"><i class="fa fa-calendar-times-o"></i> تاريخ الانتهاء (اختياري)</label>
                                        <input type="datetime-local" name="expiry_date" id="expiry_date" class="form-control"
                                               value="{{ old('expiry_date', $courseCode->expiry_date ? $courseCode->expiry_date->format('Y-m-d\TH:i') : '') }}">
                                        @error('expiry_date')
                                            <span class="text-danger"><small>{{ $message }}</small></span>
                                        @enderror
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-12">
                                    <div class="form-group">
                                        <div class="form-check mb-3">
                                            <input type="hidden" name="is_grant" value="0">
                                            <input class="form-check-input" type="checkbox" name="is_grant" id="is_grant" value="1" {{ old('is_grant', $courseCode->is_grant) ? 'checked' : '' }}>
                                            <label class="form-check-label" for="is_grant"><strong>منحة كلية — كورس ومشاريع كاملة بلا Rokn AI أو شهادة</strong></label>
                                            <small class="form-text text-muted d-block">كل حساب وبريد يمكنه استخدام منحة واحدة لكورس واحد فقط.</small>
                                        </div>
                                        <label for="allowed_email_domains"><i class="fa fa-university"></i> نطاقات البريد المسموح بها (اختياري)</label>
                                        <textarea name="allowed_email_domains" id="allowed_email_domains" class="form-control" rows="2" placeholder="مثال: students.cu.edu.eg, alexu.edu.eg">{{ old('allowed_email_domains', implode(', ', $courseCode->allowed_email_domains ?? [])) }}</textarea>
                                        <small class="text-muted">اتركه فارغًا ليعمل الكود مع أي حساب، أو افصل النطاقات بفاصلة.</small>
                                        @error('allowed_email_domains')
                                            <span class="text-danger"><small>{{ $message }}</small></span>
                                        @enderror
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-12">
                                    <div class="form-group">
                                        <label for="description"><i class="fa fa-align-right"></i> الوصف (اختياري)</label>
                                        <textarea name="description" id="description" class="form-control" rows="3" placeholder="وصف مختصر للكود">{{ old('description', $courseCode->description) }}</textarea>
                                        @error('description')
                                            <span class="text-danger"><small>{{ $message }}</small></span>
                                        @enderror
                                    </div>
                                </div>
                            </div>

                            <div class="action-buttons">
                                <button type="submit" class="btn btn-primary-modern btn-modern">
                                    <i class="fa fa-save"></i> حفظ التغييرات
                                </button>
                                <a href="{{ route('admin.course-codes.show', $courseCode) }}" class="btn btn-secondary-modern btn-modern">
                                    <i class="fa fa-eye"></i> عرض
                                </a>
                                <a href="{{ route('admin.course-codes.index') }}" class="btn btn-secondary-modern btn-modern">
                                    <i class="fa fa-arrow-left"></i> رجوع
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, checking jQuery...');

    // Ensure jQuery is available
    if (typeof jQuery === 'undefined') {
        console.error('jQuery is not loaded');
        return;
    }

    console.log('jQuery is available, version:', jQuery.fn.jquery);
    var $ = jQuery;

    // Get current lesson IDs from server
    var currentLessonIds = @json($courseCode->lesson_ids ?? []);
    var isInitialLoad = true;

    // Handle type change
    $('#type').on('change', function() {
        var type = $(this).val();
        console.log('Type changed to:', type);

        // Hide all selection divs
        $('#course-selection, #lesson-selection, #multiple-lessons-selection').hide();

        // Only reset selections if it's NOT the initial page load
        if (!isInitialLoad) {
            $('#course_id, #lesson_id').val('');
            $('#lessons-container').html('<p class="text-muted">يرجى اختيار دورة أولاً لعرض الدروس المتاحة</p>');
        }

        // Show relevant selection based on type
        switch(type) {
            case 'course':
                console.log('Showing course selection');
                $('#course-selection').show();
                break;
            case 'lesson':
                console.log('Showing lesson selection');
                $('#lesson-selection').show();
                break;
            case 'multiple_lessons':
                console.log('Showing course and multiple lessons selection');
                $('#course-selection').show();
                $('#multiple-lessons-selection').show();
                // Load lessons if course is already selected (on initial load)
                if ($('#course_id').val()) {
                    loadLessons();
                } else {
                    $('#lessons-container').html('<p class="text-muted">يرجى اختيار دورة أولاً لعرض الدروس المتاحة</p>');
                }
                break;
        }

        // Mark that initial load is complete
        isInitialLoad = false;
    });

    // Load lessons when course is selected for multiple lessons
    $('#course_id').on('change', function() {
        var courseId = $(this).val();
        console.log('Course changed to:', courseId);

        if ($('#type').val() === 'multiple_lessons') {
            loadLessons();
        }
    });

    // Load lessons via AJAX
    function loadLessons() {
        var courseId = $('#course_id').val();
        if (!courseId) {
            $('#lessons-container').html('<p class="text-muted">يرجى اختيار دورة أولاً لعرض الدروس المتاحة</p>');
            return;
        }

        $('#lessons-container').html('<div class="text-center"><i class="fa fa-spinner fa-spin"></i> جاري تحميل الدروس...</div>');

        console.log('Loading lessons for course ID:', courseId);

        $.ajax({
            url: '{{ route("admin.course-codes.get-lessons") }}',
            method: 'GET',
            data: { course_id: courseId },
            dataType: 'json',
            success: function(response) {
                console.log('Lessons loaded successfully:', response);

                if (response.length === 0) {
                    $('#lessons-container').html('<p class="text-warning">لا توجد دروس متاحة لهذه الدورة</p>');
                    return;
                }

                var html = '<div class="checkbox-grid">';

                response.forEach(function(lesson) {
                    // Check if this lesson ID is in the currentLessonIds array
                    // Convert both to integers for comparison
                    var lessonId = parseInt(lesson.id);
                    var isChecked = false;

                    if (currentLessonIds && Array.isArray(currentLessonIds)) {
                        isChecked = currentLessonIds.some(function(id) {
                            return parseInt(id) === lessonId;
                        });
                    }

                    console.log('Lesson:', lesson.title, 'ID:', lessonId, 'Is checked:', isChecked);

                    html += '<div class="checkbox-item">';
                    html += '<label for="lesson_' + lesson.id + '">';
                    html += '<input type="checkbox" name="lesson_ids[]" value="' + lesson.id + '" id="lesson_' + lesson.id + '" ' + (isChecked ? 'checked' : '') + '>';
                    html += '<span>' + lesson.title + '</span>';
                    html += '</label>';
                    html += '</div>';
                });
                html += '</div>';
                html += '<div class="mt-3">';
                html += '<button type="button" class="btn btn-sm btn-primary-modern btn-modern" onclick="selectAllLessons()"><i class="fa fa-check-square-o"></i> تحديد الكل</button> ';
                html += '<button type="button" class="btn btn-sm btn-secondary-modern btn-modern" onclick="deselectAllLessons()"><i class="fa fa-square-o"></i> إلغاء التحديد</button>';
                html += '</div>';
                $('#lessons-container').html(html);
            },
            error: function(xhr, status, error) {
                console.error('Error loading lessons:', xhr.responseText);
                console.error('Status:', status);
                console.error('Error:', error);
                $('#lessons-container').html('<p class="text-danger">حدث خطأ أثناء تحميل الدروس</p>');
            }
        });
    }

    // Form validation
    $('#edit-code-form').on('submit', function(e) {
        var type = $('#type').val();
        var isValid = true;

        console.log('Form submitted, type:', type);

        // Check required fields based on type
        if (type === 'course') {
            if (!$('#course_id').val()) {
                alert('يرجى اختيار الدورة');
                isValid = false;
            }
        } else if (type === 'lesson') {
            if (!$('#lesson_id').val()) {
                alert('يرجى اختيار الدرس');
                isValid = false;
            }
        } else if (type === 'multiple_lessons') {
            if (!$('#course_id').val()) {
                alert('يرجى اختيار الدورة');
                isValid = false;
            } else if ($('input[name="lesson_ids[]"]:checked').length === 0) {
                alert('يرجى اختيار درس واحد على الأقل');
                isValid = false;
            }
        }

        if (!isValid) {
            e.preventDefault();
        }
    });

    // Trigger type change on page load if there's a value
    if ($('#type').val()) {
        console.log('Triggering type change on page load');
        $('#type').trigger('change');
    }

    console.log('Course codes edit page initialized successfully');
});

// Global functions for lesson selection
function selectAllLessons() {
    if (typeof jQuery !== 'undefined') {
        jQuery('input[name="lesson_ids[]"]').prop('checked', true);
    }
}

function deselectAllLessons() {
    if (typeof jQuery !== 'undefined') {
        jQuery('input[name="lesson_ids[]"]').prop('checked', false);
    }
}
</script>
@endsection

