<div class="row">
    <div class="col-md-6">
        <div class="form-group">
            <label for="title_ar">العنوان (بالعربية)</label>
            <input type="text" name="title_ar" id="title_ar" class="form-control @error('title_ar') is-invalid @enderror" value="{{ old('title_ar', $path->title_ar ?? '') }}" required>
            @error('title_ar')
                <span class="invalid-feedback">{{ $message }}</span>
            @enderror
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-group">
            <label for="title_en">العنوان (بالإنجليزية)</label>
            <input type="text" name="title_en" id="title_en" class="form-control @error('title_en') is-invalid @enderror" value="{{ old('title_en', $path->title_en ?? '') }}" required>
            @error('title_en')
                <span class="invalid-feedback">{{ $message }}</span>
            @enderror
        </div>
    </div>
</div>

<div class="row mt-3">
    <div class="col-md-6">
        <div class="form-group">
            <label for="interest_ids">الاهتمامات (Interests)</label>
            <select name="interest_ids[]" id="interest_ids" class="form-control select2 @error('interest_ids') is-invalid @enderror" multiple>
                @foreach($interests as $interest)
                    <option value="{{ $interest->id }}" {{ (isset($path) && $path->interests->contains($interest->id)) || (is_array(old('interest_ids')) && in_array($interest->id, old('interest_ids'))) ? 'selected' : '' }}>
                        {{ $interest->name_ar }} / {{ $interest->name_en }}
                    </option>
                @endforeach
            </select>
            @error('interest_ids')
                <span class="invalid-feedback">{{ $message }}</span>
            @enderror
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-group">
            <label for="course_ids">الكورسات المرتبطة</label>
            <select name="course_ids[]" id="course_ids" class="form-control select2 @error('course_ids') is-invalid @enderror" multiple>
                @foreach($courses as $course)
                    <option value="{{ $course->id }}" {{ (isset($path) && $path->courses->contains($course->id)) || (is_array(old('course_ids')) && in_array($course->id, old('course_ids'))) ? 'selected' : '' }}>
                        {{ $course->name_ar }}
                    </option>
                @endforeach
            </select>
            @error('course_ids')
                <span class="invalid-feedback">{{ $message }}</span>
            @enderror
        </div>
    </div>
</div>

@push('scripts')
<script>
    $(document).ready(function() {
        $('.select2').each(function() {
            $(this).select2({
                placeholder: "اختر...",
                allowClear: true,
                dir: "rtl"
            });
        });
    });
</script>
@endpush

