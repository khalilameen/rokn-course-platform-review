@extends('admin.layouts.app')

@section('page.title', 'إضافة كورس جديد')

@section('styles')
{{-- Include Dynamic Theme Styles --}}
@include('admin.courses.partials._dynamic_styles')

<link rel="stylesheet" href="{{ asset('admin/assets/css/course-create.css') }}">
@endsection

@section('content')
<div class="admin-page fade-in">
    <div class="form-container">
        <!-- Header -->
        <div class="create-course-header">
            <div class="header-content">
                <div class="header-info">
                    <h1>
                        <div class="header-icon">
                            <i class="fa fa-plus"></i>
                        </div>
                        إضافة كورس جديد
                    </h1>
                    <p class="mb-0 opacity-75">املأ البيانات التالية لإنشاء كورس جديد</p>
                </div>
            </div>
        </div>

        <!-- Progress Bar -->
        <div class="form-progress">
            <div class="progress-bar" id="progressBar"></div>
        </div>

        <!-- Form -->
        {!! Form::open(['method' => 'POST', 'files' => true, 'route' => ['admin.courses.store'], 'id' => 'courseForm']) !!}
        <input type="hidden" name="authoring_request_id" value="{{ old('authoring_request_id', (string) Str::uuid()) }}">

        <div class="form-sections">
            @include('admin.courses.partials.create.basic-information')

            @include('admin.courses.partials.create.course-settings')

            @include('admin.courses.partials.create.ai-settings')

            @include('admin.courses.partials.create.course-image')

        </div>

        <!-- Actions Section -->
        <div class="actions-section">
            <button type="submit" class="btn-modern btn-primary-modern">
                <i class="fa fa-save"></i>
                <span>حفظ الكورس</span>
            </button>
            <a href="{{ route('admin.courses.index') }}" class="btn-modern btn-secondary-modern">
                <i class="fa fa-arrow-right"></i>
                <span>العودة للكورسات</span>
            </a>
        </div>

        {!! Form::close() !!}
    </div>
</div>

@endsection

@section('scripts')
@include('admin.courses.partials.create.scripts')
@include('admin.partials.course-authoring-draft', ['formId' => 'courseForm'])
@endsection
