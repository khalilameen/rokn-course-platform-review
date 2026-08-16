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
                                <option value="{{ $classification->id }}" {{ $course->classifications->contains($classification->id) ? 'selected' : '' }}>
                                    {{ $classification->name_ar }}
                                </option>
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
                                <option value="{{ $level->id }}" {{ $course->level_id == $level->id ? 'selected' : '' }}>
                                    {{ $level->name_ar }} ({{ $level->name_en }})
                                </option>
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
                                <option value="{{ $path->id }}" {{ $course->path_id == $path->id ? 'selected' : '' }}>
                                    {{ $path->title_ar }} ({{ $path->title_en }})
                                </option>
                            @endforeach
                        </select>
                        <div class="form-help">اختر المسار الذي يتبع له هذا الكورس</div>
                    </div>
                </div>
                    <div class="form-group-modern">
                        <label class="form-label-modern">سياسة الشارة المهنية</label>
                        <input type="hidden" name="awards_badge" value="0">
                        <label class="course-editor__inline-check course-editor__inline-check--spaced">
                            <input type="checkbox" name="awards_badge" value="1" {{ old('awards_badge', $course->awards_badge) ? 'checked' : '' }}>
                            يمنح هذا الكورس شارة مهنية
                        </label>
                        <select name="badge_track" class="form-control-modern">
                            <option value="">بدون شارة (الديني واللغات وغيرهما)</option>
                            <option value="professional" {{ old('badge_track', $course->badge_track) === 'professional' ? 'selected' : '' }}>مهني</option>
                            <option value="freelance" {{ old('badge_track', $course->badge_track) === 'freelance' ? 'selected' : '' }}>فريلانس</option>
                        </select>
                        <div class="form-help">لن تُمنح الشارة إلا عند تفعيل الخيار واختيار مهني أو فريلانس.</div>
                    </div>

                    <div class="form-group-modern">
                        <label for="teacher_ids" class="form-label-modern">
                            <i class="fa fa-user-tie label-icon"></i>
                            المعلمون
                        </label>
                        <select name="teacher_ids[]" id="teacher_ids" class="form-control-modern select2" multiple>
                            @foreach($teachers as $teacher)
                                <option value="{{ $teacher->id }}" {{ $course->teachers->contains($teacher->id) ? 'selected' : '' }}>
                                    {{ $teacher->name }}
                                </option>
                            @endforeach
                        </select>
                        <div class="form-help">يمكنك اختيار أكثر من معلم للإشراف على الكورس</div>
                    </div>
                </div>

                    <div class="form-group-modern">
                        <label for="students_count" class="form-label-modern">
                            <i class="fa fa-users label-icon"></i>
                            طلاب سابقون موثّقون
                        </label>
                        {!! Form::number('students_count', null, [
                            'class' => 'form-control-modern' . ($errors->has('students_count') ? ' is-invalid' : ''),
                            'id' => 'students_count',
                            'placeholder' => '0',
                            'min' => '0'
                        ]) !!}
                        @if ($errors->has('students_count'))
                            <div class="invalid-feedback">
                                <i class="fa fa-exclamation-circle"></i>
                                {{ $errors->first('students_count') }}
                            </div>
                        @endif
                        <div class="form-help">استخدمه فقط لطلاب سابقين قبل تشغيل النظام. المسجلون الجدد يُضافون تلقائيًا.</div>
                    </div>


                <div class="form-row">
                    <div class="form-group-modern">
                        <label for="price" class="form-label-modern">
                            <i class="fa fa-money label-icon"></i>
                            سعر الكورس (بالعملات)
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
                        <div class="form-help">حدد سعر الكورس بالعملات الافتراضية</div>
                    </div>

                </div>

                <div class="form-row">
                    <div class="form-group-modern">
                        <label class="checkbox-item {{ $course->is_main_course ? 'selected' : '' }}" for="is_main_course">
                            <div class="custom-checkbox">
                                <i class="fa fa-check{{ $course->is_main_course ? '' : ' course-editor__check-icon--hidden' }}"></i>
                            </div>
                            <div>
                                <div class="course-editor__option-title">كورس رئيسي</div>
                                <div class="course-editor__option-description">يظهر كبطل الصفحة الوحيد. اختياره يستبدل الكورس الرئيسي السابق تلقائيًا.</div>
                            </div>
                            {!! Form::hidden('is_main_course', 0) !!}
                            {!! Form::checkbox('is_main_course', 1, null, ['id' => 'is_main_course', 'class' => 'course-editor__native-checkbox']) !!}
                        </label>
                    </div>
                    <div class="form-group-modern">
                        <label class="checkbox-item {{ $course->is_coming_soon ? 'selected' : '' }}" for="is_coming_soon">
                            <div class="custom-checkbox">
                                <i class="fa fa-check{{ $course->is_coming_soon ? '' : ' course-editor__check-icon--hidden' }}"></i>
                            </div>
                            <div>
                                <div class="course-editor__option-title">مسودة مخفية</div>
                                <div class="course-editor__option-description">فعّلها لإخفاء الكورس. ألغِها لطلب النشر؛ لن يُنشر قبل اجتياز قائمة الاكتمال أعلاه.</div>
                            </div>
                            {!! Form::hidden('is_coming_soon', 0) !!}
                            {!! Form::checkbox('is_coming_soon', 1, $course->is_coming_soon, ['id' => 'is_coming_soon', 'class' => 'course-editor__native-checkbox']) !!}
                        </label>
                    </div>
                </div>

                @if($course->is_coming_soon)
                    <div class="form-row">
                        <div class="form-group-modern">
                            <label class="checkbox-item {{ $course->is_catalog_visible ? 'selected' : '' }}" for="is_catalog_visible">
                                <div class="custom-checkbox">
                                    <i class="fa fa-check{{ $course->is_catalog_visible ? '' : ' course-editor__check-icon--hidden' }}"></i>
                                </div>
                                <div>
                                    <div class="course-editor__option-title">إظهار بطاقة «قريبًا» في التطبيق</div>
                                    <div class="course-editor__option-description">يظهر الغلاف والاسم فقط ولا يمكن فتح الكورس. لن تُعرض البطاقة قبل اكتمال الغلاف والمدرب والتصنيف والوصف.</div>
                                </div>
                                {!! Form::hidden('is_catalog_visible', 0) !!}
                            {!! Form::checkbox('is_catalog_visible', 1, $course->is_catalog_visible, ['id' => 'is_catalog_visible', 'class' => 'course-editor__native-checkbox']) !!}
                            </label>
                        </div>
                    </div>
                @endif

                <div class="form-row">
                    <div class="form-group-modern">
                        <label for="home_sort_order" class="form-label-modern">ترتيب الكورس داخل صفوف الرئيسية</label>
                        {!! Form::number('home_sort_order', $course->home_sort_order ?? 100, ['class' => 'form-control-modern', 'id' => 'home_sort_order', 'min' => 0, 'max' => 10000, 'required' => true]) !!}
                        <div class="form-help">الرقم الأصغر يظهر أولًا داخل كل صف.</div>
                    </div>
                    <div class="form-group-modern">
                        <label for="catalog_badge_ar" class="form-label-modern">شارة البطاقة</label>
                        {!! Form::text('catalog_badge_ar', $course->catalog_badge_ar, ['class' => 'form-control-modern', 'id' => 'catalog_badge_ar', 'maxlength' => 40, 'placeholder' => 'مثال مجاني أو جديد']) !!}
                        <div class="form-help">اتركها فارغة إذا لم تكن لها قيمة حقيقية.</div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group-modern">
                        <label for="catalog_badge_en" class="form-label-modern">شارة البطاقة بالإنجليزية</label>
                        {!! Form::text('catalog_badge_en', $course->catalog_badge_en, ['class' => 'form-control-modern', 'id' => 'catalog_badge_en', 'maxlength' => 40]) !!}
                    </div>
                    <div class="form-group-modern">
                        <label for="catalog_badge_tone" class="form-label-modern">لون الشارة</label>
                        {!! Form::select('catalog_badge_tone', ['blue' => 'أزرق', 'green' => 'أخضر', 'gold' => 'ذهبي', 'neutral' => 'محايد'], $course->catalog_badge_tone ?: 'blue', ['class' => 'form-control-modern', 'id' => 'catalog_badge_tone']) !!}
                    </div>
                </div>
