@extends('admin.layouts.app')

@section('page.title', 'اشعارات الإدارة')


@section('content')
    <div class="admin-page row">
        <div class="col-md-10 offset-md-1">
            <div class="card">
                <div class="card-header"><i class="fa fa-th-large"></i><strong class="card-title pr-2">اشعارات الإدارة</strong>
                    <div class="pull-left"><a href="{{ route('admin.admin_notifications.create') }}">إضافة اشعار  <i class="fa fa-plus-square-o"></i></a></div>
                </div>
                <div class="card-body card-block">
                    @foreach($admin_notifications as $admin_notification)
                        <div class="row connection-block">
                        <div class="col-sm-9 col-xs-6 text-right">
                            <img class="ico_cat" src="{{ $admin_notification->image ? $admin_notification->image : '/images/cars/car-1.png' }}" />
                            {{ $admin_notification->name }}
                        </div>
                        <div class="col-sm-3 col-xs-6 text-left">
                            <a href="{{ route('admin.admin_notifications.edit', $admin_notification->id) }}" class="btn btn-sm btn-primary"><i class="fa fa-pencil-square"></i>&nbsp; تعديل</a>
                            <button type="submit" form="deleteForm{{$admin_notification->id}}" class="btn btn-sm btn-danger"><i class="fa fa-close"></i>&nbsp; حذف</button>
                        </div>
                            <form class="d-none" id="deleteForm{{$admin_notification->id}}" action="{{ route('admin.admin_notifications.destroy', $admin_notification->id) }}" method="post">
                                <input name="_method" type="hidden" value="DELETE">
                                @csrf
                            </form>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
@endsection
