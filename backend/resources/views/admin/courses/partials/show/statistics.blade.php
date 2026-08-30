        <!-- Statistics Tab -->
        <div id="statistics" class="tab-content">
            <div class="statistics-grid">
                <div class="stat-card">
                    <span class="stat-counter">{{ $sections->count() }}</span>
                    <span class="stat-label">إجمالي الأقسام</span>
                </div>
                <div class="stat-card">
                    <span class="stat-counter">{{ $sections->where('sectionable_type', 'App\Models\Lesson')->count() }}</span>
                    <span class="stat-label">الدروس</span>
                </div>
                <div class="stat-card">
                    <span class="stat-counter">{{ $sections->where('sectionable_type', 'App\Models\Question')->count() }}</span>
                    <span class="stat-label">الأسئلة</span>
                </div>
                <div class="stat-card">
                    <span class="stat-counter">{{ $sections->where('sectionable_type', 'App\Models\ItemList')->count() }}</span>
                    <span class="stat-label">الاختبارات</span>
                </div>
                <div class="stat-card">
                    <span class="stat-counter">{{ $sections->where('sectionable_type', 'App\Models\Link')->count() }}</span>
                    <span class="stat-label">الروابط</span>
                </div>
                <div class="stat-card">
                    <span class="stat-counter">{{ $activeStudentsCount }}</span>
                    <span class="stat-label">الطلاب النشطون فعليًا</span>
                </div>
            </div>
        </div>
