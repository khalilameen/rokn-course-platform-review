@extends('admin.layouts.app')

@section('page.title', 'كورسات بدون امتحانات')

@section('styles')
{{-- Include Dynamic Theme Styles --}}
@include('admin.urgent-tasks.partials._dynamic_styles')

<link rel="stylesheet" href="{{ asset('admin/assets/css/urgent-tasks-shared.css') }}">
<link rel="stylesheet" href="{{ asset('admin/assets/css/urgent-tasks-courses-without-quiz.css') }}">
@endsection

@section('content')
<div class="admin-page urgent-subpage-container">
    <!-- Header Section -->
    <div class="subpage-header danger">
        <div class="subpage-title">
            <h2><i class="fa fa-exclamation-triangle"></i> كورسات بدون امتحانات</h2>
            <span class="badge">{{ $coursesWithoutQuiz->count() }} كورس</span>
        </div>
        <a href="{{ route('admin.urgent-tasks.index') }}" class="back-button btn-cancel-modern">
            <i class="fa fa-arrow-left"></i> العودة للمهام العاجلة
        </a>
    </div>

    <!-- Warning Alert -->
    @if($coursesWithoutQuiz->count() > 0)
    <div class="alert-modern">
        <i class="fa fa-info-circle"></i>
        <div>
            هذه الكورسات لا تحتوي على امتحانات. يرجى إضافة امتحان لكل كورس لضمان تقييم الطلاب بشكل صحيح.
        </div>
    </div>
    @endif

    <!-- Main Content -->
    <div class="row">
        <div class="col-12">
            <div class="modern-data-card">
                <div class="data-card-body">

                    @if($coursesWithoutQuiz->count() > 0)
                        <div class="table-responsive">
                            <table class="modern-table-enhanced table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>اسم الكورس</th>
                                        <th>المرحلة</th>
                                        <th>عدد الطلاب</th>
                                        <th>حالة الكورس</th>
                                        <th>تاريخ الإنشاء</th>
                                        <th>الإجراءات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($coursesWithoutQuiz as $course)
                                    <tr>
                                        <td>
                                            <span class="urgent-row-number">
                                                {{ $loop->iteration }}
                                            </span>
                                        </td>
                                        <td>
                                            <div class="course-info-enhanced">
                                                <div class="course-icon-enhanced">
                                                    <i class="fa fa-graduation-cap"></i>
                                                </div>
                                                <div class="course-details">
                                                    <a href="{{ route('admin.courses.show', $course->id) }}" class="course-name urgent-entity-link">{{ $course->title }}</a>
                                                    @if($course->description)
                                                        <div class="course-description">{{ Str::limit($course->description, 60) }}</div>
                                                    @endif
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            @if($course->grade)
                                                <a href="{{ route('admin.grades.index') }}" class="status-badge-enhanced info urgent-status-link">{{ $course->grade->name }}</a>
                                            @else
                                                <span class="text-muted urgent-muted-copy">
                                                    <i class="fa fa-question-circle"></i> غير محدد
                                                </span>
                                            @endif
                                        </td>
                                        <td>
                                            <span class="status-badge-enhanced count">{{ $course->users_count ?? 0 }} طالب</span>
                                        </td>
                                        <td>
                                            @if($course->is_active)
                                                <span class="status-badge-enhanced active">
                                                    <i class="fa fa-check-circle"></i> نشط
                                                </span>
                                            @else
                                                <span class="status-badge-enhanced inactive">
                                                    <i class="fa fa-pause-circle"></i> غير نشط
                                                </span>
                                            @endif
                                        </td>
                                        <td>
                                            <div class="urgent-date">
                                                {{ $course->created_at->format('Y-m-d') }}
                                            </div>
                                            <div class="urgent-date-meta">
                                                {{ \App\Support\BusinessClock::relative($course->created_at) }}
                                            </div>
                                        </td>
                                        <td>
                                            <a href="{{ route('admin.courses.sections.create', ['course' => $course->id, 'type' => 'quiz']) }}"
                                               class="action-btn-enhanced btn-secondary-center">
                                                <i class="fa fa-plus"></i> إضافة اختبار
                                            </a>
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <!-- Enhanced Pagination -->
                        <div class="d-flex justify-content-center">
                            {{ $coursesWithoutQuiz->links() }}
                        </div>
                    @else
                        <div class="empty-state-enhanced">
                            <i class="fa fa-check-circle fa-5x text-success"></i>
                            <h3>ممتاز! جميع الكورسات مكتملة</h3>
                            <p>جميع الكورسات تحتوي على امتحانات للتقييم</p>
                            <a href="{{ route('admin.urgent-tasks.index') }}" class="action-btn-enhanced btn-cancel-modern urgent-empty-action">
                                <i class="fa fa-arrow-left"></i> العودة للمهام العاجلة
                            </a>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Add smooth hover effects
    const rows = document.querySelectorAll('.modern-table-enhanced tbody tr');
    rows.forEach(row => {
        row.addEventListener('mouseenter', function() {
            this.style.backgroundColor = '#f8f9fa';
            this.style.transform = 'scale(1.002)';
        });

        row.addEventListener('mouseleave', function() {
            this.style.backgroundColor = '';
            this.style.transform = 'scale(1)';
        });
    });

    // Enhanced button interactions
    const actionButtons = document.querySelectorAll('.action-btn-enhanced');
    actionButtons.forEach(button => {
        button.addEventListener('mouseenter', function() {
            this.style.transform = 'scale(1.05)';
            this.style.boxShadow = '0 8px 25px rgba(0, 0, 0, 0.15)';
        });

        button.addEventListener('mouseleave', function() {
            this.style.transform = 'scale(1)';
            this.style.boxShadow = '';
        });
    });
});
</script>
@endsection

@endsection
