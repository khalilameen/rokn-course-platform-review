@extends('admin.layouts.app')
@section('page.title', 'تعديل مسؤول المحتوى')
@section('content')
<div class="admin-page animated fadeIn">
    <div class="card admin-card">
        <div class="card-header"><strong>تعديل مسؤول المحتوى</strong></div>
        <div class="card-body">
            <form method="POST" action="{{ route('admin.moderators.update', $moderator) }}">
                @include('admin.moderators._form')
                <button class="btn btn-primary" type="submit">حفظ</button>
                <a class="btn btn-light" href="{{ route('admin.moderators.index') }}">إلغاء</a>
            </form>
        </div>
    </div>
</div>
@endsection
