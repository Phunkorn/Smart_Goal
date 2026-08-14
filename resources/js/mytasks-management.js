(() => {
    const workspace = document.querySelector('[data-workspace]');
    if (!workspace) return;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const toast = document.querySelector('[data-toast]');
    let toastTimer;

    const notify = (message, ok = true) => {
        if (!toast) return;
        toast.textContent = message;
        toast.style.background = ok ? '#172033' : '#dc2626';
        toast.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toast.classList.remove('show'), 2600);
    };

    const request = async (url, method, payload = null) => {
        const response = await fetch(url, {
            method,
            headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest'},
            body: payload ? JSON.stringify(payload) : null,
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'ดำเนินการไม่สำเร็จ');
        return data;
    };

    const restoreProjectActions = () => {
        if (workspace.querySelector('[data-group]')?.value !== 'project') return;
        workspace.querySelectorAll('[data-group-section]').forEach((section) => {
            if (section.querySelector('.project-actions')) return;
            const row = section.querySelector('[data-row][data-list-id]');
            if (!row?.dataset.listId) return;
            const header = section.querySelector('header');
            if (!header) return;
            const safeName = (row.dataset.project || '').replace(/["<>]/g, '');
            const actions = document.createElement('div');
            actions.className = 'project-actions';
            actions.innerHTML = `<button type="button" class="group-plus" data-add-in-group data-list-id="${row.dataset.listId}" title="เพิ่มรายการ"><i class="bi bi-plus-lg"></i></button>`;
            if (row.dataset.listUpdateUrl && row.dataset.listDeleteUrl) {
                actions.innerHTML += `<button type="button" data-edit-project data-name="${safeName}" data-url="${row.dataset.listUpdateUrl}" title="แก้ไขชื่อโปรเจกต์"><i class="bi bi-pencil"></i></button><button type="button" class="danger" data-delete-project data-name="${safeName}" data-url="${row.dataset.listDeleteUrl}" title="ลบโปรเจกต์"><i class="bi bi-trash3"></i></button>`;
            }
            header.appendChild(actions);
        });
    };

    new MutationObserver(restoreProjectActions).observe(workspace.querySelector('[data-groups]'), {childList: true, subtree: true});
    restoreProjectActions();
    document.addEventListener('click', async (event) => {
        const editProject = event.target.closest('[data-edit-project]');
        if (editProject) {
            const section = editProject.closest('[data-group-section], [data-project-card]');
            const oldName = editProject.dataset.name;
            const name = window.prompt('แก้ไขชื่อโปรเจกต์', oldName)?.trim();
            if (!name || name === oldName) return;
            try {
                await request(editProject.dataset.url, 'PATCH', {name});
                section.querySelector('.project-pill, .board-project-title h2').textContent = name;
                if (section.dataset.groupKey !== undefined) section.dataset.groupKey = name;
                if (section.dataset.projectName !== undefined) section.dataset.projectName = name;
                section.querySelectorAll('[data-row]').forEach((row) => {
                    row.dataset.project = name;
                    const projectCell = row.querySelector('.row-project');
                    if (projectCell) projectCell.textContent = name;
                });
                editProject.dataset.name = name;
                notify('เปลี่ยนชื่อโปรเจกต์แล้ว');
            } catch (error) { notify(error.message, false); }
            return;
        }

        const deleteProject = event.target.closest('[data-delete-project]');
        if (deleteProject) {
            const section = deleteProject.closest('[data-group-section], [data-project-card]');
            const count = section.querySelectorAll('[data-row], [data-board-task]').length;
            if (!window.confirm(`ลบโปรเจกต์ “${deleteProject.dataset.name}” พร้อมงาน ${count} รายการหรือไม่?\nการดำเนินการนี้ไม่สามารถย้อนกลับได้`)) return;
            deleteProject.disabled = true;
            try {
                await request(deleteProject.dataset.url, 'DELETE');
                section.remove();
                notify('ลบโปรเจกต์และรายการภายในแล้ว');
            } catch (error) { deleteProject.disabled = false; notify(error.message, false); }
            return;
        }

        const deleteTask = event.target.closest('[data-delete-task-row]');
        if (deleteTask) {
            const row = deleteTask.closest('[data-row]');
            const title = row.dataset.topic;
            if (!window.confirm(`ลบรายการ “${title}” ออกจากโปรเจกต์หรือไม่?`)) return;
            deleteTask.disabled = true;
            try {
                const data = await request(deleteTask.dataset.url, 'DELETE');
                if (data.delete_requested) {
                    deleteTask.disabled = true;
                    deleteTask.title = 'ส่งคำขอลบแล้ว';
                    row.classList.add('delete-pending');
                    notify(data.message || 'ส่งคำขอลบให้ผู้ดูแลระบบแล้ว');
                } else {
                    const section = row.closest('[data-group-section]');
                    row.remove();
                    const count = section?.querySelectorAll('[data-row]').length || 0;
                    const countNode = section?.querySelector('header small');
                    if (countNode) countNode.textContent = `${count} งาน`;
                    if (!count) section?.remove();
                    notify('ลบรายการแล้ว');
                }
            } catch (error) { deleteTask.disabled = false; notify(error.message, false); }
        }
    });
})();
