        <!-- Sections Tab -->
        <div id="sections" class="tab-content">
            @if($sections->count() > 0)
                <div class="sections-container">
                    @foreach($sections as $index => $section)
                        <div class="section-item">
                            <div class="section-header">
                                <div class="section-info">
                                    <div class="section-name">
                                        @switch($section->sectionable_type)
                                            @case('App\Models\Lesson')
                                                <div class="section-type-icon icon-lesson">
                                                    <i class="fa fa-play-circle"></i>
                                                </div>
                                                @break
                                            @case('App\Models\Question')
                                                <div class="section-type-icon icon-question">
                                                    <i class="fa fa-question-circle"></i>
                                                </div>
                                                @break
                                            @case('App\Models\Link')
                                                <div class="section-type-icon icon-link">
                                                    <i class="fa fa-link"></i>
                                                </div>
                                                @break
                                            @case('App\Models\ItemList')
                                                <div class="section-type-icon icon-quiz">
                                                    <i class="fa fa-question"></i>
                                                </div>
                                                @break
                                            @case('App\Models\Course')
                                                <div class="section-type-icon icon-course">
                                                    <i class="fa fa-book"></i>
                                                </div>
                                                @break
                                            @default
                                                <div class="section-type-icon icon-default">
                                                    <i class="fa fa-file"></i>
                                                </div>
                                        @endswitch

                                        <span>{{ $section->title }}</span>
                                    </div>

                                    <div class="section-meta">
                                        <span>الترتيب: {{ $section->order ?? ($index + 1) }}</span>
                                        <span> • </span>
                                        <span>النوع: {{ $section->getSectionType() }}</span>
                                        {{-- @if($section->sectionable)
                                            <span> • </span>
                                            <span>المحتوى: {{ $section->sectionable->title ?? $section->sectionable->name ?? 'بدون عنوان' }}</span>
                                        @endif --}}
                                    </div>
                                </div>

                                <div class="section-actions">
                                    <a href="{{ route('admin.courses.sections.show', [$course, $section]) }}" class="btn-section btn-card btn-view">
                                        <i class="fa fa-eye"></i>
                                        عرض
                                    </a>
                                    <a href="{{ route('admin.courses.sections.edit', [$course, $section]) }}" class="btn-section btn-section-secondary">
                                        <i class="fa fa-edit"></i>
                                        تعديل
                                    </a>
                                    <button onclick="deleteSection({{ $section->id }})" class="btn-section btn-section-danger">
                                        <i class="fa fa-trash"></i>
                                        حذف
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Hidden Delete Form -->
                        <form class="course-section-delete-form" id="deleteSectionForm{{ $section->id }}" action="{{ route('admin.courses.sections.destroy', [$course, $section]) }}" method="post">
                            <input name="_method" type="hidden" value="DELETE">
                            @csrf
                            <input type="hidden" name="authoring_version" value="{{ $course->authoring_version }}">
                        </form>
                    @endforeach
                </div>
            @else
                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="fa fa-list"></i>
                    </div>
                    <h3 class="empty-title">لا توجد أقسام</h3>
                    <p class="empty-description">لم يتم إضافة أي أقسام لهذا الكورس بعد. ابدأ بإضافة المحتوى لتنظيم الكورس.</p>
                    <a href="{{ route('admin.courses.sections.create', $course) }}" class="btn-action btn-action--add-section">
                        <i class="fa fa-plus"></i>
                        إضافة قسم جديد
                    </a>
                </div>
            @endif
        </div>
