            <!-- Course Settings Section -->
            <div class="form-section">
                <h2 class="section-title">
                    <div class="section-icon">
                        <i class="fa fa-cog"></i>
                    </div>
                    إعدادات الكورس
                </h2>

                <div class="form-row">

                    <div class="form-group-modern">
                        <label for="classification_ids" class="form-label-modern">
                            <i class="fa fa-tags label-icon"></i>
                            التصنيفات
                        </label>
                        <select name="classification_ids[]" id="classification_ids" class="form-control-modern select2" multiple>
                            @foreach($classifications as $classification)
                                <option value="{{ $classification->id }}">{{ $classification->name_ar }}</option>
                            @endforeach
                        </select>
                        <div class="form-help">اختر التصنيفات المناسبة للكورس</div>
                    </div>

                    <div class="form-group-modern">
                        <label for="level_id" class="form-label-modern">
                            <i class="fa fa-signal label-icon"></i>
                            مستوى الكورس
                        </label>
                        <select name="level_id" id="level_id" class="form-control-modern select2">
                            <option value="">اختر المستوى...</option>
                            @foreach($levels as $level)
                                <option value="{{ $level->id }}">{{ $level->name_ar }} ({{ $level->name_en }})</option>
                            @endforeach
                        </select>
                        <div class="form-help">حدد مستوى صعوبة الكورس</div>
                    </div>

                    <div class="form-group-modern">
                        <label for="path_id" class="form-label-modern">
                            <i class="fa fa-road label-icon"></i>
                            المسار (Path)
                        </label>
                        <select name="path_id" id="path_id" class="form-control-modern select2">
                            <option value="">لا يوجد مسار</option>
                            @foreach($paths as $path)
                                <option value="{{ $path->id }}">{{ $path->title_ar }} ({{ $path->title_en }})</option>
                            @endforeach
                        </select>
                        <div class="form-help">اختر المسار الذي يتبع له هذا الكورس</div>
                    </div>
                </div>

                    <div class="form-group-modern">
                        <label class="form-label-modern">سياسة الشارة المهنية</label>
                        <input type="hidden" name="awards_badge" value="0">
                        <label class="badge-policy-toggle">
                            <input type="checkbox" name="awards_badge" value="1" {{ old('awards_badge') ? 'checked' : '' }}>
                            يمنح هذا الكورس شارة مهنية
                        </label>
                        <select name="badge_track" class="form-control-modern">
                            <option value="">بدون شارة (الديني واللغات وغيرهما)</option>
                            <option value="professional" {{ old('badge_track') === 'professional' ? 'selected' : '' }}>مهني</option>
                            <option value="freelance" {{ old('badge_track') === 'freelance' ? 'selected' : '' }}>فريلانس</option>
                        </select>
                        <div class="form-help">لن تُمنح الشارة إلا عند تفعيل الخيار واختيار مهني أو فريلانس.</div>
                    </div>

                </div>

                <div class="form-row">
                    <div class="form-group-modern">
                        <label for="price" class="form-label-modern">
                            <i class="fa fa-money label-icon"></i>
                            سعر فئة التعلّم (بالعملات)
                        </label>
                        {!! Form::number('price', null, [
                            'class' => 'form-control-modern' . ($errors->has('price') ? ' is-invalid' : ''),
                            'id' => 'price',
                            'placeholder' => '0',
                            'step' => '1',
                            'min' => '0'
                        ]) !!}
                        @if ($errors->has('price'))
                            <div class="invalid-feedback">
                                <i class="fa fa-exclamation-circle"></i>
                                {{ $errors->first('price') }}
                            </div>
                        @endif
                        <div class="form-help">يُنشئ هذا السعر الفئة الأولى ويمكنك ضبط الفئات الثلاث بعد إضافة الكورس</div>
                    </div>


                </div>

                <div class="form-row">
                    <div class="form-group-modern">
                        <label class="checkbox-item" for="is_main_course">
                            <div class="custom-checkbox">
                                <i class="fa fa-check"></i>
                            </div>
                            <div>
                                <div class="checkbox-item__title">كورس رئيسي</div>
                                <div class="checkbox-item__description">يظهر كبطل الصفحة الوحيد. عند نشره يستبدل الكورس الرئيسي السابق تلقائيًا.</div>
                            </div>
                            {!! Form::hidden('is_main_course', 0) !!}
                            {!! Form::checkbox('is_main_course', 1, false, ['id' => 'is_main_course', 'class' => 'course-create-checkbox-input']) !!}
                        </label>
                    </div>
                    <div class="form-group-modern">
                        <label class="checkbox-item selected" for="is_coming_soon">
                            <div class="custom-checkbox">
                                <i class="fa fa-check"></i>
                            </div>
                            <div>
                                <div class="checkbox-item__title">مسودة مخفية</div>
                                <div class="checkbox-item__description">يُحفظ الكورس كمسودة حتى تكتمل وحداته ومقاطعه ومشروعاته</div>
                            </div>
                            {!! Form::hidden('is_coming_soon', 0) !!}
                            {!! Form::checkbox('is_coming_soon', 1, true, ['id' => 'is_coming_soon', 'class' => 'course-create-checkbox-input']) !!}
                        </label>
                    </div>
                </div>
                </div>
                
                 <div class="form-row">
                    <div class="form-group-modern">
                        <label for="teacher_ids" class="form-label-modern">
                            <i class="fa fa-user-tie label-icon"></i>
                            المعلمون
                        </label>
                        <select name="teacher_ids[]" id="teacher_ids" class="form-control-modern select2" multiple>
                            @foreach($teachers as $teacher)
                                <option value="{{ $teacher->id }}">{{ $teacher->name }}</option>
                            @endforeach
                        </select>
                        <div class="form-help">يمكنك اختيار أكثر من معلم للإشراف على الكورس</div>
                    </div>
                </div>
            </div>
