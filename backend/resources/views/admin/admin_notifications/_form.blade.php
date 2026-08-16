<div class="row form-group">
    <div class="col-md-2">
        <label for="title_ar'" class="form-control-label">أسم الاشعار</label>
    </div>
    <div class="col-md-10">
        {!! Form::text('title_ar', null, ['class' => 'form-control' , 'required', 'id'=>"title_ar"] )!!}
    </div>
</div>
<div class="row form-group">
    <div class="col-md-2">
        <label for="title_en'" class="form-control-label">أسم الاشعار باللإنجليزية</label>
    </div>
    <div class="col-md-10">
        {!! Form::text('title_en', null, ['class' => 'form-control' , 'required', 'id'=>"title_en"] )!!}
    </div>
</div>
<div class="row form-group">
    <div class="col-md-2">
        <label for="description_ar'" class="form-control-label">وصف الاشعار باللغة العربية</label>
    </div>
    <div class="col-md-10">
        {!! Form::text('description_ar', null, ['class' => 'form-control' , 'required', 'id'=>"description_ar"] )!!}
    </div>
</div>

<div class="row form-group">
    <div class="col-md-2">
        <label for="description_en'" class="form-control-label">وصف الاشعار باللغة الانجليزية</label>
    </div>
    <div class="col-md-10">
        {!! Form::text('description_en', null, ['class' => 'form-control' , 'required', 'id'=>"description_en"] )!!}
    </div>
</div>
<div class="row form-group">
    <div class="col-md-2">
        <label for="description_ar'" class="form-control-label">رابط الاشعار</label>
    </div>
    <div class="col-md-10">
        {!! Form::text('link', null, ['class' => 'form-control' , 'required', 'id'=>"link"] )!!}
    </div>
</div>
<div class="row form-group">
    <div class="col-md-2">
        <label for="image" class="form-control-label">الصورة</label>
    </div>
    <div class="col-md-10">
        <input id="image" name="image" class="form-control-file" type="file" required>
    </div>
</div>
<div class="form-actions form-group">
    <button type="submit" class="btn btn-success btn-sm">حفظ</button>
    <a href="{{ route('admin.admin_notifications.index') }}" class="btn btn-danger btn-sm">إلغاء</a>
</div>
