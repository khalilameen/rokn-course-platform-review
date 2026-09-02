<script>
document.addEventListener('DOMContentLoaded', function() {
    // Progress bar animation
    updateProgress();

    // File upload functionality
    const fileInput = document.getElementById('image');
    const uploadArea = document.querySelector('.file-upload-area');
    const imagePreview = document.getElementById('imagePreview');

    fileInput.addEventListener('change', handleFileSelect);

    uploadArea.addEventListener('dragover', (e) => {
        e.preventDefault();
        uploadArea.classList.add('dragover');
    });

    uploadArea.addEventListener('dragleave', () => {
        uploadArea.classList.remove('dragover');
    });

    uploadArea.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadArea.classList.remove('dragover');

        const files = e.dataTransfer.files;
        if (files.length > 0) {
            fileInput.files = files;
            handleFileSelect({ target: { files: files } });
        }
    });

    // Keep the visual card state aligned with the native checkbox.
    document.querySelectorAll('.checkbox-item').forEach(item => {
        const checkbox = item.querySelector('input[type="checkbox"]');
        if (!checkbox) {
            return;
        }

        const syncCardState = () => item.classList.toggle('selected', checkbox.checked);
        syncCardState();
        checkbox.addEventListener('change', function() {
            syncCardState();
            updateProgress();
        });
    });

    // Initialize Select2
    $('.select2').select2({
        placeholder: "اختر التصنيفات",
        allowClear: true,
        dir: "rtl"
    });

    // Form validation and progress
    document.querySelectorAll('input, select, textarea').forEach(element => {
        element.addEventListener('input', updateProgress);
        element.addEventListener('change', updateProgress);
    });
});

function handleFileSelect(e) {
    const files = e.target.files;
    const imagePreview = document.getElementById('imagePreview');

    if (files && files[0]) {
        const file = files[0];

        // Validate file type
        if (!file.type.startsWith('image/')) {
            alert('يرجى اختيار ملف صورة صحيح');
            document.getElementById('image').value = '';
            imagePreview.innerHTML = '';
            updateProgress();
            return;
        }

        // Validate file size (10MB)
        if (file.size > 10 * 1024 * 1024) {
            alert('حجم الملف يجب أن يكون أقل من 10 ميجابايت');
            document.getElementById('image').value = '';
            imagePreview.innerHTML = '';
            updateProgress();
            return;
        }

        const reader = new FileReader();
        reader.onload = function(e) {
            imagePreview.innerHTML = `
                <img src="${e.target.result}" alt="Preview" class="image-preview">
                <div class="image-preview-success">
                    <i class="fa fa-check-circle"></i> تم اختيار الصورة بنجاح
                </div>
            `;
        };
        reader.readAsDataURL(file);
    }

    updateProgress();
}

function updateProgress() {
    const form = document.getElementById('courseForm');
    const requiredFields = form.querySelectorAll('[required]');
    const allFields = form.querySelectorAll('input:not([type="hidden"]), select, textarea');

    let filledRequired = 0;
    let totalFilled = 0;

    requiredFields.forEach(field => {
        if (field.value.trim() !== '') {
            filledRequired++;
        }
    });

    allFields.forEach(field => {
        if (field.value.trim() !== '') {
            totalFilled++;
        }
    });

    // Progress based on required fields (70%) + optional fields (30%)
    const requiredProgress = (filledRequired / requiredFields.length) * 70;
    const optionalProgress = (totalFilled / allFields.length) * 30;
    const totalProgress = requiredProgress + optionalProgress;

    document.getElementById('progressBar').style.width = totalProgress + '%';
}
</script>
