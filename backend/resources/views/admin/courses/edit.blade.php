@extends('admin.layouts.app')

@section('page.title', 'تعديل الكورس')

@section('styles')
{{-- Include Dynamic Theme Styles --}}
@include('admin.courses.partials._dynamic_styles')

<link rel="stylesheet" href="{{ asset('admin/assets/css/course-editor.css') }}">
@endsection

@section('content')
<div class="admin-page course-editor fade-in">
    <div class="form-container">
        <!-- Header -->
        <div class="edit-course-header">
            <div class="header-content">
                <div class="header-info">
                    <h1>
                        <div class="header-icon">
                            <i class="fa fa-edit"></i>
                        </div>
                        تعديل الكورس
                    </h1>
                    <p class="mb-0 opacity-75">قم بتعديل بيانات الكورس حسب الحاجة</p>
                </div>

            </div>
        </div>

        <!-- Changes Indicator -->
        <div class="changes-indicator" id="changesIndicator">
            <div class="changes-text">
                <i class="fa fa-exclamation-triangle"></i>
                لديك تغييرات غير محفوظة
            </div>
        </div>

        @if(session('error'))
            <div class="alert alert-warning mt-3" role="alert">
                <strong>{{ session('error') }}</strong>
                @if(session('publishing_issues'))
                    <ul class="mb-0 mt-2 pr-4">
                        @foreach(session('publishing_issues') as $issue)
                            <li>{{ $issue }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif

        <div class="alert {{ $publishingAudit['ready'] ? 'alert-success' : 'alert-info' }} mt-3" role="status">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <strong>
                    {{ $publishingAudit['ready'] ? 'الكورس جاهز للنشر' : 'قائمة اكتمال الكورس قبل النشر' }}
                </strong>
                <span>
                    {{ $publishingAudit['counts']['modules'] }} وحدات ·
                    {{ $publishingAudit['counts']['reels'] }} خطوة ·
                    {{ $publishingAudit['counts']['projects'] }} مشروعات
                </span>
            </div>
            @if(!$publishingAudit['ready'])
                <ul class="mb-0 mt-2 pr-4">
                    @foreach($publishingAudit['issues'] as $issue)
                        <li>{{ $issue }}</li>
                    @endforeach
                </ul>
            @elseif(!empty($publishingAudit['warnings']))
                <div class="mt-2">{{ implode(' ', $publishingAudit['warnings']) }}</div>
            @endif
        </div>

        <!-- Form -->
        {!! Form::model($course, ['method' => 'PATCH', 'files' => true, 'url' => route('admin.courses.update', $course->id), 'id' => 'courseEditForm']) !!}

        <div class="form-sections">
            @include('admin.courses.partials.edit.basic-information')

            @include('admin.courses.partials.edit.course-settings')

            @include('admin.courses.partials.edit.ai-settings')

            @include('admin.courses.partials.edit.access-plans')

            @include('admin.courses.partials.edit.course-image')

            @include('admin.courses.partials.edit.course-lessons')


        </div>

        <!-- Actions Section -->
        <div class="actions-section">
            <button type="submit" class="btn-modern btn-primary-modern">
                <i class="fa fa-save"></i>
                <span>حفظ التغييرات</span>
            </button>
            <a href="{{ route('admin.courses.index') }}" class="btn-modern btn-secondary-modern">
                <i class="fa fa-arrow-right"></i>
                <span>العودة للكورسات</span>
            </a>
        </div>

        {!! Form::close() !!}
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    let originalFormData = new FormData(document.getElementById('courseEditForm'));

    // Initialize Select2
    $('.select2').select2({
        placeholder: "اختر التصنيفات",
        allowClear: true,
        dir: "rtl"
    });

    // File upload functionality
    const fileInput = document.getElementById('image');
    const uploadArea = document.querySelector('.file-upload-area');
    const imagePreview = document.getElementById('imagePreview');

    fileInput.addEventListener('change', handleFileSelect);

    uploadArea.addEventListener('dragover', (e) => {
        e.preventDefault();
        uploadArea.classList.add('dragover');
    });

    uploadArea.addEventListener('dragleave', () => {
        uploadArea.classList.remove('dragover');
    });

    uploadArea.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadArea.classList.remove('dragover');

        const files = e.dataTransfer.files;
        if (files.length > 0) {
            fileInput.files = files;
            handleFileSelect({ target: { files: files } });
        }
    });

    // Keep the visual card state aligned with the native checkbox.
    document.querySelectorAll('.checkbox-item').forEach(item => {
        const checkbox = item.querySelector('input[type="checkbox"]');
        if (!checkbox) {
            return;
        }

        const syncCardState = () => item.classList.toggle('selected', checkbox.checked);
        syncCardState();
        checkbox.addEventListener('change', function() {
            syncCardState();
            checkForChanges();
        });
    });

    // Form change detection
    document.querySelectorAll('input, select, textarea').forEach(element => {
        element.addEventListener('input', checkForChanges);
        element.addEventListener('change', checkForChanges);
    });

});

function handleFileSelect(e) {
    const files = e.target.files;
    const imagePreview = document.getElementById('imagePreview');

    if (files && files[0]) {
        const file = files[0];

        // Validate file type
        if (!file.type.startsWith('image/')) {
            alert('يرجى اختيار ملف صورة صحيح');
            return;
        }

        // Validate file size (10MB)
        if (file.size > 10 * 1024 * 1024) {
            alert('حجم الملف يجب أن يكون أقل من 10 ميجابايت');
            return;
        }

        const reader = new FileReader();
        reader.onload = function(e) {
            imagePreview.innerHTML = `
                <img src="${e.target.result}" alt="Preview" class="image-preview">
                <div class="course-editor__preview-status">
                    <i class="fa fa-check-circle"></i> سيتم تحديث الصورة عند الحفظ
                </div>
            `;
        };
        reader.readAsDataURL(file);
    }

    checkForChanges();
}

function checkForChanges() {
    const originalFormData = new FormData(document.getElementById('courseEditForm'));
    // Compare with initial values or implement change detection logic
    const changesIndicator = document.getElementById('changesIndicator');
    // For simplicity, show the indicator when any input has been modified
    changesIndicator.classList.add('show');
}

</script>
@endsection
