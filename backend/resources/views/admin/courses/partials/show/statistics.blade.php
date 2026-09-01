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
                @if($ratingSummary)
                    <div class="stat-card">
                        <span class="stat-counter">{{ $ratingSummary['average'] !== null ? number_format($ratingSummary['average'], 1) : '—' }}</span>
                        <span class="stat-label">متوسط {{ number_format($ratingSummary['count']) }} تقييم فعلي</span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-counter">{{ number_format($ratingSummary['removed_count']) }}</span>
                        <span class="stat-label">تقييمات حذفها أصحابها</span>
                    </div>
                @endif
            </div>
        </div>
