@php
    $attachmentManagerId = 'attachment-manager-' . $attachmentType . '-' . $attachmentOwner->getKey();
@endphp
<section id="{{ $attachmentManagerId }}" class="card mt-4" data-store-url="{{ route('admin.attachments.store') }}">
    <div class="card-body">
        <h4 class="mb-2">ملفات المرفقات</h4>
        <p class="text-muted mb-3">ارفع الملف هنا ليصل إلى الطالب من داخل الكورس</p>
        <div class="row align-items-end">
            <div class="col-md-5 mb-2">
                <label class="form-label">اسم الملف</label>
                <input type="text" class="form-control attachment-name" maxlength="255" placeholder="اختياري">
            </div>
            <div class="col-md-5 mb-2">
                <label class="form-label">الملف</label>
                <input type="file" class="form-control attachment-file"
                       accept=".pdf,.zip,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.jpg,.jpeg,.png,.webp">
            </div>
            <div class="col-md-2 mb-2 d-flex gap-2">
                <button type="button" class="btn btn-primary attachment-upload">رفع</button>
                <button type="button" class="btn btn-outline-secondary attachment-cancel d-none">إيقاف</button>
            </div>
        </div>
        <div class="progress mt-2 d-none attachment-progress">
            <div class="progress-bar attachment-progress__bar" role="progressbar"></div>
        </div>
        <div class="small mt-2 attachment-status" aria-live="polite"></div>
        <div class="list-group mt-3 attachment-list">
            @forelse($attachmentOwner->attachments as $attachment)
                <div class="list-group-item d-flex align-items-center justify-content-between" data-attachment-id="{{ $attachment->id }}">
                    <span>{{ $attachment->title }} <small class="text-muted">{{ strtoupper((string) $attachment->file_type) }} · {{ $attachment->file_size_human }}</small></span>
                    <button type="button" class="btn btn-sm btn-outline-danger attachment-delete"
                            data-delete-url="{{ route('admin.attachments.destroy', $attachment) }}">حذف</button>
                </div>
            @empty
                <div class="list-group-item text-muted attachment-empty">لا توجد ملفات</div>
            @endforelse
        </div>
    </div>
