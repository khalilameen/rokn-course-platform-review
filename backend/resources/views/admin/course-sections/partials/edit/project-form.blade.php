                <!-- Project Form -->
                @php
                    $project = $section->getSectionType() == 'project' ? $section->sectionable : null;
                @endphp
                <div class="form-section dynamic-form" id="project-form" data-type="project">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fa fa-project-diagram"></i>
                        </div>
                        <h3 class="section-title">تفاصيل المشروع</h3>
                    </div>

                    <div class="alert alert-info">
                        مشروع العبور اختياري. وجوده في هذه الوحدة يعني أنه آخر خطوة فيها، ولا تنتقل للتي بعدها قبل اجتيازه.
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">متطلبات المشروع (بالعربية) *</label>
                                <textarea name="project_requirements_ar" class="form-control" rows="5" placeholder="اكتب وصفاً تفصيلياً للمطلوب بالعربية..." data-required="true">{{ old('project_requirements_ar', $project->requirements_text_ar ?? $project->requirements_text ?? '') }}</textarea>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">متطلبات المشروع (بالإنجليزية)</label>
                                <textarea name="project_requirements_en" class="form-control" rows="5" placeholder="Enter detailed requirements in English...">{{ old('project_requirements_en', $project->requirements_text_en ?? '') }}</textarea>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">موجه الذكاء الاصطناعي (AI Prompt) *</label>
                        <textarea name="ai_prompt" class="form-control" rows="5" placeholder="اكتب التعليمات للذكاء الاصطناعي لتقييم المشروع (مثال: قيم الكود بناء على المعايير التالية...)" data-required="true">{{ old('ai_prompt', $project->ai_prompt ?? '') }}</textarea>
                        <small class="text-muted">هذا النص لن يظهر للطالب، بل سيستخدم لتوجيه عملية التصحيح الآلي.</small>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="form-label">نوع الموديل (AI Model Type)</label>
                                <input type="text" name="ai_model_type" class="form-control" value="{{ old('ai_model_type', $project->ai_model_type ?? '') }}" placeholder="مثال: google/gemini-pro-1.5">
                                <small class="text-muted">OpenRouter Model Slug</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="form-label">درجة الحرارة (Temperature)</label>
                                <input type="number" name="temperature" class="form-control" value="{{ old('temperature', $project->temperature ?? 0.7) }}" step="0.1" min="0" max="2">
                                <small class="text-muted">القيمة بين 0 و 2</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="form-label">عدد التوكنز (Max Tokens)</label>
                                <input type="number" name="tokens_number" class="form-control" value="{{ old('tokens_number', $project->tokens_number ?? 1000) }}" min="1">
                                <small class="text-muted">الحد الأقصى للتوكنز</small>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">درجة النجاح (%) *</label>
                                <input type="number" name="passing_score" class="form-control" value="{{ old('passing_score', $project->passing_score ?? 50) }}" min="1" max="100" data-required="true">
                                <small class="form-text text-muted">الطالب لا يرسل الدرجة؛ الخادم وحده يقرر العبور.</small>
                            </div>
                            <div class="form-group">
                                <label>مدة المراجعة الاحتياطية بالثواني</label>
                                <input type="number" name="fallback_review_delay_seconds" class="form-control" value="{{ old('fallback_review_delay_seconds', $project->fallback_review_delay_seconds ?? 90) }}" min="30" max="300">
                                <small class="form-text text-muted">بعدها تعبر المحاولة ذات المجهود تلقائيًا إذا تعطل المقيم الخارجي.</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label">&nbsp;</label>
                                <div class="custom-control custom-checkbox mt-2">
                                    <input type="checkbox" name="is_graduation_project" class="custom-control-input" id="is_graduation_project" {{ old('is_graduation_project', $project->is_graduation_project ?? false) ? 'checked' : '' }}>
                                    <label class="custom-control-label" for="is_graduation_project">مشروع تخرج (نهاية الكورس)</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
