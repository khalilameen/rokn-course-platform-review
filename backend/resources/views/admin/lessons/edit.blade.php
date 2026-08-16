@extends('admin.layouts.app')

@section('page.title', 'تعديل الدرس')

@section('styles')
<link rel="stylesheet" href="{{ asset('admin/assets/css/admin-learning-views.css') }}">
@endsection

@section('content')
    <div class="admin-learning admin-learning--catalog admin-page">
        @include('admin.partials.page-header', [
            'pageTitle' => 'تعديل الدرس',
            'pageDescription' => 'حدّث بيانات الدرس مع الحفاظ على مصادر المحتوى الحالية.',
            'pageIcon' => 'fa-pencil-square',
        ])
    <div class="row">
        <div class="col-md-8 offset-md-2">
            <div class="card admin-card learning-form-card">
                <div class="card-header"><i class="fa fa-th-large"></i><strong class="card-title pr-2">تعديل الدرس</strong>
                </div>
                <div class="card-body card-block">
                    {!! Form::model($lesson,['method' => 'PATCH', 'files' => true, 'url' => route('admin.lessons.update', $lesson->id)]) !!}
                        @include('admin.lessons._form')
                    {!! Form::close() !!}
                </div>
            </div>
        </div>
    </div>
    </div>
@endsection
