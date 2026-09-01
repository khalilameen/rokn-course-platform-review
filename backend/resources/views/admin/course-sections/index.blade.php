@extends('admin.layouts.app')

@section('page.title', 'إدارة محتوى الكورس')

@section('styles')
{{-- Include Dynamic Theme Styles --}}
@include('admin.course-sections.partials._dynamic_styles')
<link rel="stylesheet" href="{{ asset('admin/assets/css/course-sections-index.css') }}">
@endsection

@section('content')

<div class="admin-page fade-in">
    <!-- Header -->
    <div class="sections-management-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1 class="h3 mb-0">إدارة محتوى الكورس</h1>
                <p class="mb-0 opacity-75">تنظيم الوحدات والدروس والمشاريع</p>
            </div>
            <div>
                @if($course->is_coming_soon)<a href="{{ route('admin.courses.modules.create', $course) }}" class="btn-modern">
                    <i class="fa fa-folder-plus"></i>
                    إضافة وحدة جديدة
                </a>@endif
            </div>
        </div>

        <div class="course-info-banner">
            <div class="course-meta">
                <div class="course-meta-item">
                    <i class="fa fa-book ml-1"></i> {{ $course->name_ar }}
                </div>
                <div class="course-meta-item">
                    <i class="fa fa-layer-group ml-1"></i> {{ $modules->count() }} وحدات
                </div>
            </div>
            <div>
                <a href="{{ route('admin.courses.show', $course) }}" class="btn-modern btn-modern-outline">
                    <i class="fa fa-eye"></i> عرض الكورس
                </a>
                <a href="{{ route('admin.courses.index') }}" class="btn-modern btn-modern-outline">
                    <i class="fa fa-arrow-left"></i> عودة
                </a>
            </div>
        </div>
    </div>

    <div class="modules-container">
        <!-- Modules List (Sortable) -->
        <div id="modules-list">
            @foreach($modules as $module)
                <div class="module-card" data-module-id="{{ $module->id }}">
                    <div class="module-header">
                        <div class="module-title">
                            <i class="fa fa-grip-vertical module-drag-handle"></i>
                            {{ $module->title_ar ?? $module->title_en ?? $module->title }}
                            <span class="badge badge-light ml-2">{{ $module->sections->count() }} أقسام</span>
                        </div>
                        <div class="module-actions">
                            @if($course->is_coming_soon)<a href="{{ route('admin.courses.sections.create', [$course, 'module_id' => $module->id]) }}" class="btn btn-sm btn-primary">
                                <i class="fa fa-plus"></i> إضافة قسم
                            </a>@endif
                            <a href="{{ route('admin.courses.modules.edit', [$course, $module]) }}" class="btn btn-sm btn-info text-white">
                                <i class="fa fa-edit"></i>
                            </a>
                            <form action="{{ route('admin.courses.modules.destroy', [$course, $module]) }}" method="POST" class="course-section-inline-form" onsubmit="return confirm('هل أنت متأكد من حذف الوحدة؟ سيتم حذف الأقسام المرتبطة بها أو فك ارتباطها.');">
                                @csrf
                                @method('DELETE')
                                <input type="hidden" name="authoring_version" value="{{ $course->authoring_version }}">
                                <button type="submit" class="btn btn-sm btn-danger">
                                    <i class="fa fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="sections-list sortable-sections" data-module-id="{{ $module->id }}">
                        @foreach($module->sections as $section)
                            @include('admin.course-sections.partials.section-card', ['section' => $section])
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        <!-- Ungrouped Sections -->
        @if($ungroupedSections->count() > 0)
            <div class="ungrouped-sections-container">
                <h4 class="text-muted mb-3"><i class="fa fa-cubes"></i> أقسام عامة (غير مرتبطة بوحدة)</h4>
                <div class="sections-list sortable-sections" data-module-id="">
                    @foreach($ungroupedSections as $section)
                        @include('admin.course-sections.partials.section-card', ['section' => $section])
                    @endforeach
                </div>
            </div>
        @elseif($modules->count() == 0)
            <div class="text-center py-5">
                <i class="fa fa-folder-open text-muted fa-3x mb-3"></i>
                <h3 class="text-muted">لا توجد وحدات أو أقسام</h3>
                <p class="text-muted">ابدأ بإضافة وحدة دراسية لتنظيم الكورس</p>
                <a href="{{ route('admin.courses.modules.create', $course) }}" class="btn btn-primary mt-2">
                    <i class="fa fa-plus"></i> إضافة وحدة أولى
                </a>
            </div>
        @endif
        
    </div>
