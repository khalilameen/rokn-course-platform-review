    <!-- Courses Container -->
    <div class="courses-container">
        <!-- Header -->
        <div class="courses-header">
            <h2 class="courses-title">
                <div class="title-icon">
                    <i class="fa fa-book"></i>
                </div>
                قائمة الكورسات
            </h2>
        </div>

        <!-- Filters Section -->
        <div class="filters-section">
            <div class="filters-grid">
                <div class="filter-group">
                    <label class="filter-label">البحث في الكورسات</label>
                    <input type="text" id="courseSearch" class="filter-input" placeholder="ابحث باسم الكورس أو الوصف...">
                </div>
                
                <div class="filter-group courses-filter--hidden">
                    <label class="filter-label">نوع الكورس</label>
                    <select id="typeFilter" class="filter-select">
                        <option value="">جميع الأنواع</option>
                        <option value="online">أونلاين</option>
                        <option value="center">مركز</option>
                        <option value="both">مركز وأونلاين</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="filter-label">التصنيفات </label>
                    <select id="classificationFilter" class="filter-select">
                        <option value="">جميع التصنيفات</option>
                        @php
                            $allClassifications = $courses->flatMap->classifications->unique('id');
                        @endphp
                        @foreach($allClassifications as $classification)
                            <option value="{{ $classification->id }}">{{ $classification->name_ar }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="filter-group">
                    <button onclick="resetFilters()" class="btn-modern btn-primary-modern">
                        <i class="fa fa-refresh"></i>
                        إعادة تعيين
                    </button>
                </div>
            </div>
        </div>

        <!-- Courses Grid -->
        @if($courses->count() > 0)
            <div class="courses-grid" id="coursesGrid">
                @foreach($courses as $course)
                    <div class="course-card" data-course-type="{{ $course->course_type }}" data-classification-ids="{{ json_encode($course->classifications->pluck('id')) }}" data-search="{{ strtolower($course->title . ' ' . $course->description) }}" data-url="{{ route('admin.courses.show', $course->id) }}" onclick="navigateToCourse(event, this)">
                        <!-- Course Image -->
                        <div class="course-image-container">
                            @if($course->image)
                                <img src="{{ $course->image }}" alt="{{ $course->title }}" class="course-image-img">
                            @else
                                <div class="course-image-placeholder">
                                    <i class="fa fa-book"></i>
                                </div>
                            @endif
                            @if($course->is_coming_soon)
                                <div class="course-coming-soon-badge">
                                    <i class="fa fa-clock-o course-coming-soon-badge__icon"></i> قريباً
                                </div>
                            @endif
                        </div>

                        <!-- Course Body -->
                        <div class="course-body">
                            <!-- Course Title -->
                            <h3 class="course-title">{{ $course->title }}</h3>

                            @php($publishingAudit = $publishingAudits->get($course->id))
                            <div class="mb-3">
                                @if(!$course->is_coming_soon)
                                    <span class="badge badge-success px-3 py-2">منشور</span>
                                @elseif($course->is_catalog_visible)
                                    <span class="badge badge-primary px-3 py-2">مُعلن في التطبيق · قريبًا</span>
                                @elseif($publishingAudit && $publishingAudit['ready'])
                                    <span class="badge badge-info px-3 py-2">مسودة جاهزة للنشر</span>
                                @else
                                    <span class="badge badge-warning px-3 py-2">
                                        مسودة · {{ count($publishingAudit['issues'] ?? []) }} عناصر ناقصة
                                    </span>
                                @endif
                            </div>

                            <!-- Course Meta -->
                            <div class="course-meta">
                                @if($course->classifications->count() > 0)
                                    <div class="meta-item">
                                        <i class="fa fa-tags meta-icon"></i>
                                        <span>
                                            @foreach($course->classifications as $classification)
                                                <span class="badge badge-light">{{ $classification->name_ar }}</span>
                                            @endforeach
                                        </span>
                                    </div>
                                @endif



                                <div class="meta-item">
                                    <i class="fa fa-money meta-icon"></i>
                                    <span>{{ (int) $course->price === 0 ? 'مجاني' : number_format($course->price) . ' عملة' }}</span>
                                </div>
                                <div class="meta-item">
                                    <i class="fa fa-robot meta-icon"></i>
                                    <span>{{ $course->ai_chat_enabled ? 'Rokn AI مفعّل للمدفوع' : 'Rokn AI متوقف' }}</span>
                                </div>
                            </div>

                            @if($canViewFinance)
                            <div class="course-finance-summary">
                                <div class="course-finance-summary__grid">
                                    <div>
                                        <strong class="course-finance-summary__value course-finance-summary__value--total">{{ number_format((int) ($course->total_coins_spent ?? 0)) }}</strong>
                                        <small>إجمالي العملات</small>
                                    </div>
                                    <div>
                                        <strong class="course-finance-summary__value course-finance-summary__value--paid">{{ number_format((int) ($course->paid_coins_spent ?? 0)) }}</strong>
                                        <small>عملات مشتراة</small>
                                    </div>
                                    <div>
                                        <strong class="course-finance-summary__value course-finance-summary__value--reward">{{ number_format((int) ($course->reward_coins_spent ?? 0)) }}</strong>
                                        <small>عملات مكافآت</small>
                                    </div>
                                </div>
                                <small class="course-finance-summary__note">
                                    تُستهلك المكافآت أولًا ثم العملات المشتراة. هذه وحدات عملات ركن وليست إيرادًا نقديًا؛ دخل Kashier مستقل في طلبات الباقات.
                                </small>
                            </div>
                            @endif

                            <!-- Course Description -->
                            @if($course->description)
                                <div class="course-description">
                                    {{ $course->description }}
                                </div>
                            @endif

                            <!-- Course Stats -->
                            <div class="course-stats">
                                <div class="stat-mini">
                                    <span class="stat-mini-number">{{ number_format((int) ($course->active_enrollments_count ?? 0)) }}</span>
                                    <span class="stat-mini-label">اشتراكات فعلية</span>
                                </div>
                                <div class="stat-mini">
                                    <span class="stat-mini-number">{{ number_format((int) ($course->students_count ?? 0)) }}</span>
                                    <span class="stat-mini-label">رصيد طلاب سابق</span>
                                </div>
                                <div class="stat-mini">
                                    <span class="stat-mini-number">{{ $course->ratings_count ? number_format((float) $course->ratings_avg_rating, 1) : '—' }}</span>
                                    <span class="stat-mini-label">تقييم · {{ number_format((int) $course->ratings_count) }}</span>
                                </div>
                                <div class="stat-mini">
                                    <span class="stat-mini-number">{{ number_format((int) ($course->preview_steps_count ?? 0)) }}</span>
                                    <span class="stat-mini-label">خطوات مجانية</span>
                                </div>
                            </div>
                            <div class="text-muted course-card-footnote mb-3">
                                يظهر للطالب {{ number_format((int) ($course->students_count ?? 0) + (int) ($course->active_enrollments_count ?? 0)) }} طالبًا
                                · {{ number_format((int) $course->sections_count) }} أقسام
                                @if($course->is_main_course) · <strong class="text-primary">الكورس الرئيسي الوحيد</strong> @endif
                            </div>

                            <!-- Course Actions -->
                            <div class="course-actions">
                                <a href="{{ route('admin.courses.show', $course->id) }}" class="btn-card btn-card-primary">
                                    <i class="fa fa-eye"></i>
                                    عرض
                                </a>
                                <a href="{{ route('admin.courses.edit', $course->id) }}" class="btn-card btn-card-success">
                                    <i class="fa fa-edit"></i>
                                    تعديل
                                </a>
                                <a href="{{ route('admin.courses.sections.index', $course->id) }}" class="btn-card btn-card-info">
                                    <i class="fa fa-list"></i>
                                    الأقسام
                                </a>
                                <button onclick="deleteCourse({{ $course->id }})" class="btn-card btn-card-danger">
                                    <i class="fa fa-trash"></i>
                                    حذف
                                </button>
                            </div>
                        </div>

                        <!-- Hidden Delete Form -->
                        <form class="course-delete-form" id="deleteForm{{ $course->id }}" action="{{ route('admin.courses.destroy', $course->id) }}" method="post">
                            <input name="_method" type="hidden" value="DELETE">
                            @csrf
                        </form>
                    </div>
                @endforeach
            </div>
        @else
            <!-- Empty State -->
            <div class="empty-state">
                <div class="empty-icon">
                    <i class="fa fa-book"></i>
                </div>
                <h3 class="empty-title">لا توجد كورسات حالياً</h3>
                <p class="empty-description">
                    لم يتم إنشاء أي كورسات بعد. ابدأ بإضافة أول كورس لك.
                </p>
                <a href="{{ route('admin.courses.create') }}" class="btn-modern btn-primary-modern">
                    <i class="fa fa-plus"></i>
                    إضافة كورس جديد
                </a>
            </div>
        @endif
    </div>
