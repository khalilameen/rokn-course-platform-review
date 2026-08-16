@extends('admin.layouts.app')

@section('page.title', 'عرض الكورسات الحالية - XML')

@section('styles')
<link rel="stylesheet" href="{{ asset('admin/assets/css/courses-exist.css') }}">
@endsection

@section('content')

<div class="admin-page fade-in">
    <!-- Header Section -->
    <div class="exist-header">
        <div class="header-content">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <h1 class="mb-2">
                        <i class="fa fa-code ml-2"></i>
                        عرض الكورسات الحالية
                    </h1>
                    <p class="mb-0 opacity-75">عرض وتصدير بيانات الكورسات بصيغة XML</p>
                </div>
            </div>
        </div>
    </div>

    @include('admin.courses.partials.exist.course-data')
</div>

@endsection

@section('scripts')
@include('admin.courses.partials.exist.scripts')

@endsection
