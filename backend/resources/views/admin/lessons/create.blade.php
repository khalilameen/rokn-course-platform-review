@extends('admin.layouts.app')

@section('page.title', 'اضافة درس')

@section('styles')
<link rel="stylesheet" href="{{ asset('admin/assets/css/admin-learning-views.css') }}">
@endsection

@section('content')
    <div class="admin-learning admin-learning--catalog admin-page">
        @include('admin.partials.page-header', [
            'pageTitle' => 'إضافة درس',
            'pageDescription' => 'أدخل بيانات الدرس ومصدر الفيديو والملفات المرتبطة.',
            'pageIcon' => 'fa-plus-circle',
        ])
    <div class="row">
        <div class="col-md-8 offset-md-2">
            <div class="card admin-card learning-form-card">
                <div class="card-header"><i class="fa fa-th-large"></i><strong class="card-title pr-2">أضافه درس</strong>
                </div>
                <div class="card-body card-block">
                    {!! Form::open(['method' => 'POST','files' => true, 'route' => ['admin.lessons.store']]) !!}
                        @include('admin.lessons._form')
                    {!! Form::close() !!}
                </div>
            </div>
        </div>
    </div>
    </div>
@endsection
