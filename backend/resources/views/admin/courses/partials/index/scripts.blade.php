<script>
document.addEventListener('DOMContentLoaded', function() {
    // The result set is paginated on the server. Filtering only the cards on
    // the current page creates false empty states while matching courses exist
    // on later pages, so search is submitted as one authoritative query.
});

function resetFilters() {
    document.getElementById('courseSearch').value = '';
    document.getElementById('classificationFilter').value = '';

    window.location.href = @json(route('admin.courses.index'));
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
            <h3 class="modal-title">أرشفة الكورس</h3>
            <p class="modal-message">
                سيتوقف ظهوره وفتح محتواه للطلاب<br>
                <strong>يمكنك استعادته لاحقًا كمسودة مخفية.</strong>
            </p>
            <div class="modal-actions">
                <button class="btn-modal btn-cancel" onclick="closeDeleteModal()">
                    <i class="fa fa-times"></i>
                    إلغاء
                </button>
                <button class="btn-modal btn-confirm" onclick="confirmDelete(${courseId})">
                    <i class="fa fa-trash"></i>
                    نقل إلى الأرشيف
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
    confirmBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> جارٍ النقل...';
    confirmBtn.disabled = true;
    confirmBtn.style.opacity = '0.7';

    document.getElementById('deleteForm' + courseId).submit();
}

</script>
