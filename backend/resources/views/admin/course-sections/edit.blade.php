@extends('admin.layouts.app')

@section('page.title', 'تعديل قسم الكورس')

@section('styles')
{{-- Include Dynamic Theme Styles --}}
@include('admin.course-sections.partials._dynamic_styles')
<link rel="stylesheet" href="{{ asset('admin/assets/css/course-sections-editor.css') }}">
<link rel="stylesheet" href="{{ asset('admin/assets/css/course-sections-edit.css') }}">
@endsection

@section('content')
@php($editorLabel = $section->getSectionType() === 'project' ? 'مشروع العبور' : ($section->getSectionType() === 'lesson' ? 'المقطع' : 'المحتوى'))

<div class="admin-page fade-in">
    <!-- Header Section -->
    <div class="edit-section-header">
        <div class="header-content">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <h1 class="mb-2">
                        <i class="fa fa-edit ml-2"></i>
                        تعديل {{ $editorLabel }}
                    </h1>
                    <p class="mb-0 opacity-75">حدّث ما سيظهر للطالب داخل الكورس</p>
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
                        <span>القسم رقم {{ $section->order }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Form Container -->
    <div class="edit-section-container">
        <div class="form-container">
            <form action="{{ route('admin.courses.sections.update', [$course, $section]) }}" method="POST" id="sectionForm" enctype="multipart/form-data"
                  data-bunny-upload-init="{{ route('admin.courses.sections.video-uploads.store', $course) }}"
                  data-bunny-upload-renew="{{ route('admin.courses.sections.video-uploads.renew', $course) }}"
                  data-course-id="{{ $course->id }}" data-section-id="{{ $section->id }}">
                @csrf
                <input type="hidden" name="return_to" value="{{ request('return_to') === 'studio' ? 'studio' : '' }}">
                <input type="hidden" name="authoring_version" id="authoringVersion" value="{{ $course->authoring_version }}">
                @method('PUT')

                @include('admin.course-sections.partials.edit.basic-information')

                @include('admin.course-sections.partials.edit.type-selection')

                <!-- Dynamic Forms for Each Type -->

                @include('admin.course-sections.partials.edit.lesson-form')

                @include('admin.course-sections.partials.edit.link-form')

                @include('admin.course-sections.partials.edit.quiz-form')

                @include('admin.course-sections.partials.edit.project-form')

                @include('admin.course-sections.partials.edit.course-form')

            </form>
            @include('admin.attachments.manager', [
                'attachmentOwner' => $section,
                'attachmentType' => 'course_section',
            ])
        </div>

        <!-- Form Actions -->
        <div class="form-actions">
            <div>
                <a href="{{ request('return_to') === 'studio' ? route('admin.courses.show', $course) : route('admin.courses.sections.index', $course) }}" class="btn-modern btn-secondary">
                    <i class="fa fa-arrow-left"></i>
                    إلغاء والعودة
                </a>
            </div>
            <div>
                <button type="submit" form="sectionForm" class="btn-modern btn-primary">
                    <i class="fa fa-save"></i>
                    حفظ التعديلات
                </button>
            </div>
        </div>
    </div>
</div>

@endsection

@section('scripts')
@include('admin.course-sections.partials.edit.scripts')
@include('admin.course-sections.partials.bunny-direct-upload')
@include('admin.partials.course-authoring-draft', ['formId' => 'sectionForm'])

@endsection
