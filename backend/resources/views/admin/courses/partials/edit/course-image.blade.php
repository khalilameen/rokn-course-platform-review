            <!-- Course Image Section -->
            <div class="form-section">
                <h2 class="section-title">
                    <div class="section-icon">
                        <i class="fa fa-image"></i>
                    </div>
                    صورة الكورس
                </h2>

                <div class="form-group-modern">
                    <label class="form-label-modern">
                        <i class="fa fa-camera label-icon"></i>
                        صورة الكورس
                    </label>

                    @if($course->image)
                        <div class="course-editor__current-image">
                            <img src="{{ $course->image }}" alt="{{ $course->name_ar }}" class="current-image">
                            <div class="course-editor__image-status">
                                <i class="fa fa-check-circle"></i> الصورة الحالية
                            </div>
                        </div>
                    @endif

                    <label class="file-upload-area" for="image">
                        <div class="upload-icon">
                            <i class="fa fa-cloud-upload"></i>
                        </div>
                        <div class="upload-text">اضغط لاختيار صورة جديدة أو اسحبها هنا</div>
                        <div class="upload-subtext">PNG, JPG, GIF حتى 10MB (اختياري - لتغيير الصورة الحالية)</div>
                        <input type="file" name="image" id="image" class="file-input-hidden" accept="image/jpeg,image/png,image/webp">
                    </label>
                    <div id="imagePreview"></div>
                    @if ($errors->has('image'))
                        <div class="invalid-feedback">
                            <i class="fa fa-exclamation-circle"></i>
                            {{ $errors->first('image') }}
                        </div>
                    @endif
                </div>
            </div>
