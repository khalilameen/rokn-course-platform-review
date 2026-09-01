@extends('admin.layouts.app')

@section('page.title', 'تعديل الباقة')

@section('content')
<div class="card">
    <div class="card-header">
        <strong>تعديل الباقة: {{ $package->name_ar }}</strong>
    </div>
    <div class="card-body card-block">
        <form action="{{ route('admin.packages.update', $package->id) }}" method="post" class="form-horizontal">
            @csrf
            @method('PUT')
            <div class="row form-group">
                <div class="col col-md-3"><label for="name_ar" class=" form-control-label">الاسم (AR)</label></div>
                <div class="col-12 col-md-9">
                    <input type="text" id="name_ar" name="name_ar" placeholder="أدخل الاسم بالعربية" class="form-control" value="{{ old('name_ar', $package->name_ar) }}" required>
                </div>
            </div>
            <div class="row form-group">
                <div class="col col-md-3"><label for="name_en" class=" form-control-label">الاسم (EN)</label></div>
                <div class="col-12 col-md-9">
                    <input type="text" id="name_en" name="name_en" placeholder="Enter name in English" class="form-control" value="{{ old('name_en', $package->name_en) }}" required>
                </div>
            </div>
            <div class="row form-group">
                <div class="col col-md-3"><label for="price" class=" form-control-label">السعر</label></div>
                <div class="col-12 col-md-9">
                    <input type="number" id="price" name="price" step="0.01" placeholder="0.00" class="form-control" value="{{ old('price', $package->price) }}" required>
                </div>
            </div>
            <div class="row form-group">
                <div class="col col-md-3"><label for="coins" class=" form-control-label">عدد العملات (Coins)</label></div>
                <div class="col-12 col-md-9">
                    <input type="number" id="coins" name="coins" placeholder="0" class="form-control" value="{{ old('coins', $package->coins) }}" @readonly(filled($package->google_product_id) || filled($package->apple_product_id)) required>
                    @if(filled($package->google_product_id) || filled($package->apple_product_id))
                        <small class="form-text text-muted">عدد العملات ثابت بعد ربط منتج متجر. للسعر أو الرصيد الجديد أنشئ باقة جديدة.</small>
                    @endif
                </div>
            </div>
            <div class="row form-group">
                <div class="col col-md-3"><label class="form-control-label">الإتاحة</label></div>
                <div class="col-12 col-md-9">
                    <label class="mr-3"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $package->is_active))> ظاهرة في الكتالوج</label>
                    <label><input type="checkbox" name="direct_enabled" value="1" @checked(old('direct_enabled', $package->direct_enabled))> متاحة عبر كاشير</label>
                </div>
            </div>
            <div class="row form-group">
                <div class="col col-md-3"><label for="google_product_id" class="form-control-label">منتج Google Play</label></div>
                <div class="col-12 col-md-9">
                    <input type="text" id="google_product_id" name="google_product_id" class="form-control" value="{{ old('google_product_id', $package->google_product_id) }}" placeholder="rokn.coins.4200" @readonly(filled($package->google_product_id))>
                    <label class="mt-2"><input type="checkbox" name="google_enabled" value="1" @checked(old('google_enabled', $package->google_enabled))> متاح للشراء في نسخة Play</label>
                </div>
            </div>
            <div class="row form-group">
                <div class="col col-md-3"><label for="apple_product_id" class="form-control-label">منتج App Store</label></div>
                <div class="col-12 col-md-9">
                    <input type="text" id="apple_product_id" name="apple_product_id" class="form-control" value="{{ old('apple_product_id', $package->apple_product_id) }}" placeholder="com.rokn.coins.4200" @readonly(filled($package->apple_product_id))>
                    <label class="mt-2"><input type="checkbox" name="apple_enabled" value="1" @checked(old('apple_enabled', $package->apple_enabled))> متاح للشراء في نسخة App Store</label>
                </div>
            </div>
            <div class="card-footer">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="fa fa-dot-circle-o"></i> تحديث
                </button>
                <a href="{{ route('admin.packages.index') }}" class="btn btn-secondary btn-sm">
                    <i class="fa fa-arrow-left"></i> رجوع
                </a>
            </div>
        </form>
    </div>
</div>
@endsection
