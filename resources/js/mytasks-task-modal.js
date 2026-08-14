(() => {
    const workspace = document.querySelector('[data-workspace]');
    const modal = document.querySelector('[data-task-modal]');
    const form = modal?.querySelector('[data-task-form]');
    if (!workspace || !modal || !form) return;

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const toast = document.querySelector('[data-toast]');
    let activeRow = null;

    const notify = (message, error = false) => {
        if (!toast) return;
        toast.textContent = message;
        toast.classList.toggle('error', error);
        toast.classList.add('show');
        window.setTimeout(() => toast.classList.remove('show'), 2600);
    };

    const endpoint = (template, id) => template.replace('__ID__', id);
    const request = async (url, method, payload) => {
        const response = await fetch(url, {
            method,
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf},
            body: JSON.stringify(payload),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            const validation = data.errors ? Object.values(data.errors).flat()[0] : null;
            throw new Error(validation || data.message || 'บันทึกข้อมูลไม่สำเร็จ');
        }
        return data;
    };

    const open = (row) => {
        activeRow = row;
        form.elements.job_topic.value = row.dataset.topic || '';
        form.elements.job_details.value = row.dataset.details || '';
        form.elements.job_status.value = row.querySelector('[data-field="status"]')?.value || row.dataset.status;
        form.elements.job_priority.value = row.querySelector('[data-field="priority"]')?.value || row.dataset.priority;
        form.elements.job_due_at.value = row.querySelector('[data-field="due"]')?.value || row.dataset.due || '';
        form.elements.project.value = row.dataset.project || 'งานทั่วไป';
        form.elements.assignee.value = row.dataset.assignee || '';
        form.querySelector('[data-modal-progress]').textContent = `${row.querySelector('[data-field="progress"]')?.value || 0}%`;
        modal.hidden = false;
        document.body.classList.add('modal-open');
        requestAnimationFrame(() => form.elements.job_topic.focus());
    };

    const close = () => {
        modal.hidden = true;
        document.body.classList.remove('modal-open');
        activeRow = null;
    };

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-open-task-modal]');
        if (trigger) {
            event.preventDefault();
            const row = trigger.closest('[data-row]');
            if (row) open(row);
            return;
        }
        if (event.target === modal || event.target.closest('[data-close-task]')) close();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.hidden) close();
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!activeRow) return;
        const button = form.querySelector('[type="submit"]');
        const id = activeRow.dataset.id;
        const values = Object.fromEntries(new FormData(form));
        button.disabled = true;
        button.textContent = 'กำลังบันทึก...';
        try {
            await request(endpoint(workspace.dataset.detailsTemplate, id), 'PATCH', {
                job_topic: values.job_topic,
                job_details: values.job_details,
            });
            const jobs = [];
            const currentStatus = activeRow.querySelector('[data-field="status"]');
            const currentPriority = activeRow.querySelector('[data-field="priority"]');
            const currentDue = activeRow.querySelector('[data-field="due"]');
            if (currentStatus && currentStatus.value !== values.job_status) jobs.push(request(endpoint(workspace.dataset.statusTemplate, id), 'PATCH', {job_status: values.job_status}));
            if (currentPriority && currentPriority.value !== values.job_priority) jobs.push(request(endpoint(workspace.dataset.priorityTemplate, id), 'POST', {job_priority: values.job_priority}));
            if (currentDue && currentDue.value !== values.job_due_at) jobs.push(request(endpoint(workspace.dataset.dueTemplate, id), 'POST', {job_due_at: values.job_due_at}));
            await Promise.all(jobs);

            activeRow.dataset.topic = values.job_topic;
            activeRow.dataset.details = values.job_details;
            activeRow.dataset.status = values.job_status;
            activeRow.dataset.priority = values.job_priority;
            activeRow.dataset.due = values.job_due_at;
            activeRow.querySelector('.row-title strong').textContent = values.job_topic;
            activeRow.querySelector('.row-title small').textContent = values.job_details || 'ยังไม่มีรายละเอียดงาน';
            if (currentStatus) currentStatus.value = values.job_status;
            if (currentPriority) currentPriority.value = values.job_priority;
            if (currentDue) currentDue.value = values.job_due_at;
            notify('บันทึกการแก้ไขงานแล้ว');
            close();
        } catch (error) {
            notify(error.message, true);
        } finally {
            button.disabled = false;
            button.textContent = 'บันทึกการแก้ไข';
        }
    });
})();
