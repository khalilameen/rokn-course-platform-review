@extends('admin.layouts.app')
@section('page.title', 'إضافة مسؤول محتوى')
@section('content')
<div class="admin-page animated fadeIn">
    <div class="card admin-card">
        <div class="card-header"><strong>حساب مسؤول محتوى جديد</strong></div>
        <div class="card-body">
            <p class="text-muted">هذا الحساب يدير الكورسات والمدربين فقط، ولا يرى الطلاب أو المدفوعات أو الإعدادات الحساسة.</p>
            <form method="POST" action="{{ route('admin.moderators.store') }}">
                <input type="hidden" name="authoring_request_id" value="{{ old('authoring_request_id', (string) \Illuminate\Support\Str::uuid()) }}">
                @include('admin.moderators._form')
                <button class="btn btn-primary" type="submit">إنشاء الحساب</button>
                <a class="btn btn-light" href="{{ route('admin.moderators.index') }}">إلغاء</a>
            </form>
        </div>
    </div>
</div>
@endsection
