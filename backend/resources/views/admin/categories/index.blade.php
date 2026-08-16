@extends('admin.layouts.app')

@section('page.title', 'الأقسام')


@section('content')
    <div class="admin-page row">
        <div class="col-md-10 offset-md-1">
            <div class="card">
                <div class="card-header"><i class="fa fa-th-large"></i><strong class="card-title pr-2">القسم</strong>
                    <div class="pull-left"><a href="{{ route('admin.categories.create') }}"> إضافة قسم <i class="fa fa-plus-square-o"></i></a></div>
                </div>
                <div class="card-body card-block">
                    @foreach($categories as $categoriy)
                        <div class="row connection-block">
                        <div class="col-sm-9 col-xs-6 text-right">
                            <img class="ico_cat" src="{{ $categoriy->image ? $categoriy->image : '/images/cars/car-1.png' }}" />
                            {{ $categoriy->name }}
                        </div>
                        <div class="col-sm-3 col-xs-6 text-left">
                            <a href="{{ route('admin.categories.edit', $categoriy->id) }}" class="btn btn-sm btn-primary"><i class="fa fa-pencil-square"></i>&nbsp; تعديل</a>
                            <button type="submit" form="deleteForm{{$categoriy->id}}" class="btn btn-sm btn-danger"><i class="fa fa-close"></i>&nbsp; حذف</button>
                        </div>
                            <form class="d-none" id="deleteForm{{$categoriy->id}}" action="{{ route('admin.categories.destroy', $categoriy->id) }}" method="post">
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
