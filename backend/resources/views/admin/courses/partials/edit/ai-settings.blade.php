            <!-- AI Settings Section -->
            <div class="form-section">
                <h2 class="section-title">
                    <div class="section-icon">
                        <i class="fa fa-robot"></i>
                    </div>
                    إعدادات الذكاء الاصطناعي (OpenRouter)
                </h2>

                <div class="form-row">
                    <div class="form-group-modern form-group-full">
                        <label class="checkbox-item {{ old('ai_chat_enabled', $course->ai_chat_enabled) ? 'selected' : '' }}" for="ai_chat_enabled">
                            <div class="custom-checkbox">
                                <i class="fa fa-check{{ old('ai_chat_enabled', $course->ai_chat_enabled) ? '' : ' course-editor__check-icon--hidden' }}"></i>
                            </div>
                            <div>
                                <div class="course-editor__option-title">تفعيل Rokn AI لطلاب الفئات المدفوعة</div>
                                <div class="course-editor__option-description">الكورس المجاني والمنحة المؤسسية لا يستهلكان الذكاء الاصطناعي. إلغاء التفعيل يوقفه للجميع في هذا الكورس.</div>
                            </div>
                            {!! Form::hidden('ai_chat_enabled', 0) !!}
                            {!! Form::checkbox('ai_chat_enabled', 1, old('ai_chat_enabled', $course->ai_chat_enabled), ['id' => 'ai_chat_enabled', 'class' => 'course-editor__native-checkbox']) !!}
                        </label>
                    </div>
                </div>

                @if(strtolower((string) optional(auth()->user())->role) === 'admin')
                <div class="form-row">
                    <div class="form-group-modern">
                        <label for="ai_model_type" class="form-label-modern">
                            <i class="fa fa-microchip label-icon"></i>
                            نموذج Rokn AI
                        </label>
                        {!! Form::select('ai_model_type', ['' => 'النموذج الافتراضي الآمن'] + collect($allowedAiModels)->mapWithKeys(fn ($model) => [$model => $model])->all(), null, [
                            'class' => 'form-control-modern' . ($errors->has('ai_model_type') ? ' is-invalid' : ''),
                            'id' => 'ai_model_type',
                        ]) !!}
                        @if ($errors->has('ai_model_type'))
                            <div class="invalid-feedback">
                                <i class="fa fa-exclamation-circle"></i>
                                {{ $errors->first('ai_model_type') }}
                            </div>
                        @endif
                        @if($course->ai_model_type && !in_array($course->ai_model_type, $allowedAiModels, true))
                            <div class="alert alert-warning mt-2 mb-0">النموذج القديم لم يعد ضمن القائمة المعتمدة وسيُستخدم النموذج الافتراضي حتى تختار نموذجًا معتمدًا.</div>
                        @endif
                        <div class="form-help">لا يمكن حفظ نموذج خارج القائمة المعتمدة في إعدادات الخادم.</div>
                    </div>

                    <div class="form-group-modern">
                        <label for="temperature" class="form-label-modern">
                            <i class="fa fa-thermometer-half label-icon"></i>
                            درجة الحرارة (Temperature)
                        </label>
                        {!! Form::number('temperature', null, [
                            'class' => 'form-control-modern' . ($errors->has('temperature') ? ' is-invalid' : ''),
                            'id' => 'temperature',
                            'step' => '0.1',
                            'min' => '0',
                            'max' => '2'
                        ]) !!}
                        @if ($errors->has('temperature'))
                            <div class="invalid-feedback">
                                <i class="fa fa-exclamation-circle"></i>
                                {{ $errors->first('temperature') }}
                            </div>
                        @endif
                        <div class="form-help">القيمة بين 0 و 2 (الافتراضي 0.7)</div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group-modern">
                        <label for="tokens_number" class="form-label-modern">
                            <i class="fa fa-coins label-icon"></i>
                            عدد التوكنز (Max Tokens)
                        </label>
                        {!! Form::number('tokens_number', null, [
                            'class' => 'form-control-modern' . ($errors->has('tokens_number') ? ' is-invalid' : ''),
                            'id' => 'tokens_number',
                            'min' => '1',
                            'max' => config('openrouter.max_tokens', 420)
                        ]) !!}
                        @if ($errors->has('tokens_number'))
                            <div class="invalid-feedback">
                                <i class="fa fa-exclamation-circle"></i>
                                {{ $errors->first('tokens_number') }}
                            </div>
                        @endif
                        <div class="form-help">الحد الأقصى للتوكنز في الرد الواحد</div>
                    </div>
                </div>
                @endif

                <div class="form-row">
                    <div class="form-group-modern form-group-full">
                        <input type="hidden" name="chat_attachments_enabled" value="0">
                        <label class="course-editor__inline-check">
                            <input type="checkbox" name="chat_attachments_enabled" value="1" {{ old('chat_attachments_enabled', $course->chat_attachments_enabled) ? 'checked' : '' }}>
                            السماح بالمرفقات في شات هذا الكورس
                        </label>
                        <label class="form-label-modern">الحد الأعلى للمرفقات في الرسالة</label>
                        <input class="form-control-modern" type="number" min="1" max="5" name="chat_attachment_max_files" value="{{ old('chat_attachment_max_files', $course->chat_attachment_max_files ?? 1) }}">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group-modern form-group-full">
                        <label for="chat_ai_prompt" class="form-label-modern">
                            <i class="fa fa-comment-dots label-icon"></i>
                            سياق الشات وحدوده
                        </label>
                        {!! Form::textarea('chat_ai_prompt', null, [
                            'class' => 'form-control-modern' . ($errors->has('chat_ai_prompt') ? ' is-invalid' : ''),
                            'id' => 'chat_ai_prompt',
                            'placeholder' => 'اكتب ما يميز محتوى الكورس وأسلوب شرحه...',
                            'rows' => 4,
                            'maxlength' => 850
                        ]) !!}
                        @if ($errors->has('chat_ai_prompt'))
                            <div class="invalid-feedback">
                                <i class="fa fa-exclamation-circle"></i>
                                {{ $errors->first('chat_ai_prompt') }}
                            </div>
                        @endif
                        <div class="form-help">اكتب اتجاه الكورس وأفكاره ونبرة المدرب باختصار. لا تنسخ محتوى الكورس؛ الخادم يرسل ملخصًا مضغوطًا لتقليل التكلفة.</div>
                    </div>
                </div>
            </div>
