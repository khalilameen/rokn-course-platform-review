<div class="row form-group">
    <div class="col-md-2">
        <label for="name_ar'" class="form-control-label">أسم القسم</label>
    </div>
    <div class="col-md-10">
        {!! Form::text('name_ar', null, ['class' => 'form-control' , 'required', 'id'=>"name_ar"] )!!}
    </div>
</div>

<div class="row form-group">
    <div class="col-md-2">
        <label for="name_en" class="form-control-label">اسم القسم بالإنجليزية</label>
    </div>
    <div class="col-md-10">
        {!! Form::text('name_en', null, ['class' => 'form-control', 'id' => 'name_en'] )!!}
    </div>
</div>

<div class="row form-group">
    <div class="col-md-2">
        <label for="description_ar" class="form-control-label">وصف مختصر</label>
    </div>
    <div class="col-md-10">
        {!! Form::textarea('description_ar', null, ['class' => 'form-control', 'id' => 'description_ar', 'rows' => 3] )!!}
    </div>
</div>

<div class="row form-group">
    <div class="col-md-2">
        <label for="image" class="form-control-label">الصورة</label>
    </div>
    <div class="col-md-10">
        <input id="image" name="image" class="form-control-file" type="file" accept="image/*">
    </div>
</div>
<div class="form-actions form-group">
    <button type="submit" class="btn btn-success btn-sm">حفظ</button>
    <a href="{{ route('admin.categories.index') }}" class="btn btn-danger btn-sm">إلغاء</a>
</div>
