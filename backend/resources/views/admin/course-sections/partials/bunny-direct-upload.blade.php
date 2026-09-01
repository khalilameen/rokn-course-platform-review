<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('sectionForm');
    const fileInput = document.getElementById('bunny_video');
    const claimInput = document.getElementById('bunny_video_claim');
    if (!form || !fileInput || !claimInput) return;

    const progressBox = document.getElementById('bunny_upload_progress');
    const progressBar = progressBox?.querySelector('.progress-bar');
    const statusText = document.getElementById('bunny_upload_status');
    const cancelButton = document.getElementById('bunny_upload_cancel');
    const retryButton = document.getElementById('bunny_upload_retry');
    const sectionType = document.getElementById('section_type');
    const csrf = form.querySelector('input[name="_token"]')?.value || '';
    const maxBytes = 5 * 1024 * 1024 * 1024;
    const allowedMimes = ['video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/webm'];
    const mimeByExtension = {
        mp4: 'video/mp4',
        mov: 'video/quicktime',
        avi: 'video/x-msvideo',
        webm: 'video/webm',
    };
    const chunkBytes = 20 * 1024 * 1024;
    const recordVersion = 2;
    const ownerId = @json((string) auth()->id());
    const courseId = String(form.dataset.courseId || '');
    const sectionId = String(form.dataset.sectionId || 'new');
    const storageKey = `rokn:bunny-upload:${ownerId}:${courseId}:${sectionId}`;
    const terminalCodes = new Set([
        'bunny_upload_claim_unavailable',
        'bunny_upload_operation_unavailable',
    ]);
    const serverRejectedClaim = @json($errors->has('bunny_video_claim_terminal'));
    let currentFile = null;
    let currentRecord = null;
    let currentRequest = null;
    let stopped = false;
    let uploading = false;
    let submittingAfterUpload = false;
    let lastSubmitter = null;

    const show = (message, percent, retry) => {
        progressBox?.classList.remove('is-hidden');
        if (statusText) statusText.textContent = message;
        if (progressBar && Number.isFinite(percent)) {
            const bounded = Math.max(0, Math.min(100, percent));
            progressBar.style.width = `${bounded}%`;
            progressBar.setAttribute('aria-valuenow', String(Math.round(bounded)));
        }
        retryButton?.classList.toggle('is-hidden', !retry);
    };

    const postJson = async (url, body) => {
        const data = await window.RoknAdminRequest.request(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
            },
            body: JSON.stringify(body),
        });
        return data.data || data;
    };

    const fingerprint = file => [
        file.name,
        file.size,
        file.lastModified,
        file.type,
    ].join(':');

    const operationId = () => {
        if (window.crypto?.randomUUID) return window.crypto.randomUUID();
        const bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        const hex = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
        return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
    };

    const readRecord = file => {
        try {
            const saved = JSON.parse(localStorage.getItem(storageKey) || 'null');
            const matchesContext = saved
                && Number(saved.version) === recordVersion
                && String(saved.ownerId) === ownerId
                && String(saved.courseId) === courseId
                && String(saved.sectionId) === sectionId
                && saved.fingerprint === fingerprint(file);
            const claimExpiresAt = Date.parse(saved?.claimExpiresAt || '') || 0;
            const operationExpired = !saved?.claim
                && Number(saved?.savedAt || 0) < Date.now() - (15 * 60 * 1000);
            if (!matchesContext || operationExpired || (saved.claim && claimExpiresAt <= Date.now())) {
                localStorage.removeItem(storageKey);
                return null;
            }
            // performance.now is process-local. A reloaded page renews the
            // short-lived authorization while keeping the resumable TUS URL.
            saved.headers = null;
            saved.authorizationDeadline = 0;
            return saved;
        } catch (_) {
            return null;
        }
    };

    const saveRecord = record => {
        currentRecord = Object.assign(record, {
            version: recordVersion,
            ownerId,
            courseId,
            sectionId,
            savedAt: Date.now(),
        });
        localStorage.setItem(storageKey, JSON.stringify(currentRecord));
    };

    const clearRecord = () => {
        localStorage.removeItem(storageKey);
        currentRecord = null;
        claimInput.value = '';
        fileInput.disabled = false;
        if (fileInput.dataset.videoRequired === 'true') fileInput.setAttribute('data-required', 'true');
    };

    const applyAuthorization = (record, authorization) => {
        const ttlSeconds = Number(authorization.authorization_expires_in_seconds || 0);
        record.headers = authorization.headers;
        record.authorizationDeadline = Number.isFinite(ttlSeconds) && ttlSeconds > 0
            ? performance.now() + ttlSeconds * 1000
            : 0;
        record.authorizationExpiresAt = Date.parse(authorization.authorization_expires_at || '') || 0;
        if (authorization.claim_expires_at) {
            record.claimExpiresAt = authorization.claim_expires_at;
        }
    };

    const freshAuthorization = async record => {
        if (record.headers && Number(record.authorizationDeadline || 0) > performance.now() + 60000) {
            return record.headers;
        }
        const auth = await postJson(form.dataset.bunnyUploadRenew, {claim: record.claim});
        applyAuthorization(record, auth);
        saveRecord(record);
        return record.headers;
    };

    const metadataValue = value => btoa(unescape(encodeURIComponent(String(value))));

    const createTusUpload = async (file, record) => {
        const response = await fetch(record.endpoint, {
            method: 'POST',
            headers: {
                ...record.headers,
                'Tus-Resumable': '1.0.0',
                'Upload-Length': String(file.size),
                'Upload-Metadata': `filename ${metadataValue(file.name)},filetype ${metadataValue(file.type)}`,
            },
        });
        if (!response.ok) throw new Error('تعذر بدء رفع الفيديو');
        const location = response.headers.get('Location');
        if (!location) throw new Error('لم ترجع خدمة الفيديو رابط الرفع');
        record.uploadUrl = new URL(location, record.endpoint).toString();
        saveRecord(record);
    };

    const remoteOffset = async record => {
        const headers = await freshAuthorization(record);
        const controller = new AbortController();
        const timer = window.setTimeout(() => controller.abort(), 15000);
        try {
            const response = await fetch(record.uploadUrl, {
                method: 'HEAD',
                headers: {...headers, 'Tus-Resumable': '1.0.0'},
                signal: controller.signal,
            });
            if (response.status === 404 || response.status === 410) return null;
            if (!response.ok) throw new Error('تعذر استئناف الرفع');
            return Number(response.headers.get('Upload-Offset') || 0);
        } catch (error) {
            if (error?.name === 'AbortError') throw new Error('الاتصال بطيء جدًا');
            throw error;
        } finally {
            window.clearTimeout(timer);
        }
    };

    const patchChunk = (record, file, offset, headers) => new Promise((resolve, reject) => {
        const end = Math.min(file.size, offset + chunkBytes);
        const request = new XMLHttpRequest();
        currentRequest = request;
        request.open('PATCH', record.uploadUrl, true);
        request.timeout = 120000;
        Object.entries(headers).forEach(([name, value]) => request.setRequestHeader(name, value));
        request.setRequestHeader('Tus-Resumable', '1.0.0');
        request.setRequestHeader('Upload-Offset', String(offset));
        request.setRequestHeader('Content-Type', 'application/offset+octet-stream');
        request.upload.onprogress = event => {
            if (!event.lengthComputable) return;
            show(`جاري الرفع ${Math.floor(((offset + event.loaded) / file.size) * 100)}٪`, ((offset + event.loaded) / file.size) * 100, false);
        };
        request.onload = () => {
            currentRequest = null;
            if (request.status >= 200 && request.status < 300) {
                resolve(Number(request.getResponseHeader('Upload-Offset') || end));
            } else {
                reject(Object.assign(new Error('تعذر متابعة الرفع'), {status: request.status}));
            }
        };
        request.onerror = () => reject(new Error('انقطع الاتصال أثناء الرفع'));
        request.ontimeout = () => reject(new Error('الاتصال بطيء جدًا'));
        request.onabort = () => reject(Object.assign(new Error('تم إيقاف الرفع'), {cancelled: true}));
        request.send(file.slice(offset, end));
    });

    const upload = async (file, restartCount = 0) => {
        const extension = String(file.name || '').split('.').pop().toLowerCase();
        const declaredMime = file.type || mimeByExtension[extension] || '';
        if (!allowedMimes.includes(declaredMime) || mimeByExtension[extension] !== declaredMime) {
            throw new Error('صيغة الفيديو غير مدعومة');
        }
        if (file.size < 1 || file.size > maxBytes) throw new Error('حجم الفيديو يجب ألا يتجاوز 5GB');
        const title = (document.getElementById('lesson_title_ar')?.value || document.getElementById('title_ar')?.value || '').trim();
        if (!title) throw new Error('أضف عنوان المقطع أولًا');

        let record = readRecord(file);
        if (!record) {
            // Persist the operation identity before contacting our API. A lost
            // response can then be retried without allocating a second video.
            record = {
                fingerprint: fingerprint(file),
                idempotencyKey: operationId(),
                endpoint: null,
                claim: null,
                headers: null,
                authorizationExpiresAt: 0,
                authorizationDeadline: 0,
                claimExpiresAt: null,
                uploadUrl: null,
            };
            saveRecord(record);
            show('جاري تجهيز الرفع', 0, false);
            const issued = await postJson(form.dataset.bunnyUploadInit, {
                title,
                size: file.size,
                mime: declaredMime,
                original_name: file.name,
                section_id: form.dataset.sectionId || null,
                idempotency_key: record.idempotencyKey,
            });
            Object.assign(record, {
                endpoint: issued.upload_endpoint,
                claim: issued.claim,
                claimExpiresAt: issued.claim_expires_at,
            });
            applyAuthorization(record, issued);
            saveRecord(record);
            await createTusUpload(file, record);
        } else {
            if (!record.claim) {
                const issued = await postJson(form.dataset.bunnyUploadInit, {
                    title,
                    size: file.size,
                    mime: declaredMime,
                    original_name: file.name,
                    section_id: form.dataset.sectionId || null,
                    idempotency_key: record.idempotencyKey,
                });
                Object.assign(record, {
                    endpoint: issued.upload_endpoint,
                    claim: issued.claim,
                    claimExpiresAt: issued.claim_expires_at,
                });
                applyAuthorization(record, issued);
                saveRecord(record);
            }
            claimInput.value = record.claim;
            if (!record.uploadUrl) {
                await freshAuthorization(record);
                await createTusUpload(file, record);
            }
        }

        let offset = await remoteOffset(record);
        if (offset === null) {
            clearRecord();
            if (restartCount >= 1) {
                throw Object.assign(new Error('تعذر استئناف الرفع\nاختر الملف وحاول مرة أخرى'), {
                    code: 'bunny_remote_upload_unavailable',
                });
            }
            return upload(file, restartCount + 1);
        }
        if (!Number.isFinite(offset) || offset < 0 || offset > file.size) {
            throw new Error('حالة الرفع غير صالحة');
        }

        let failures = 0;
        while (offset < file.size) {
            if (stopped) throw Object.assign(new Error('تم إيقاف الرفع'), {cancelled: true});
            try {
                const headers = await freshAuthorization(record);
                offset = await patchChunk(record, file, offset, headers);
                failures = 0;
            } catch (error) {
                if (error.cancelled || stopped) throw error;
                if ([401, 403].includes(Number(error.status || 0))) {
                    record.authorizationExpiresAt = 0;
                    record.authorizationDeadline = 0;
                }
                failures += 1;
                if (failures > 5) throw error;
                await new Promise(resolve => setTimeout(resolve, [1000, 2000, 5000, 10000, 20000][failures - 1]));
                const resumed = await remoteOffset(record);
                if (resumed === null) {
                    clearRecord();
                    if (restartCount >= 1) {
                        throw Object.assign(new Error('تعذر استئناف الرفع\nاختر الملف وحاول مرة أخرى'), {
                            code: 'bunny_remote_upload_unavailable',
                        });
                    }
                    return upload(file, restartCount + 1);
                }
                offset = resumed;
            }
        }

        claimInput.value = record.claim;
        fileInput.removeAttribute('required');
        fileInput.removeAttribute('data-required');
        fileInput.disabled = true;
        show('اكتمل رفع الفيديو', 100, false);
    };

    const startUploadAndSubmit = async () => {
        if (!currentFile || uploading) return;
        uploading = true;
        stopped = false;
        retryButton?.classList.add('is-hidden');
        try {
            await upload(currentFile);
            submittingAfterUpload = true;
            if (lastSubmitter) form.requestSubmit(lastSubmitter);
            else form.requestSubmit();
        } catch (error) {
            if (terminalCodes.has(String(error?.code || ''))) clearRecord();
            show(error.message || 'تعذر رفع الفيديو', Number(progressBar?.getAttribute('aria-valuenow') || 0), true);
        } finally {
            uploading = false;
        }
    };

    fileInput.addEventListener('change', function () {
        currentFile = this.files?.[0] || null;
        claimInput.value = '';
        this.disabled = false;
        if (this.dataset.videoRequired === 'true') this.setAttribute('data-required', 'true');
        if (!currentFile) return;
        const saved = readRecord(currentFile);
        if (saved) {
            currentRecord = saved;
            claimInput.value = saved.claim;
            show('يمكن متابعة الرفع السابق', 0, true);
        } else {
            progressBox?.classList.add('is-hidden');
        }
    });

    form.addEventListener('submit', function (event) {
        lastSubmitter = event.submitter || lastSubmitter;
        if (submittingAfterUpload) return;
        if (sectionType?.value !== 'lesson') return;
        if (claimInput.value) {
            fileInput.disabled = true;
            return;
        }
        currentFile = fileInput.files?.[0] || null;
        if (!currentFile) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        void startUploadAndSubmit();
    }, true);

    cancelButton?.addEventListener('click', function () {
        stopped = true;
        currentRequest?.abort();
        show('تم إيقاف الرفع ويمكنك متابعته لاحقًا', Number(progressBar?.getAttribute('aria-valuenow') || 0), true);
    });
    retryButton?.addEventListener('click', function () {
        stopped = false;
        void startUploadAndSubmit();
    });

    if (serverRejectedClaim) {
        clearRecord();
    } else if (claimInput.value) {
        fileInput.removeAttribute('required');
        fileInput.removeAttribute('data-required');
    }
    window.addEventListener('beforeunload', event => {
        if (!uploading) return;
        event.preventDefault();
        event.returnValue = '';
    });
});
</script>
