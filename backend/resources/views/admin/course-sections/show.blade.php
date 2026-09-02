@extends('admin.layouts.app')

@section('page.title', 'تفاصيل القسم')

@section('styles')
{{-- Include Dynamic Theme Styles --}}
@include('admin.course-sections.partials._dynamic_styles')
<link rel="stylesheet" href="{{ asset('admin/assets/css/course-sections-show.css') }}">
@endsection

@section('content')

<div class="admin-page fade-in">
    <!-- Header Section -->
    <div class="show-section-header">
        <div class="header-content">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <h1 class="mb-2">
                        <i class="fa fa-eye ml-2"></i>
                        تفاصيل القسم
                    </h1>
                    <p class="mb-0 opacity-75">معلومات كاملة عن القسم ومحتواه</p>
                </div>
            </div>

            <!-- Course Info Banner -->
            <div class="course-info-banner">
                <div class="course-meta">
                    <div class="course-meta-item">
                        <i class="fa fa-book"></i>
                        <span>{{ $course->title ?? $course->name_ar }}</span>
                    </div>
                    @if($course->classifications->count() > 0)
                        <div class="course-meta-item">
                            <i class="fa fa-tags"></i>
                            <span>
                                @foreach($course->classifications as $classification)
                                    {{ $classification->name_ar }}{{ !$loop->last ? '، ' : '' }}
                                @endforeach
                            </span>
                        </div>
                    @endif
                    <div class="course-meta-item">
                        <i class="fa fa-list"></i>
                        <span>{{ $course->sections()->count() }} قسم</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Section Container -->
    <div class="show-section-container">
        <!-- Section Hero -->
        <div class="section-hero">
            <div class="section-hero-content">
                @php
                    $sectionType = class_basename($section->sectionable_type);
                    $iconClass = '';
                    $iconBg = '';

                    switch(strtolower($sectionType)) {
                        case 'lesson':
                            $iconClass = 'fa fa-play-circle';
                            $iconBg = 'badge-lesson';
                            $typeNameAr = 'درس';
                            break;
                        case 'question':
                            $iconClass = 'fa fa-question-circle';
                            $iconBg = 'badge-question';
                            $typeNameAr = 'سؤال';
                            break;
                        case 'link':
                            $iconClass = 'fa fa-link';
                            $iconBg = 'badge-link';
                            $typeNameAr = 'رابط خارجي';
                            break;
                        case 'itemlist':
                            $iconClass = 'fa fa-list-ul';
                            $iconBg = 'badge-quiz';
                            $typeNameAr = 'اختبار';
                            break;
                        case 'course':
                            $iconClass = 'fa fa-book';
                            $iconBg = 'badge-course';
                            $typeNameAr = 'كورس فرعي';
                            break;
                        default:
                            $iconClass = 'fa fa-file';
                            $iconBg = 'badge-lesson';
                            $typeNameAr = $sectionType;
                    }
                @endphp

                <div class="section-type-badge {{ $iconBg }}">
                    <i class="{{ $iconClass }}"></i>
                </div>

                <div class="section-hero-details">
                    <h2 class="section-main-title">{{ $section->title }}</h2>
                    <div class="section-meta-row">
                        <span class="meta-badge badge-order">
                            <i class="fa fa-sort-numeric-up"></i>
                            ترتيب: {{ $section->order }}
                        </span>
                        <span class="meta-badge badge-type-name">
                            <i class="{{ $iconClass }}"></i>
                            {{ $typeNameAr }}
                        </span>
                        <span class="meta-badge badge-date">
                            <i class="fa fa-calendar"></i>
                            {{ $section->created_at->format('Y-m-d') }}
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Content Details -->
        <div class="content-details">
            {{-- @if($section->sectionable) --}}
                @switch(strtolower($sectionType))
                    @case('lesson')
                        <!-- Lesson Details -->
                        <div class="detail-section">
                            <div class="detail-header">
                                <div class="detail-icon">
                                    <i class="fa fa-play-circle"></i>
                                </div>
                                <h3 class="detail-title">تفاصيل الدرس</h3>
                            </div>

                            <div class="detail-grid">
                                <div class="detail-item">
                                    <div class="detail-label">عنوان الدرس</div>
                                    <div class="detail-value">{{ $section->sectionable->title ?? 'غير محدد' }}</div>
                                </div>
                                @if($section->sectionable->video_link)
                                    <div class="detail-item">
                                        <div class="detail-label">رابط الفيديو</div>
                                        <a href="{{ $section->sectionable->video_link }}" target="_blank" class="detail-value url">
                                            فتح الفيديو
                                            <i class="fa fa-link ml-1"></i>
                                        </a>
                                    </div>
                                @endif
                            </div>

                            @if($section->sectionable->description)
                                <div class="content-display">
                                    <div class="detail-label">الوصف</div>
                                    <div class="content-text">{{ $section->sectionable->description }}</div>
                                </div>
                            @endif

                            @if($section->sectionable->video_link)
                                <div class="content-display">
                                    <div class="detail-label">معاينة الفيديو</div>
                                    <div class="video-preview">
                                        @php
                                            $videoLink = $section->sectionable->video_link;
                                            $embedUrl = $videoLink;

                                            // Convert YouTube URLs to embed format
                                            if (preg_match('/youtube\.com\/watch\?v=([a-zA-Z0-9_-]+)/', $videoLink, $matches)) {
                                                $embedUrl = 'https://www.youtube.com/embed/' . $matches[1];
                                            } elseif (preg_match('/youtu\.be\/([a-zA-Z0-9_-]+)/', $videoLink, $matches)) {
                                                $embedUrl = 'https://www.youtube.com/embed/' . $matches[1];
                                            } elseif (preg_match('/youtube\.com\/embed\/([a-zA-Z0-9_-]+)/', $videoLink, $matches)) {
                                                $embedUrl = $videoLink; // Already in embed format
                                            }

                                            // Convert Vimeo URLs to embed format
                                            elseif (preg_match('/vimeo\.com\/(\d+)/', $videoLink, $matches)) {
                                                $embedUrl = 'https://player.vimeo.com/video/' . $matches[1];
                                            }

                                            // Convert Google Drive URLs to embed format
                                            elseif (preg_match('/drive\.google\.com\/file\/d\/([a-zA-Z0-9_-]+)/', $videoLink, $matches)) {
                                                $embedUrl = 'https://drive.google.com/file/d/' . $matches[1] . '/preview';
                                            } elseif (preg_match('/drive\.google\.com\/open\?id=([a-zA-Z0-9_-]+)/', $videoLink, $matches)) {
                                                $embedUrl = 'https://drive.google.com/file/d/' . $matches[1] . '/preview';
                                            } elseif (preg_match('/docs\.google\.com\/file\/d\/([a-zA-Z0-9_-]+)/', $videoLink, $matches)) {
                                                $embedUrl = 'https://drive.google.com/file/d/' . $matches[1] . '/preview';
                                            }
                                        @endphp
                                        <iframe src="{{ $embedUrl }}" frameborder="0" allowfullscreen allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"></iframe>
                                    </div>
                                </div>
                            @endif
                        </div>
                        @break

                    @case('question')
                        <!-- Question Details -->
                        <div class="detail-section">
                            <div class="detail-header">
                                <div class="detail-icon">
                                    <i class="fa fa-question-circle"></i>
                                </div>
                                <h3 class="detail-title">تفاصيل السؤال</h3>
                            </div>

                            <div class="detail-grid">
                                <div class="detail-item">
                                    <div class="detail-label">عنوان السؤال</div>
                                    <div class="detail-value">{{ $section->sectionable->title ?? 'غير محدد' }}</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">الإجابة الصحيحة</div>
                                    <div class="detail-value">الخيار {{ $section->sectionable->right_answer }}</div>
                                </div>
                            </div>

                            @if($section->sectionable->question)
                                <div class="content-display">
                                    <div class="detail-label">نص السؤال</div>
                                    <div class="content-text">{{ $section->sectionable->question }}</div>
                                </div>
                            @endif

                            <div class="content-display">
                                <div class="detail-label">الخيارات المتاحة</div>
                                <div class="choices-grid">
                                    <div class="choice-item {{ $section->sectionable->right_answer == 1 ? 'correct' : '' }}">
                                        <div class="choice-label">الخيار الأول</div>
                                        <div class="choice-text">{{ $section->sectionable->choice1 }}</div>
                                    </div>
                                    <div class="choice-item {{ $section->sectionable->right_answer == 2 ? 'correct' : '' }}">
                                        <div class="choice-label">الخيار الثاني</div>
                                        <div class="choice-text">{{ $section->sectionable->choice2 }}</div>
                                    </div>
                                    <div class="choice-item {{ $section->sectionable->right_answer == 3 ? 'correct' : '' }}">
                                        <div class="choice-label">الخيار الثالث</div>
                                        <div class="choice-text">{{ $section->sectionable->choice3 }}</div>
                                    </div>
                                    <div class="choice-item {{ $section->sectionable->right_answer == 4 ? 'correct' : '' }}">
                                        <div class="choice-label">الخيار الرابع</div>
                                        <div class="choice-text">{{ $section->sectionable->choice4 }}</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        @break

                    @case('link')
                        <!-- Link Details -->
                        <div class="detail-section">
                            <div class="detail-header">
                                <div class="detail-icon">
                                    <i class="fa fa-link"></i>
                                </div>
                                <h3 class="detail-title">تفاصيل الرابط</h3>
                            </div>

                            <div class="detail-grid">
                                <div class="detail-item">
                                    <div class="detail-label">العنوان</div>
                                    <div class="detail-value">{{ $section->sectionable->title_ar ?? 'غير محدد' }}</div>
                                </div>
                                {{-- <div class="detail-item">
                                    <div class="detail-label">العنوان بالإنجليزية</div>
                                    <div class="detail-value">{{ $section->sectionable->title_en ?? 'غير محدد' }}</div>
                                </div> --}}
                                <div class="detail-item">
                                    <div class="detail-label">نوع الرابط</div>
                                    <div class="detail-value">{{ $section->sectionable->type ?? 'غير محدد' }}</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">الرابط</div>
                                    <a href="{{ $section->sectionable->link }}" target="_blank" class="detail-value url">
                                        فتح الرابط
                                        <i class="fa fa-link ml-1"></i>
                                    </a>
                                </div>
                            </div>

                            @if($section->sectionable->description_ar)
                                <div class="content-display">
                                    <div class="detail-label">الوصف</div>
                                    <div class="content-text">{{ $section->sectionable->description_ar }}</div>
                                </div>
                            @endif

                            {{-- @if($section->sectionable->description_en)
                                <div class="content-display">
                                    <div class="detail-label">الوصف بالإنجليزية</div>
                                    <div class="content-text">{{ $section->sectionable->description_en }}</div>
                                </div>
                            @endif --}}

                            <a href="{{ $section->sectionable->link }}" target="_blank" class="related-content-link">
                                <i class="fa fa-link ml-1"></i>
                                زيارة الرابط الخارجي
                            </a>
                        </div>
                        @break

                    @case('itemlist')
                        <!-- Quiz Details -->
                        <div class="detail-section">
                            <div class="detail-header">
                                <div class="detail-icon">
                                    <i class="fa fa-list-ul"></i>
                                </div>
                                <h3 class="detail-title">تفاصيل الاختبار</h3>
                            </div>

                            <div class="detail-grid">
                                <div class="detail-item">
                                    <div class="detail-label">عنوان الاختبار</div>
                                    <div class="detail-value">{{ $section->sectionable->title ?? 'غير محدد' }}</div>
                                </div>
                                @if($section->sectionable->questions)
                                    <div class="detail-item">
                                        <div class="detail-label">عدد الأسئلة</div>
                                        <div class="detail-value">{{ $section->sectionable->questions->count() }}</div>
                                    </div>
                                @endif
                                <div class="detail-item">
                                    <div class="detail-label">مدة الاختبار</div>
                                    <div class="detail-value">
                                        <i class="fa fa-clock ml-1 quiz-duration-icon"></i>
                                        {{ $section->sectionable->time_minutes ?? 'غير محدد' }} دقيقة
                                    </div>
                                </div>
                            </div>

                            @if($section->sectionable->description ?? null)
                                <div class="content-display">
                                    <div class="detail-label">الوصف</div>
                                    <div class="content-text">{{ $section->sectionable->description }}</div>
                                </div>
                            @endif

                            @if($section->sectionable->questions && $section->sectionable->questions->count() > 0)
                                <div class="detail-section mt-4">
                                    <div class="detail-header">
                                        <div class="detail-icon">
                                            <i class="fa fa-question-circle"></i>
                                        </div>
                                        <h3 class="detail-title">الأسئلة</h3>
                                    </div>

                                    @foreach($section->sectionable->questions as $index => $question)
                                        <div class="question-card section-question-card mb-3">
                                            <h5 class="question-card-title mb-3">
                                                <i class="fa fa-question-circle ml-1"></i>
                                                السؤال {{ $index + 1 }}
                                            </h5>

                                            <div class="detail-item mb-3">
                                                <div class="detail-label">نص السؤال</div>
                                                <div class="detail-value">{{ $question->question }}</div>
                                            </div>

                                            <div class="row">
                                                <div class="col-md-6 mb-2">
                                                    <div class="choice-item {{ $question->right_answer == 1 ? 'correct-choice' : '' }}">
                                                        <strong>الخيار 1:</strong> {{ $question->choice1 }}
                                                        @if($question->right_answer == 1)
                                                            <i class="fa fa-check-circle text-success ml-1"></i>
                                                        @endif
                                                    </div>
                                                </div>
                                                <div class="col-md-6 mb-2">
                                                    <div class="choice-item {{ $question->right_answer == 2 ? 'correct-choice' : '' }}">
                                                        <strong>الخيار 2:</strong> {{ $question->choice2 }}
                                                        @if($question->right_answer == 2)
                                                            <i class="fa fa-check-circle text-success ml-1"></i>
                                                        @endif
                                                    </div>
                                                </div>
                                                <div class="col-md-6 mb-2">
                                                    <div class="choice-item {{ $question->right_answer == 3 ? 'correct-choice' : '' }}">
                                                        <strong>الخيار 3:</strong> {{ $question->choice3 }}
                                                        @if($question->right_answer == 3)
                                                            <i class="fa fa-check-circle text-success ml-1"></i>
                                                        @endif
                                                    </div>
                                                </div>
                                                <div class="col-md-6 mb-2">
                                                    <div class="choice-item {{ $question->right_answer == 4 ? 'correct-choice' : '' }}">
                                                        <strong>الخيار 4:</strong> {{ $question->choice4 }}
                                                        @if($question->right_answer == 4)
                                                            <i class="fa fa-check-circle text-success ml-1"></i>
                                                        @endif
                                                    </div>
                                                </div>
                                                @if($question->choice5)
                                                    <div class="col-md-6 mb-2">
                                                        <div class="choice-item {{ $question->right_answer == 5 ? 'correct-choice' : '' }}">
                                                            <strong>الخيار 5:</strong> {{ $question->choice5 }}
                                                            @if($question->right_answer == 5)
                                                                <i class="fa fa-check-circle text-success ml-1"></i>
                                                            @endif
                                                        </div>
                                                    </div>
                                                @endif
                                                @if($question->choice6)
                                                    <div class="col-md-6 mb-2">
                                                        <div class="choice-item {{ $question->right_answer == 6 ? 'correct-choice' : '' }}">
                                                            <strong>الخيار 6:</strong> {{ $question->choice6 }}
                                                            @if($question->right_answer == 6)
                                                                <i class="fa fa-check-circle text-success ml-1"></i>
                                                            @endif
                                                        </div>
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                        @break

                    @case('course')
                        <!-- Course Details -->
                        <div class="detail-section">
                            <div class="detail-header">
                                <div class="detail-icon">
                                    <i class="fa fa-book"></i>
                                </div>
                                <h3 class="detail-title">تفاصيل الكورس الفرعي</h3>
                            </div>

                            <div class="detail-grid">
                                <div class="detail-item">
                                    <div class="detail-label">اسم الكورس</div>
                                    <div class="detail-value">{{ $section->sectionable->name_ar ?? 'غير محدد' }}</div>
                                </div>
                                {{-- <div class="detail-item">
                                    <div class="detail-label">اسم الكورس بالإنجليزية</div>
                                    <div class="detail-value">{{ $section->sectionable->name_en ?? 'غير محدد' }}</div>
                                </div> --}}
                                @if($section->sectionable->grade)
                                    <div class="detail-item">
                                        <div class="detail-label">المرحلة الدراسية</div>
                                        <div class="detail-value">{{ $section->sectionable->grade->name_ar }}</div>
                                    </div>
                                @endif

                                <div class="detail-item">
                                    <div class="detail-label">عدد الأقسام</div>
                                    <div class="detail-value">{{ $section->sectionable->sections()->count() }} قسم</div>
                                </div>
                            </div>

                            @if($section->sectionable->description_ar)
                                <div class="content-display">
                                    <div class="detail-label">الوصف</div>
                                    <div class="content-text">{{ $section->sectionable->description_ar }}</div>
                                </div>
                            @endif

                            {{-- @if($section->sectionable->description_en)
                                <div class="content-display">
                                    <div class="detail-label">الوصف بالإنجليزية</div>
                                    <div class="content-text">{{ $section->sectionable->description_en }}</div>
                                </div>
                            @endif --}}

                            <a href="{{ route('admin.courses.show', $section->sectionable) }}" class="related-content-link">
                                <i class="fa fa-eye ml-1"></i>
                                عرض تفاصيل الكورس
                            </a>
                        </div>
                        @break

                    @default
                        <!-- Generic Content -->
                        <div class="detail-section">
                            <div class="detail-header">
                                <div class="detail-icon">
                                    <i class="fa fa-file"></i>
                                </div>
                                <h3 class="detail-title">تفاصيل المحتوى</h3>
                            </div>
                            <p class="text-muted">نوع محتوى غير معروف: {{ $sectionType }}</p>
                        </div>
                @endswitch
            {{-- @else
                <div class="detail-section">
                    <div class="alert alert-warning">
                        <i class="fa fa-exclamation-triangle ml-1"></i>
                        لا يوجد محتوى مرتبط بهذا القسم
                    </div>
                </div>
            @endif --}}

            <!-- Timestamps Section -->
            <div class="timestamps-section">
                <div class="detail-header detail-header--compact">
                    <div class="detail-icon">
                        <i class="fa fa-clock-o"></i>
                    </div>
                    <h3 class="detail-title">معلومات التوقيت</h3>
                </div>
                <div class="timestamps-grid">
                    <div class="timestamp-item">
                        <div class="timestamp-label">تاريخ الإنشاء</div>
                        <div class="timestamp-value">{{ $section->created_at->format('Y-m-d H:i') }}</div>
                    </div>
                    <div class="timestamp-item">
                        <div class="timestamp-label">آخر تحديث</div>
                        <div class="timestamp-value">{{ $section->updated_at->format('Y-m-d H:i') }}</div>
                    </div>
                    <div class="timestamp-item">
                        <div class="timestamp-label">منذ</div>
                        <div class="timestamp-value">{{ $section->updated_at->diffForHumans() }}</div>
                    </div>
                </div>
            </div>

            <!-- Actions Bar -->
            <div class="section-actions-bar">
                <div class="action-buttons">
                    <a href="{{ route('admin.courses.sections.index', $course) }}" class="btn-modern btn-back">
                        <i class="fa fa-arrow-left"></i>
                        العودة للأقسام
                    </a>
                </div>
                <div class="action-buttons">
                    <a href="{{ route('admin.courses.sections.edit', [$course, $section]) }}" class="btn-modern btn-edit">
                        <i class="fa fa-edit"></i>
                        تعديل القسم
                    </a>
                    <form action="{{ route('admin.courses.sections.destroy', [$course, $section]) }}" method="POST" class="course-section-inline-form">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="authoring_version" value="{{ $course->authoring_version }}">
                        <button type="submit" class="btn-modern btn-delete" onclick="return confirm('هل أنت متأكد من حذف هذا القسم؟')">
                            <i class="fa fa-trash"></i>
                            حذف القسم
                        </button>
                    </form>
                </div>
            </div>

        </div>
    </div>
</div>

@endsection
