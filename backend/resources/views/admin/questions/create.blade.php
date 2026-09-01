@extends('admin.layouts.app')

@section('page.title', 'اضافة سؤال')

@section('styles')
<link rel="stylesheet" href="{{ asset('admin/assets/css/admin-learning-views.css') }}">
@endsection

@section('content')
    <div class="admin-learning admin-learning--catalog admin-page">
        @include('admin.partials.page-header', [
            'pageTitle' => 'إضافة سؤال',
            'pageDescription' => 'أضف نص السؤال واختياراته وإجابته الصحيحة.',
            'pageIcon' => 'fa-plus-circle',
        ])
    <div class="row">
        <div class="col-md-8 offset-md-2">
            <div class="card admin-card learning-form-card">
                <div class="card-header"><i class="fa fa-th-large"></i><strong class="card-title pr-2">أضافه سؤال</strong>
                </div>
                <div class="card-body card-block">
                    {!! Form::open(['method' => 'POST','files' => true, 'route' => ['admin.questions.store'], 'id' => 'questionForm']) !!}
                        <input type="hidden" name="authoring_request_id" value="{{ old('authoring_request_id', (string) Str::uuid()) }}">
                        @include('admin.questions._form')
                    {!! Form::close() !!}
                    @include('admin.partials.course-authoring-draft', ['formId' => 'questionForm'])
                </div>
            </div>
        </div>
    </div>
    </div>
@endsection
