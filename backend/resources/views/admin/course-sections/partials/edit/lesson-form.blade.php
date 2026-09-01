                <!-- Lesson Form -->
                @php
                    $settings = \App\Models\Setting::first();
                    $bunnyEnabled = $settings && $settings->bunny_enabled;
                    $lesson = $section->getSectionType() == 'lesson' ? $section->sectionable : null;
                    $currentVideoSource = $lesson ? ($lesson->video_source_type ?? 'youtube') : 'youtube';
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
                                       value="{{ old('lesson_title_ar', $lesson->title_ar ?? $lesson->title ?? '') }}" placeholder="يُنسخ تلقائيًا من العنوان الظاهر" data-required="true" readonly>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label" for="lesson_title_en">العنوان الداخلي للمقطع (بالإنجليزية)</label>
                                <input type="text" id="lesson_title_en" name="lesson_title_en" class="form-control"
                                       value="{{ old('lesson_title_en', $lesson->title_en ?? '') }}" placeholder="Copied from the visible title" readonly>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label" for="lesson_description_ar">كابشن المقطع (بالعربية)</label>
                                <textarea id="lesson_description_ar" name="lesson_description_ar" class="form-control" rows="4"
                                          placeholder="النص الذي يظهر أسفل الفيديو مثل كابشن تيك توك">{{ old('lesson_description_ar', $lesson->description_ar ?? $lesson->description ?? '') }}</textarea>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label" for="lesson_description_en">كابشن المقطع (بالإنجليزية)</label>
                                <textarea id="lesson_description_en" name="lesson_description_en" class="form-control" rows="4"
                                          placeholder="Enter English lesson description">{{ old('lesson_description_en', $lesson->description_en ?? '') }}</textarea>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="lesson_duration_minutes">مدة الدرس (بالدقائق)</label>
                        <input type="number" id="lesson_duration_minutes" name="lesson_duration_minutes" class="form-control"
                               value="{{ old('lesson_duration_minutes', $lesson?->duration_minutes ?? '') }}" placeholder="مثال: 15" min="1">
                        <small class="text-muted">حدد مدة الدرس بالدقائق</small>
                    </div>

                    @if($bunnyEnabled)
                    <div class="form-group">
                        <label class="form-label" for="lesson_thumbnail">الصورة المصغرة</label>
                        @if($lesson && $lesson->thumbnail_path)
                        <div class="alert section-existing-media">
                            <div class="section-existing-media__content">
                                <i class="fa fa-check-circle section-existing-media__icon"></i>
                                <div>
                                    <strong class="section-existing-media__title">صورة مصغرة موجودة</strong>
                                    <p class="section-existing-media__copy">
                                        يمكنك رفع صورة جديدة لاستبدالها
                                    </p>
                                </div>
                            </div>
                        </div>
                        @endif
                        <input type="file" id="lesson_thumbnail" name="lesson_thumbnail" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif">
                        <small class="text-muted course-section-help">
                            <i class="fa fa-info-circle"></i>
                            الصيغ المدعومة: JPEG, PNG, WebP, GIF - الحد الأقصى: 2MB
                        </small>
                    </div>
                    @endif

                    <div class="form-group">
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" name="is_opened" class="custom-control-input" id="is_opened" value="1" {{ old('is_opened', $lesson->is_opened ?? false) ? 'checked' : '' }}>
                            <label class="custom-control-label" for="is_opened">معاينة مجانية قبل فتح الكورس</label>
                        </div>
                    </div>

                    <input type="hidden" name="video_source_type" value="bunny">
                    @if(!$bunnyEnabled)
                        <div class="alert alert-danger">
                            فعّل Bunny.net وأكمل مفاتيح البث والتوقيع من الإعدادات قبل حفظ أي درس.
                        </div>
                    @endif

                    <!-- Bunny is the single production video source. -->
                    <div class="form-group" id="bunny_video_section">
                        <label class="form-label" for="bunny_video">ملف الفيديو {{ $lesson && $lesson->bunny_video_id ? '(اختياري - فيديو موجود)' : '*' }}</label>
                        @if($lesson && $lesson->bunny_video_id)
                        <div class="alert section-existing-media">
                            <div class="section-existing-media__content">
                                <i class="fa fa-check-circle section-existing-media__icon"></i>
                                <div>
                                    <strong class="section-existing-media__title">فيديو مرفوع بالفعل</strong>
                                    <p class="section-existing-media__copy">
                                        معرف الفيديو: {{ $lesson->bunny_video_id }}<br>
                                        يمكنك رفع فيديو جديد لاستبداله
                                    </p>
                                </div>
                            </div>
                        </div>
                        @endif
                        <input type="file" id="bunny_video" class="form-control"
                               accept="video/mp4,video/quicktime,video/x-msvideo,video/webm"
                               data-video-required="{{ $lesson && $lesson->bunny_video_id ? 'false' : 'true' }}"
                               {{ $lesson && $lesson->bunny_video_id ? '' : 'data-required=true' }}>
                        <input type="hidden" id="bunny_video_claim" name="bunny_video_claim" value="{{ old('bunny_video_claim') }}">
                        <div id="bunny_upload_progress" class="course-section-upload-progress is-hidden">
                            <div class="progress">
                                <div class="progress-bar" role="progressbar"></div>
                            </div>
                            <small class="text-muted" id="bunny_upload_status">جاري الرفع...</small>
                            <div class="mt-2">
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="bunny_upload_cancel">إيقاف</button>
                                <button type="button" class="btn btn-sm btn-outline-primary is-hidden" id="bunny_upload_retry">متابعة الرفع</button>
                            </div>
                        </div>
                        <small class="text-muted course-section-help">
                            <i class="fa fa-info-circle"></i>
                            الصيغ المدعومة: MP4, MOV, AVI, WebM - الحد الأقصى: 5GB
                        </small>
                    </div>

                    <!-- Toggle Button for Additional Files -->
                    <div class="form-group">
                        <button type="button" id="toggleFileLinks" class="btn-modern toggle-file-links-btn">
                            <i class="fa fa-plus-circle"></i>
                            إضافة روابط ملفات إضافية
                        </button>
                    </div>

                    <!-- Additional File Links -->
                    <div id="additionalFileLinks" class="additional-file-links {{ ($lesson && ($lesson->file_link1 || $lesson->file_link2)) ? '' : 'is-hidden' }}">
                        <div class="form-group">
                            <label class="form-label" for="file_link1">رابط الملف الأول (اختياري)</label>
                            <input type="url" id="file_link1" name="file_link1" class="form-control"
                                   value="{{ old('file_link1', $lesson->file_link1 ?? '') }}" placeholder="https://...">
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="file_link2">رابط الملف الثاني (اختياري)</label>
                            <input type="url" id="file_link2" name="file_link2" class="form-control"
                                   value="{{ old('file_link2', $lesson->file_link2 ?? '') }}" placeholder="https://...">
                        </div>
                    </div>
                </div>
