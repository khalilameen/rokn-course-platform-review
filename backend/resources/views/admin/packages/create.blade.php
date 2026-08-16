@extends('admin.layouts.app')

@section('page.title', 'إضافة باقة جديدة')

@section('content')
<div class="card">
    <div class="card-header">
        <strong>إضافة باقة جديدة</strong>
    </div>
    <div class="card-body card-block">
        <form action="{{ route('admin.packages.store') }}" method="post" class="form-horizontal">
            @csrf
            <div class="row form-group">
                <div class="col col-md-3"><label for="name_ar" class=" form-control-label">الاسم (AR)</label></div>
                <div class="col-12 col-md-9">
                    <input type="text" id="name_ar" name="name_ar" placeholder="أدخل الاسم بالعربية" class="form-control" value="{{ old('name_ar') }}" required>
                </div>
            </div>
            <div class="row form-group">
                <div class="col col-md-3"><label for="name_en" class=" form-control-label">الاسم (EN)</label></div>
                <div class="col-12 col-md-9">
                    <input type="text" id="name_en" name="name_en" placeholder="Enter name in English" class="form-control" value="{{ old('name_en') }}" required>
                </div>
            </div>
            <div class="row form-group">
                <div class="col col-md-3"><label for="price" class=" form-control-label">السعر</label></div>
                <div class="col-12 col-md-9">
                    <input type="number" id="price" name="price" step="0.01" placeholder="0.00" class="form-control" value="{{ old('price') }}" required>
                </div>
            </div>
            <div class="row form-group">
                <div class="col col-md-3"><label for="coins" class=" form-control-label">عدد العملات (Coins)</label></div>
                <div class="col-12 col-md-9">
                    <input type="number" id="coins" name="coins" placeholder="0" class="form-control" value="{{ old('coins') }}" required>
                </div>
            </div>
            <div class="card-footer">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="fa fa-dot-circle-o"></i> حفظ
                </button>
                <button type="reset" class="btn btn-danger btn-sm">
                    <i class="fa fa-ban"></i> إعادة تعيين
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
