@extends('admin.layouts.app')

@section('page.title', 'اضافة اختبار')

@section('styles')
<link rel="stylesheet" href="{{ asset('admin/assets/css/admin-learning-views.css') }}">
@endsection

@section('content')
    <div class="row admin-learning admin-learning--quiz">
        <div class="col-12">
            <div class="card border-0 shadow">
                <div class="page-header-modern">
                    <div class="d-flex justify-content-between align-items-center flex-wrap admin-learning__toolbar">
                        <h3>
                            <span class="icon-wrapper">
                                <i class="fa fa-plus-circle"></i>
                            </span>
                            إضافة اختبار جديد
                        </h3>
                        @if(request('course_id'))
                            <a href="{{ route('admin.courses.sections.index', request('course_id')) }}" class="back-btn">
                                <i class="fa fa-arrow-right"></i>
                                العودة لأقسام الكورس
                            </a>
                        @endif
                    </div>
                </div>
                <div class="card-body-modern">
                    {!! Form::open(['method' => 'POST','files' => true, 'url' => route('admin.quizzes.store'), 'id' => 'exam_form', 'data-question-numbers'=>0 ]) !!}
                        <input type="hidden" name="authoring_request_id" value="{{ old('authoring_request_id', (string) Str::uuid()) }}">
                        @include('admin.quizzes._form')
                        <input id="exam_id" name="exam_id" type="hidden" value=''>
                        @if(request('course_id'))
                            <input name="course_id" type="hidden" value="{{ request('course_id') }}">
                        @endif
                    {!! Form::close() !!}
                    @include('admin.partials.course-authoring-draft', ['formId' => 'exam_form'])

                    <div class="text-center">
                        <a href="#" class="add_question add-question-btn">
                            <i class="fa fa-plus-circle"></i>
                            أضف سؤال جديد
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
