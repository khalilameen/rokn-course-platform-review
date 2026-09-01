@extends('admin.layouts.app')

@section('page.title', 'تعديل الوحدة')

@section('styles')
@include('admin.courses.partials._dynamic_styles')
<link rel="stylesheet" href="{{ asset('admin/assets/css/course-modules.css') }}">
@endsection

@section('content')
<div class="admin-page course-module-editor container">
    <div class="form-container">
        <div class="form-header">
            <h1 class="mb-0">
                <i class="fa fa-edit"></i>
                تعديل الوحدة: {{ $module->title_ar }}
            </h1>
        </div>

        <div class="form-body">
            {!! Form::model($module, ['route' => ['admin.courses.modules.update', [$course, $module]], 'method' => 'PUT', 'id' => 'moduleForm']) !!}
            <input type="hidden" name="return_to" value="{{ request('return_to') === 'studio' ? 'studio' : '' }}">
            <input type="hidden" name="authoring_version" value="{{ $course->authoring_version }}">

            <div class="row">
                <div class="col-md-6">
                    <div class="form-group-modern">
                        <label for="title_ar" class="form-label-modern">عنوان الوحدة (بالعربية) <span class="text-danger">*</span></label>
                        {!! Form::text('title_ar', null, ['class' => 'form-control-modern', 'required']) !!}
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group-modern">
                        <label for="title_en" class="form-label-modern">عنوان الوحدة (بالإنجليزية)</label>
                        {!! Form::text('title_en', null, ['class' => 'form-control-modern']) !!}
                    </div>
                </div>
            </div>

            <div class="form-group-modern">
                <label for="attachments_link" class="form-label-modern">رابط المرفقات (اختياري)</label>
                {!! Form::text('attachments_link', null, ['class' => 'form-control-modern', 'placeholder' => 'https://example.com/attachments']) !!}
                <div class="alert alert-warning mt-2 mb-0">
                    الرابط الخارجي لا يمكن لركن التحكم في نسخه أو مشاركته بعد فتحه. ارفع الملفات داخل ركن عندما تكون حصرية للكورس.
                </div>
                <small class="text-muted">رابط خارجي للملفات والمرفقات الخاصة بهذه الوحدة</small>
            </div>

            <div class="form-group-modern">
                <label for="attachment_platform" class="form-label-modern">منصة المرفقات</label>
                {!! Form::select('attachment_platform', [
                    'both' => 'الكل (كمبيوتر وموبايل)',
                    'computer' => 'كمبيوتر فقط',
                    'mobile' => 'موبايل فقط'
                ], null, ['class' => 'form-control-modern']) !!}
                <small class="text-muted">حدد المنصة المتوافقة مع رابط المرفقات</small>
            </div>

            <div class="form-actions mt-4">
                <button type="submit" class="btn-modern btn-primary">
                    <i class="fa fa-save"></i>
                    تحديث الوحدة
                </button>
                <a href="{{ request('return_to') === 'studio' ? route('admin.courses.show', $course) : route('admin.courses.sections.index', $course) }}" class="btn-modern btn-secondary">
                    <i class="fa fa-arrow-right"></i>
                    إلغاء
                </a>
            </div>

            {!! Form::close() !!}
            @include('admin.attachments.manager', [
                'attachmentOwner' => $module,
                'attachmentType' => 'course_module',
            ])
        </div>
    </div>
</div>
@include('admin.partials.course-authoring-draft', ['formId' => 'moduleForm'])
@endsection
