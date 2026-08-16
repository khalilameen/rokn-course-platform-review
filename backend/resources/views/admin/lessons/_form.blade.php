<div class="row form-group">
    <div class="col-md-2">
        <label for="title_ar" class="form-control-label">أسم الدرس (بالعربية) *</label>
    </div>
    <div class="col-md-10">
        {!! Form::text('title_ar', null, ['class' => 'form-control' , 'required', 'id'=>"title_ar", 'placeholder' => 'أدخل عنوان الدرس بالعربية'] )!!}
    </div>
</div>

<div class="row form-group">
    <div class="col-md-2">
        <label for="title_en" class="form-control-label">أسم الدرس (بالإنجليزية)</label>
    </div>
    <div class="col-md-10">
        {!! Form::text('title_en', null, ['class' => 'form-control' , 'id'=>"title_en", 'placeholder' => 'Enter lesson title in English'] )!!}
    </div>
</div>



<div class="row form-group">
    <div class="col-md-2">
        <label for="category_id'" class="form-control-label">الكورس</label>
    </div>
    <div class="col-md-10 ">
         
         <select class="course form-control"  name="list_id" >
         @foreach($courses as $course)
            <option value="{{ $course->id }}" 
             @if(isset($lesson))
                {{ is_null($lesson) || $course->id !=  $lesson->list_id ?'':'selected' }}
                @endif   >{{ $course->title }}</option>
            @endforeach   
          </select>
    </div>
</div>

<div class="row form-group">
    <div class="col-md-2">
        <label for="category_id'" class="form-control-label"> الدرس مفتوح</label>
    </div>
    <div class="col-md-10 ">
         
         <select class="quizzes form-control"  name="is_opened" >
         
            <option value="1" {{ isset($lesson) && $lesson->is_opened == 1 ? "selected":'' }}> 
            كل الزوار 
            </option>
                 <option value="0" {{ isset($lesson) &&  $lesson->is_opened == 0 ? "selected":'' }}> 
            الأعضاء فقط
            </option>
                
          </select>
    </div>
</div>

<div class="row form-group">
    <div class="col-md-2">
        <label for="category_id'" class="form-control-label">الاختبار التابع</label>
    </div>
    <div class="col-md-10 ">
         
         <select class="quizzes form-control"  name="quiz_id" >
             <option value="" >بلا اختبار</option>   
         @foreach($quizzes as $quiz)
            <option value="{{ $quiz->id }}"   
                @if(isset($lesson))
                {{ is_null($lesson) || $quiz->id !=  $lesson->quiz_id ?'':'selected' }}
                @endif  >{{ $quiz->title }} @if(!$quiz->lesson) (بلا درس) @endif</option>
            @endforeach   
          </select>
    </div>
</div>
<div class="row form-group">
    <div class="col-md-2">
        <label for="name_en'" class="form-control-label">درجة  الظهور   (الأعلى يظهر أولا)</label>
    </div>
    <div class="col-md-10">
        {!! Form::text('priority', null, ['class' => 'form-control' , 'required', 'id'=>"priority"] )!!}

    </div>
</div>

<div class="row form-group">
    <div class="col-md-2">
        <label for="description_ar" class="form-control-label">وصف الدرس (بالعربية)</label>
    </div>
    <div class="col-md-10">
        {!! Form::text('description_ar', null, ['class' => 'form-control' , 'id'=>"description_ar", 'placeholder' => 'أدخل وصف الدرس بالعربية'] )!!}
    </div>
</div>

<div class="row form-group">
    <div class="col-md-2">
        <label for="description_en" class="form-control-label">وصف الدرس (بالإنجليزية)</label>
    </div>
    <div class="col-md-10">
        {!! Form::text('description_en', null, ['class' => 'form-control' , 'id'=>"description_en", 'placeholder' => 'Enter lesson description in English'] )!!}
    </div>
</div>

@php
    $settings = \App\Models\Setting::first();
    $bunnyEnabled = $settings && $settings->bunny_enabled;
    $currentVideoSource = isset($lesson) ? ($lesson->video_source_type ?? 'youtube') : 'youtube';
@endphp

@if($bunnyEnabled)
<div class="row form-group">
    <div class="col-md-2">
        <label for="thumbnail" class="form-control-label">الصورة المصغرة</label>
    </div>
    <div class="col-md-10">
        @if(isset($lesson) && $lesson->thumbnail_path)
        <div class="alert alert-info learning-alert-compact">
            <i class="fa fa-check-circle"></i>
            <strong>صورة مصغرة مرفوعة</strong>
            <br><small>يمكنك رفع صورة جديدة لاستبدالها</small>
        </div>
        @endif
        <input type="file" name="thumbnail" id="thumbnail" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif">
        <small class="text-muted learning-help-text">
            الصيغ المدعومة: JPEG, PNG, WebP, GIF - الحد الأقصى: 2MB
        </small>
    </div>
</div>
@endif

