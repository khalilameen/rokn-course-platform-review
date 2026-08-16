    <!-- Main Container -->
    <div class="exist-container">
        <!-- Control Panel -->
        <div class="control-panel">
            <h2 class="panel-title">
                <div class="title-icon">
                    <i class="fa fa-database"></i>
                </div>
                بيانات الكورسات
            </h2>
            
            <div class="control-buttons">
                <button class="btn-modern btn-success" onclick="downloadXML()">
                    <i class="fa fa-download"></i>
                    تحميل XML
                </button>
                <button class="btn-modern btn-primary" onclick="refreshData()">
                    <i class="fa fa-sync-alt"></i>
                    تحديث البيانات
                </button>
                <a href="{{ route('admin.courses.index') }}" class="btn-modern btn-secondary">
                    <i class="fa fa-arrow-left"></i>
                    العودة للكورسات
                </a>
            </div>
        </div>

        <!-- Content Tabs -->
        <div class="content-tabs">
            <button class="tab-button active" onclick="showTab('courses')">
                <i class="fa fa-list ml-1"></i>
                عرض الكورسات
            </button>
            <button class="tab-button" onclick="showTab('xml')">
                <i class="fa fa-code ml-1"></i>
                XML المُولد
            </button>
            <button class="tab-button" onclick="showTab('stats')">
                <i class="fa fa-chart-bar ml-1"></i>
                الإحصائيات
            </button>
        </div>

        <!-- Courses Tab -->
        <div id="courses-tab" class="tab-content active">
            @if($courses->count() > 0)
                <div class="courses-grid">
                    @foreach($courses as $course)
                        <div class="course-card">
                            <div class="course-header">
                                <div class="course-icon">
                                    <i class="fa fa-book"></i>
                                </div>
                                <div class="course-info">
                                    <h3 class="course-title">{{ $course->name_ar }}</h3>
                                    <span class="course-id">ID: {{ $course->id }}</span>
                                </div>
                            </div>
                            
                            @if($course->description_ar)
                                <div class="course-description">
                                    {{ Str::limit($course->description_ar, 120) }}
                                </div>
                            @endif
                            
                            <div class="course-meta">
                                @if($course->classifications->count() > 0)
                                    <span class="classification-badge">
                                        <i class="fa fa-tags ml-1"></i>
                                        @foreach($course->classifications as $classification) {{ $classification->name_ar }}{{ !$loop->last ? '، ' : '' }} @endforeach
                                    </span>
                                @else
                                    <span class="classification-badge classification-badge--missing">
                                        <i class="fa fa-exclamation-triangle ml-1"></i>
                                        بدون تصنيفات
                                    </span>
                                @endif
                                
                                <div class="course-created-at">
                                    <i class="fa fa-calendar ml-1"></i>
                                    {{ $course->created_at ? $course->created_at->format('Y/m/d') : 'غير محدد' }}
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="fa fa-book"></i>
                    </div>
                    <h3 class="empty-title">لا توجد كورسات</h3>
                    <p class="empty-description">
                        لم يتم العثور على أي كورسات مع معرف المستأجر
                    </p>
                    <a href="{{ route('admin.courses.create') }}" class="btn-modern btn-primary">
                        <i class="fa fa-plus"></i>
                        إنشاء كورس جديد
                    </a>
                </div>
            @endif
        </div>

        <!-- XML Tab -->
        <div id="xml-tab" class="tab-content">
            <div class="xml-display">
                <div class="xml-header">
                    <h3 class="xml-title">
                        <i class="fa fa-code"></i>
                        XML المُولد
                    </h3>
                    <button class="copy-button" onclick="copyXML()">
                        <i class="fa fa-copy"></i>
                        نسخ
                    </button>
                </div>
                <div class="xml-content" id="xml-content">{{ $coursesXML }}</div>
            </div>
            
            <div class="xml-info-panel">
                <h4 class="xml-info-panel__title">
                    <i class="fa fa-info-circle ml-1"></i>
                    معلومات XML
                </h4>
                <p class="xml-info-panel__row">
                    <strong>العدد الإجمالي للكورسات:</strong> {{ $courses->count() }}
                </p>
                <p class="xml-info-panel__row">
                    <strong>حجم الملف المقدر:</strong> {{ strlen($coursesXML) }} بايت
                </p>
                <p class="xml-info-panel__row xml-info-panel__row--last">
                    <strong>تاريخ الإنشاء:</strong> {{ now()->format('Y-m-d H:i:s') }}
                </p>
            </div>
        </div>

        <!-- Stats Tab -->
        <div id="stats-tab" class="tab-content">
            <div class="stats-summary">
                <h3 class="stats-summary__title">إحصائيات الكورسات</h3>
                
                <div class="stats-grid">
                    <div class="stat-item">
                        <span class="stat-number">{{ $courses->count() }}</span>
                        <span class="stat-label">إجمالي الكورسات</span>
                    </div>
                    
                    <div class="stat-item">
                        <span class="stat-number">{{ $courses->filter(function($c) { return $c->classifications->count() > 0; })->count() }}</span>
                        <span class="stat-label">كورسات بتصنيفات محددة</span>
                    </div>
                    
                    <div class="stat-item">
                        <span class="stat-number">{{ $courses->filter(function($c) { return $c->classifications->count() == 0; })->count() }}</span>
                        <span class="stat-label">كورسات بدون تصنيفات</span>
                    </div>
                    
                    <div class="stat-item">
                        <span class="stat-number">{{ $courses->where('description_ar', '!=', '')->whereNotNull('description_ar')->count() }}</span>
                        <span class="stat-label">كورسات بوصف</span>
                    </div>
                </div>
            </div>
            
            @if($courses->count() > 0)
                <div class="classification-summary">
                    <h4 class="classification-summary__title">توزيع الكورسات حسب التصنيف</h4>
                    
                    @php
                        $classificationGroups = $courses->groupBy(function($course) {
                            return $course->classifications->count() > 0 ? $course->classifications->first()->name_ar : 'بدون تصنيفات';
                        });
                    @endphp
                    
                    <div class="courses-grid">
                        @foreach($classificationGroups as $classificationName => $classificationCourses)
                            <div class="course-card">
                                <div class="course-header">
                                    <div class="course-icon course-icon--classification">
                                        <i class="fa fa-tags"></i>
                                    </div>
                                    <div class="course-info">
                                        <h3 class="course-title">{{ $classificationName }}</h3>
                                        <span class="course-id">{{ count($classificationCourses) }} كورس</span>
                                    </div>
                                </div>
                                
                                <div class="course-meta">
                                    <span class="classification-badge">
                                        نسبة: {{ number_format((count($classificationCourses) / $courses->count()) * 100, 1) }}%
                                    </span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>
