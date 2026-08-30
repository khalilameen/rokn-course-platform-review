<script>
document.addEventListener('DOMContentLoaded', function () {
    const studio = document.getElementById('courseStudio');
    const toast = document.getElementById('courseStudioToast');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    const notify = (message, isError = false) => {
        if (!toast) return;
        toast.textContent = message;
        toast.classList.toggle('is-error', isError);
        toast.classList.add('is-visible');
        window.setTimeout(() => toast.classList.remove('is-visible'), 2800);
    };

    const tabs = Array.from(document.querySelectorAll('[data-studio-tab]'));
    const activateTab = button => {
        tabs.forEach(tab => { const active = tab === button; tab.classList.toggle('is-active', active); tab.setAttribute('aria-selected', active ? 'true' : 'false'); tab.tabIndex = active ? 0 : -1; });
        document.querySelectorAll('[data-studio-panel]').forEach(panel => { const active = panel.id === button.dataset.studioTab; panel.classList.toggle('is-active', active); panel.hidden = !active; });
    };
    tabs.forEach((button, index) => {
        button.addEventListener('click', () => activateTab(button));
        button.addEventListener('keydown', event => {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            const nextIndex = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + (event.key === 'ArrowLeft' ? 1 : -1) + tabs.length) % tabs.length;
            tabs[nextIndex].focus();
            activateTab(tabs[nextIndex]);
        });
    });

    document.getElementById('studentPreviewToggle')?.addEventListener('click', function () {
        const previewing = studio.classList.toggle('is-previewing');
        this.setAttribute('aria-pressed', previewing ? 'true' : 'false');
        this.querySelector('span').textContent = previewing ? 'العودة للتحرير' : 'معاينة الطالب';
        this.querySelector('i').className = previewing ? 'fa fa-pencil' : 'fa fa-eye';
    });

    document.querySelectorAll('.outline-module__toggle[aria-controls]').forEach(button => button.addEventListener('click', function () {
        const content = document.getElementById(this.getAttribute('aria-controls'));
        if (!content) return;
        const expanded = this.getAttribute('aria-expanded') !== 'false';
        this.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        content.hidden = expanded;
    }));

    const postOrder = async (url, payload, successMessage) => {
        try {
            const response = await fetch(url, {method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf}, body: JSON.stringify(payload)});
            if (!response.ok) {
                const result = await response.json().catch(() => ({}));
                const validationMessage = Object.values(result.errors || {}).flat()[0];
                throw new Error(validationMessage || result.message || 'لم يُحفظ الترتيب.');
            }
            notify(successMessage);
        } catch (error) {
            notify(error.message || 'لم يُحفظ الترتيب. أعد تحميل الصفحة وحاول مرة أخرى.', true);
            window.setTimeout(() => window.location.reload(), 1500);
        }
    };

    const modulesList = document.getElementById('studioModulesList');
    if (modulesList && window.Sortable) new Sortable(modulesList, {handle: '.outline-module__drag', animation: 160, ghostClass: 'is-dragging', onEnd: function () {
        const modules = Array.from(modulesList.querySelectorAll(':scope > .outline-module')).map((node, index) => ({id: Number(node.dataset.moduleId), order: index + 1}));
        postOrder(@json(route('admin.courses.modules.reorder', $course)), {modules}, 'تم حفظ ترتيب الوحدات');
    }});

    if (window.Sortable) document.querySelectorAll('.studio-sortable-sections').forEach(list => new Sortable(list, {group: 'studio-sections', handle: '.outline-item__drag', animation: 160, ghostClass: 'is-dragging', onMove: function (event) {
        if (event.dragged.dataset.sectionType !== 'project') return true;
        if (!event.to.dataset.moduleId) {
            notify('مشروع العبور يجب أن يبقى داخل وحدة.', true);
            return false;
        }
        const existingProject = Array.from(event.to.querySelectorAll(':scope > .outline-item[data-section-type="project"]')).find(item => item !== event.dragged);
        if (existingProject) {
            notify('يمكن لكل وحدة أن تحتوي مشروع عبور واحدًا فقط.', true);
            return false;
        }
        return true;
    }, onEnd: function (event) {
        const changedLists = Array.from(new Set([event.from, event.to]));
        changedLists.forEach(target => {
            const project = target.querySelector(':scope > .outline-item[data-section-type="project"]');
            if (project) target.insertBefore(project, target.querySelector(':scope > .outline-item-actions'));
        });
        const sections = changedLists.flatMap(target => {
            const moduleId = target.dataset.moduleId ? Number(target.dataset.moduleId) : null;
            return Array.from(target.querySelectorAll(':scope > .outline-item')).map((node, index) => ({id: Number(node.dataset.sectionId), order: index + 1, module_id: moduleId}));
        });
        postOrder(@json(route('admin.courses.sections.reorder', $course)), {sections}, 'تم حفظ ترتيب المحتوى');
    }}));
});
</script>
