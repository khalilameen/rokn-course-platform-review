<div class="row form-group">
    <div class="col-md-2">
        <label for="title'" class="form-control-label">أسم السؤال</label>
    </div>
    <div class="col-md-10">
        {!! Form::text('title', null, ['class' => 'form-control' , 'required', 'id'=>"title"] )!!}
    </div>
</div>



<div class="row form-group">
    <div class="col-md-2">
        <label for="category_id'" class="form-control-label">الاختبار </label>
    </div>
    <div class="col-md-10 ">
         
         <select class="quiz form-control"  name="list_id" >
         @foreach($quizzes as $quiz)
            <option value="{{ $quiz->id }}" 
             @if(isset($question))
                {{ is_null($question) || $quiz->id !=  $question->list_id ?'':'selected' }}
                @endif   >{{ $quiz->title }}</option>
            @endforeach   
          </select>
    </div>
</div>


<div class="row form-group">
    <div class="col-md-2">
        <label for="name_en'" class="form-control-label">درجة  الظهور   (الأعلى يظهر أولا)</label>
    </div>
    <div class="col-md-10">
        {!! Form::text('priority', null, ['class' => 'form-control' , '', 'id'=>"priority"] )!!}

    </div>
</div>
<div class="row form-group">
    <div class="col-md-2">
        <label for="description" class="form-control-label">وصف السؤال</label>
    </div>
    <div class="col-md-10">
        {!! Form::text('description', null, ['class' => 'form-control' , '', 'id'=>"description"] )!!}
    </div>
</div>

<div class="row form-group">
    <div class="col-md-2">
        <label for="image" class="form-control-label">الصورة</label>
    </div>
    <div class="col-md-10">
        @if(isset($question->image))
        <img src="{{ $question->image }}" />
        @endif
        <input id="image" name="image" class="form-control-file" type="file" required>
    </div>
</div>

<div class="row form-group">
    <div class="col-md-2">
        <label for="question" class="form-control-label">رأس السؤال</label>
    </div>
    <div class="col-md-10">
        {!! Form::text('question', null, ['class' => 'form-control' , 'required', 'id'=>"question"] )!!}
    </div>
</div>

<div class="row form-group">
    <div class="col-md-2">
        <label for="choice1" class="form-control-label"> اختيار 1 </label>
    </div>
    <div class="col-md-10">
        {!! Form::text('choice1', null, ['class' => 'form-control' , 'required', 'id'=>"choice1"] )!!}
    </div>
</div>

<div class="row form-group">
    <div class="col-md-2">
        <label for="choice2" class="form-control-label"> اختيار 2 </label>
    </div>
    <div class="col-md-10">
        {!! Form::text('choice2', null, ['class' => 'form-control' , 'required', 'id'=>"choice2"] )!!}
    </div>
</div>

<div class="row form-group">
    <div class="col-md-2">
        <label for="choice3" class="form-control-label"> اختيار 3 </label>
    </div>
    <div class="col-md-10">
        {!! Form::text('choice3', null, ['class' => 'form-control' , '', 'id'=>"choice3"] )!!}
    </div>
</div>

<div class="row form-group">
    <div class="col-md-2">
        <label for="choice4" class="form-control-label"> اختيار 4 </label>
    </div>
    <div class="col-md-10">
        {!! Form::text('choice4', null, ['class' => 'form-control' , '', 'id'=>"choice4"] )!!}
    </div>
</div>

<div class="row form-group">
    <div class="col-md-2">
        <label for="choice5" class="form-control-label"> اختيار 5 </label>
    </div>
    <div class="col-md-10">
        {!! Form::text('choice5', null, ['class' => 'form-control' , '', 'id'=>"choice5"] )!!}
    </div>
</div>

<div class="row form-group">
    <div class="col-md-2">
        <label for="choice6" class="form-control-label"> اختيار 6 </label>
    </div>
    <div class="col-md-10">
        {!! Form::text('choice6', null, ['class' => 'form-control' , '', 'id'=>"choice6"] )!!}
    </div>
</div>

<div class="row form-group">
    <div class="col-md-2">
        <label for="right_answer" class="form-control-label"> الاجابة الصحيحة</label>
    </div>
    <div class="col-md-10">
        {!! Form::text('right_answer', null, ['class' => 'form-control' , 'required', 'id'=>"right_answer"] )!!}
    </div>
</div>




<br/>


<div class="form-actions form-group">
    <button type="submit" class="btn btn-success btn-sm">حفظ</button>
    <a href="{{ route('admin.questions.index') }}" class="btn btn-danger btn-sm">إلغاء</a>
</div>
  <script>

</script>
@section('scripts')

@endsection