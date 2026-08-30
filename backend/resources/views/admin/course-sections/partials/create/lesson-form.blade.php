                <!-- Lesson Form -->
                @php
                    $settings = \App\Models\Setting::first();
                    $bunnyEnabled = $settings && $settings->bunny_enabled;
                @endphp
                <div class="form-section dynamic-form" id="lesson-form" data-type="lesson">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fa fa-play-circle"></i>
                        </div>
                        <h3 class="section-title">المقطع داخل مشغل الريلز</h3>
                    </div>

                    <div class="row lesson-title-sync-fields" aria-hidden="true">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label" for="lesson_title_ar">العنوان الداخلي للمقطع (بالعربية) *</label>
                                <input type="text" id="lesson_title_ar" name="lesson_title_ar" class="form-control"
                                       value="{{ old('lesson_title_ar') }}" placeholder="يُنسخ تلقائيًا من العنوان الظاهر" data-required="true" readonly>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label" for="lesson_title_en">العنوان الداخلي للمقطع (بالإنجليزية)</label>
                                <input type="text" id="lesson_title_en" name="lesson_title_en" class="form-control"
                                       value="{{ old('lesson_title_en') }}" placeholder="Copied from the visible title" readonly>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label" for="lesson_description_ar">كابشن المقطع (بالعربية)</label>
                                <textarea id="lesson_description_ar" name="lesson_description_ar" class="form-control" rows="4"
                                          placeholder="النص الذي يظهر أسفل الفيديو مثل كابشن تيك توك">{{ old('lesson_description_ar') }}</textarea>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label" for="lesson_description_en">كابشن المقطع (بالإنجليزية)</label>
                                <textarea id="lesson_description_en" name="lesson_description_en" class="form-control" rows="4"
                                          placeholder="Enter English lesson description">{{ old('lesson_description_en') }}</textarea>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="lesson_duration_minutes">مدة الدرس (بالدقائق)</label>
                        <input type="number" id="lesson_duration_minutes" name="lesson_duration_minutes" class="form-control"
                               value="{{ old('lesson_duration_minutes') }}" placeholder="مثال: 15" min="1">
                        <small class="text-muted">حدد مدة الدرس بالدقائق</small>
                    </div>

                    @if($bunnyEnabled)
                    <div class="form-group">
                        <label class="form-label" for="lesson_thumbnail">الصورة المصغرة</label>
                        <input type="file" id="lesson_thumbnail" name="lesson_thumbnail" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif">
                        <small class="text-muted course-section-help">
                            <i class="fa fa-info-circle"></i>
                            الصيغ المدعومة: JPEG, PNG, WebP, GIF - الحد الأقصى: 2MB
                        </small>
                    </div>
                    @endif

                    <div class="form-group">
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" name="is_opened" class="custom-control-input" id="is_opened" value="1" {{ old('is_opened') ? 'checked' : '' }}>
                            <label class="custom-control-label" for="is_opened">معاينة مجانية قبل فتح الكورس</label>
                        </div>
                    </div>

                    <!-- Video Source Type (Forced to Bunny) -->
                    <input type="hidden" name="video_source_type" value="bunny">

                    <!-- Bunny Video Upload Section -->
                    <div class="form-group" id="bunny_video_section">
                        <label class="form-label" for="bunny_video">ملف الفيديو *</label>
                        <input type="file" id="bunny_video" name="bunny_video" class="form-control" accept="video/*" data-required="true">
                        <div id="bunny_upload_progress" class="course-section-upload-progress is-hidden">
                            <div class="progress">
                                <div class="progress-bar" role="progressbar"></div>
                            </div>
                            <small class="text-muted" id="bunny_upload_status">جاري الرفع...</small>
                        </div>
                        <small class="text-muted course-section-help">
                            <i class="fa fa-info-circle"></i>
                            الصيغ المدعومة: MP4, MOV, AVI, WebM - الحد الأقصى: 5GB
                        </small>
                    </div>

                    <!-- Hidden Additional File Links (Removed functionality from UI) -->
                    <div id="additionalFileLinks" class="additional-file-links is-hidden">
                        <input type="hidden" name="file_link1" value="">
                        <input type="hidden" name="file_link2" value="">
                    </div>
                </div>
