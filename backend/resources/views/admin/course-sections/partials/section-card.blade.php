<div class="section-card" data-section-id="{{ $section->id }}">
    <div class="drag-handle">
        <i class="fa fa-grip-vertical"></i>
    </div>
    
    <div class="section-type-icon {{ 
        $section->isProject() ? 'icon-project' : 
        ($section->isLesson() ? 'icon-lesson' : 
        ($section->getSectionType() == 'quiz' ? 'icon-quiz' : 
        ($section->getSectionType() == 'link' ? 'icon-link' : 'icon-other'))) 
    }}">
        <i class="fa 
            {{ $section->isProject() ? 'fa-project-diagram' : 
               ($section->isLesson() ? 'fa-play-circle' : 
               ($section->getSectionType() == 'quiz' ? 'fa-clipboard-list' : 
               ($section->getSectionType() == 'link' ? 'fa-link' : 'fa-file'))) 
            }}">
        </i>
    </div>

    <div class="section-info">
        <div class="section-title">
            {{ $section->title_ar ?? $section->title_en ?? $section->title }}
            @if($section->isProject())
                <span class="badge badge-warning ml-2">مشروع</span>
                @if($section->sectionable && $section->sectionable->is_graduation_project)
                    <span class="badge badge-success">تخرج</span>
                @endif
            @endif
        </div>
        <div class="section-meta">
            <span>{{ match($section->getSectionType()) {
                'lesson' => 'مقطع فيديو',
                'quiz' => 'اختبار الوحدة',
                'project' => 'مشروع عبور',
                default => 'محتوى',
            } }}</span>
            @if(!$section->isProject() && $section->sectionable)
                 <span> {{ Str::limit($section->sectionable->title ?? '', 30) }}</span>
            @endif
        </div>
    </div>

    <div class="section-actions">
        <a href="{{ route('admin.courses.sections.edit', [$course, $section]) }}" class="btn btn-xs btn-outline-primary">
            <i class="fa fa-edit"></i>
        </a>
        <form action="{{ route('admin.courses.sections.destroy', [$course, $section]) }}" method="POST" onsubmit="return confirm('حذف؟')">
            @csrf @method('DELETE')
            <input type="hidden" name="authoring_version" value="{{ $course->authoring_version }}">
            <button class="btn btn-xs btn-outline-danger"><i class="fa fa-trash"></i></button>
        </form>
    </div>
</div>
