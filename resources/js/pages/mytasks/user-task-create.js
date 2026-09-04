const modal = document.querySelector('[data-user-task-create-modal]');
const form = modal?.querySelector('[data-user-task-create-form]');

if (modal && form) {
    const project = form.querySelector('[data-user-task-project]');
    const newProject = form.querySelector('[data-user-task-new-project]');
    const projectName = form.elements.project_name;
    const errorBox = form.querySelector('[data-user-task-create-error]');
    const submit = form.querySelector('[type=submit]');
    const openButtons = document.querySelectorAll('[data-open-user-task-create]');
    const details = form.querySelector('[data-user-task-details]');
    const detailsList = form.querySelector('[data-user-task-details-list]');
    const detailTemplate = form.querySelector('[data-user-task-detail-template]');

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
    details?.addEventListener('click', (event) => {
        const add = event.target.closest('[data-add-user-task-detail]');
        if (add && detailTemplate && detailsList) {
            const row = detailTemplate.content.firstElementChild.cloneNode(true);
            detailsList.append(row);
            row.querySelector('input')?.focus();
            return;
        }

        const remove = event.target.closest('[data-remove-user-task-detail]');
        if (!remove) return;
        remove.closest('[data-user-task-detail-row]')?.remove();
    });

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

            /*
             * งานที่เพิ่งสร้างจะเห็นได้ชัดที่สุดในมุมมองบอร์ด เพราะบอร์ดจัดกลุ่มตามโปรเจกต์
             * ผู้ใช้จึงเห็นรายการใหม่อยู่ใต้โปรเจกต์ที่เพิ่งเลือกไปทันที
             * ของเดิม reload() ทิ้งไว้ที่มุมมองเดิม ซึ่งถ้าเป็นมุมมองปฏิทินหรือประชุม
             * ผู้ใช้จะไม่เห็นอะไรเปลี่ยนเลยหลังกดสร้าง
             *
             * ใช้ query string ตัวเดิมของหน้าต่อ เพื่อไม่ให้ตัวกรองขอบเขตงานที่เลือกไว้หายไป
             */
            const destination = new URL(window.location.href);
            destination.searchParams.set('view', 'board');
            window.location.assign(destination);
        } catch (error) {
            errorBox.textContent = error.message;
            errorBox.hidden = false;
            submit.disabled = false;
        }
    });

    syncProjectMode();
}
