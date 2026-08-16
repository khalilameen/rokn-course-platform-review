@extends('admin.layouts.app')

@section('page.title', 'نتائج الامتحانات')

@section('content')
<div class="content mt-3 admin-page exam-results-page">
    <div class="animated fadeIn">
        <!-- Page Header -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="page-header-card">
                    <div class="d-flex justify-content-between align-items-center flex-wrap">
                        <div class="page-header-title">
                            <div class="icon-circle bg-gradient-primary">
                                <i class="fa fa-graduation-cap"></i>
                            </div>
                            <div>
                                <h2 class="mb-1">نتائج الامتحانات</h2>
                                <p class="text-muted mb-0">إدارة ومراقبة أداء الطلاب في الامتحانات</p>
                            </div>
                        </div>
                        <div class="page-header-actions">
                            <button type="button" class="btn btn-modern btn-secondary" onclick="getStats()">
                                <i class="fa fa-chart-bar mr-1"></i>
                                <span>إحصائيات</span>
                            </button>
                            <a href="{{ route('admin.exam-results.export', request()->query()) }}"
                               class="btn btn-modern btn-success">
                                <i class="fa fa-download mr-1"></i>
                                <span>تصدير CSV</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-12">
                <div class="card modern-card">
                    <!-- Filters -->
                    <div class="card-body border-bottom bg-light">
                        <form method="GET" action="{{ route('admin.exam-results.index') }}">
                            <div class="row">
                                <div class="col-lg-3 col-md-6 mb-3">
                                    <div class="form-group mb-0">
                                        <label for="search" class="form-label-modern">
                                            <i class="fa fa-search text-primary"></i>
                                            <span class="mr-2">البحث</span>
                                        </label>
                                        <input type="text" class="form-control form-control-modern" id="search" name="search"
                                               value="{{ request('search') }}"
                                               placeholder="اسم الطالب أو الامتحان...">
                                    </div>
                                </div>

                                <div class="col-lg-2 col-md-6 mb-3">
                                    <div class="form-group mb-0">
                                        <label for="student_id" class="form-label-modern">
                                            <i class="fa fa-user text-info"></i>
                                            <span class="mr-2">الطالب</span>
                                        </label>
                                        <select class="form-control form-control-modern" id="student_id" name="student_id">
                                            <option value="">جميع الطلاب</option>
                                            @foreach($students as $student)
                                                <option value="{{ $student->id }}"
                                                    {{ request('student_id') == $student->id ? 'selected' : '' }}>
                                                    {{ $student->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>

                                <div class="col-lg-2 col-md-6 mb-3">
                                    <div class="form-group mb-0">
                                        <label for="quiz_id" class="form-label-modern">
                                            <i class="fa fa-file-text-o text-warning"></i>
                                            <span class="mr-2">الامتحان</span>
                                        </label>
                                        <select class="form-control form-control-modern" id="quiz_id" name="quiz_id">
                                            <option value="">الامتحانات</option>
                                            @foreach($exams as $exam)
                                                <option value="{{ $exam->id }}"
                                                    {{ request('quiz_id') == $exam->id ? 'selected' : '' }}>
                                                    {{ $exam->title }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>

                                <div class="col-lg-2 col-md-6 mb-3">
                                    <div class="form-group mb-0">
                                        <label for="grade" class="form-label-modern">
                                            <i class="fa fa-trophy text-success"></i>
                                            <span class="mr-2">النتيجة</span>
                                        </label>
                                        <select class="form-control form-control-modern" id="grade" name="grade">
                                            <option value="">جميع النتائج</option>
                                            <option value="passed" {{ request('grade') == 'passed' ? 'selected' : '' }}>
                                                ناجح
                                            </option>
                                            <option value="failed" {{ request('grade') == 'failed' ? 'selected' : '' }}>
                                                راسب
                                            </option>
                                        </select>
                                    </div>
                                </div>

                                <div class="col-lg-3 col-md-12 mb-3">
                                    <div class="form-group mb-0">
                                        <label class="form-label-modern d-block">&nbsp;</label>
                                        <div class="filter-buttons-wrapper">
                                            <button type="submit" class="btn btn-modern btn-primary filter-btn">
                                                <i class="fa fa-search"></i>
                                                <span class="mr-2">بحث</span>
                                            </button>
                                            <a href="{{ route('admin.exam-results.index') }}" class="btn btn-modern btn-outline-secondary filter-btn">
                                                <i class="fa fa-redo"></i>
                                                <span class="mr-2">إعادة تعيين</span>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Results Table -->
                    <div class="card-body p-0">
                        @if($examResults->count() > 0)
                            <div class="table-responsive">
                                <table class="table modern-table mb-0">
                                    <thead>
                                        <tr>
                                            <th class="text-center">
                                                الطالب
                                            </th>
                                            <th class="text-center">
                                                الامتحان
                                            </th>
                                            <th class="text-center">
                                                تاريخ الإكمال
                                            </th>
                                            <th class="text-center">
                                                المدة
                                            </th>
                                            <th class="text-center">
                                                النتيجة
                                            </th>
                                            <th class="text-center">
                                                الدرجة
                                            </th>
                                            <th class="text-center">
                                                الحالة
                                            </th>
                                            <th class="text-center">
                                                الإجراءات
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($examResults as $result)
                                            <tr class="table-row-hover">
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="avatar-modern bg-gradient-primary">
                                                            <span>{{ mb_substr($result->user->name, 0, 1) }}</span>
                                                        </div>
                                                        <div class="mr-3">
                                                            <div class="font-weight-bold text-dark">{{ $result->user->name }}</div>
                                                            <small class="text-muted">
                                                                {{-- {{ $result->user->email }} --}}
                                                            </small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="exam-info">
                                                        @if($result->quiz && $result->quiz->title)
                                                            <div class="font-weight-bold text-dark mb-1">{{ $result->quiz->title }}</div>
                                                            {{-- @if($result->quiz->description)
                                                                <small class="text-muted">{{ Str::limit($result->quiz->description, 40) }}</small>
                                                            @endif --}}
                                                        @else
                                                            <span class="text-muted">
                                                                امتحان رقم {{ $result->quiz_id }}
                                                            </span>
                                                        @endif
                                                    </div>
                                                </td>
                                                <td>
                                                    @if($result->completed_at)
                                                        <div class="date-time-box">
                                                            <div class="font-weight-bold">{{ $result->completed_at->format('Y/m/d') }}</div>
                                                            <small class="text-muted">
                                                                {{ $result->completed_at->format('h:i') }}
                                                            </small>
                                                        </div>
                                                    @else
                                                        <span class="badge badge-modern badge-secondary">
                                                            غير مكتمل
                                                        </span>
                                                    @endif
                                                </td>
                                                <td>
                                                    @if($result->time_taken_minutes)
                                                        <span class="badge badge-modern badge-info">
                                                            {{ $result->time_taken_minutes }} دقيقة
                                                        </span>
                                                    @else
                                                        <span class="badge badge-modern badge-light">
                                                            غير محدد
                                                        </span>
                                                    @endif
                                                </td>
                                                <td>
                                                    @php
                                                        $percentage = $result->score_percentage ?? 0;
                                                        $displayPercentage = number_format($percentage, 1);
                                                        $barWidth = min(100, max(5, $percentage));

                                                        if ($percentage >= 90) {
                                                            $progressClass = 'bg-gradient-success';
                                                        } elseif ($percentage >= 75) {
                                                            $progressClass = 'bg-gradient-info';
                                                        } elseif ($percentage >= 60) {
                                                            $progressClass = 'bg-gradient-warning';
                                                        } else {
                                                            $progressClass = 'bg-gradient-danger';
                                                        }
                                                    @endphp
                                                    <div class="score-container">
                                                        <div class="progress progress-modern">
                                                            <div class="progress-bar {{ $progressClass }}"
                                                                 role="progressbar"
                                                                 data-progress-value="{{ $barWidth }}"
                                                                 aria-valuenow="{{ $percentage }}"
                                                                 aria-valuemin="0"
                                                                 aria-valuemax="100">
                                                            </div>
                                                        </div>
                                                        <span class="score-text font-weight-bold">{{ $displayPercentage }}%</span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="grade-box">
                                                        <span class="grade-value">{{ $result->correct_answers ?? 0 }}</span>
                                                        <span class="grade-separator">/</span>
                                                        <span class="grade-total">{{ $result->total_questions ?? 0 }}</span>
                                                    </div>
                                                </td>
                                                <td>
                                                    @if($result->is_passed)
                                                        <span class="badge badge-modern badge-success-modern">
                                                            <i class="fa fa-check-circle"></i>
                                                            ناجح
                                                        </span>
                                                    @else
                                                        <span class="badge badge-modern badge-danger-modern">
                                                            <i class="fa fa-times-circle"></i>
                                                            راسب
                                                        </span>
                                                    @endif
                                                </td>
                                                <td class="text-center">
                                                    <a href="{{ route('admin.exam-results.show', $result->id) }}"
                                                       class="btn btn-action btn-view"
                                                       title="عرض التفاصيل"
                                                       data-toggle="tooltip">
                                                        <i class="fa fa-eye"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            <!-- Pagination -->
                            <div class="modern-pagination-footer">
                                <div class="pagination-container">
                                    <div class="pagination-info-modern">
                                        <i class="fa fa-info-circle ml-1"></i>
                                        <span>
                                            عرض <strong>{{ $examResults->firstItem() ?? 0 }}</strong> إلى <strong>{{ $examResults->lastItem() ?? 0 }}</strong>
                                            من أصل <strong>{{ $examResults->total() }}</strong> نتيجة
                                        </span>
                                    </div>
                                    <div class="pagination-controls">
                                        {{ $examResults->appends(request()->query())->links() }}
                                    </div>
                                </div>
                            </div>
                        @else
                            <div class="empty-state">
                                <div class="empty-state-icon">
                                    <i class="fa fa-graduation-cap"></i>
                                </div>
                                <h4 class="empty-state-title">لا توجد نتائج امتحانات</h4>
                                <p class="empty-state-text">لم يتم العثور على نتائج امتحانات تطابق المعايير المحددة</p>
                                <a href="{{ route('admin.exam-results.index') }}" class="btn btn-modern btn-primary mt-3">
                                    <i class="fa fa-redo mr-1"></i>
                                    عرض جميع النتائج
                                </a>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Statistics Modal -->
<div class="modal fade" id="statsModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content modern-modal">
            <div class="modal-header stats-modal-header">
                <h4 class="modal-title mb-0">
                    <i class="fa fa-chart-bar mr-2"></i>
                    إحصائيات الامتحانات
                </h4>
                <button type="button" class="close stats-close-btn" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body stats-modal-body" id="statsContent">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary stats-loading-spinner" role="status">
                        <span class="sr-only">جاري التحميل...</span>
                    </div>
                    <p class="mt-3 text-muted">جاري تحميل الإحصائيات...</p>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@section('styles')
{{-- Include Dynamic Theme Styles --}}
@include('admin.exam-results.partials._dynamic_styles')
<link rel="stylesheet" href="{{ asset('admin/assets/css/exam-results-index.css') }}">

@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {

    // Initialize tooltips
    if (typeof window.jQuery !== 'undefined' && window.jQuery.fn.tooltip) {
        window.jQuery('[data-toggle="tooltip"]').tooltip();
    }
});

function getStats() {
    console.log('getStats function called');

    // Show modal using Bootstrap's JavaScript API
    var modal = document.getElementById('statsModal');
    if (typeof bootstrap !== 'undefined') {
        // Bootstrap 5
        var bsModal = new bootstrap.Modal(modal);
        bsModal.show();
    } else if (typeof window.jQuery !== 'undefined') {
        // Bootstrap 4 with jQuery
        window.jQuery('#statsModal').modal('show');
    } else {
        // Fallback - manually show modal
        modal.style.display = 'block';
        modal.classList.add('show');
        document.body.classList.add('modal-open');

        // Add backdrop
        var backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop fade show';
        document.body.appendChild(backdrop);
    }

    // Fetch stats
    fetch("{{ route('admin.exam-results.stats') }}")
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok: ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            console.log('Data received:', data);
            const content = `
                <div class="row stats-grid-row">
                    <div class="col-md-4 col-sm-6">
                        <div class="stats-card-modern">
                            <div class="stats-card-icon icon-primary">
                                <i class="fa fa-clipboard"></i>
                            </div>
                            <h3>${data.total_attempts || 0}</h3>
                            <div class="stats-divider"></div>
                            <p>إجمالي المحاولات</p>
                        </div>
                    </div>
                    <div class="col-md-4 col-sm-6">
                        <div class="stats-card-modern">
                            <div class="stats-card-icon icon-success">
                                <i class="fa fa-check-circle"></i>
                            </div>
                            <h3>${data.passed_attempts || 0}</h3>
                            <div class="stats-divider"></div>
                            <p>المحاولات الناجحة</p>
                        </div>
                    </div>
                    <div class="col-md-4 col-sm-6">
                        <div class="stats-card-modern">
                            <div class="stats-card-icon icon-danger">
                                <i class="fa fa-times-circle"></i>
                            </div>
                            <h3>${data.failed_attempts || 0}</h3>
                            <div class="stats-divider"></div>
                            <p>المحاولات الراسبة</p>
                        </div>
                    </div>
                    <div class="col-md-4 col-sm-6">
                        <div class="stats-card-modern">
                            <div class="stats-card-icon icon-info">
                                <i class="fa fa-percent"></i>
                            </div>
                            <h3>${data.pass_rate || 0}%</h3>
                            <div class="stats-divider"></div>
                            <p>معدل النجاح</p>
                        </div>
                    </div>
                    <div class="col-md-4 col-sm-6">
                        <div class="stats-card-modern">
                            <div class="stats-card-icon icon-warning">
                                <i class="fa fa-bar-chart-o"></i>
                            </div>
                            <h3>${Math.round(data.average_score || 0)}%</h3>
                            <div class="stats-divider"></div>
                            <p>متوسط الدرجات</p>
                        </div>
                    </div>
                    <div class="col-md-4 col-sm-6">
                        <div class="stats-card-modern">
                            <div class="stats-card-icon icon-purple">
                                <i class="fa fa-users"></i>
                            </div>
                            <h3>${data.total_students || 0}</h3>
                            <div class="stats-divider"></div>
                            <p>عدد الطلاب</p>
                        </div>
                    </div>
                </div>
            `;
            document.getElementById('statsContent').innerHTML = content;
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('statsContent').innerHTML = `
                <div class="alert alert-danger stats-error-alert">
                    <i class="fa fa-exclamation-triangle mr-2"></i>
                    <strong>خطأ!</strong> حدث خطأ في تحميل الإحصائيات: ${error.message}
                </div>
            `;
        });
}

// Add event listener for closing modal
document.addEventListener('DOMContentLoaded', function() {
    // Close modal when clicking close button
    var closeButtons = document.querySelectorAll('[data-dismiss="modal"]');
    closeButtons.forEach(function(button) {
        button.addEventListener('click', function() {
            var modal = document.getElementById('statsModal');
            modal.style.display = 'none';
            modal.classList.remove('show');
            document.body.classList.remove('modal-open');

            // Remove backdrop if it exists
            var backdrop = document.querySelector('.modal-backdrop');
            if (backdrop) {
                backdrop.remove();
            }
        });
    });

    // Close modal when clicking outside
    var modal = document.getElementById('statsModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                modal.style.display = 'none';
                modal.classList.remove('show');
                document.body.classList.remove('modal-open');

                var backdrop = document.querySelector('.modal-backdrop');
                if (backdrop) {
                    backdrop.remove();
                }
            }
        });
    }
});
</script>
@endsection