</section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const root = document.getElementById(@json($attachmentManagerId));
    if (!root) return;
    const file = root.querySelector('.attachment-file');
    const name = root.querySelector('.attachment-name');
    const upload = root.querySelector('.attachment-upload');
    const cancel = root.querySelector('.attachment-cancel');
    const progress = root.querySelector('.attachment-progress');
    const bar = progress.querySelector('.progress-bar');
    const status = root.querySelector('.attachment-status');
    const list = root.querySelector('.attachment-list');
    const csrf = @json(csrf_token());
    let request = null;
    let cancelRequested = false;
    let uploadBodySent = false;
    let reconciliationPending = false;

    const versionInput = () => document.querySelector('[name="authoring_version"]');
    const setVersion = value => document.querySelectorAll('[name="authoring_version"]').forEach(input => {
        input.value = String(value);
    });
    const currentVersion = () => Number(versionInput()?.value || 0);
    const reconcileStalePage = message => {
        window.RoknAdminRequest.blockMutationsUntilReload();
        reconciliationPending = true;
        status.textContent = String(message || 'تغيّر الكورس أثناء العملية\nنعيد تحميل أحدث نسخة');
        status.className = 'small mt-2 attachment-status text-muted';
        upload.disabled = true;
        window.setTimeout(() => window.location.reload(), 700);
    };
    const showError = payload => {
        const first = payload?.errors && Object.values(payload.errors).flat().find(Boolean);
        status.textContent = String(first || payload?.message || 'تعذر رفع الملف');
        status.className = 'small mt-2 attachment-status text-danger';
    };
    const addRow = payload => {
        root.querySelector('.attachment-empty')?.remove();
        const row = document.createElement('div');
        row.className = 'list-group-item d-flex align-items-center justify-content-between';
        row.dataset.attachmentId = payload.attachment.id;
        const copy = document.createElement('span');
        copy.textContent = `${payload.attachment.title} · ${String(payload.attachment.file_type || '').toUpperCase()}`;
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'btn btn-sm btn-outline-danger attachment-delete';
        remove.dataset.deleteUrl = payload.delete_url;
        remove.textContent = 'حذف';
        row.append(copy, remove);
        list.append(row);
    };

    upload.addEventListener('click', function () {
        if (window.RoknAdminRequest.mutationsAreBlocked() || request || !file.files?.[0]) {
            if (!file.files?.[0]) showError({message: 'اختر الملف أولًا'});
            return;
        }
        const body = new FormData();
        body.append('_token', csrf);
        body.append('file', file.files[0]);
        body.append('name', name.value.trim());
        body.append('attachable_type', @json($attachmentType));
        body.append('attachable_id', @json((string) $attachmentOwner->getKey()));
        const expectedVersion = currentVersion();
        body.append('authoring_version', String(expectedVersion));
        cancelRequested = false;
        uploadBodySent = false;
        reconciliationPending = false;
        request = new XMLHttpRequest();
        request.open('POST', root.dataset.storeUrl, true);
        request.timeout = 300000;
        request.setRequestHeader('Accept', 'application/json');
        progress.classList.remove('d-none');
        cancel.classList.remove('d-none');
        upload.disabled = true;
        bar.style.width = '0%';
        status.textContent = 'جاري الرفع';
        status.className = 'small mt-2 attachment-status text-muted';
        request.upload.onprogress = event => {
            if (!event.lengthComputable) return;
            bar.style.width = `${Math.round(event.loaded / event.total * 100)}%`;
        };
        request.upload.onload = () => {
            uploadBodySent = true;
        };
        request.onload = () => {
            let payload;
            try {
                payload = JSON.parse(request.responseText || '{}');
            } catch (_) {
                payload = {message: 'انتهت الجلسة\nأعد تحميل الصفحة'};
            }
            if (request.status >= 200 && request.status < 300) {
                let resultingVersion;
                try {
                    resultingVersion = window.RoknAdminRequest.requireAuthoringVersion(payload, expectedVersion, false);
                    if (!payload.attachment || !Number.isSafeInteger(Number(payload.attachment.id)) || Number(payload.attachment.id) < 1 || typeof payload.delete_url !== 'string' || !payload.delete_url) {
                        throw new window.RoknAdminRequest.AdminRequestError('وصل رد غير مكتمل بعد الرفع', 200, 'invalid_authoring_response');
                    }
                } catch (error) {
                    reconcileStalePage(error.message);
                    return;
                }
                setVersion(resultingVersion);
                if (!list.querySelector(`[data-attachment-id="${payload.attachment.id}"]`)) addRow(payload);
                status.textContent = payload.message;
                status.className = 'small mt-2 attachment-status text-success';
                file.value = '';
                name.value = '';
            } else {
                showError(payload);
                if (request.status === 409 || payload?.errors?.authoring_version) {
                    reconcileStalePage(status.textContent);
                }
            }
        };
        const reconcileUnknownUpload = () => {
            if (!uploadBodySent) {
                showError({message: 'انقطع الاتصال قبل اكتمال الرفع\nحاول مرة أخرى'});
                return;
            }
            reconcileStalePage('انقطع الرد بعد الرفع\nنعيد تحميل قائمة المرفقات');
        };
        request.onerror = reconcileUnknownUpload;
        request.ontimeout = reconcileUnknownUpload;
        request.onabort = () => {
            if (cancelRequested && !uploadBodySent) {
                status.textContent = 'تم إيقاف الرفع';
                status.className = 'small mt-2 attachment-status text-muted';
                return;
            }
            reconcileStalePage('نتحقق من حالة الرفع');
        };
        request.onloadend = () => {
            request = null;
            cancelRequested = false;
            uploadBodySent = false;
            upload.disabled = reconciliationPending;
            cancel.classList.add('d-none');
        };
        request.send(body);
    });
    cancel.addEventListener('click', () => {
        if (!request) return;
        cancelRequested = true;
        request.abort();
    });

    window.addEventListener('beforeunload', event => {
        if (!request) return;
        event.preventDefault();
        event.returnValue = '';
    });

    list.addEventListener('click', async event => {
        const button = event.target.closest('.attachment-delete');
        if (!button || button.disabled || window.RoknAdminRequest.mutationsAreBlocked()) return;
        button.disabled = true;
        try {
            const expectedVersion = currentVersion();
            const payload = await window.RoknAdminRequest.request(button.dataset.deleteUrl, {
                method: 'DELETE',
                headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
                body: JSON.stringify({authoring_version: expectedVersion}),
            });
            setVersion(window.RoknAdminRequest.requireAuthoringVersion(payload, expectedVersion, true));
            button.closest('[data-attachment-id]')?.remove();
            status.textContent = payload.message;
            status.className = 'small mt-2 attachment-status text-success';
            if (!list.querySelector('[data-attachment-id]')) {
                list.innerHTML = '<div class="list-group-item text-muted attachment-empty">لا توجد ملفات</div>';
            }
        } catch (payload) {
            if (payload.code !== 'cancelled') showError(payload);
            if (payload.code === 'mutation_outcome_unknown' || payload.code === 'invalid_authoring_response' || payload.status === 409) {
                reconcileStalePage(payload.message);
            }
            if (!reconciliationPending) button.disabled = false;
        }
    });
});
</script>
