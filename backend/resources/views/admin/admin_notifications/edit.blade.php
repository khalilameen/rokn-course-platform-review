@extends('admin.layouts.app')

@section('page.title', 'تعديل الاشعار')

@section('content')
    <div class="row">
        <div class="col-md-8 offset-md-2">
            <div class="card">
                <div class="card-header"><i class="fa fa-th-large"></i><strong class="card-title pr-2">تعديل الاشعار</strong>
                </div>
                <div class="card-body card-block">
                    {!! Form::model($admin_notification,['method' => 'PATCH', 'files' => true, 'url' => route('admin.admin_notifications.update', $admin_notification->id), 'id' => 'notificationTemplateEditForm']) !!}
                        @include('admin.admin_notifications._form')
                    {!! Form::close() !!}
                    @include('admin.partials.course-authoring-draft', ['formId' => 'notificationTemplateEditForm'])
                </div>
            </div>
        </div>
  </div>
@endsection
