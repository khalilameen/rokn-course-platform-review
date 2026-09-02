@extends('admin.layouts.app')

@section('page.title', 'معاينة الطالب')

@section('styles')
<style>
    .learner-preview{--ink:#101828;--muted:#667085;--line:#e4e7ec;--blue:#155eef;--soft:#f7f9fc;direction:rtl;max-width:1180px;margin:0 auto;padding:24px 18px 56px;color:var(--ink)}
    .learner-preview *{box-sizing:border-box}.learner-preview a{text-decoration:none}
    .learner-preview__bar{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:18px}
    .learner-preview__bar h1{font-size:24px;margin:0 0 5px}.learner-preview__bar p{color:var(--muted);margin:0;font-size:14px}
    .learner-preview__back,.learner-preview__device{display:inline-flex;align-items:center;gap:8px;border:1px solid var(--line);border-radius:11px;padding:10px 14px;color:var(--ink);background:#fff;font-weight:700}
    .learner-preview__device{background:var(--ink);color:#fff;border-color:var(--ink)}.learner-preview__device.is-disabled{background:#eaecf0;color:#98a2b3;border-color:#eaecf0;pointer-events:none}
    .learner-preview__notice{display:flex;align-items:center;gap:12px;border:1px solid #b2ccff;background:#eff4ff;border-radius:14px;padding:13px 16px;margin-bottom:18px;font-size:14px}
    .learner-preview__notice.is-draft{border-color:#fedf89;background:#fffaeb}.learner-preview__notice i{font-size:18px}
    .learner-preview__plans{display:flex;gap:10px;overflow-x:auto;padding:2px 1px 14px;scrollbar-width:thin}.learner-preview__plan{flex:0 0 180px;border:1px solid var(--line);background:#fff;border-radius:14px;padding:13px;color:var(--ink)}
    .learner-preview__plan strong,.learner-preview__plan span{display:block}.learner-preview__plan span{color:var(--muted);font-size:13px;margin-top:4px}.learner-preview__plan.is-active{border-color:var(--blue);box-shadow:0 0 0 2px #dbe7ff;background:#f8faff}
    .learner-preview__shell{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:18px;align-items:start}
    .learner-preview__phone,.learner-preview__side{background:#fff;border:1px solid var(--line);border-radius:20px;overflow:hidden}.learner-preview__cover{aspect-ratio:16/7;background:#101828;position:relative;overflow:hidden}.learner-preview__cover img{width:100%;height:100%;object-fit:cover}.learner-preview__cover-placeholder{height:100%;display:grid;place-items:center;color:#98a2b3;font-size:34px}
    .learner-preview__course{padding:20px}.learner-preview__course h2{font-size:25px;line-height:1.35;margin:0 0 8px}.learner-preview__course>p{color:var(--muted);line-height:1.7;margin:0 0 15px;white-space:pre-line}
    .learner-preview__facts,.learner-preview__features{display:flex;flex-wrap:wrap;gap:8px}.learner-preview__chip{display:inline-flex;align-items:center;gap:6px;background:var(--soft);border:1px solid #eef1f5;border-radius:999px;padding:7px 10px;font-size:13px}.learner-preview__chip.is-off{color:#98a2b3;text-decoration:line-through}
    .learner-preview__side{position:sticky;top:16px;padding:18px}.learner-preview__side h3{font-size:17px;margin:0 0 13px}.learner-preview__side-section+.learner-preview__side-section{border-top:1px solid var(--line);margin-top:18px;padding-top:18px}
    .learner-preview__prompt{border-radius:13px;background:#f2f4f7;padding:13px}.learner-preview__prompt strong,.learner-preview__prompt span{display:block}.learner-preview__prompt span{font-size:13px;color:var(--muted);line-height:1.55;margin-top:5px}
    .learner-preview__certificate-text{margin:9px 0 0;color:var(--muted);line-height:1.65}.learner-preview__certificate-text strong{display:block;color:var(--ink);font-weight:700}
    .learner-preview__outline{margin-top:18px}.learner-preview__outline>h2{font-size:20px;margin:0 0 12px}.learner-preview__module{border:1px solid var(--line);border-radius:16px;overflow:hidden;margin-bottom:12px}.learner-preview__module-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 16px;background:#f9fafb}.learner-preview__module-head h3{font-size:16px;margin:0}.learner-preview__module-head span{color:var(--muted);font-size:12px}
    .learner-preview__step{display:grid;grid-template-columns:38px minmax(0,1fr) auto;gap:10px;align-items:start;padding:13px 16px;border-top:1px solid #f0f2f5}.learner-preview__step-icon{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;background:#eff4ff;color:var(--blue)}.learner-preview__step.is-locked .learner-preview__step-icon{background:#f2f4f7;color:#98a2b3}.learner-preview__step h4{font-size:14px;margin:1px 0 3px}.learner-preview__step p{font-size:12px;color:var(--muted);margin:0;line-height:1.5}.learner-preview__step-state{font-size:12px;color:var(--muted);white-space:nowrap;padding-top:7px}
    .learner-preview__project{margin-top:8px;border-right:2px solid #b2ccff;padding-right:9px}.learner-preview__empty{text-align:center;border:1px dashed #d0d5dd;border-radius:16px;padding:28px;color:var(--muted)}
    @media(max-width:900px){.learner-preview__shell{grid-template-columns:1fr}.learner-preview__side{position:static;order:-1}.learner-preview__bar{align-items:flex-start;flex-direction:column}.learner-preview__bar-actions{display:flex;gap:8px;width:100%;overflow-x:auto}.learner-preview__course h2{font-size:22px}}
    @media(max-width:520px){.learner-preview{padding:14px 10px 40px}.learner-preview__phone,.learner-preview__side{border-radius:15px}.learner-preview__course{padding:16px}.learner-preview__cover{aspect-ratio:16/9}.learner-preview__step{grid-template-columns:34px minmax(0,1fr)}.learner-preview__step-state{grid-column:2}}
</style>
@endsection

@section('content')
@php
    $modules = collect($previewPayload['modules'] ?? []);
    $allSections = collect($previewPayload['sections'] ?? []);
    $metadata = is_array($previewPayload['metadata'] ?? null) ? $previewPayload['metadata'] : [];
    $moduleSectionIds = $modules->flatMap(fn ($module) => collect($module['sections'] ?? [])->pluck('id'));
    $ungrouped = $allSections->reject(fn ($section) => $moduleSectionIds->contains($section['id'] ?? null));
    $attachmentCount = $modules->flatMap(function ($module) {
        $moduleId = (string) ($module['id'] ?? '');
        $moduleAttachments = collect($module['attachments'] ?? [])
            ->map(fn ($attachment) => 'file:'.(string) ($attachment['id'] ?? ''));
        $sectionAttachments = collect($module['sections'] ?? [])->flatMap(
            fn ($section) => collect($section['attachments'] ?? [])
                ->map(fn ($attachment) => 'file:'.(string) ($attachment['id'] ?? ''))
        );
        if (!empty($module['attachments_link'])) {
            $moduleAttachments->push('link:'.$moduleId);
        }

        return $moduleAttachments->concat($sectionAttachments);
    })->filter(fn ($key) => $key !== 'file:')->unique()->count();
    $published = $previewCourse->isPublishedForLearning();
    $typeLabels = ['lesson' => 'مقطع', 'project' => 'مشروع عبور', 'quiz' => 'اختبار', 'question' => 'سؤال', 'link' => 'رابط', 'course' => 'كورس'];
    $typeIcons = ['lesson' => 'fa-play', 'project' => 'fa-briefcase', 'quiz' => 'fa-check-square-o', 'question' => 'fa-question', 'link' => 'fa-link', 'course' => 'fa-book'];
    $renderSection = function (array $section) use ($typeLabels, $typeIcons) {
        $type = (string) ($section['type'] ?? 'lesson');
        $content = is_array($section['content'] ?? null) ? $section['content'] : [];
        $locked = (bool) ($section['is_locked'] ?? true);
        $preview = (bool) ($section['is_preview'] ?? false);
        $duration = max(0, (int) ($content['duration_minutes'] ?? 0));
        return compact('type', 'content', 'locked', 'preview', 'duration', 'section', 'typeLabels', 'typeIcons');
    };
@endphp
<main class="learner-preview">
    <header class="learner-preview__bar">
        <div><h1>معاينة الطالب</h1><p>طالب جديد · فئة {{ $selectedPlan['name'] }}</p></div>
        <div class="learner-preview__bar-actions">
            <a class="learner-preview__back" href="{{ route('admin.courses.show', $previewCourse) }}"><i class="fa fa-arrow-right"></i> الاستوديو</a>
            <a class="learner-preview__device {{ $published ? '' : 'is-disabled' }}" href="{{ $published ? 'rokn://course/'.$previewCourse->id : '#' }}"><i class="fa fa-mobile"></i> فتح على جهاز الاختبار</a>
        </div>
    </header>

    <div class="learner-preview__notice {{ $published ? '' : 'is-draft' }}">
        <i class="fa {{ $published ? 'fa-check-circle' : 'fa-eye-slash' }}"></i>
        <span>{{ $published ? 'هذه معاينة خاصة من نفس عقد التطبيق · رابط الجهاز يفتح النسخة المنشورة الحالية' : 'المسودة ظاهرة هنا للمودريتور فقط · لن يصل إليها أي طالب ولن يعمل رابط الجهاز قبل النشر' }}</span>
    </div>

    <nav class="learner-preview__plans" aria-label="اختر فئة الطالب">
        @foreach($planOptions as $plan)
            <a class="learner-preview__plan {{ $plan['code'] === $selectedPlan['code'] ? 'is-active' : '' }}" href="{{ route('admin.courses.student-preview', [$previewCourse, 'plan' => $plan['code']]) }}">
                <strong>{{ $plan['name'] }}</strong>
                <span>{{ ($plan['code'] ?? '') === 'grant' ? 'إتاحة المنحة' : number_format((int) $plan['price_coins']).' عملة' }}</span>
            </a>
        @endforeach
    </nav>

    <div class="learner-preview__shell">
        <section class="learner-preview__phone" aria-label="تجربة الطالب داخل الكورس">
            <div class="learner-preview__cover">
                @if(!empty($previewPayload['image']))<img src="{{ $previewPayload['image'] }}" alt="غلاف {{ $previewPayload['title'] }}">
                @else<div class="learner-preview__cover-placeholder"><i class="fa fa-picture-o"></i></div>@endif
            </div>
            <div class="learner-preview__course">
                <h2>{{ $previewPayload['title'] }}</h2>
                @if(!empty($previewPayload['description']))<p>{{ $previewPayload['description'] }}</p>@endif
                <div class="learner-preview__facts">
                    @if(isset($metadata['duration_minutes']))<span class="learner-preview__chip"><i class="fa fa-clock-o"></i> {{ number_format((int) $metadata['duration_minutes']) }} دقيقة</span>@endif
                    @if(isset($metadata['students_count']))<span class="learner-preview__chip"><i class="fa fa-users"></i> {{ number_format((int) $metadata['students_count']) }} طالب</span>@endif
                    @if(!empty($previewPayload['average_rating']))<span class="learner-preview__chip"><i class="fa fa-star"></i> {{ number_format((float) $previewPayload['average_rating'], 1) }}</span>@endif
                    <span class="learner-preview__chip"><i class="fa fa-list"></i> {{ number_format($allSections->count()) }} عنصر</span>
                </div>

                <div class="learner-preview__outline">
                    <h2>خريطة الكورس</h2>
                    @forelse($modules as $module)
                        <article class="learner-preview__module">
                            <header class="learner-preview__module-head">
                                <h3>{{ $module['title'] }}</h3>
                                <span>{{ number_format(count($module['sections'] ?? [])) }} عناصر · {{ number_format((int) ($module['attachments_count'] ?? 0)) }} مرفقات</span>
                            </header>
                            @foreach(($module['sections'] ?? []) as $section)
                                @php(extract($renderSection($section)))
                                <div class="learner-preview__step {{ $locked ? 'is-locked' : '' }}">
                                    <div class="learner-preview__step-icon"><i class="fa {{ $locked ? 'fa-lock' : ($typeIcons[$type] ?? 'fa-circle-o') }}"></i></div>
                                    <div>
                                        <h4>{{ $section['title'] }}</h4>
                                        <p>{{ $typeLabels[$type] ?? $type }}@if($duration) · {{ $duration }} دقيقة@endif @if($preview) · متاح مجانًا@endif</p>
                                        @if($type === 'project' && !$locked)
                                            <div class="learner-preview__project">
                                                @if(!empty($content['requirements_text']))<p>{{ $content['requirements_text'] }}</p>@endif
                                                @php($feedback = $content['project_feedback'] ?? [])
                                                <p>{{ !empty($feedback['report_enabled']) ? 'يتلقى الطالب تقريرًا' : 'تقييم عبور' }}{{ !empty($feedback['reply_enabled']) ? ' · ويمكنه متابعة التقرير داخل الشات' : '' }}</p>
                                            </div>
                                        @endif
                                        @if(!$locked && !empty($section['attachments']))<p><i class="fa fa-paperclip"></i> {{ number_format(count($section['attachments'])) }} مرفقات قابلة للتحميل</p>@endif
                                    </div>
                                    <span class="learner-preview__step-state">{{ $locked ? 'مغلق' : 'متاح' }}</span>
                                </div>
                            @endforeach
                        </article>
                    @empty
                        @if($ungrouped->isEmpty())<div class="learner-preview__empty">لا يوجد محتوى بعد</div>@endif
                    @endforelse

                    @if($ungrouped->isNotEmpty())
                        <article class="learner-preview__module">
                            <header class="learner-preview__module-head"><h3>محتوى الكورس</h3><span>{{ number_format($ungrouped->count()) }} عناصر</span></header>
                            @foreach($ungrouped as $section)
                                @php(extract($renderSection($section)))
                                <div class="learner-preview__step {{ $locked ? 'is-locked' : '' }}">
                                    <div class="learner-preview__step-icon"><i class="fa {{ $locked ? 'fa-lock' : ($typeIcons[$type] ?? 'fa-circle-o') }}"></i></div>
                                    <div><h4>{{ $section['title'] }}</h4><p>{{ $typeLabels[$type] ?? $type }}@if($duration) · {{ $duration }} دقيقة@endif</p></div>
                                    <span class="learner-preview__step-state">{{ $locked ? 'مغلق' : 'متاح' }}</span>
                                </div>
                            @endforeach
                        </article>
                    @endif
                </div>
            </div>
        </section>

        <aside class="learner-preview__side">
            <section class="learner-preview__side-section">
                <h3>ما يحصل عليه الطالب</h3>
                <div class="learner-preview__features">
                    <span class="learner-preview__chip {{ !empty($previewPayload['chat_available']) ? '' : 'is-off' }}"><i class="fa fa-comments"></i> شات ركن</span>
                    <span class="learner-preview__chip {{ !empty($previewPayload['certificate_included']) ? '' : 'is-off' }}"><i class="fa fa-certificate"></i> الشهادة</span>
                    <span class="learner-preview__chip {{ !empty($selectedPlan['project_report_enabled']) ? '' : 'is-off' }}"><i class="fa fa-file-text-o"></i> تقرير المشروع</span>
                    <span class="learner-preview__chip {{ !empty($selectedPlan['project_thread_reply_enabled']) ? '' : 'is-off' }}"><i class="fa fa-reply"></i> متابعة التقرير</span>
                    <span class="learner-preview__chip {{ !empty($previewPayload['chat_attachments_enabled']) ? '' : 'is-off' }}"><i class="fa fa-paperclip"></i> ملفات الشات</span>
                </div>
            </section>
            <section class="learner-preview__side-section">
                <h3>مرفقات الكورس</h3>
                <p>{{ $attachmentCount ? number_format($attachmentCount).' ملفات تظهر عند فتح وحدتها' : 'لا توجد مرفقات في هذه النسخة' }}</p>
                @if(!empty($previewPayload['attachment_prompt']['enabled']))
                    <div class="learner-preview__prompt">
                        <strong>{{ $previewPayload['attachment_prompt']['title'] }}</strong>
                        <span>{{ $previewPayload['attachment_prompt']['body'] }}</span>
                        <span>تظهر بعد {{ number_format((int) $previewPayload['attachment_prompt']['at_seconds']) }} ثانية</span>
                        <span>{{ config('course_attachments.prompt.frequencies.'.$previewPayload['attachment_prompt']['frequency']) }}</span>
                    </div>
                @endif
            </section>
            <section class="learner-preview__side-section">
                <h3>حالة الشهادة</h3>
                <p>{{ !empty($previewPayload['certificate_included']) ? 'ضمن هذه الفئة · تصدر بعد إتمام المطلوب' : 'ليست ضمن هذه الفئة' }}</p>
                @if(!empty($previewPayload['certificate_included']))
                    <p class="learner-preview__certificate-text">
                        {{ $certificateTextTemplate['text'] }}
                        <strong>{{ $previewPayload['title'] }}</strong>
                    </p>
                @endif
            </section>
        </aside>
    </div>
</main>
@endsection