@if($bunnyEnabled)
<div class="row form-group">
    <div class="col-md-2">
        <label class="form-control-label">مصدر الفيديو</label>
    </div>
    <div class="col-md-10">
        <div class="btn-group btn-group-toggle learning-source-toggle" data-toggle="buttons">
            <label class="btn btn-outline-primary learning-source-option {{ $currentVideoSource === 'youtube' ? 'active' : '' }}">
                <input type="radio" name="video_source_type" value="youtube" {{ $currentVideoSource === 'youtube' ? 'checked' : '' }} id="source_youtube">
                <i class="fa fa-youtube-play"></i> رابط يوتيوب
            </label>
            <label class="btn btn-outline-success learning-source-option {{ $currentVideoSource === 'bunny' ? 'active' : '' }}">
                <input type="radio" name="video_source_type" value="bunny" {{ $currentVideoSource === 'bunny' ? 'checked' : '' }} id="source_bunny">
                <i class="fa fa-cloud-upload"></i> رفع فيديو (Bunny)
            </label>
        </div>
    </div>
</div>
@else
{!! Form::hidden('video_source_type', 'youtube') !!}
@endif

<!-- YouTube Video Link -->
<div class="row form-group learning-video-section{{ $bunnyEnabled && $currentVideoSource === 'bunny' ? ' is-hidden' : '' }}" id="youtube_section">
    <div class="col-md-2">
        <label for="video_link" class="form-control-label">رابط الفيديو</label>
    </div>
    <div class="col-md-10">
        {!! Form::text('video_link', null, ['class' => 'form-control', 'id'=>"video_link", 'placeholder' => 'https://www.youtube.com/watch?v=...'] )!!}
    </div>
</div>

@if($bunnyEnabled)
<!-- Bunny Video Upload -->
<div class="row form-group learning-video-section{{ $currentVideoSource === 'youtube' ? ' is-hidden' : '' }}" id="bunny_section">
    <div class="col-md-2">
        <label for="bunny_video" class="form-control-label">ملف الفيديو</label>
    </div>
    <div class="col-md-10">
        @if(isset($lesson) && $lesson->bunny_video_id)
        <div class="alert alert-info learning-alert-compact">
            <i class="fa fa-check-circle"></i>
            <strong>فيديو مرفوع:</strong> {{ $lesson->bunny_video_id }}
            <br><small>يمكنك رفع فيديو جديد لاستبداله</small>
        </div>
        @endif
        <input type="file" name="bunny_video" id="bunny_video" class="form-control" accept="video/*">
        <div id="upload_progress" class="learning-upload-progress is-hidden">
            <div class="progress">
                <div class="progress-bar progress-bar-striped progress-bar-animated learning-progress-zero" role="progressbar"></div>
            </div>
            <small class="text-muted" id="upload_status">جاري الرفع...</small>
        </div>
        <small class="text-muted learning-help-text">
            الصيغ المدعومة: MP4, MOV, AVI, WebM - الحد الأقصى: 5GB
        </small>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const sourceYoutube = document.getElementById('source_youtube');
    const sourceBunny = document.getElementById('source_bunny');
    const youtubeSection = document.getElementById('youtube_section');
    const bunnySection = document.getElementById('bunny_section');
    const videoLinkInput = document.getElementById('video_link');
    const bunnyVideoInput = document.getElementById('bunny_video');

    function updateVideoSourceVisibility() {
        if (sourceYoutube && sourceYoutube.checked) {
            if (youtubeSection) youtubeSection.classList.remove('is-hidden');
            if (bunnySection) bunnySection.classList.add('is-hidden');
            // Remove required from bunny, add to youtube
            if (videoLinkInput) videoLinkInput.removeAttribute('required');
            if (bunnyVideoInput) bunnyVideoInput.removeAttribute('required');
        } else if (sourceBunny && sourceBunny.checked) {
            if (youtubeSection) youtubeSection.classList.add('is-hidden');
            if (bunnySection) bunnySection.classList.remove('is-hidden');
            // Remove required from youtube
            if (videoLinkInput) videoLinkInput.removeAttribute('required');
            if (bunnyVideoInput) bunnyVideoInput.removeAttribute('required');
        }
    }

    if (sourceYoutube && sourceBunny) {
        sourceYoutube.addEventListener('change', updateVideoSourceVisibility);
        sourceBunny.addEventListener('change', updateVideoSourceVisibility);
        // Initialize on page load
        updateVideoSourceVisibility();
    }
});
</script>
@endif

<div class="row form-group">
    <div class="col-md-2">
        <label for="file_link1" class="form-control-label">رابط الملف 1 </label>
    </div>
    <div class="col-md-10">
        {!! Form::text('file_link1', null, ['class' => 'form-control' , 'required', 'id'=>"file_link1"] )!!}
    </div>
</div>


<div class="row form-group">
    <div class="col-md-2">
        <label for="file_link2" class="form-control-label"> رابط الملف 2</label>
    </div>
    <div class="col-md-10">
        {!! Form::text('file_link2', null, ['class' => 'form-control' , 'required', 'id'=>"file_link2"] )!!}
    </div>
</div>


<br/>


<div class="form-actions form-group">
    <button type="submit" class="btn btn-success btn-sm">حفظ</button>
    <a href="{{ route('admin.lessons.index') }}" class="btn btn-danger btn-sm">إلغاء</a>
</div>
  <script>

</script>
@section('scripts')

@endsection
