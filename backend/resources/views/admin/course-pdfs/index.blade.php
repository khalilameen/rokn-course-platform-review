@extends('admin.layouts.app')

@section('page.title', 'إدارة ملفات PDF للكورس')

@section('styles')
@include('admin.course-pdfs.partials._dynamic_styles')
<link rel="stylesheet" href="{{ asset('admin/assets/css/course-pdfs-index.css') }}">
@endsection

@section('content')
<div class="admin-page pdf-wrapper">
    <!-- Header Section -->
    <div class="pdf-management-header">
        <div class="header-content">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <h1 class="mb-2">
                        <i class="fa fa-file-pdf-o me-2"></i>
                        إدارة ملفات PDF
                    </h1>
                    <p class="mb-0 opacity-75">إدارة ملفات PDF للكورس: {{ $course->name_ar ?? $course->title }}</p>
                </div>
                <a href="{{ route('admin.courses.show', $course) }}" class="btn-modern">
                    <i class="fa fa-arrow-right"></i>
                    العودة للكورس
                </a>
            </div>
            
            <div class="course-info-banner">
                <div class="course-meta">
                    <div class="course-meta-item">
                        <i class="fa fa-book"></i>
                        <span>{{ $course->name_ar ?? $course->title }}</span>
                    </div>
                    <div class="course-meta-item">
                        <i class="fa fa-file-pdf-o"></i>
                        <span>{{ $pdfs->count() }} ملفات</span>
                    </div>
                    <div class="course-meta-item">
                        <i class="fa fa-check-circle"></i>
                        <span>{{ $pdfs->where('is_active', true)->count() }} نشط</span>
                    </div>
                </div>
                <div class="pdf-actions">
                    @if($course->is_coming_soon)
                    <a href="{{ route('admin.courses.pdfs.create', $course) }}" class="btn-modern btn-success">
                        <i class="fa fa-plus"></i>
                        إضافة ملف PDF
                    </a>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- PDF List Container -->
    <div class="pdf-container">
        @if(!$course->is_coming_soon)
            <div class="alert alert-info">
                المرفقات المعروضة للطلاب ثابتة الآن
                <a href="{{ route('admin.courses.edit', [$course, 'return_to' => 'studio']) }}">حوّل الكورس إلى مسودة للتعديل ثم أعد نشره</a>
            </div>
        @endif
        <div class="pdf-header">
            <h3 class="pdf-title">
                <div class="title-icon">
                    <i class="fa fa-file-pdf-o"></i>
                </div>
                ملفات PDF
            </h3>
            <div class="pdf-stats">
                <div class="stat-item">
                    <span class="stat-number">{{ $pdfs->count() }}</span>
                    <span class="stat-label">إجمالي الملفات</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number">{{ $pdfs->where('is_active', true)->count() }}</span>
                    <span class="stat-label">ملفات نشطة</span>
                </div>
            </div>
        </div>

        <div class="pdf-grid">
            @if($pdfs->count() > 0)
                <div class="sortable-pdfs" id="sortable-pdfs">
                    @foreach($pdfs as $pdf)
                        <div class="pdf-card {{ !$pdf->is_active ? 'inactive' : '' }}" data-id="{{ $pdf->id }}">
                            <div class="pdf-content">
                                @if($course->is_coming_soon)
                                    <button type="button" class="pdf-drag-handle" aria-label="اسحب لترتيب الملف">
                                        <i class="fa fa-bars" aria-hidden="true"></i>
                                    </button>
                                @endif
                                <div class="pdf-info">
                                    <div class="pdf-file-title">
                                        <div class="pdf-icon">
                                            <i class="fa fa-file-pdf-o"></i>
                                        </div>
                                        <div>
                                            <span>{{ $pdf->title }}</span>
                                            @if($pdf->title_en)
                                                <small class="d-block text-muted">{{ $pdf->title_en }}</small>
                                            @endif
                                        </div>
                                    </div>
                                    <div class="pdf-meta">
                                        <span class="meta-badge badge-order">
                                            <i class="fa fa-sort"></i> الترتيب: {{ $pdf->order }}
                                        </span>
                                        <span class="meta-badge badge-size">
                                            <i class="fa fa-database"></i> {{ $pdf->formatted_file_size }}
                                        </span>
                                        <span class="meta-badge {{ $pdf->is_active ? 'badge-active' : 'badge-inactive' }}">
                                            <i class="fa {{ $pdf->is_active ? 'fa-check' : 'fa-times' }}"></i>
                                            {{ $pdf->is_active ? 'نشط' : 'غير نشط' }}
                                        </span>
                                    </div>
                                    @if($pdf->description)
                                        <p class="pdf-description">{{ Str::limit($pdf->description, 100) }}</p>
                                    @endif
                                    @if($pdf->original_filename)
                                        <small class="text-muted d-block mt-1">
                                            <i class="fa fa-file"></i> {{ $pdf->original_filename }}
                                        </small>
                                    @endif
                                </div>
                                <div class="pdf-actions-card">
                                    <a href="{{ route('admin.courses.pdfs.preview', [$course, $pdf]) }}" 
                                       class="btn-card btn-preview" target="_blank" title="معاينة">
                                        <i class="fa fa-eye"></i>
                                    </a>
                                    @if($course->is_coming_soon)
                                    <button class="btn-card btn-toggle {{ !$pdf->is_active ? 'inactive' : '' }}" 
                                            onclick="toggleStatus({{ $pdf->id }})" title="تبديل الحالة">
                                        <i class="fa {{ $pdf->is_active ? 'fa-toggle-on' : 'fa-toggle-off' }}"></i>
                                    </button>
                                    <a href="{{ route('admin.courses.pdfs.edit', [$course, $pdf]) }}" 
                                       class="btn-card btn-edit" title="تعديل">
                                        <i class="fa fa-edit"></i>
                                    </a>
                                    <form action="{{ route('admin.courses.pdfs.destroy', [$course, $pdf]) }}" 
                                          method="POST" class="d-inline" 
                                          onsubmit="return confirm('هل أنت متأكد من حذف هذا الملف؟')">
                                        @csrf
                                        @method('DELETE')
                                        <input type="hidden" name="authoring_version" value="{{ $course->authoring_version }}">
                                        <button type="submit" class="btn-card btn-delete" title="حذف">
                                            <i class="fa fa-trash"></i>
                                        </button>
                                    </form>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="fa fa-file-pdf-o"></i>
                    </div>
                    <h3 class="empty-title">لا توجد ملفات PDF</h3>
                    <p class="empty-description">لم يتم إضافة أي ملفات PDF لهذا الكورس بعد</p>
                    @if($course->is_coming_soon)<a href="{{ route('admin.courses.pdfs.create', $course) }}" class="btn-add-first">
                        <i class="fa fa-plus"></i>
                        إضافة أول ملف PDF
                    </a>@endif
                </div>
            @endif
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script src="{{ asset('admin/assets/js/vendor/sortablejs/Sortable.min.js') }}?v={{ filemtime(public_path('admin/assets/js/vendor/sortablejs/Sortable.min.js')) }}"></script>
<script>
let authoringVersion = Number(@json((int) $course->authoring_version));
const csrf = @json(csrf_token());
const mutatePdf = (key, url, payload) => window.RoknAdminRequest.serializeMutation(key, async () => {
    const expectedVersion = authoringVersion;
    const data = await window.RoknAdminRequest.request(url, {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
        body: JSON.stringify({...payload, authoring_version: authoringVersion}),
    });
    return {...data, authoring_version: window.RoknAdminRequest.requireAuthoringVersion(data, expectedVersion, true)};
});
// Initialize sortable
var sortableRoot = document.getElementById('sortable-pdfs');
var sortable = @json((bool) $course->is_coming_soon) && sortableRoot ? new Sortable(sortableRoot, {
    handle: '.pdf-drag-handle',
    animation: 150,
    ghostClass: 'dragging',
    onEnd: function(evt) {
        if (evt.oldIndex === evt.newIndex) return;
        var order = [];
        document.querySelectorAll('.pdf-card').forEach(function(card) {
            order.push(card.dataset.id);
        });
        
        mutatePdf('course-pdf-mutation', '{{ route('admin.courses.pdfs.reorder', $course) }}', {order})
        .then(data => {
            authoringVersion = Number(data.authoring_version);
            document.querySelectorAll('[name="authoring_version"]').forEach(input => input.value = authoringVersion);
            // Update order badges
            document.querySelectorAll('.pdf-card').forEach(function(card, index) {
                var badge = card.querySelector('.badge-order');
                if (badge) {
                    badge.innerHTML = '<i class="fa fa-sort"></i> الترتيب: ' + (index + 1);
                }
            });
        }).catch(error => {
            if (error.code === 'cancelled') return;
            window.RoknAdminRequest.blockMutationsUntilReload();
            sortable?.option('disabled', true);
            alert(error.message || 'تعذّر حفظ الترتيب');
            location.reload();
        });
    }
}) : null;

// Toggle status function
function toggleStatus(pdfId) {
    mutatePdf('course-pdf-mutation', '{{ url('dashboard/courses/' . $course->id . '/pdfs') }}/' + pdfId + '/toggle-status', {})
    .then(data => {
        authoringVersion = Number(data.authoring_version);
        location.reload();
    }).catch(error => {
        if (error.code !== 'cancelled') alert(error.message || 'تعذّر تحديث الحالة');
        if (
            error.code === 'mutation_outcome_unknown' ||
            error.code === 'invalid_authoring_response' ||
            error.status === 409
        ) {
            window.RoknAdminRequest.blockMutationsUntilReload();
            sortable?.option('disabled', true);
            document.querySelectorAll('[onclick^="toggleStatus("]').forEach(button => button.disabled = true);
            location.reload();
        }
    });
}
</script>
@endsection
