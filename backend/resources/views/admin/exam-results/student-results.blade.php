@extends('admin.layouts.app')

@section('page.title', 'نتائج امتحانات الطالب')

@section('content')
<div class="content mt-3 admin-page exam-results-page">
    <div class="animated fadeIn">
        <div class="row">
            <div class="col-lg-12">
                <!-- Back Button -->
                <div class="mb-3">
                    <a href="{{ route('admin.users.show', $student->id) }}" class="btn btn-secondary">
                        <i class="fa fa-arrow-right mr-1"></i>
                        العودة إلى ملف الطالب
                    </a>
                </div>

                <!-- Student Info -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h4 class="card-title mb-0">
                            <i class="fa fa-user-graduate mr-2"></i>
                            نتائج امتحانات الطالب: {{ $student->name }}
                        </h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="student-info">
                                    <h6 class="text-muted">معلومات الطالب</h6>
                                    <p><strong>الاسم:</strong> {{ $student->name }}</p>
                                    <p><strong>البريد الإلكتروني:</strong> {{ $student->email }}</p>
                                    @if($student->first_name || $student->last_name)
                                        <p><strong>الاسم الكامل:</strong> {{ $student->first_name }} {{ $student->last_name }}</p>
                                    @endif
                                </div>
                            </div>
                            <div class="col-md-8">
                                @if($examResults->count() > 0)
                                    @php
                                        $totalAttempts = $examResults->total();
                                        $passedAttempts = $examResults->where('is_passed', true)->count();
                                        $averageScore = $examResults->avg('score_percentage');
                                    @endphp
                                    <div class="row">
                                        <div class="col-md-3">
                                            <div class="stat-box text-center">
                                                <h4 class="text-primary">{{ $totalAttempts }}</h4>
                                                <small class="text-muted">إجمالي المحاولات</small>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="stat-box text-center">
                                                <h4 class="text-success">{{ $passedAttempts }}</h4>
                                                <small class="text-muted">المحاولات الناجحة</small>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="stat-box text-center">
                                                <h4 class="text-info">{{ round($averageScore, 1) }}%</h4>
                                                <small class="text-muted">متوسط الدرجات</small>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="stat-box text-center">
                                                <h4 class="{{ $totalAttempts > 0 ? (($passedAttempts / $totalAttempts) >= 0.6 ? 'text-success' : 'text-warning') : 'text-muted' }}">
                                                    {{ $totalAttempts > 0 ? round(($passedAttempts / $totalAttempts) * 100, 1) : 0 }}%
                                                </h4>
                                                <small class="text-muted">معدل النجاح</small>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Exam Results -->
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title mb-0">
                            <i class="fa fa-list mr-2"></i>
                            تفاصيل نتائج الامتحانات
                        </h4>
                    </div>
                    <div class="card-body">
                        @if($examResults->count() > 0)
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>الامتحان</th>
                                            <th>تاريخ الإكمال</th>
                                            <th>المدة</th>
                                            <th>النتيجة</th>
                                            <th>الدرجة</th>
                                            <th>الحالة</th>
                                            <th>الإجراءات</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($examResults as $result)
                                            <tr>
                                                <td>
                                                    <strong>{{ $result->quiz->title }}</strong>
                                                    @if($result->quiz->description)
                                                        <br>
                                                        <small class="text-muted">{{ Str::limit($result->quiz->description, 50) }}</small>
                                                    @endif
                                                    @if($result->attempt_number > 1)
                                                        <br>
                                                        <span class="badge badge-info badge-sm">المحاولة {{ $result->attempt_number }}</span>
                                                    @endif
                                                </td>
                                                <td>
                                                    <span class="d-block">{{ \App\Support\BusinessClock::format($result->completed_at, 'Y/m/d') }}</span>
                                                    <small class="text-muted">{{ \App\Support\BusinessClock::format($result->completed_at, 'H:i') }}</small>
                                                </td>
                                                <td>
                                                    <span class="badge badge-info">
                                                        {{ $result->time_taken_minutes }} دقيقة
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="progress student-score-progress">
                                                        <div class="progress-bar {{ $result->score_percentage >= 60 ? 'bg-success' : 'bg-danger' }}" 
                                                             role="progressbar" 
                                                             data-progress-value="{{ $result->score_percentage }}"
                                                             aria-valuenow="{{ $result->score_percentage }}"
                                                             aria-valuemin="0"
                                                             aria-valuemax="100">
                                                            {{ $result->score_percentage }}%
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="text-muted">{{ $result->correct_answers }}/{{ $result->total_questions }}</span>
                                                </td>
                                                <td>
                                                    @if($result->is_passed)
                                                        <span class="badge badge-success">
                                                            <i class="fa fa-check mr-1"></i>
                                                            ناجح
                                                        </span>
                                                    @else
                                                        <span class="badge badge-danger">
                                                            <i class="fa fa-times mr-1"></i>
                                                            راسب
                                                        </span>
                                                    @endif
                                                </td>
                                                <td>
                                                    <a href="{{ route('admin.exam-results.show', $result->id) }}" 
                                                       class="btn btn-sm btn-outline-primary" 
                                                       title="عرض التفاصيل">
                                                        <i class="fa fa-eye"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            <!-- Pagination -->
                            <div class="d-flex justify-content-center">
                                {{ $examResults->links() }}
                            </div>
                        @else
                            <div class="text-center py-4">
                                <i class="fa fa-graduation-cap fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">لا توجد نتائج امتحانات</h5>
                                <p class="text-muted">لم يقم هذا الطالب بأداء أي امتحانات بعد</p>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@section('styles')
{{-- Include Dynamic Theme Styles --}}
@include('admin.exam-results.partials._dynamic_styles')
<link rel="stylesheet" href="{{ asset('admin/assets/css/exam-results-student-results.css') }}">

@endsection
