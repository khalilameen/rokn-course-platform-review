@extends('admin.layouts.app')

@section('page.title', 'تفاصيل نتيجة الامتحان')

@section('content')
<div class="content mt-3 admin-page exam-results-page">
    <div class="animated fadeIn">
        <div class="row">
            <div class="col-lg-12">
                <!-- Back Button -->
                <div class="mb-3">
                    <a href="{{ route('admin.exam-results.index') }}" class="btn btn-secondary">
                        <i class="fa fa-arrow-right mr-1"></i>
                        العودة إلى نتائج الامتحانات
                    </a>
                </div>

                <!-- Exam Result Summary -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h4 class="card-title mb-0">
                            <i class="fa fa-graduation-cap mr-2"></i>
                            ملخص نتيجة الامتحان
                        </h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <!-- Student Info -->
                            <div class="col-md-6">
                                <h5 class="text-primary mb-3">
                                    <i class="fa fa-user mr-2"></i>
                                    معلومات الطالب
                                </h5>
                                <div class="info-group">
                                    <strong>الاسم:</strong> {{ $examAttempt->user->name }}
                                </div>
                                <div class="info-group">
                                    <strong>البريد الإلكتروني:</strong> {{ $examAttempt->user->email }}
                                </div>
                            </div>

                            <!-- Exam Info -->
                            <div class="col-md-6">
                                <h5 class="text-primary mb-3">
                                    <i class="fa fa-file-text mr-2"></i>
                                    معلومات الامتحان
                                </h5>
                                @if($examAttempt->description)
                                    <div class="info-group">
                                        <strong>الوصف:</strong> {{ $examAttempt->quiz->description }}
                                    </div>
                                @endif
                                <div class="info-group">
                                    <strong>رقم المحاولة:</strong> {{ $examAttempt->attempt_number }}
                                </div>
                            </div>
                        </div>

                        <hr>

                        <!-- Results Summary -->
                        <div class="row">
                            <div class="col-md-12">
                                <h5 class="text-primary mb-3">
                                    <i class="fa fa-chart-bar mr-2"></i>
                                    ملخص النتائج
                                </h5>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-2">
                                <div class="text-center">
                                    <div class="stat-card">
                                        <div class="stat-value text-primary">{{ $examAttempt->total_questions }}</div>
                                        <div class="stat-label">إجمالي الأسئلة</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="text-center">
                                    <div class="stat-card">
                                        <div class="stat-value text-info">{{ $examAttempt->answered_questions }}</div>
                                        <div class="stat-label">الأسئلة المجابة</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="text-center">
                                    <div class="stat-card">
                                        <div class="stat-value text-success">{{ $examAttempt->correct_answers }}</div>
                                        <div class="stat-label">الإجابات الصحيحة</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="text-center">
                                    <div class="stat-card">
                                        <div class="stat-value {{ $examAttempt->score_percentage >= 60 ? 'text-success' : 'text-danger' }}">
                                            {{ $examAttempt->score_percentage }}%
                                        </div>
                                        <div class="stat-label">النتيجة النهائية</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="text-center">
                                    <div class="stat-card">
                                        <div class="stat-value text-warning">{{ $examAttempt->time_taken_minutes }}</div>
                                        <div class="stat-label">المدة (دقيقة)</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="text-center">
                                    <div class="stat-card">
                                        @if($examAttempt->is_passed)
                                            <div class="stat-value text-success">
                                                <i class="fa fa-check-circle"></i>
                                            </div>
                                            <div class="stat-label text-success">ناجح</div>
                                        @else
                                            <div class="stat-value text-danger">
                                                <i class="fa fa-times-circle"></i>
                                            </div>
                                            <div class="stat-label text-danger">راسب</div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row mt-3">
                            <div class="col-md-6">
                                <div class="info-group">
                                    <strong>تاريخ البدء:</strong> {{ \App\Support\BusinessClock::format($examAttempt->started_at, 'Y/m/d H:i') }}
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-group">
                                    <strong>تاريخ الإكمال:</strong> {{ \App\Support\BusinessClock::format($examAttempt->completed_at, 'Y/m/d H:i') }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Questions and Answers -->
                <div class="card">
                    <div class="card-header">
                        <h4 class="card-title mb-0">
                            <i class="fa fa-question-circle mr-2"></i>
                            الأسئلة والإجابات التفصيلية
                        </h4>
                    </div>
                    <div class="card-body">
                        @if($questionsWithAnswers->count() > 0)
                            @foreach($questionsWithAnswers as $index => $question)
                                <div class="question-card mb-4 {{ $question['is_correct'] ? 'border-success' : ($question['student_answer'] ? 'border-danger' : 'border-warning') }}">
                                    <div class="question-header">
                                        <div class="row align-items-center">
                                            <div class="col-md-8">
                                                <h6 class="question-title mb-1">
                                                    <span class="question-number">{{ $index + 1 }}.</span>
                                                    {{ $question['title'] ?? 'سؤال ' . ($index + 1) }}
                                                </h6>
                                            </div>
                                            <div class="col-md-4 text-left">
                                                @if($question['is_correct'])
                                                    <span class="badge badge-success">
                                                        <i class="fa fa-check mr-1"></i>
                                                        إجابة صحيحة
                                                    </span>
                                                @elseif($question['student_answer'])
                                                    <span class="badge badge-danger">
                                                        <i class="fa fa-times mr-1"></i>
                                                        إجابة خاطئة
                                                    </span>
                                                @else
                                                    <span class="badge badge-warning">
                                                        <i class="fa fa-question mr-1"></i>
                                                        لم يتم الإجابة
                                                    </span>
                                                @endif
                                            </div>
                                        </div>
                                    </div>

                                    <div class="question-content">
                                        <!-- Question Text -->
                                        <div class="question-text mb-3">
                                            <p><strong>السؤال:</strong> {{ $question['question'] }}</p>
                                            @if($question['description'])
                                                <p class="text-muted"><small>{{ $question['description'] }}</small></p>
                                            @endif
                                            @if($question['question_image'])
                                                <img src="{{ filter_var($question['question_image'], FILTER_VALIDATE_URL) ? $question['question_image'] : asset(ltrim($question['question_image'], '/')) }}"
                                                     class="img-fluid question-image"
                                                     alt="صورة السؤال">
                                            @endif
                                        </div>

                                        <!-- Choices -->
                                        <div class="choices">
                                            @foreach($question['choices'] as $choiceKey => $choiceValue)
                                                @if($choiceValue)
                                                    @php
                                                        $choiceNumber = str_replace('choice', '', $choiceKey);
                                                        $isStudentAnswer = $question['student_answer'] == $choiceNumber;
                                                        $isCorrectAnswer = $question['right_answer'] == $choiceNumber;
                                                    @endphp

                                                    <div class="choice-item
                                                        @if($isCorrectAnswer) correct-answer @endif
                                                        @if($isStudentAnswer && !$isCorrectAnswer) wrong-answer @endif
                                                        @if($isStudentAnswer && $isCorrectAnswer) student-correct @endif">

                                                        <div class="choice-content">
                                                            <div class="choice-text">
                                                                <strong>{{ $choiceNumber }}.</strong> {{ $choiceValue }}
                                                            </div>

                                                            <div class="choice-indicators">
                                                                @if($isCorrectAnswer)
                                                                    <span>
                                                                        <i class="fa fa-check text-success"></i>
                                                                        <small class="text-success">الإجابة الصحيحة</small>
                                                                    </span>
                                                                @endif

                                                                @if($isStudentAnswer && !$isCorrectAnswer)
                                                                    <span>
                                                                        <i class="fa fa-arrow-left text-danger"></i>
                                                                        <small class="text-danger">إجابة الطالب</small>
                                                                    </span>
                                                                @endif
                                                            </div>
                                                        </div>
                                                    </div>
                                                @endif
                                            @endforeach
                                        </div>

                                        <!-- Answer Info -->
                                        <div class="answer-info mt-3">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    @if($question['answered_at'])
                                                        <small class="text-muted">
                                                            <i class="fa fa-clock mr-1"></i>
                                                            تمت الإجابة في: {{ \App\Support\BusinessClock::format($question['answered_at'], 'H:i:s') }}
                                                        </small>
                                                    @else
                                                        <small class="text-warning">
                                                            <i class="fa fa-exclamation-triangle mr-1"></i>
                                                            لم يتم الإجابة على هذا السؤال
                                                        </small>
                                                    @endif
                                                </div>
                                                <div class="col-md-6 text-left">
                                                    <small class="text-muted">
                                                        النقاط: {{ $question['points_earned'] }}/{{ $question['max_points'] }}
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        @else
                            <div class="text-center py-4">
                                <i class="fa fa-question-circle fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">لا توجد أسئلة</h5>
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
@endsection
