<div class="row form-group">
    <div class="col-md-2">
        <label for="name_ar'" class="form-control-label">أسم الكوبون</label>
    </div>
    <div class="col-md-10">
        {!! Form::text('name_ar', null, ['class' => 'form-control' , 'required', 'id'=>"name_ar"] )!!}
    </div>
</div>
<div class="row form-group">
    <div class="col-md-2">
        <label for="name_en'" class="form-control-label">أسم الكوبون باللإنجليزية</label>
    </div>
    <div class="col-md-10">
        {!! Form::text('name_en', null, ['class' => 'form-control' , 'required', 'id'=>"name_en"] )!!}
    </div>
</div>
<div class="row form-group">
    <div class="col-md-2">
        <label for="name_ar'" class="form-control-label">كود الكوبون</label>
    </div>
    <div class="col-md-10">
        {!! Form::text('code', null, ['class' => 'form-control' , 'required', 'id'=>"code"] )!!}
    </div>
</div>
<div class="row form-group">
    <div class="col-md-2">
        <label for="name_en'" class="form-control-label">نسبة الخصم</label>
    </div>
    <div class="col-md-10">
        {!! Form::number('balance', null, ['class' => 'form-control' , 'required', 'id'=>"balance","max"=>"100","min"=>"1"] )!!}
    </div>
</div>

<div class="row form-group">
    <div class="col-md-2">
        <label for="name_en'" class="form-control-label">تاريخ الانتهاء</label>
    </div>
    <div class="col-md-10">
        {!! Form::date('expiry_date', $coupon->expiry_date->format('m/d/Y'), ['class' => 'form-control' , 'required', 'id'=>"expiry_date","min"=>\Carbon\Carbon::now()] )!!}
    </div>
</div>
<div class="row form-group">
    <div class="col-md-2">
        <label for="name_en'" class="form-control-label"> مفعل</label>
    </div>
    <div class="col-md-10 ">
         
         <select class=" form-control"  name="active" >
            <option value="0" {{ (@$coupon->active == 0)? 'selected':'' }}>غير مفعل</option>
            <option value="1"  {{ (@$coupon->active== 1)? 'selected':'' }}   >مفعل</option>
          </select>
    </div>
</div>
<!--
<div class="row form-group">
    <div class="col-md-2">
        <label for="image" class="form-control-label">الصورة</label>
    </div>
    <div class="col-md-10">
        <input id="image" name="image" class="form-control-file" type="file" required>
    </div>
</div>-->
<div class="form-actions form-group">
    <button type="submit" class="btn btn-success btn-sm">حفظ</button>
    <a href="{{ route('admin.coupons.index') }}" class="btn btn-danger btn-sm">إلغاء</a>
</div>
