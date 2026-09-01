@extends('admin.layouts.app')

@section('page.title', 'الاختبارات')

@section('styles')
<link rel="stylesheet" href="{{ asset('admin/assets/css/admin-learning-views.css') }}">
@endsection

@section('content')
    <div class="quiz-container admin-learning admin-learning--quiz">
        <div class="row">
            <div class="col-12">
                <div class="card border-0">
                    <div class="quiz-header">
                        <div class="d-flex justify-content-between align-items-center flex-wrap admin-learning__toolbar">
                            <h3>
                                <span class="icon-wrapper">
                                    <i class="fa fa-clipboard"></i>
                                </span>
                                إدارة الاختبارات
                            </h3>
                            <a href="{{ route('admin.quizzes.create') }}" class="add-quiz-btn">
                                <i class="fa fa-plus-circle"></i>
                                إضافة اختبار جديد
                            </a>
                        </div>
                    </div>

                    <div class="card-body admin-learning__body">
                        @if($quizzes && count($quizzes) > 0)
                            <div class="row">
                                @foreach($quizzes as $quizz)
                                    <div class="col-12">
                                        <div class="quiz-card">
                                            <div class="quiz-card-body">
                                                <div class="quiz-content">
                                                    <div class="quiz-info">
                                                        <img class="quiz-image" src="{{ $quizz->image ? $quizz->image : '/images/quizz.png' }}"/>
                                                        <h4 class="quiz-title">{{ $quizz->title }}</h4>
                                                    </div>

                                                    <div class="quiz-actions">
                                                        <form action="{{ route('admin.quizzes.copy', $quizz->id) }}" method="POST" class="d-inline">
                                                            @csrf
                                                            <input type="hidden" name="editor_version" value="{{ hash('sha256', $quizz->id.'|'.optional($quizz->updated_at)->format('Y-m-d H:i:s.u')) }}">
                                                            <input type="hidden" name="authoring_request_id" value="{{ (string) Str::uuid() }}">
                                                            <button type="submit" class="btn btn-modern btn-copy">
                                                                <i class="fa fa-copy"></i>
                                                                نسخ
                                                            </button>
                                                        </form>
                                                        <a href="{{ route('admin.quizzes.edit', $quizz->id) }}" class="btn btn-modern btn-edit">
                                                            <i class="fa fa-edit"></i>
                                                            تعديل
                                                        </a>
                                                        <a onclick="if(confirm('هل أنت متأكد من حذف هذا الاختبار؟')) { document.getElementById('deleteForm{{$quizz->id}}').submit(); }" href="#" class="btn btn-modern btn-delete">
                                                            <i class="fa fa-trash"></i>
                                                            حذف
                                                        </a>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <form class="admin-inline-hidden" id="deleteForm{{$quizz->id}}" action="{{ route('admin.quizzes.destroy', $quizz->id) }}" method="post">
                                            <input name="_method" type="hidden" value="DELETE">
                                            @csrf
                                            <input type="hidden" name="editor_version" value="{{ hash('sha256', $quizz->id.'|'.optional($quizz->updated_at)->format('Y-m-d H:i:s.u')) }}">
                                        </form>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="empty-state">
                                <div class="empty-state-icon">
                                    <i class="fa fa-clipboard"></i>
                                </div>
                                <h3 class="empty-state-title">لا توجد اختبارات حتى الآن</h3>
                                <p class="empty-state-text">ابدأ بإنشاء اختبار جديد للطلاب</p>
                                <a href="{{ route('admin.quizzes.create') }}" class="add-quiz-btn">
                                    <i class="fa fa-plus-circle"></i>
                                    إضافة اختبار جديد
                                </a>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
