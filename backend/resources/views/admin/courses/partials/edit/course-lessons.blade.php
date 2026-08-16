            <!-- Course Lessons Section -->
            @if(!empty($lessons) && $lessons->count() > 0)
            <div class="form-section">
                <h2 class="section-title">
                    <div class="section-icon">
                        <i class="fa fa-play-circle"></i>
                    </div>
                    ربط الدروس
                </h2>

                <div class="checkbox-group">
                    @foreach($lessons as $lesson)
                        <label class="checkbox-item {{ in_array($lesson->id, $courseLessons) ? 'selected' : '' }}" for="lesson_{{ $lesson->id }}">
                            <div class="custom-checkbox">
                                <i class="fa fa-check"></i>
                            </div>
                            <div>
                                <div class="course-editor__option-title">{{ $lesson->title }}</div>
                                @if($lesson->description)
                                    <div class="course-editor__option-description">{{ Str::limit($lesson->description, 60) }}</div>
                                @endif
                            </div>
                            <input type="checkbox" name="lessons[]" value="{{ $lesson->id }}" id="lesson_{{ $lesson->id }}"
                                   {{ in_array($lesson->id, $courseLessons) ? 'checked' : '' }} class="course-editor__native-checkbox">
                        </label>
                    @endforeach
                </div>
                <div class="form-help">اختر الدروس التي تريد ربطها بهذا الكورس</div>
            </div>
            @endif
