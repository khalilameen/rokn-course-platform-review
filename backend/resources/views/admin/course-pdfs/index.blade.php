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
                    <a href="{{ route('admin.courses.pdfs.create', $course) }}" class="btn-modern btn-success">
                        <i class="fa fa-plus"></i>
                        إضافة ملف PDF
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- PDF List Container -->
    <div class="pdf-container">
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
                                        <button type="submit" class="btn-card btn-delete" title="حذف">
                                            <i class="fa fa-trash"></i>
                                        </button>
                                    </form>
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
                    <a href="{{ route('admin.courses.pdfs.create', $course) }}" class="btn-add-first">
                        <i class="fa fa-plus"></i>
                        إضافة أول ملف PDF
                    </a>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js" integrity="sha384-eeLEhtwdMwD3X9y+8P3Cn7Idl/M+w8H4uZqkgD/2eJVkWIN1yKzEj6XegJ9dL3q0" crossorigin="anonymous"></script>
<script>
// Initialize sortable
var sortable = new Sortable(document.getElementById('sortable-pdfs'), {
    animation: 150,
    ghostClass: 'dragging',
    onEnd: function(evt) {
        var order = [];
        document.querySelectorAll('.pdf-card').forEach(function(card) {
            order.push(card.dataset.id);
        });
        
        fetch('{{ route('admin.courses.pdfs.reorder', $course) }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify({ order: order })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update order badges
                document.querySelectorAll('.pdf-card').forEach(function(card, index) {
                    var badge = card.querySelector('.badge-order');
                    if (badge) {
                        badge.innerHTML = '<i class="fa fa-sort"></i> الترتيب: ' + (index + 1);
                    }
                });
            }
        });
    }
});

// Toggle status function
function toggleStatus(pdfId) {
    fetch('{{ url('dashboard/courses/' . $course->id . '/pdfs') }}/' + pdfId + '/toggle-status', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        }
    });
}
</script>
@endsection
