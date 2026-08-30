        <!-- Overview Tab -->
        <div id="overview" class="tab-content active">
            <div class="info-grid">
                <!-- Basic Information -->
                <div class="info-section">
                    <h3 class="section-title">
                        <div class="section-icon">
                            <i class="fa fa-info"></i>
                        </div>
                        المعلومات الأساسية
                    </h3>
                    <table class="info-table">
                        <tr>
                            <td>الاسم:</td>
                            <td>{{ $course->name_ar ?? $course->name_en ?? 'غير محدد' }}</td>
                        </tr>

                        @if($course->level)
                            <tr>
                                <td>المستوى:</td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        @if($course->level->badge_image_url)
                                            <img src="{{ $course->level->badge_image_url }}" alt="{{ $course->level->name_ar }}" class="course-level-badge">
                                        @endif
                                        <span class="badge badge-primary">{{ $course->level->name_ar }}</span>
                                    </div>
                                </td>
                            </tr>
                        @endif
                        @if($course->classifications->count() > 0)
                            <tr>
                                <td>التصنيفات:</td>
                                <td>
                                    @foreach($course->classifications as $classification)
                                        <span class="badge badge-info">{{ $classification->name_ar }}</span>
                                    @endforeach
                                </td>
                            </tr>
                        @endif
                        <tr>
                            <td>تاريخ الإنشاء:</td>
                            <td>{{ $course->created_at->format('Y/m/d H:i') }}</td>
                        </tr>
                        <tr>
                            <td>آخر تحديث:</td>
                            <td>{{ $course->updated_at->format('Y/m/d H:i') }}</td>
                        </tr>
                    </table>
                </div>

                <!-- Pricing Information -->
                <div class="info-section">
                    <h3 class="section-title">
                        <div class="section-icon">
                            <i class="fa fa-money"></i>
                        </div>
                        معلومات السعر
                    </h3>
                    <table class="info-table">
                        <tr>
                            <td>السعر:</td>
                            <td>
                                @if($course->price)
                                    {{ number_format($course->price) }} عملة
                                @else
                                    مجاني
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td>عدد الطلاب:</td>
                            <td>{{ $activeStudentsCount }} طالب نشط</td>
                        </tr>

                    </table>
                </div>

                <!-- Course Statistics -->
                <div class="info-section">
                    <h3 class="section-title">
                        <div class="section-icon">
                            <i class="fa fa-bar-chart-o"></i>
                        </div>
                        إحصائيات المحتوى
                    </h3>
                    <table class="info-table">
                        <tr>
                            <td>عدد الفيديوهات:</td>
                            <td>{{ $course->video_count ?? 0 }}</td>
                        </tr>
                        <tr>
                            <td>عدد الساعات:</td>
                            <td>{{ $course->hours_count ?? 0 }} ساعة</td>
                        </tr>
                        <tr>
                            <td>عدد الأسئلة:</td>
                            <td>{{ $course->questions_count ?? 0 }}</td>
                        </tr>
                        <tr>
                            <td>عدد الاختبارات:</td>
                            <td>{{ $course->exam_count ?? 0 }}</td>
                        </tr>
                        <tr>
                            <td>عدد الواجبات:</td>
                            <td>{{ $course->home_work_count ?? 0 }}</td>
                        </tr>
                        <tr>
                            <td>عدد الملفات:</td>
                            <td>{{ $course->files_count ?? 0 }}</td>
                        </tr>
                    </table>
                </div>

            </div>

            <!-- Description -->
            @if($course->description_ar || $course->description_en)
                <div class="info-section course-description-section">
                    <h3 class="section-title">
                        <div class="section-icon">
                            <i class="fa fa-align-left"></i>
                        </div>
                        وصف الكورس
                    </h3>
                    @if($course->description_ar)
                        <div class="course-description-block">
                            <h4 class="section-title course-description-title">الوصف العربي:</h4>
                            <p class="course-description-copy">{{ $course->description_ar }}</p>
                        </div>
                    @endif
                    @if($course->description_en)
                        <div>
                            <h4 class="section-title course-description-title">الوصف الإنجليزي:</h4>
                            <p class="course-description-copy">{{ $course->description_en }}</p>
                        </div>
                    @endif
                </div>
            @endif
        </div>
