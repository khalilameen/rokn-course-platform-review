@extends('admin.layouts.app')

@section('page.title', 'تعديل الاختبار')

@section('styles')
<link rel="stylesheet" href="{{ asset('admin/assets/css/admin-learning-views.css') }}">
@endsection

@section('content')
    <div class="row admin-learning admin-learning--quiz">
        <div class="col-12">
            <div class="card border-0 shadow">
                <div id="card" class="page-header-modern">
                    <div class="d-flex justify-content-between align-items-center flex-wrap admin-learning__toolbar">
                        <h3>
                            <span class="icon-wrapper">
                                <i class="fa fa-edit"></i>
                            </span>
                            تعديل الاختبار
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
                    {!! Form::model($quiz,['method' => 'POST', 'files' => true, 'url' => route('admin.quizzes.store'), 'id' => 'exam_form', 'data-question-numbers'=>0 ]) !!}
                        @include('admin.quizzes._form')

                        <input id="exam_id" name="exam_id" type="hidden" value='{{$quiz->id}}'>
                        @if(request('course_id'))
                            <input name="course_id" type="hidden" value="{{ request('course_id') }}">
                        @endif

                        <div>
                            @if($quiz->questions)
                                <div class="questions-section-header">
                                    <h4>
                                        <span class="icon-wrapper">
                                            <i class="fa fa-list"></i>
                                        </span>
                                        أسئلة الاختبار
                                    </h4>
                                </div>

                                @foreach($quiz->questions as $question)
                                    @include('admin.quizzes.question', ['question' => $question, 'question_index' => $loop->index])
                                @endforeach
                            @endif
                        </div>
                    {!! Form::close() !!}

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
