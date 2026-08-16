@extends('admin.layouts.app')

@section('page.title', 'ألأسئلة')

@section('styles')
<link rel="stylesheet" href="{{ asset('admin/assets/css/admin-learning-views.css') }}">
@endsection

@section('content')
    <div class="admin-learning admin-learning--catalog admin-page">
        @include('admin.partials.page-header', [
            'pageTitle' => 'الأسئلة',
            'pageDescription' => 'إدارة بنك الأسئلة والاختيارات الصحيحة.',
            'pageIcon' => 'fa-question-circle',
            'pageActionUrl' => route('admin.questions.create'),
            'pageActionLabel' => 'إضافة سؤال',
            'pageActionIcon' => 'fa-plus',
            'pageActionClass' => 'btn-primary',
        ])
    <div class="row">
        <div class="col-md-10 offset-md-1">
            <div class="card admin-card">
                <div class="card-header"><i class="fa fa-th-large"></i><strong class="card-title pr-2">السؤال</strong>
                </div>
                <div class="card-body card-block">
                    @foreach($questions as $question)
                        <div class="row connection-block learning-list__item">
                        <div class="col-sm-9 col-xs-6 text-right">
                            <img class="ico_cat learning-list__image" src="{{ $question->image ? $question->image : '/images/question.png' }}" alt="" />
                            {{ $question->title }}
                        </div>
                        <div class="col-sm-3 col-xs-6 text-left">
                            <a href="{{ route('admin.questions.edit', $question->id) }}" class="btn btn-sm btn-primary"><i class="fa fa-pencil-square"></i>&nbsp; تعديل</a>
                            <a onclick="document.getElementById('deleteForm{{$question->id}}').submit()" href="#" class="btn btn-sm btn-danger"><i class="fa fa-close"></i>&nbsp; حذف</a>
                        </div>
                            <form class="admin-inline-hidden" id="deleteForm{{$question->id}}" action="{{ route('admin.questions.destroy', $question->id) }}" method="post">
                                <input name="_method" type="hidden" value="DELETE">
                                @csrf
                            </form>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
    </div>
@endsection
