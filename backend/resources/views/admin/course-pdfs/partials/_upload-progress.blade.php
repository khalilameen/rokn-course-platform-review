<div class="form-group d-none" id="pdfUploadProgress" role="status" aria-live="polite">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <span id="pdfUploadProgressLabel">جارٍ رفع الملف</span>
        <span id="pdfUploadProgressValue">0%</span>
    </div>
    <div class="progress">
        <div class="progress-bar" id="pdfUploadProgressBar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div>
    </div>
    <button type="button" class="btn btn-link text-danger px-0 mt-2" id="cancelPdfUpload">إلغاء الرفع</button>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('coursePdfForm');
    const file = document.getElementById('pdfFile');
    const container = document.getElementById('pdfUploadProgress');
    const bar = document.getElementById('pdfUploadProgressBar');
    const value = document.getElementById('pdfUploadProgressValue');
    const label = document.getElementById('pdfUploadProgressLabel');
    const cancel = document.getElementById('cancelPdfUpload');
    if (!form || !file || !container || !bar || !value || !label || !cancel) return;

    let request = null;
    let bodySent = false;
    let navigatingAfterSuccess = false;
    form.addEventListener('submit', function (event) {
        if (request) {
            event.preventDefault();
            return;
        }
        if (!file.files || file.files.length === 0) return;
        event.preventDefault();
        request = new XMLHttpRequest();
        bodySent = false;
        const submit = form.querySelector('[type="submit"]')
            || document.querySelector('[form="' + CSS.escape(form.id) + '"][type="submit"]');
        const allowRetry = function () {
            request = null;
            if (submit) submit.disabled = false;
            cancel.disabled = false;
            label.textContent = 'تعذر رفع الملف  تحقق من الاتصال ثم حاول مرة أخرى';
        };
        const reconcileUnknownOutcome = function () {
            request = null;
            window.RoknAdminRequest?.blockMutationsUntilReload();
            if (submit) submit.disabled = true;
            cancel.disabled = true;
            label.textContent = 'وصل الملف ونراجع نتيجة الحفظ';
            window.setTimeout(() => window.location.reload(), 2000);
        };
        if (submit) submit.disabled = true;
        container.classList.remove('d-none');
        label.textContent = 'جارٍ رفع الملف';

        request.upload.addEventListener('progress', function (progress) {
            if (!progress.lengthComputable) return;
            const percent = Math.min(100, Math.round((progress.loaded / progress.total) * 100));
            bar.style.width = percent + '%';
            bar.setAttribute('aria-valuenow', String(percent));
            value.textContent = percent + '%';
        });
        request.upload.addEventListener('load', function () {
            bodySent = true;
            cancel.disabled = true;
            label.textContent = 'اكتمل الرفع  جاري حفظ الملف';
        });
        request.addEventListener('load', function () {
            if (request.status < 200 || request.status >= 400) {
                allowRetry();
                return;
            }
            const destination = request.responseURL || form.action;
            request = null;
            navigatingAfterSuccess = true;
            window.location.assign(destination);
        });
        request.addEventListener('error', function () {
            bodySent ? reconcileUnknownOutcome() : allowRetry();
        });
        request.addEventListener('timeout', function () {
            bodySent ? reconcileUnknownOutcome() : allowRetry();
        });
        request.addEventListener('abort', function () {
            if (bodySent) {
                reconcileUnknownOutcome();
                return;
            }
            request = null;
            if (submit) submit.disabled = false;
            container.classList.add('d-none');
            bar.style.width = '0%';
            bar.setAttribute('aria-valuenow', '0');
            value.textContent = '0%';
        });
        request.open('POST', form.action, true);
        request.timeout = 10 * 60 * 1000;
        request.send(new FormData(form));
    });
    cancel.addEventListener('click', function () {
        if (request && !bodySent) request.abort();
    });
    window.addEventListener('beforeunload', function (event) {
        if (!request || navigatingAfterSuccess) return;
        event.preventDefault();
        event.returnValue = '';
    });
});
</script>
