@extends('admin.layouts.app')

@section('page.title', 'إضافة مرحلة دراسية جديدة')

@section('styles')
{{-- Include Dynamic Theme Styles --}}
@include('admin.grades.partials._dynamic_styles')

<link rel="stylesheet" href="{{ asset('admin/assets/css/grades-create.css') }}">
<link rel="stylesheet" href="{{ asset('admin/assets/css/grades-form.css') }}">

@endsection

@section('content')
    <div class="container-fluid grades-module admin-page">
        <div class="row justify-content-center">
            <div class="col-12 col-lg-10 col-xl-8">
                <div class="card border-0 shadow-lg slide-up">
                    <!-- Enhanced Header -->
                    <div class="create-header">
                        <div class="page-header-content">
                            <div>
                                <div class="header-title">
                                    <i class="fa fa-plus-circle"></i>
                                    <h1>إضافة مرحلة دراسية جديدة</h1>
                                </div>
                                <p class="header-description">
                                    قم بإضافة مرحلة دراسية جديدة لتنظيم الكورسات والمحتوى التعليمي
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Form Container -->
                    <div class="form-container">
                        <!-- Success Message (if any) -->
                        @if(session('success'))
                            <div class="success-message">
                                <i class="fa fa-check-circle"></i>
                                <span>{{ session('success') }}</span>
                            </div>
                        @endif

                        <!-- Form -->
                        <form action="{{ route('admin.grades.store') }}" method="post" id="gradeForm">
                            @csrf
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
    const form = document.getElementById('gradeForm');

    // Add form validation
    form.addEventListener('submit', function(e) {
        let isValid = true;
        const requiredFields = ['name_ar', 'type', 'country'];

        requiredFields.forEach(fieldName => {
            const field = document.getElementById(fieldName);
            if (field && !field.value.trim()) {
                field.classList.add('is-invalid');
                isValid = false;
            }
        });

        if (!isValid) {
            e.preventDefault();
            // Scroll to first error
            const firstError = form.querySelector('.is-invalid');
            if (firstError) {
                firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                firstError.focus();
            }
        }
    });

    // Auto-save draft functionality (optional)
    const formData = {};
    const inputs = form.querySelectorAll('input, select, textarea');

    inputs.forEach(input => {
        input.addEventListener('input', function() {
            formData[this.name] = this.value;
            localStorage.setItem('grade_draft', JSON.stringify(formData));
        });
    });

    // Load draft if available
    const savedDraft = localStorage.getItem('grade_draft');
    if (savedDraft) {
        try {
            const draft = JSON.parse(savedDraft);
            Object.keys(draft).forEach(key => {
                const field = form.querySelector(`[name="${key}"]`);
                if (field && !field.value) {
                    field.value = draft[key];
                    field.style.backgroundColor = '#fff3cd';
                    setTimeout(() => {
                        field.style.backgroundColor = '';
                    }, 3000);
                }
            });
        } catch (e) {
            console.log('Could not load draft');
        }
    }

    // Clear draft on successful submission
    form.addEventListener('submit', function() {
        localStorage.removeItem('grade_draft');
    });
});
</script>
@endsection
