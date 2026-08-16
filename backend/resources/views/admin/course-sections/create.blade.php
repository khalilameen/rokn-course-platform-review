@extends('admin.layouts.app')

@section('page.title', 'إضافة قسم جديد للكورس')

@section('styles')
{{-- Include Dynamic Theme Styles --}}
@include('admin.course-sections.partials._dynamic_styles')
<link rel="stylesheet" href="{{ asset('admin/assets/css/course-sections-editor.css') }}">
<link rel="stylesheet" href="{{ asset('admin/assets/css/course-sections-create.css') }}">
@endsection

@section('content')

<div class="admin-page fade-in">
    <!-- Header Section -->
    <div class="create-section-header">
        <div class="header-content">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <h1 class="mb-2">
                        <i class="fa fa-plus-circle ml-2"></i>
                        إضافة قسم جديد
                    </h1>
                    <p class="mb-0 opacity-75">إضافة محتوى جديد لكورسك</p>
                </div>
            </div>

            <!-- Course Info Banner -->
            <div class="course-info-banner">
                <div class="course-meta">
                    <div class="course-meta-item">
                        <i class="fa fa-book"></i>
                        <span>{{ $course->title ?? $course->name_ar }}</span>
                    </div>
                    @if($course->classifications->count() > 0)
                        <div class="course-meta-item">
                            <i class="fa fa-tags"></i>
                            <span>
                                @foreach($course->classifications as $classification)
                                    {{ $classification->name_ar }}{{ !$loop->last ? '، ' : '' }}
                                @endforeach
                            </span>
                        </div>
                    @endif
                    <div class="course-meta-item">
                        <i class="fa fa-list"></i>
                        <span>عدد الأقسام الحالية {{ $course->sections()->count() }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Form Container -->
    <div class="create-section-container">
        <div class="form-container">
            <form action="{{ route('admin.courses.sections.store', $course) }}" method="POST" id="sectionForm" enctype="multipart/form-data">
                @csrf

                @include('admin.course-sections.partials.create.basic-information')

                @include('admin.course-sections.partials.create.type-selection')

                <!-- Dynamic Forms for Each Type -->

                @include('admin.course-sections.partials.create.lesson-form')

                @include('admin.course-sections.partials.create.link-form')

                @include('admin.course-sections.partials.create.quiz-form')

                @include('admin.course-sections.partials.create.project-form')

                @include('admin.course-sections.partials.create.course-form')

            </form>
        </div>

        <!-- Form Actions -->
        <div class="form-actions">
            <div>
                <a href="{{ route('admin.courses.sections.index', $course) }}" class="btn-modern btn-secondary">
                    <i class="fa fa-arrow-left"></i>
                    إلغاء والعودة
                </a>
            </div>
            <div>
                <button type="submit" form="sectionForm" class="btn-modern btn-primary">
                    <i class="fa fa-save"></i>
                    حفظ القسم
                </button>
            </div>
        </div>
    </div>
</div>

@endsection

@section('scripts')
@include('admin.course-sections.partials.create.scripts')

@endsection
