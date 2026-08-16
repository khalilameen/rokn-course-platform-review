<script>
document.addEventListener('DOMContentLoaded', function() {
    const courseSearch = document.getElementById('courseSearch');
    const typeFilter = document.getElementById('typeFilter');
    const classificationFilter = document.getElementById('classificationFilter');
    const coursesGrid = document.getElementById('coursesGrid');

    function filterCourses() {
        const searchTerm = courseSearch.value.toLowerCase();
        const selectedType = typeFilter.value;
        const selectedClassification = classificationFilter.value;
        const courseCards = document.querySelectorAll('.course-card');

        courseCards.forEach(card => {
            const searchText = card.getAttribute('data-search');
            const courseType = card.getAttribute('data-course-type');
            const classificationIds = JSON.parse(card.getAttribute('data-classification-ids') || '[]');

            const matchesSearch = !searchTerm || searchText.includes(searchTerm);
            const matchesType = !selectedType || courseType === selectedType;
            const matchesClassification = !selectedClassification || classificationIds.includes(parseInt(selectedClassification));

            if (matchesSearch && matchesType && matchesClassification) {
                card.style.display = 'block';
            } else {
                card.style.display = 'none';
            }
        });

        // Show/hide empty state
        const visibleCards = document.querySelectorAll('.course-card:not([style*="display: none"])');
        const existingEmptyState = document.querySelector('.empty-state.search-empty-state');

        if (visibleCards.length === 0 && !existingEmptyState) {
            // Add empty state without replacing existing content
            const emptyStateHtml = `
                <div class="empty-state search-empty-state courses-search-empty-state">
                    <div class="empty-icon">
                        <i class="fa fa-search"></i>
                    </div>
                    <h3 class="empty-title">لم يتم العثور على نتائج</h3>
                    <p class="empty-description">
                        جرب تغيير معايير البحث للعثور على الكورسات المناسبة.
                    </p>
                </div>
            `;
            coursesGrid.insertAdjacentHTML('beforeend', emptyStateHtml);
        } else if (visibleCards.length > 0 && existingEmptyState) {
            // Remove empty state when courses are visible
            existingEmptyState.remove();
        }
    }

    // Event listeners for filters
    courseSearch.addEventListener('input', filterCourses);
    typeFilter.addEventListener('change', filterCourses);
    classificationFilter.addEventListener('change', filterCourses);
});

function resetFilters() {
    document.getElementById('courseSearch').value = '';
    document.getElementById('typeFilter').value = '';
    document.getElementById('classificationFilter').value = '';

    // Show all courses
    document.querySelectorAll('.course-card').forEach(card => {
        card.style.display = 'block';
    });

    // Remove any search-generated empty state
    const searchEmptyState = document.querySelector('.empty-state.search-empty-state');
    if (searchEmptyState) {
        searchEmptyState.remove();
    }
}

function navigateToCourse(event, card) {
    // Don't navigate if clicking on buttons or links
    if (event.target.closest('.btn-card') || event.target.closest('button') || event.target.closest('a')) {
        return;
    }
    
    const url = card.getAttribute('data-url');
    if (url) {
        window.location.href = url;
    }
}

function deleteCourse(courseId) {
    // Create modern confirmation modal
    const modal = document.createElement('div');
    modal.className = 'delete-confirmation-overlay';
    modal.innerHTML = `
        <div class="delete-confirmation-modal">
            <div class="modal-icon">
                <i class="fa fa-exclamation-triangle"></i>
            </div>
            <h3 class="modal-title">تأكيد الحذف</h3>
            <p class="modal-message">
                هل أنت متأكد من حذف هذا الكورس؟<br>
                <strong>سيتم حذف جميع الأقسام والمحتوى المرتبط به نهائياً.</strong>
            </p>
            <div class="modal-actions">
                <button class="btn-modal btn-cancel" onclick="closeDeleteModal()">
                    <i class="fa fa-times"></i>
                    إلغاء
                </button>
                <button class="btn-modal btn-confirm" onclick="confirmDelete(${courseId})">
                    <i class="fa fa-trash"></i>
                    حذف نهائياً
                </button>
            </div>
        </div>
    `;

    document.body.appendChild(modal);
    document.body.style.overflow = 'hidden';

    // Close modal when clicking outside
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            closeDeleteModal();
        }
    });
}

function closeDeleteModal() {
    const modal = document.querySelector('.delete-confirmation-overlay');
    if (modal) {
        modal.style.animation = 'fadeOut 0.3s ease-out forwards';
        setTimeout(() => {
            modal.remove();
            document.body.style.overflow = 'auto';
        }, 300);
    }
}

function confirmDelete(courseId) {
    const confirmBtn = document.querySelector('.btn-confirm');

    // Add loading state
    confirmBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> جاري الحذف...';
    confirmBtn.disabled = true;
    confirmBtn.style.opacity = '0.7';

    // Submit form after short delay for better UX
    setTimeout(() => {
        document.getElementById('deleteForm' + courseId).submit();
    }, 500);
}

</script>
