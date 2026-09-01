const modal = document.querySelector('[data-user-task-create-modal]');
const form = modal?.querySelector('[data-user-task-create-form]');

if (modal && form) {
    const project = form.querySelector('[data-user-task-project]');
    const newProject = form.querySelector('[data-user-task-new-project]');
    const projectName = form.elements.project_name;
    const errorBox = form.querySelector('[data-user-task-create-error]');
    const submit = form.querySelector('[type=submit]');
    const openButtons = document.querySelectorAll('[data-open-user-task-create]');

    const syncProjectMode = () => {
        const createsProject = !project.value;
        newProject.hidden = !createsProject;
        projectName.required = createsProject;
    };
    const close = () => {
        modal.hidden = true;
        errorBox.hidden = true;
    };
    const open = (listId = '') => {
        if (listId && [...project.options].some((option) => option.value === String(listId))) {
            project.value = String(listId);
        }
        modal.hidden = false;
        syncProjectMode();
        form.elements.job_topic.focus();
    };

    openButtons.forEach((button) => {
        button.innerHTML = '<i class=\'bi bi-plus-lg\' aria-hidden=\'true\'></i> สร้างงาน';
        button.onclick = () => open();
    });
    document.addEventListener('mytasks:create-task', (event) => open(event.detail?.listId));
    modal.querySelectorAll('[data-close-user-task-create]').forEach((button) => button.addEventListener('click', close));
    modal.addEventListener('click', (event) => {
        if (event.target === modal) close();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.hidden) close();
    });
    project.addEventListener('change', syncProjectMode);

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        errorBox.hidden = true;
        submit.disabled = true;

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                },
                body: new FormData(form),
            });
            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(Object.values(payload.errors || {}).flat()[0] || payload.message || 'สร้างงานไม่สำเร็จ');
            }

            window.location.reload();
        } catch (error) {
            errorBox.textContent = error.message;
            errorBox.hidden = false;
            submit.disabled = false;
        }
    });

    syncProjectMode();
}
