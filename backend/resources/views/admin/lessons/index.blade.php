@extends('admin.layouts.app')

@section('page.title', 'ألدروس')

@section('styles')
<link rel="stylesheet" href="{{ asset('admin/assets/css/admin-learning-views.css') }}">
@endsection

@section('content')
    <div class="admin-learning admin-learning--catalog admin-page">
        @include('admin.partials.page-header', [
            'pageTitle' => 'الدروس',
            'pageDescription' => 'إدارة محتوى الدروس وظهوره للطلاب.',
            'pageIcon' => 'fa-play-circle',
            'pageActionUrl' => route('admin.lessons.create'),
            'pageActionLabel' => 'إضافة درس',
            'pageActionIcon' => 'fa-plus',
            'pageActionClass' => 'btn-primary',
        ])
    <div class="row">
        <div class="col-md-10 offset-md-1">
            <div class="card admin-card">
                <div class="card-header"><i class="fa fa-th-large"></i><strong class="card-title pr-2 learning-list__header-title">الدرس</strong>
                <strong class="card-title pr-2">الظهور</strong>
                </div>
                <div class="card-body card-block">
                    @foreach($lessons as $lesson)
                        <div class="row connection-block learning-list__item">
                        <div class="col-sm-6 col-xs-3 text-right">
                            <img class="ico_cat learning-list__image" src="{{ $lesson->image ? $lesson->image : '/images/lesson.png' }}" alt="" />
                            {{ $lesson->title }}
                        </div>
                         <div class="col-sm-3 col-xs-3 text-right">

                            {{ $lesson->is_opened ? "كل الزوار" : "الأعضاء فقط" }}
                        </div>
                        <div class="col-sm-3 col-xs-6 text-left">
                            <a href="{{ route('admin.lessons.edit', $lesson->id) }}" class="btn btn-sm btn-primary"><i class="fa fa-pencil-square"></i>&nbsp; تعديل</a>
                            <a onclick="document.getElementById('deleteForm{{$lesson->id}}').submit()" href="#" class="btn btn-sm btn-danger"><i class="fa fa-close"></i>&nbsp; حذف</a>
                        </div>
                            <form class="admin-inline-hidden" id="deleteForm{{$lesson->id}}" action="{{ route('admin.lessons.destroy', $lesson->id) }}" method="post">
                                <input name="_method" type="hidden" value="DELETE">
                                @csrf
                            </form>
                    </div>
                    @endforeach

                    <!-- Pagination Links -->
                    <div class="d-flex justify-content-center admin-learning__pagination">
                        {{ $lessons->links() }}
                    </div>

                </div>
            </div>
        </div>
    </div>
    </div>
@endsection
