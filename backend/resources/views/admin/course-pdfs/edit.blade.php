@extends('admin.layouts.app')

@section('page.title', 'تعديل ملف PDF')

@section('styles')
@include('admin.course-pdfs.partials._dynamic_styles')
<link rel="stylesheet" href="{{ asset('admin/assets/css/course-pdfs-edit.css') }}">
@endsection

@section('content')
<div class="admin-page form-wrapper">
    <!-- Header Section -->
    <div class="form-header">
        <div class="header-content">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <h1 class="mb-2">
                        <i class="fa fa-edit me-2"></i>
                        تعديل ملف PDF
                    </h1>
                    <p class="mb-0 opacity-75">الكورس: {{ $course->name_ar ?? $course->title }}</p>
                </div>
                <a href="{{ route('admin.courses.pdfs.index', $course) }}" class="btn-modern">
                    <i class="fa fa-arrow-right"></i>
                    العودة للقائمة
                </a>
            </div>
        </div>
    </div>

    <!-- Form Container -->
    <div class="form-container">
        <form action="{{ route('admin.courses.pdfs.update', [$course, $pdf]) }}" method="POST" enctype="multipart/form-data" id="coursePdfForm">
            @csrf
            @method('PUT')
            <input type="hidden" name="authoring_version" value="{{ $course->authoring_version }}">

            <!-- Arabic Content -->
            <h4 class="section-title">
                <i class="fa fa-language"></i>
                المحتوى العربي
            </h4>

            <div class="form-group">
                <label class="form-label">
                    العنوان <span class="required">*</span>
                </label>
                <input type="text" name="title" class="form-control @error('title') is-invalid @enderror" 
                       value="{{ old('title', $pdf->title) }}" placeholder="أدخل عنوان الملف" required>
                @error('title')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="form-group">
                <label class="form-label">الوصف</label>
                <textarea name="description" class="form-control @error('description') is-invalid @enderror" 
                          rows="3" placeholder="أدخل وصف الملف (اختياري)">{{ old('description', $pdf->description) }}</textarea>
                @error('description')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <!-- English Content -->
            <h4 class="section-title mt-4">
                <i class="fa fa-globe"></i>
                المحتوى الإنجليزي (اختياري)
            </h4>

            <div class="form-group">
                <label class="form-label">Title (English)</label>
                <input type="text" name="title_en" class="form-control @error('title_en') is-invalid @enderror" 
                       value="{{ old('title_en', $pdf->title_en) }}" placeholder="Enter file title in English">
                @error('title_en')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="form-group">
                <label class="form-label">Description (English)</label>
                <textarea name="description_en" class="form-control @error('description_en') is-invalid @enderror" 
                          rows="3" placeholder="Enter file description in English">{{ old('description_en', $pdf->description_en) }}</textarea>
                @error('description_en')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <!-- Current File -->
            <h4 class="section-title mt-4">
                <i class="fa fa-file-pdf-o"></i>
                ملف PDF
            </h4>

            <div class="current-file-info">
                <div class="current-file-icon">
                    <i class="fa fa-file-pdf-o"></i>
                </div>
                <div class="current-file-details">
                    <div class="current-file-name">{{ $pdf->original_filename ?? 'ملف PDF' }}</div>
                    <div class="current-file-size">{{ $pdf->formatted_file_size }}</div>
                </div>
                <a href="{{ route('admin.courses.pdfs.preview', [$course, $pdf]) }}" 
                   class="btn-preview-current" target="_blank">
                    <i class="fa fa-eye"></i>
                    معاينة
                </a>
            </div>

            <div class="form-group">
                <label class="form-label">استبدال الملف (اختياري)</label>
                <div class="file-upload-area" id="dropZone">
                    <div class="file-upload-icon">
                        <i class="fa fa-cloud-upload"></i>
                    </div>
                    <p class="file-upload-text">اسحب الملف الجديد هنا أو انقر للاختيار</p>
                    <p class="file-upload-hint">الحد الأقصى: 50 ميجابايت - PDF فقط</p>
                    <input type="file" name="pdf_file" class="file-input @error('pdf_file') is-invalid @enderror" 
                           id="pdfFile" accept=".pdf">
                </div>
                <div class="file-preview" id="filePreview">
                    <div class="file-preview-icon">
                        <i class="fa fa-file-pdf-o"></i>
                    </div>
                    <div class="file-preview-info">
                        <div class="file-preview-name" id="fileName"></div>
                        <div class="file-preview-size" id="fileSize"></div>
                    </div>
                    <button type="button" class="file-preview-remove" id="removeFile">
                        <i class="fa fa-times"></i>
                    </button>
                </div>
                @error('pdf_file')
                    <div class="invalid-feedback d-block">{{ $message }}</div>
                @enderror
                <span class="form-text">اترك هذا الحقل فارغاً للاحتفاظ بالملف الحالي</span>
            </div>

            <!-- Settings -->
            <h4 class="section-title mt-4">
                <i class="fa fa-cog"></i>
                الإعدادات
            </h4>

            <div class="settings-card">
                <div class="row">
                    <div class="col-6">
                        <div class="form-group">
                            <label class="form-label">الترتيب</label>
                            <input type="number" name="order" class="form-control @error('order') is-invalid @enderror" 
                                   value="{{ old('order', $pdf->order) }}" min="0">
                            <span class="form-text">ترتيب الملف في القائمة</span>
                            @error('order')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-group">
                            <label class="form-label">الحالة</label>
                            <div class="toggle-switch-container mt-2">
                                <label class="toggle-switch">
                                    <input type="checkbox" name="is_active" id="isActive" value="1" 
                                           {{ old('is_active', $pdf->is_active) ? 'checked' : '' }}>
                                    <span class="toggle-slider"></span>
                                </label>
                                <div class="toggle-label">
                                    <span class="toggle-label-text">تفعيل الملف</span>
                                    <span class="toggle-label-hint">سيظهر الملف للطلاب عند التفعيل</span>
                                </div>
                                <span class="toggle-status {{ $pdf->is_active ? 'active' : 'inactive' }}" id="statusBadge">
                                    {{ $pdf->is_active ? 'مفعّل' : 'غير مفعّل' }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            @include('admin.course-pdfs.partials._upload-progress')

            <!-- Form Actions -->
            <div class="form-actions">
                <a href="{{ route('admin.courses.pdfs.index', $course) }}" class="btn-cancel">
                    <i class="fa fa-times"></i>
                    إلغاء
                </a>
                <button type="submit" class="btn-submit">
                    <i class="fa fa-save"></i>
                    حفظ التغييرات
                </button>
            </div>
        </form>
    </div>
</div>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const dropZone = document.getElementById('dropZone');
    const fileInput = document.getElementById('pdfFile');
    const filePreview = document.getElementById('filePreview');
    const fileName = document.getElementById('fileName');
    const fileSize = document.getElementById('fileSize');
    const removeFile = document.getElementById('removeFile');

    // Format file size
    function formatFileSize(bytes) {
        if (bytes >= 1073741824) {
            return (bytes / 1073741824).toFixed(2) + ' GB';
        } else if (bytes >= 1048576) {
            return (bytes / 1048576).toFixed(2) + ' MB';
        } else if (bytes >= 1024) {
            return (bytes / 1024).toFixed(2) + ' KB';
        } else {
            return bytes + ' bytes';
        }
    }

    // Show file preview
    function showFilePreview(file) {
        fileName.textContent = file.name;
        fileSize.textContent = formatFileSize(file.size);
        filePreview.classList.add('show');
    }

    // Hide file preview
    function hideFilePreview() {
        filePreview.classList.remove('show');
        fileInput.value = '';
    }

    // File input change
    fileInput.addEventListener('change', function() {
        if (this.files.length > 0) {
            showFilePreview(this.files[0]);
        }
    });

    // Drag and drop
    dropZone.addEventListener('dragover', function(e) {
        e.preventDefault();
        this.classList.add('dragover');
    });

    dropZone.addEventListener('dragleave', function() {
        this.classList.remove('dragover');
    });

    dropZone.addEventListener('drop', function(e) {
        e.preventDefault();
        this.classList.remove('dragover');
        
        if (e.dataTransfer.files.length > 0) {
            fileInput.files = e.dataTransfer.files;
            showFilePreview(e.dataTransfer.files[0]);
        }
    });

    // Remove file
    removeFile.addEventListener('click', function() {
        hideFilePreview();
    });

    // Toggle status badge update
    const isActiveCheckbox = document.getElementById('isActive');
    const statusBadge = document.getElementById('statusBadge');
    
    if (isActiveCheckbox && statusBadge) {
        function updateStatusBadge() {
            if (isActiveCheckbox.checked) {
                statusBadge.textContent = 'مفعّل';
                statusBadge.classList.remove('inactive');
                statusBadge.classList.add('active');
            } else {
                statusBadge.textContent = 'غير مفعّل';
                statusBadge.classList.remove('active');
                statusBadge.classList.add('inactive');
            }
        }
        
        isActiveCheckbox.addEventListener('change', updateStatusBadge);
    }
});
</script>
@include('admin.partials.course-authoring-draft', ['formId' => 'coursePdfForm'])
@endsection
