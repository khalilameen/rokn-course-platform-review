            <!-- Course Groups Section -->
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
                        <label class="checkbox-item" for="lesson_{{ $lesson->id }}">
                            <div class="custom-checkbox">
                                <i class="fa fa-check"></i>
                            </div>
                            <div>
                                <div class="checkbox-item__title">{{ $lesson->title }}</div>
                                @if($lesson->description)
                                    <div class="checkbox-item__description">{{ Str::limit($lesson->description, 60) }}</div>
                                @endif
                            </div>
                            <input type="checkbox" name="lessons[]" value="{{ $lesson->id }}" id="lesson_{{ $lesson->id }}" class="course-create-checkbox-input">
                        </label>
                    @endforeach
                </div>
                <div class="form-help">اختر الدروس التي تريد ربطها بهذا الكورس</div>
            </div>
            @endif


