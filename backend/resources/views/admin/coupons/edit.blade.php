@extends('admin.layouts.app')

@section('page.title', 'تعديل الكوبون')

@section('content')
    <div class="row">
        <div class="col-md-8 offset-md-2">
            <div class="card">
                <div class="card-header"><i class="fa fa-th-large"></i><strong class="card-title pr-2">تعديل الكوبون</strong>
                </div>
                <div class="card-body card-block">
                    {!! Form::model($coupon,['method' => 'PATCH', 'files' => true, 'url' => route('admin.coupons.update', $coupon->id)]) !!}
                        @include('admin.coupons._form')
                    {!! Form::close() !!}
                </div>
            </div>
        </div>
  </div>
@endsection
