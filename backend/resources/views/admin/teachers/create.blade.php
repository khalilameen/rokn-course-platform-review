@extends('admin.layouts.app')

@section('page.title', 'إضافة معلم جديد')

@section('content')
<div class="admin-page animated fadeIn">
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <strong>إضافة معلم جديد</strong>
                </div>
                <div class="card-body card-block">
                    <form action="{{ route('admin.teachers.store') }}" method="POST" enctype="multipart/form-data" class="form-horizontal" id="teacherForm">
                        @csrf
                        <input type="hidden" name="authoring_request_id" value="{{ old('authoring_request_id', (string) \Illuminate\Support\Str::uuid()) }}">
                        @include('admin.teachers._form')
                        
                        <div class="form-actions form-group">
                            <button type="submit" class="btn btn-primary btn-sm">حفظ</button>
                            <a href="{{ route('admin.teachers.index') }}" class="btn btn-secondary btn-sm">إلغاء</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
@include('admin.partials.course-authoring-draft', ['formId' => 'teacherForm'])
@endsection
