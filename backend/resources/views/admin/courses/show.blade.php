@extends('admin.layouts.app')

@section('page.title', 'تفاصيل الكورس')

@section('styles')
{{-- Include Dynamic Theme Styles --}}
@include('admin.courses.partials._dynamic_styles')

<link rel="stylesheet" href="{{ asset('admin/assets/css/courses-show.css') }}">
@endsection

@section('content')
<div class="admin-page fade-in">
    <!-- Header Section -->
    <div class="course-show-header">
        <div class="header-content">
            <!-- Course Info -->
            <div class="course-info">
                <h1 class="course-title">
                    <i class="fa fa-graduation-cap ml-2"></i>
                    {{ $course->title }}
                </h1>
                @if($course->description)
                    <p class="course-subtitle">{{ $course->description }}</p>
                @endif
            </div>

            <!-- Course Actions -->
            <div class="course-actions">
                <a href="{{ route('admin.courses.edit', $course) }}" class="btn-action">
                    <i class="fa fa-edit"></i>
                    تعديل الكورس
                </a>
                <a href="{{ route('admin.courses.sections.index', $course) }}" class="btn-action">
                    <i class="fa fa-list"></i>
                    إدارة الأقسام
                </a>
                <a href="{{ route('admin.courses.sections.create', $course) }}" class="btn-action">
                    <i class="fa fa-plus"></i>
                    إضافة قسم جديد
                </a>
                <a href="{{ route('admin.courses.pdfs.index', $course) }}" class="btn-action btn-action--pdf">
                    <i class="fa fa-file-pdf-o"></i>
                    إدارة ملفات PDF
                </a>
                <a href="{{ route('admin.courses.index') }}" class="btn-action">
                    <i class="fa fa-arrow-left"></i>
                    العودة للقائمة
                </a>
            </div>
        </div>
    </div>

    <div class="alert {{ $publishingAudit['ready'] ? 'alert-success' : 'alert-warning' }} mt-3" role="status">
        <div class="d-flex justify-content-between align-items-center flex-wrap">
            <strong>
                {{ !$course->is_coming_soon ? 'الكورس منشور' : ($publishingAudit['ready'] ? 'المسودة جاهزة للنشر' : 'المسودة غير مكتملة') }}
            </strong>
            <span>
                {{ $publishingAudit['counts']['modules'] }} وحدات ·
                {{ $publishingAudit['counts']['reels'] }} خطوة ·
                {{ $publishingAudit['counts']['projects'] }} مشروعات
            </span>
        </div>
        @if($course->is_coming_soon && !$publishingAudit['ready'])
            <ul class="mb-0 mt-2 pr-4">
                @foreach($publishingAudit['issues'] as $issue)
                    <li>{{ $issue }}</li>
                @endforeach
            </ul>
        @endif
    </div>

    <!-- Content Container -->
    <div class="content-container">
        <!-- Tabs -->
        <div class="content-tabs">
            <button class="tab-button active" onclick="switchTab(event, 'overview')">
                <i class="fa fa-info-circle"></i>
                نظرة عامة
            </button>
            <button class="tab-button" onclick="switchTab(event, 'sections')">
                <i class="fa fa-list"></i>
                الأقسام ({{ $sections->count() }})
            </button>
            <button class="tab-button" onclick="switchTab(event, 'statistics')">
                <i class="fa fa-chart-bar"></i>
                الإحصائيات
            </button>
            @if($commercialReport)
                <button class="tab-button" onclick="switchTab(event, 'commercial-report')">
                    <i class="fa fa-line-chart"></i>
                    الطلاب والدخل
                </button>
            @endif
        </div>

        @include('admin.courses.partials.show.overview')

        @include('admin.courses.partials.show.sections')

        @include('admin.courses.partials.show.statistics')

        @if($commercialReport)
            @include('admin.courses.partials.show.commercial-report')
        @endif
    </div>
</div>

@endsection

@section('scripts')
@include('admin.courses.partials.show.scripts')
@endsection
