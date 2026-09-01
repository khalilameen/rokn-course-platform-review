@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">
    <div class="row page-titles">
        <div class="col-md-5 align-self-center">
            <h4 class="text-themecolor">تعديل المسار</h4>
        </div>
        <div class="col-md-7 align-self-center text-end">
            <div class="d-flex justify-content-end align-items-center">
                <ol class="breadcrumb justify-content-end">

                    <li class="breadcrumb-item active">تعديل</li>
                </ol>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <form action="{{ route('admin.paths.update', $path->id) }}" method="POST" id="pathForm">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="editor_version" value="{{ $editorVersion }}">
                        @include('admin.paths._form')
                        <div class="form-actions mt-4">
                            <button type="submit" class="btn btn-success text-white"> <i class="fa fa-check"></i> تحديث</button>
                            <a href="{{ route('admin.paths.index') }}" class="btn btn-dark">إلغاء</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
    @include('admin.partials.course-authoring-draft', ['formId' => 'pathForm'])
@endsection