</div>

<!-- Sortable JS -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js" integrity="sha384-eeLEhtwdMwD3X9y+8P3Cn7Idl/M+w8H4uZqkgD/2eJVkWIN1yKzEj6XegJ9dL3q0" crossorigin="anonymous"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    let authoringVersion = Number(@json((int) $course->authoring_version));
    const csrf = @json(csrf_token());
    const saveOrder = (key, url, payload, successMessage) =>
        window.RoknAdminRequest.serializeMutation(key, async () => {
            try {
                const data = await window.RoknAdminRequest.request(url, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
                    body: JSON.stringify({...payload, authoring_version: authoringVersion}),
                });
                authoringVersion = Number(data.authoring_version || authoringVersion);
                document.querySelectorAll('[name="authoring_version"]').forEach(input => input.value = authoringVersion);
                showNotification(successMessage, 'success');
            } catch (error) {
                if (error.code === 'cancelled') return;
                showNotification(error.message || 'تعذّر حفظ الترتيب', 'error');
                setTimeout(() => location.reload(), 1200);
            }
        });
    
    // 1. Modules Sorting
    const modulesList = document.getElementById('modules-list');
    if (modulesList) {
        new Sortable(modulesList, {
            handle: '.module-drag-handle',
            animation: 150,
            ghostClass: 'dragging',
            onEnd: function() {
                const modules = [];
                modulesList.querySelectorAll('.module-card').forEach((card, index) => {
                    modules.push({
                        id: card.dataset.moduleId,
                        order: index + 1
                    });
                });

                void saveOrder(
                    'course-outline-order',
                    '{{ route("admin.courses.modules.reorder", $course) }}',
                    {modules},
                    'تم ترتيب الوحدات بنجاح'
                );
            }
        });
    }

    // 2. Sections Sorting (Nested & Shared)
    const sectionLists = document.querySelectorAll('.sortable-sections');
    sectionLists.forEach(list => {
        new Sortable(list, {
            group: 'shared-sections', // Allow moving between lists
            handle: '.drag-handle',
            animation: 150,
            ghostClass: 'dragging',
            onEnd: function(evt) {
                const newContainer = evt.to;
                const moduleId = newContainer.dataset.moduleId || null; // "" -> null in Laravel validation? No, nullable means null. "" is string. But in JSON it will be "" or null.
                // We should ensure we send null if empty string.
                
                const sections = [];
                newContainer.querySelectorAll('.section-card').forEach((card, index) => {
                    sections.push({
                        id: card.dataset.sectionId,
                        order: index + 1,
                        module_id: moduleId ? moduleId : null
                    });
                });

                void saveOrder(
                    'course-outline-order',
                    '{{ route("admin.courses.sections.reorder", $course) }}',
                    {sections},
                    'تم تحديث الأقسام بنجاح'
                );
            }
        });
    });

    function showNotification(msg, type) {
        // Simple alert or toast implementation
        // For now relying on existing one if available or creating simple div
        const div = document.createElement('div');
        div.textContent = msg;
        div.style.cssText = `position: fixed; top: 20px; right: 20px; padding: 15px; background: ${type=='success'?'#48bb78':'#f56565'}; color: white; border-radius: 8px; z-index: 9999;`;
        document.body.appendChild(div);
        setTimeout(() => div.remove(), 3000);
    }
});
</script>
@endsection
