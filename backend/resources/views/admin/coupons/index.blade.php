@extends('admin.layouts.app')

@section('page.title', 'الكوبونات')


@section('content')
    <div class="admin-page row">
        <div class="col-md-10 offset-md-1">
            <div class="card">
                <div class="card-header"><i class="fa fa-th-large"></i><strong class="card-title pr-2">الكوبون</strong>
                    <div class="pull-left"><a href="{{ route('admin.coupons.create') }}"> إضافة كوبون <i class="fa fa-plus-square-o"></i></a></div>
                </div>
                <div class="card-body card-block">
                    @foreach($coupons as $coupon)
                        <div class="row connection-block">
                        <div class="col-sm-2 col-xs-4 text-right">
                            
                            {{ $coupon->name_ar }}
                        </div>
                        <div class="col-sm-2 col-xs-4 text-right">
                            
                           <span title="كود الكوبون"  >{{ $coupon->code }}</span>
                        </div>
                        <div class="col-sm-2 col-xs-4 text-right">
                            
                           <span title="نسبة الخصم">{{ (int) $coupon->balance }}٪</span>
                           <span title="مرات الاستخدام">{{ (int) $coupon->redemptions_count }} استخدام</span>
                           <span title="النطاق">{{ $coupon->course?->name_ar ?: 'كل الكورسات' }}</span>
                           <span title="الحد">{{ $coupon->max_redemptions ? $coupon->redemptions_count . ' / ' . $coupon->max_redemptions : 'بلا حد كلي' }}</span>
                           <span title="تاريخ الانتهاء">{{ optional($coupon->expiry_date)->format("Y-m-d") ?: 'بلا تاريخ' }}</span>
                        </div> 
                        <div class="col-sm-2 col-xs-4 text-right">
                            
                           <span title="حالو الكوبون"  >{{ ($coupon->active)?"مفعل": "غير مفعل" }}</span>
                        </div>                       
                        <div class="col-sm-3 col-xs-6 text-left">
                            <a href="{{ route('admin.coupons.edit', $coupon->id) }}" class="btn btn-sm btn-primary"><i class="fa fa-pencil-square"></i>&nbsp; تعديل</a>
                            <button type="submit" form="deleteForm{{$coupon->id}}" class="btn btn-sm btn-danger"><i class="fa fa-close"></i>&nbsp; حذف</button>
                        </div>
                            <form class="d-none" id="deleteForm{{$coupon->id}}" action="{{ route('admin.coupons.destroy', $coupon->id) }}" method="post">
                                <input name="_method" type="hidden" value="DELETE">
                                @csrf
                                <input type="hidden" name="editor_version" value="{{ $editorVersions->get($coupon->id) }}">
                            </form>
                    </div>
                    @endforeach
                    @if($coupons->hasPages())
                        <div class="mt-3">{{ $coupons->links() }}</div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
