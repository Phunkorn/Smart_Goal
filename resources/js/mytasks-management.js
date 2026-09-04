import {attachmentLimits} from './pages/mytasks/attachment-store.js';
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
            if (row.dataset.listOwned === '1') {
                actions.innerHTML = `<button type="button" class="group-plus" data-add-in-group data-list-id="${row.dataset.listId}" title="เพิ่มรายการ"><i class="bi bi-plus-lg"></i></button>`;
            }
            if (row.dataset.listUpdateUrl && row.dataset.listDeleteUrl) {
                actions.innerHTML += `<details class="project-more-menu"><summary aria-label="เมนูโปรเจกต์"><i class="bi bi-three-dots"></i></summary><div><button type="button" data-edit-project data-name="${safeName}" data-url="${row.dataset.listUpdateUrl}"><i class="bi bi-pencil"></i> แก้ไขชื่อ</button><button type="button" class="danger" data-delete-project data-name="${safeName}" data-url="${row.dataset.listDeleteUrl}"><i class="bi bi-trash3"></i> ลบโปรเจกต์</button></div></details>`;
            }
            if (!actions.childElementCount) return;
            header.appendChild(actions);
        });
    };

    const groups = workspace.querySelector('[data-groups]');
    if (groups) new MutationObserver(restoreProjectActions).observe(groups, {childList: true, subtree: true});
    restoreProjectActions();
    document.addEventListener('click', async (event) => {
        const editProject = event.target.closest('[data-edit-project]');
        if (editProject) {
            const section = editProject.closest('[data-group-section], [data-project-card]');
            const oldName = editProject.dataset.name;
            const result = await Swal.fire({title: 'แก้ไขชื่อโปรเจกต์', input: 'text', inputValue: oldName, inputAttributes: {maxlength: 80}, showCancelButton: true, confirmButtonText: 'บันทึก', cancelButtonText: 'ยกเลิก', reverseButtons: true, inputValidator: (value) => value.trim() ? undefined : 'กรุณาระบุชื่อโปรเจกต์'});
            const name = result.value?.trim();
            if (!result.isConfirmed || !name || name === oldName) return;
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
            const count = Number(deleteProject.dataset.totalCount) || section.querySelectorAll('[data-row], [data-board-task]').length;
            const result = await Swal.fire({icon: 'warning', title: 'ลบโปรเจกต์นี้หรือไม่?', text: `โปรเจกต์ “${deleteProject.dataset.name}” พร้อมงาน ${count} รายการจะถูกลบ และไม่สามารถย้อนกลับได้`, showCancelButton: true, confirmButtonText: 'ลบโปรเจกต์', cancelButtonText: 'ยกเลิก', confirmButtonColor: '#dc2626', reverseButtons: true});
            if (!result.isConfirmed) return;
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
            const result = await Swal.fire({icon: 'warning', title: 'ลบรายการนี้หรือไม่?', text: `“${title}” จะถูกนำออกจากโปรเจกต์`, showCancelButton: true, confirmButtonText: 'ลบรายการ', cancelButtonText: 'ยกเลิก', confirmButtonColor: '#dc2626', reverseButtons: true});
            if (!result.isConfirmed) return;
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
                    notify('ลบรายการแล้ว');
                }
            } catch (error) { deleteTask.disabled = false; notify(error.message, false); }
        }
    });
})();

