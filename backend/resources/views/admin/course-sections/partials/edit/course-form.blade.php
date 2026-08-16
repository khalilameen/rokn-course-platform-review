                <!-- Course Form -->
                <div class="form-section dynamic-form" id="course-form" data-type="course">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fa fa-book"></i>
                        </div>
                        <h3 class="section-title">تفاصيل الكورس الفرعي</h3>
                    </div>

                    @php
                        $subCourse = $section->getSectionType() == 'course' ? $section->sectionable : null;
                    @endphp

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label" for="course_name_ar">اسم الكورس بالعربية *</label>
                                <input type="text" id="course_name_ar" name="course_name_ar" class="form-control"
                                       value="{{ old('course_name_ar', $subCourse->name_ar ?? '') }}" placeholder="أدخل اسم الكورس بالعربية" data-required="true">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label" for="course_name_en">اسم الكورس بالإنجليزية *</label>
                                <input type="text" id="course_name_en" name="course_name_en" class="form-control"
                                       value="{{ old('course_name_en', $subCourse->name_en ?? '') }}" placeholder="Enter course name in English"  data-required="true">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label" for="course_description_ar">الوصف بالعربية</label>
                                <textarea id="course_description_ar" name="course_description_ar" class="form-control" rows="3"
                                          placeholder="أدخل وصف الكورس بالعربية">{{ old('course_description_ar', $subCourse->description_ar ?? '') }}</textarea>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="form-label" for="course_description_en">الوصف بالإنجليزية</label>
                                <textarea id="course_description_en" name="course_description_en" class="form-control" rows="3"
                                          placeholder="Enter course description in English">{{ old('course_description_en', $subCourse->description_en ?? '') }}</textarea>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="course_grade_id">المرحلة الدراسية *</label>
                        <select id="course_grade_id" name="course_grade_id" class="form-select" data-required="true">
                            <option value="">اختر المرحلة الدراسية</option>
                            @foreach(\App\Models\Grade::all() as $grade)
                                <option value="{{ $grade->id }}" {{ old('course_grade_id', $subCourse->grade_id ?? '') == $grade->id ? 'selected' : '' }}>
                                    {{ $grade->name_ar }}
                                </option>
                            @endforeach
                        </select>
                    </div>


                </div>
