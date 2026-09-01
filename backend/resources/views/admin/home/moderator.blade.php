@extends('admin.layouts.app')

@section('page.title', 'مساحة المحتوى')

@section('styles')
<link rel="stylesheet" href="{{ asset('admin/assets/css/moderator-workspace.css') }}">
@endsection

@section('content')
<div class="admin-page moderator-workspace">
    <header class="moderator-workspace__hero">
        <div>
            <span class="moderator-workspace__eyebrow">مساحة فريق المحتوى</span>
            <h1>ابنِ الكورس كما سيراه الطالب</h1>
            <p>افتح أي كورس، عدّل بياناته، ونظّم وحداته ودروسه من استوديو واحد.</p>
        </div>
        <a href="{{ route('admin.courses.create') }}" class="moderator-workspace__create">
            <i class="fa fa-plus" aria-hidden="true"></i>
            إنشاء كورس
        </a>
    </header>

    <section class="moderator-workspace__summary" aria-label="ملخص المحتوى">
        <div><strong>{{ number_format($contentSummary['courses']) }}</strong><span>كورس</span></div>
        <div><strong>{{ number_format($contentSummary['modules']) }}</strong><span>وحدة</span></div>
        <div><strong>{{ number_format($contentSummary['sections']) }}</strong><span>درس ومشروع</span></div>
        <div><strong>{{ number_format($contentSummary['published']) }}</strong><span>منشور</span></div>
    </section>

    <div class="moderator-workspace__section-heading">
        <div>
            <h2>الكورسات</h2>
            <p>مرتبة حسب آخر تعديل، مثل قائمة الفيديوهات في استوديو المحتوى.</p>
        </div>
        <a href="{{ route('admin.courses.index') }}">عرض القائمة الكاملة</a>
    </div>

    @if($courses->isEmpty())
        <section class="moderator-workspace__empty">
            <i class="fa fa-play-circle-o" aria-hidden="true"></i>
            <h2>ابدأ بأول كورس</h2>
            <p>أضف البيانات الأساسية أولًا، ثم ابنِ الوحدات والدروس من الاستوديو.</p>
            <a href="{{ route('admin.courses.create') }}">إنشاء أول كورس</a>
        </section>
    @else
        <section class="moderator-course-list" aria-label="قائمة الكورسات">
            @foreach($courses as $course)
                @php
                    $audit = $publishingAudits->get($course->id);
                    $courseTitle = trim((string) ($course->name_ar ?: $course->name_en)) ?: 'كورس بلا عنوان';
                    $courseDescription = trim((string) ($course->description_ar ?: $course->description_en)) ?: 'أضف وصفًا واضحًا للكورس ليظهر للطالب.';
                @endphp
                <article class="moderator-course-row">
                    <a class="moderator-course-row__cover" href="{{ route('admin.courses.show', $course) }}" aria-label="فتح استوديو {{ $courseTitle }}">
                        @if($course->image)
                            <img src="{{ $course->image }}" alt="">
                        @else
                            <span><i class="fa fa-book" aria-hidden="true"></i></span>
                        @endif
                    </a>
                    <div class="moderator-course-row__body">
                        <div class="moderator-course-row__title-line">
                            <h3><a href="{{ route('admin.courses.show', $course) }}">{{ $courseTitle }}</a></h3>
                            @if(!$course->is_coming_soon)
                                <span class="moderator-status moderator-status--published">منشور</span>
                            @elseif($audit && $audit['ready'])
                                <span class="moderator-status moderator-status--ready">البيانات مكتملة</span>
                            @else
                                <span class="moderator-status moderator-status--draft">مسودة</span>
                            @endif
                        </div>
                        <p>{{ Illuminate\Support\Str::limit($courseDescription, 120) }}</p>
                        <div class="moderator-course-row__meta">
                            <span><i class="fa fa-list-alt" aria-hidden="true"></i> {{ $course->modules_count }} وحدات</span>
                            <span><i class="fa fa-play-circle" aria-hidden="true"></i> {{ $course->sections_count }} عناصر</span>
                            <span><i class="fa fa-clock-o" aria-hidden="true"></i> عُدّل {{ \App\Support\BusinessClock::relative($course->updated_at) }}</span>
                        </div>
                        @if($course->is_coming_soon && $audit && !$audit['ready'])
                            <div class="moderator-course-row__readiness">
                                <i class="fa fa-info-circle" aria-hidden="true"></i>
                                {{ count($audit['issues']) }} عناصر مطلوبة في البيانات الأساسية
                            </div>
                        @endif
                    </div>
                    <div class="moderator-course-row__actions">
                        <a class="moderator-course-row__primary" href="{{ route('admin.courses.show', $course) }}">
                            فتح الاستوديو
                        </a>
                        <a href="{{ route('admin.courses.edit', $course) }}">الإعدادات</a>
                    </div>
                </article>
            @endforeach
        </section>
        @if($courses->hasPages())
            <nav class="moderator-workspace__pagination" aria-label="صفحات الكورسات">{{ $courses->links() }}</nav>
        @endif
    @endif
</div>
@endsection