(() => {
    const workspace = document.querySelector('[data-workspace]');
    if (!workspace || workspace.dataset.context !== 'admin-member') return;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const parseError = async (response) => {
        const data = await response.json().catch(() => ({}));
        return Object.values(data.errors || {}).flat()[0] || data.message || 'ดำเนินการไฟล์แนบโปรเจกต์ไม่สำเร็จ';
    };

    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-project-attachment-delete]');
        if (!button) return;
        event.preventDefault();
        if (!window.confirm('ต้องการลบไฟล์แนบโปรเจกต์นี้ใช่หรือไม่?')) return;
        button.disabled = true;
        const response = await fetch(button.dataset.projectAttachmentDelete, {
            method: 'DELETE',
            headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest'},
        });
        if (!response.ok) {
            button.disabled = false;
            window.Swal?.fire({icon: 'error', title: 'ลบไฟล์ไม่สำเร็จ', text: await parseError(response)});
            return;
        }
        window.location.reload();
    });

    document.addEventListener('change', async (event) => {
        const input = event.target.closest('[data-project-attachment-input]');
        if (!input?.files.length) return;
        const container = input.closest('[data-project-attachments]');
        if (!container?.dataset.uploadUrl) return;
        const currentCount = Number(container.querySelector('[data-project-attachment-count]')?.textContent || 0);
        const limits = attachmentLimits(document);
        if (currentCount + input.files.length > limits.maxFiles || [...input.files].some((file) => file.size / 1024 > limits.maxKilobytes)) {
            input.value = '';
            window.Swal?.fire({icon: 'warning', title: 'ตรวจสอบจำนวนหรือขนาดไฟล์', text: `แนบได้รวมไม่เกิน ${limits.maxFiles} ไฟล์ และไฟล์ละไม่เกิน ${limits.maxSizeLabel}`});
            return;
        }
        input.disabled = true;
        const body = new FormData();
        [...input.files].forEach((file) => body.append('attachments[]', file));
        const response = await fetch(container.dataset.uploadUrl, {
            method: 'POST',
            headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest'},
            body,
        });
        if (!response.ok) {
            input.disabled = false;
            window.Swal?.fire({icon: 'error', title: 'แนบไฟล์ไม่สำเร็จ', text: await parseError(response)});
            return;
        }
        window.location.reload();
    });
})();

(() => {
    const workspace = document.querySelector('[data-workspace]');
    if (!workspace) return;

    const closeMenus = (except = null) => {
        workspace.querySelectorAll('.task-more-menu[open], .project-more-menu[open]').forEach((menu) => {
            if (menu !== except) menu.removeAttribute('open');
        });
    };

    workspace.addEventListener('click', (event) => {
        /*
         * ช่องกำหนดส่งของแถวงานใช้ตัวเลือกวันที่ของระบบชุดเดียวกับบอร์ด
         * ผูกไว้แบบ delegated ที่ document ผ่าน data-date-picker บนตัว <input> เดิม
         * จึงไม่ต้องเรียกปฏิทินของเบราว์เซอร์ที่นี่อีก และผู้ใช้ทุกบทบาทได้ปฏิทินหน้าตาเดียวกัน
         */

        const summary = event.target.closest('.task-more-menu summary, .project-more-menu summary');
        if (summary) {
            const menu = summary.parentElement;
            closeMenus(menu);
            window.setTimeout(() => {
                if (!menu.open) return;
                const rect = summary.getBoundingClientRect();
                const width = 190;
                const left = Math.max(8, Math.min(rect.right - width, window.innerWidth - width - 8));
                const menuHeight = menu.querySelector(':scope > div')?.offsetHeight || 180;
                const top = rect.bottom + menuHeight + 8 <= window.innerHeight
                    ? rect.bottom + 4
                    : Math.max(8, rect.top - menuHeight - 4);
                menu.style.setProperty('--menu-left', left + 'px');
                menu.style.setProperty('--menu-top', top + 'px');
            });
        }
        else if (!event.target.closest('.task-more-menu, .project-more-menu')) closeMenus();
    });

    workspace.addEventListener('change', (event) => {
        if (event.target.matches('[data-field="status"]')) {
            const wrapper = event.target.closest('[data-status-choice]');
            if (wrapper) {
                wrapper.classList.remove('status-unsupported', 'status-progress', 'status-review', 'status-done', 'status-paused', 'status-late');
                wrapper.classList.add({2: 'status-progress', 3: 'status-review', 4: 'status-done', 5: 'status-paused', 6: 'status-late'}[event.target.value] || 'status-unsupported');
            }
            return;
        }

        if (event.target.matches('[data-field="priority"]')) {
            const wrapper = event.target.closest('[data-priority-choice]');
            if (wrapper) {
                wrapper.classList.remove('priority-1', 'priority-2', 'priority-3', 'priority-4', 'priority-5');
                wrapper.classList.add(`priority-${event.target.value}`);
            }
            return;
        }

        if (!event.target.matches('.row-duration input[type="date"]')) return;
        const date = new Date(event.target.value + 'T00:00:00');
        const label = event.target.closest('.row-duration')?.querySelector('[data-due-label]');
        if (label && !Number.isNaN(date.getTime())) {
            const dayMonth = new Intl.DateTimeFormat('th-TH', { day: 'numeric', month: 'short' }).format(date);
            const shortBuddhistYear = String((date.getFullYear() + 543) % 100).padStart(2, '0');
            label.textContent = `${dayMonth} ${shortBuddhistYear}`;
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeMenus();
    });
})();
