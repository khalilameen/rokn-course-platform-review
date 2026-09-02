@extends('admin.layouts.app')

@section('page.title', 'اضافة اشعار')

@section('content')
    <div class="row">
        <div class="col-md-8 offset-md-2">
            <div class="card">
                <div class="card-header"><i class="fa fa-th-large"></i><strong class="card-title pr-2">أضافه اشعار</strong>
                </div>
                <div class="card-body card-block">
                    {!! Form::open(['method' => 'POST','files' => true, 'route' => ['admin.admin_notifications.store']]) !!}
                        <input type="hidden" name="authoring_request_id" value="{{ old('authoring_request_id', (string) \Illuminate\Support\Str::uuid()) }}">
                        @include('admin.admin_notifications._form')
                    {!! Form::close() !!}
                </div>
            </div>
        </div>
  </div>
@endsection
