                <!-- Quiz Form -->
                <div class="form-section dynamic-form" id="quiz-form" data-type="quiz">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fa fa-list-ul"></i>
                        </div>
                        <h3 class="section-title">تفاصيل الاختبار</h3>
                    </div>

                    @php
                        $quiz = $section->getSectionType() == 'quiz' ? $section->sectionable : null;
                        $quizQuestions = $quiz ? $quiz->questions : collect();
                    @endphp

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label" for="quiz_title_ar">عنوان الاختبار (بالعربية) *</label>
                                <input type="text" id="quiz_title_ar" name="quiz_title_ar" class="form-control"
                                       value="{{ old('quiz_title_ar', $quiz->title_ar ?? $quiz->title ?? '') }}" placeholder="أدخل عنوان الاختبار بالعربية" data-required="true">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label" for="quiz_title_en">عنوان الاختبار (بالإنجليزية)</label>
                                <input type="text" id="quiz_title_en" name="quiz_title_en" class="form-control"
                                       value="{{ old('quiz_title_en', $quiz->title_en ?? '') }}" placeholder="Enter English quiz title">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label" for="quiz_description_ar">وصف الاختبار (بالعربية)</label>
                                <textarea id="quiz_description_ar" name="quiz_description_ar" class="form-control" rows="4"
                                          placeholder="أدخل وصف الاختبار بالعربية">{{ old('quiz_description_ar', $quiz->description_ar ?? $quiz->description ?? '') }}</textarea>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label" for="quiz_description_en">وصف الاختبار (بالإنجليزية)</label>
                                <textarea id="quiz_description_en" name="quiz_description_en" class="form-control" rows="4"
                                          placeholder="Enter English quiz description">{{ old('quiz_description_en', $quiz->description_en ?? '') }}</textarea>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="time_minutes">مدة الاختبار (بالدقائق) *</label>
                        <input type="number" id="time_minutes" name="time_minutes" class="form-control"
                               value="{{ old('time_minutes', $quiz->time_minutes ?? '') }}" placeholder="أدخل مدة الاختبار بالدقائق" min="1" data-required="true">
                        <small class="text-muted">حدد المدة الزمنية المسموحة لإكمال الاختبار</small>
                    </div>

                    <!-- Questions Container -->
                    <div class="mb-3">
                        <h4 class="section-title mb-3">الأسئلة *</h4>
                        <div id="questionsContainer">
                            <!-- Existing questions will be loaded here -->
                        </div>
                        <button type="button" id="addQuestionBtn" class="btn-modern add-question-btn--info mt-3">
                            <i class="fa fa-plus"></i>
                            إضافة سؤال
                        </button>
                    </div>
                </div>
