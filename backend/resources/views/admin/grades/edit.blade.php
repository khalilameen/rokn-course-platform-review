@extends('admin.layouts.app')

@section('page.title', 'تعديل المرحلة الدراسية')

@section('styles')
{{-- Include Dynamic Theme Styles --}}
@include('admin.grades.partials._dynamic_styles')

<link rel="stylesheet" href="{{ asset('admin/assets/css/grades-edit.css') }}">
<link rel="stylesheet" href="{{ asset('admin/assets/css/grades-form.css') }}">

@endsection

@section('content')
    <div class="container-fluid grades-module admin-page">
        <div class="row justify-content-center">
            <div class="col-12 col-lg-10 col-xl-8">
                <div class="card border-0 shadow-lg slide-up">
                    <!-- Enhanced Header -->
                    <div class="edit-header">
                        <div class="page-header-content">
                            <div>
                                <div class="header-title">
                                    <i class="fa fa-edit"></i>
                                    <h1>تعديل المرحلة الدراسية</h1>
                                </div>
                                <p class="header-description">
                                    تعديل معلومات وتفاصيل المرحلة الدراسية: {{ $grade->name_ar }}
                                </p>
                                @if($grade->updated_at)
                                    <div class="last-updated">
                                        <i class="fa fa-clock-o ml-1"></i>
                                        آخر تعديل: {{ $grade->updated_at->diffForHumans() }}
                                    </div>
                                @endif
                            </div>
                        </div>

                        <!-- Grade Info Card -->
                        <div class="grade-info-card">
                            <div class="grade-meta">
                                <div class="grade-meta-item">
                                    <i class="fa fa-tag"></i>
                                    <span>{{ $grade->name_ar }}</span>
                                </div>
                                @if($grade->type)
                                    <div class="grade-meta-item">
                                        <i class="fa fa-graduation-cap"></i>
                                        <span>
                                            @if($grade->type == 'primary')
                                                ابتدائي
                                            @elseif($grade->type == 'preparatory')
                                                إعدادي
                                            @elseif($grade->type == 'secondary')
                                                ثانوي
                                            @endif
                                        </span>
                                    </div>
                                @endif
                                @if($grade->country)
                                    <div class="grade-meta-item">
                                        <i class="fa fa-globe"></i>
                                        <span>{{ $grade->country }}</span>
                                    </div>
                                @endif
                                @if($grade->courses && $grade->courses->count() > 0)
                                    <div class="grade-meta-item">
                                        <i class="fa fa-book"></i>
                                        <span>{{ $grade->courses->count() }} كورس</span>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>

                    <!-- Form Container -->
                    <div class="form-container">
                        <!-- Warning Message (if grade has courses) -->
                        @if($grade->courses && $grade->courses->count() > 0)
                            <div class="warning-message">
                                <i class="fa fa-exclamation-triangle"></i>
                                <div>
                                    <strong>تنبيه:</strong> هذه المرحلة مرتبطة بـ {{ $grade->courses->count() }} كورس.
                                    قد يؤثر تعديل النوع أو البلد على الكورسات المرتبطة.
                                    <a href="{{ route('admin.grades.courses', $grade->id) }}" class="text-white text-underline">
                                        عرض الكورسات المرتبطة
                                    </a>
                                </div>
                            </div>
                        @endif

                        <!-- Success Message (if any) -->
                        @if(session('success'))
                            <div class="success-message">
                                <i class="fa fa-check-circle"></i>
                                <span>{{ session('success') }}</span>
                            </div>
                        @endif

                        <!-- Form -->
                        <form action="{{ route('admin.grades.update', $grade->id) }}" method="post" id="gradeEditForm">
                            @csrf
                            @method('PUT')
                            @include('admin.grades._form')
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Form enhancement
    const form = document.getElementById('gradeEditForm');

    // Track changes
    const originalData = new FormData(form);
    let hasChanges = false;

    form.addEventListener('input', function() {
        hasChanges = true;
    });

    // Warn before leaving if there are unsaved changes
    window.addEventListener('beforeunload', function(e) {
        if (hasChanges) {
            e.preventDefault();
            e.returnValue = 'لديك تغييرات غير محفوظة. هل تريد المغادرة؟';
        }
    });

    // Clear changes flag on form submission
    form.addEventListener('submit', function() {
        hasChanges = false;
    });

    // Auto-save draft functionality
    let autoSaveTimeout;
    form.addEventListener('input', function() {
        clearTimeout(autoSaveTimeout);
        autoSaveTimeout = setTimeout(function() {
            const formData = new FormData(form);
            const data = {};
            for (let [key, value] of formData.entries()) {
                data[key] = value;
            }
            localStorage.setItem('grade_edit_{{ $grade->id }}', JSON.stringify(data));

            setTimeout(() => {
                indicator.remove();
            }, 2000);
        }, 3000);
    });
});
</script>
@endsection
