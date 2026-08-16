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
                        <label class="checkbox-item {{ old('ai_chat_enabled', true) ? 'selected' : '' }}" for="ai_chat_enabled">
                            <div class="custom-checkbox">
                                <i class="fa fa-check"></i>
                            </div>
                            <div>
                                <div class="checkbox-item__title">تفعيل Rokn AI للمشتركين المدفوعين</div>
                                <div class="checkbox-item__description">الكورس المجاني والمنحة المؤسسية لا يستهلكان الذكاء الاصطناعي. إلغاء التفعيل يوقفه للجميع في هذا الكورس.</div>
                            </div>
                            {!! Form::hidden('ai_chat_enabled', 0) !!}
                            {!! Form::checkbox('ai_chat_enabled', 1, old('ai_chat_enabled', true), ['id' => 'ai_chat_enabled', 'class' => 'course-create-checkbox-input']) !!}
                        </label>
                    </div>
                </div>

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
                        <div class="form-help">لا يمكن حفظ نموذج خارج القائمة المعتمدة في إعدادات الخادم.</div>
                    </div>

                    <div class="form-group-modern">
                        <label for="temperature" class="form-label-modern">
                            <i class="fa fa-thermometer-half label-icon"></i>
                            درجة الحرارة (Temperature)
                        </label>
                        {!! Form::number('temperature', 0.7, [
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
                        {!! Form::number('tokens_number', config('openrouter.max_tokens', 420), [
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

                <div class="form-row">
                    <div class="form-group-modern form-group-full">
                        <label for="chat_ai_prompt" class="form-label-modern">
                            <i class="fa fa-comment-dots label-icon"></i>
                            البرومبت الخاص بالشات (System Prompt)
                        </label>
                        {!! Form::textarea('chat_ai_prompt', null, [
                            'class' => 'form-control-modern' . ($errors->has('chat_ai_prompt') ? ' is-invalid' : ''),
                            'id' => 'chat_ai_prompt',
                            'placeholder' => 'أدخل التعليمات الخاصة بالبوت هنا...',
                            'rows' => 4,
                            'maxlength' => 1200
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

            <div class="form-section">
                <h2 class="section-title"><div class="section-icon"><i class="fa fa-th-large"></i></div>تنظيم الظهور في الرئيسية</h2>
                <div class="form-row">
                    <div class="form-group-modern">
                        <label for="home_sort_order" class="form-label-modern">الترتيب داخل الصفوف</label>
                        {!! Form::number('home_sort_order', 100, ['class' => 'form-control-modern', 'id' => 'home_sort_order', 'min' => 0, 'max' => 10000, 'required' => true]) !!}
                        <div class="form-help">الرقم الأصغر يظهر أولًا داخل كل تصنيف مختار.</div>
                    </div>
                    <div class="form-group-modern">
                        <label for="catalog_badge_ar" class="form-label-modern">شارة البطاقة</label>
                        {!! Form::text('catalog_badge_ar', null, ['class' => 'form-control-modern', 'id' => 'catalog_badge_ar', 'maxlength' => 40, 'placeholder' => 'مثال مجاني أو جديد']) !!}
                        <div class="form-help">اتركها فارغة إذا لم تكن لها قيمة حقيقية.</div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group-modern">
                        <label for="catalog_badge_en" class="form-label-modern">الترجمة الإنجليزية للشارة</label>
                        {!! Form::text('catalog_badge_en', null, ['class' => 'form-control-modern', 'id' => 'catalog_badge_en', 'maxlength' => 40]) !!}
                    </div>
                    <div class="form-group-modern">
                        <label for="catalog_badge_tone" class="form-label-modern">لون الشارة</label>
                        {!! Form::select('catalog_badge_tone', ['blue' => 'أزرق', 'green' => 'أخضر', 'gold' => 'ذهبي', 'neutral' => 'محايد'], 'blue', ['class' => 'form-control-modern', 'id' => 'catalog_badge_tone']) !!}
                    </div>
                </div>
            </div>
