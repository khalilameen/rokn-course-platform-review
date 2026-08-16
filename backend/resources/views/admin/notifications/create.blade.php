@extends('admin.layouts.app')
@section('page.title', 'إرسال إشعار لجميع الطلاب')
@section('styles')
    <link rel="stylesheet" href="{{ asset('admin/assets/css/notifications-dashboard.css') }}">
@endsection
@section('breadcrumbs')
    <div class="col-sm-12">
        <div class="page-header float-right">
            <div class="page-title">
                <h1>الإشعارات</h1>
            </div>
        </div>
    </div>
@endsection
@section('content')
    <div class="admin-page notification-form-page row">
        <div class="col-md-8 offset-md-2">
            <div class="card">
                <div class="card-header">
                    <i class="fa fa-bell-o"></i>
                    <strong class="card-title pr-2">إرسال إشعار لجميع الطلاب</strong>
                </div>
                <div class="card-body card-block">
                    @if(session('success'))
                        <div class="alert alert-success">{{ session('success') }}</div>
                    @endif
                    @if($errors->any())
                        <div class="alert alert-danger">
                            <ul class="mb-0">
                                @foreach($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    {!! Form::open(['method' => 'POST', 'route' => ['admin.notifications.store']]) !!}
                        <div class="form-group">
                            <label for="notification-course">الكورس (اختياري)</label>
                            <select name="course_id" id="notification-course" class="form-control">
                                <option value="">إشعار عام</option>
                                @foreach($courses as $course)
                                    <option value="{{ $course->id }}" {{ (string) old('course_id') === (string) $course->id ? 'selected' : '' }}>
                                        {{ $course->name_ar }}
                                    </option>
                                @endforeach
                            </select>
                            <small class="form-text text-muted">اختيار كورس مطلوب عند استهداف المشتركين أو غير المشتركين ويجعل الضغط يفتح صفحة الكورس.</small>
                        </div>
                        <div class="form-group">
                            <label for="notification-audience">الجمهور</label>
                            <select name="audience" id="notification-audience" class="form-control" required>
                                <option value="not_enrolled" {{ old('audience') === 'not_enrolled' ? 'selected' : '' }}>غير المشتركين في الكورس</option>
                                <option value="enrolled" {{ old('audience') === 'enrolled' ? 'selected' : '' }}>المشتركون في الكورس</option>
                                <option value="all" {{ old('audience', 'all') === 'all' ? 'selected' : '' }}>كل الطلاب</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="notification-title">عنوان الإشعار <span class="text-danger">*</span></label>
                            <input name="title" id="notification-title" placeholder="عنوان الإشعار..." class="form-control" type="text" required value="{{ old('title') }}">
                        </div>
                        <div class="form-group">
                            <label for="notification-message">نص الإشعار <span class="text-danger">*</span></label>
                            <textarea name="message" id="notification-message" placeholder="اكتب نص الإشعار هنا..." class="form-control" rows="4" required>{{ old('message') }}</textarea>
                        </div>
                        <div class="form-group">
                            <label for="notification-title-en">English title <small class="text-muted">(optional)</small></label>
                            <input name="title_en" id="notification-title-en" class="form-control" type="text" value="{{ old('title_en') }}" dir="ltr">
                        </div>
                        <div class="form-group">
                            <label for="notification-message-en">English message <small class="text-muted">(optional)</small></label>
                            <textarea name="message_en" id="notification-message-en" class="form-control" rows="3" dir="ltr">{{ old('message_en') }}</textarea>
                        </div>
                        <div class="form-group">
                            <button type="submit" class="btn btn-primary btn-block">
                                <i class="fa fa-paper-plane"></i> إضافة إلى قائمة الإرسال
                            </button>
                        </div>
                    {!! Form::close() !!}
                </div>
            </div>
        </div>
    </div>
@endsection
