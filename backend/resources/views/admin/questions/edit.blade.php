@extends('admin.layouts.app')

@section('page.title', 'تعديل السؤال')

@section('styles')
<link rel="stylesheet" href="{{ asset('admin/assets/css/admin-learning-views.css') }}">
@endsection

@section('content')
    <div class="admin-learning admin-learning--catalog admin-page">
        @include('admin.partials.page-header', [
            'pageTitle' => 'تعديل السؤال',
            'pageDescription' => 'حدّث نص السؤال والاختيارات والإجابة الصحيحة.',
            'pageIcon' => 'fa-pencil-square',
        ])
    <div class="row">
        <div class="col-md-8 offset-md-2">
            <div class="card admin-card learning-form-card">
                <div class="card-header"><i class="fa fa-th-large"></i><strong class="card-title pr-2">تعديل السؤال</strong>
                </div>
                <div class="card-body card-block">
                    {!! Form::model($question,['method' => 'PATCH', 'files' => true, 'url' => route('admin.questions.update', $question->id), 'id' => 'questionForm']) !!}
                        <input type="hidden" name="editor_version" value="{{ hash('sha256', $question->id.'|'.optional($question->updated_at)->format('Y-m-d H:i:s.u')) }}">
                        @include('admin.questions._form')
                    {!! Form::close() !!}
                    @include('admin.partials.course-authoring-draft', ['formId' => 'questionForm'])
                </div>
            </div>
        </div>
    </div>
    </div>
@endsection
