<script>
function switchTab(event, tabName) {
    // Hide all tab contents
    const tabContents = document.querySelectorAll('.tab-content');
    tabContents.forEach(content => {
        content.classList.remove('active');
    });

    // Remove active class from all tab buttons
    const tabButtons = document.querySelectorAll('.tab-button');
    tabButtons.forEach(button => {
        button.classList.remove('active');
    });

    // Show selected tab content
    document.getElementById(tabName).classList.add('active');
    event.target.classList.add('active');
}

function deleteSection(sectionId) {
    if (confirm('هل أنت متأكد من حذف هذا القسم؟ سيتم حذف المحتوى المرتبط به.')) {
        document.getElementById('deleteSectionForm' + sectionId).submit();
    }
}
</script>
