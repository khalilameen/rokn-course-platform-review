@php($draftFormId = $formId ?? null)
@if($draftFormId)
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById(@json($draftFormId));
    if (!form || !window.localStorage) return;
    const conflictMessage = @json($errors->first('authoring_version') ?: $errors->first('editor_version'));
    if (conflictMessage) {
        const conflict = document.createElement('div');
        conflict.className = 'alert alert-warning mb-3';
        conflict.style.whiteSpace = 'pre-line';
        conflict.textContent = conflictMessage;
        form.prepend(conflict);
    }
    // A create request gets a fresh idempotency UUID after a hard reload. It
    // must not invalidate the user's saved text; only an existing resource's
    // editor revision decides whether a draft is safe to restore.
    const versionInput = form.querySelector('[name="authoring_version"], [name="editor_version"]');
    const key = 'rokn:course-authoring-draft:{{ auth()->id() }}:' + location.pathname + ':' + form.id;
    const ignored = new Set(['_token', '_method', 'authoring_version', 'editor_version', 'authoring_request_id', 'password', 'password_confirmation']);
    const enqueueDraftMicrotask = window.queueMicrotask || (callback => Promise.resolve().then(callback));
    let dirty = false;
    let submitting = false;
    let submitQueued = false;

    const snapshot = () => {
        const values = {};
        let hadFiles = false;
        form.querySelectorAll('input, textarea, select').forEach(field => {
            if (!field.name || ignored.has(field.name) || field.disabled) return;
            if (
                field.type === 'hidden'
                && form.querySelector('input[type="checkbox"][name="' + CSS.escape(field.name) + '"]')
            ) return;
            if (field.type === 'file') {
                hadFiles = hadFiles || Boolean(field.files && field.files.length);
                return;
            }
            if (field.type === 'radio') {
                if (field.checked) values[field.name] = field.value;
                return;
            }
            if (field.type === 'checkbox') {
                if (field.name.endsWith('[]')) {
                    values[field.name] = values[field.name] || [];
                    if (field.checked) values[field.name].push(field.value);
                } else {
                    values[field.name] = field.checked;
                }
                return;
            }
            if (field.multiple) {
                values[field.name] = Array.from(field.selectedOptions).map(option => option.value);
                return;
            }
            values[field.name] = field.value;
        });
        try {
            localStorage.setItem(key, JSON.stringify({
                version: String(versionInput?.value || ''),
                savedAt: Date.now(),
                hadFiles,
                values,
            }));
        } catch (_) {}
    };

    try {
        const saved = JSON.parse(localStorage.getItem(key) || 'null');
        const currentVersion = String(versionInput?.value || '');
        if (saved && saved.version === currentVersion && Date.now() - saved.savedAt < 7 * 86400000) {
            document.dispatchEvent(new CustomEvent('rokn:authoring-draft-prepare', {
                detail: {formId: form.id, values: saved.values || {}},
            }));
            Object.entries(saved.values || {}).forEach(([name, value]) => {
                const fields = form.querySelectorAll('[name="' + CSS.escape(name) + '"]');
                fields.forEach(field => {
                    if (
                        field.type === 'hidden'
                        && form.querySelector('input[type="checkbox"][name="' + CSS.escape(field.name) + '"]')
                    ) return;
                    if (field.type === 'checkbox') {
                        field.checked = Array.isArray(value) ? value.includes(field.value) : Boolean(value);
                    }
                    else if (field.type === 'radio') field.checked = field.value === value;
                    else if (field.multiple && Array.isArray(value)) {
                        Array.from(field.options).forEach(option => option.selected = value.includes(option.value));
                    } else field.value = value ?? '';
                    field.dispatchEvent(new Event('change', {bubbles: true}));
                });
            });
            dirty = Object.keys(saved.values || {}).length > 0;
            if (saved.hadFiles) {
                const note = document.createElement('div');
                note.className = 'alert alert-warning mt-3';
                note.textContent = 'استعدنا النصوص التي لم تُحفظ\nاختر الملف مرة أخرى قبل الحفظ';
                form.prepend(note);
            }
        }
    } catch (_) {
        localStorage.removeItem(key);
    }

    let timer;
    form.addEventListener('input', () => {
        dirty = true;
        clearTimeout(timer);
        timer = setTimeout(snapshot, 250);
    });
    form.addEventListener('change', () => {
        dirty = true;
        snapshot();
    });
    form.addEventListener('reset', () => enqueueDraftMicrotask(() => {
        clearTimeout(timer);
        dirty = false;
        localStorage.removeItem(key);
    }));
    form.addEventListener('submit', event => {
        if (submitting || submitQueued) {
            event.preventDefault();
            return;
        }
        submitQueued = true;
        enqueueDraftMicrotask(() => {
            if (event.defaultPrevented) {
                submitQueued = false;
                return;
            }
            submitting = true;
            snapshot();
            form.setAttribute('aria-busy', 'true');
            form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(button => {
                button.disabled = true;
            });
        });
    });
    window.addEventListener('beforeunload', event => {
        if (!dirty || submitting) return;
        event.preventDefault();
        event.returnValue = '';
    });
});
</script>
@endif
