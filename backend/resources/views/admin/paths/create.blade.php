@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">
    <div class="row page-titles">
        <div class="col-md-5 align-self-center">
            <h4 class="text-themecolor">إضافة مسار جديد</h4>
        </div>
        <div class="col-md-7 align-self-center text-end">
            <div class="d-flex justify-content-end align-items-center">
                <ol class="breadcrumb justify-content-end">
                    <li class="breadcrumb-item active">إضافة</li>
                </ol>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <form action="{{ route('admin.paths.store') }}" method="POST" id="pathForm">
                        @csrf
                        @include('admin.paths._form')
                        <div class="form-actions mt-4">
                            <button type="submit" class="btn btn-success text-white"> <i class="fa fa-check"></i> حفظ</button>
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
