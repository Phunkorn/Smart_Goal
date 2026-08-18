import {projectPriorityClasses, projectPriorityMeta, statusClasses, statusMeta, taskPriorityClasses, taskPriorityMeta} from './pages/mytasks/priority-meta.js';

(() => {
    const workspace = document.querySelector('[data-workspace]');
    const board = workspace?.querySelector('[data-project-board]');
    const cardGrid = board?.querySelector('[data-board-list-body]');
    if (!workspace || !board || !cardGrid) return;

    const search = workspace.querySelector('[data-search]');
    const filter = workspace.querySelector('[data-filter]');
    const sort = workspace.querySelector('[data-sort]');
    const toast = document.querySelector('[data-toast]');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const attachmentModal = document.querySelector('[data-attachment-modal]');
    const attachmentDataNode = document.querySelector('[data-attachment-data]');
    const attachmentData = attachmentDataNode ? JSON.parse(attachmentDataNode.textContent || '{}') : {};
    const endpoint = (template, id) => template.replace('__ID__', id);
    let ascending = true;
    const statusMeta = {
        1: {className: 'status-todo', label: 'ยังไม่เริ่ม'},
        2: {className: 'status-progress', label: 'กำลังทำ'},
        3: {className: 'status-review', label: 'รอตรวจสอบ'},
        4: {className: 'status-done', label: 'เสร็จแล้ว'},
        5: {className: 'status-paused', label: 'พักงาน'},
    };
    const projectPriorityMeta = {
        1: {className: 'priority-low', tone: 'project-tone-low', label: 'ต่ำ', projectLabel: 'สำคัญ/ต่ำ'},
        2: {className: 'priority-medium', tone: 'project-tone-medium', label: 'กลาง', projectLabel: 'สำคัญ/กลาง'},
        3: {className: 'priority-high', tone: 'project-tone-high', label: 'สูง', projectLabel: 'สำคัญ/สูง'},
    };
    const taskPriorityMeta = {
        1: {className: 'priority-routine', label: 'routine'},
        2: {className: 'priority-important', label: 'สำคัญไม่ด่วน'},
        3: {className: 'priority-urgent', label: 'สำคัญด่วน'},
        4: {className: 'priority-quick', label: 'ด่วนไม่ค่อยสำคัญ'},
        5: {className: 'priority-flexible', label: 'ไม่รีบ ไม่มีกำหนด'},
    };

    const notify = (message, ok = true) => {
        if (!toast) return;
        toast.textContent = message;
        toast.style.background = ok ? '#172033' : '#dc2626';
        toast.classList.add('show');
        window.setTimeout(() => toast.classList.remove('show'), 2400);
    };

    const request = async (url, method, payload) => {
        const response = await fetch(url, {
            method,
            headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest'},
            body: JSON.stringify(payload),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'บันทึกไม่สำเร็จ');
        return data;
    };

    const tasksForProject = (header) => header
        ? [...cardGrid.querySelectorAll('[data-board-task]')].filter((task) => task.dataset.projectKey === header.dataset.projectKey)
        : [];

    const headerForTask = (task) => [...cardGrid.querySelectorAll('[data-project-header]')]
        .find((header) => header.dataset.projectKey === task.dataset.projectKey);

    const closeStatusMenus = (except = null) => {
        board.querySelectorAll('[data-board-status-menu][open], [data-board-priority-menu][open], [data-project-priority-menu][open]').forEach((menu) => {
            if (menu !== except) menu.removeAttribute('open');
        });
    };

    const positionStatusMenu = (menu) => {
        const summary = menu.querySelector('summary');
        if (!summary) return;
        const rect = summary.getBoundingClientRect();
        const menuWidth = 164;
        const menuHeight = 220;
        const left = Math.max(8, Math.min(rect.left, window.innerWidth - menuWidth - 8));
        const top = rect.bottom + menuHeight <= window.innerHeight - 8
            ? rect.bottom + 6
            : Math.max(8, rect.top - menuHeight - 6);
        menu.style.setProperty('--status-menu-left', `${left}px`);
        menu.style.setProperty('--status-menu-top', `${top}px`);
    };

    const uploadAttachments = async (input) => {
        const files = [...(input.files || [])];
        if (!files.length) return;

        const existingCount = Number(input.dataset.existingCount || 0);
        if (existingCount + files.length > 5) {
            input.value = '';
            notify(`แนบได้รวมไม่เกิน 5 ไฟล์ (ขณะนี้มี ${existingCount} ไฟล์)`, false);
            return;
        }

        const oversized = files.find((file) => file.size > 10 * 1024 * 1024);
        if (oversized) {
            input.value = '';
            notify(`ไฟล์ “${oversized.name}” มีขนาดเกิน 10 MB`, false);
            return;
        }

        const menu = input.closest('.board-task-menu');
        const trigger = menu?.querySelector('[data-board-pick-attachment]');
        const formData = new FormData();
        files.forEach((file) => formData.append('completion_attachments[]', file));
        if (trigger) trigger.disabled = true;

        try {
            const response = await fetch(input.dataset.url, {
                method: 'POST',
                headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest'},
                body: formData,
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'แนบไฟล์ไม่สำเร็จ');
            notify('แนบไฟล์เรียบร้อยแล้ว');
            window.setTimeout(() => window.location.reload(), 450);
        } catch (error) {
            notify(error.message, false);
            if (trigger) trigger.disabled = false;
            input.value = '';
        }
    };

    const closeAttachmentModal = () => {
        if (!attachmentModal) return;
        attachmentModal.hidden = true;
        document.body.style.overflow = '';
    };

    const openAttachmentModal = (taskId) => {
        const data = attachmentData[String(taskId)];
        if (!attachmentModal || !data) return;
        const list = attachmentModal.querySelector('[data-attachment-list]');
        const empty = attachmentModal.querySelector('[data-attachment-empty]');
        const upload = attachmentModal.querySelector('[data-attachment-upload]');
        const input = attachmentModal.querySelector('[data-modal-attachment-input]');
        attachmentModal.querySelector('[data-attachment-topic]').textContent = data.topic || '';
        list.replaceChildren();
        (data.files || []).forEach((file) => {
            const link = document.createElement('a');
            link.href = file.url;
            link.target = '_blank';
            link.rel = 'noopener';
            const icon = document.createElement('i');
            icon.className = 'bi bi-file-earmark';
            const name = document.createElement('span');
            name.textContent = file.name;
            const open = document.createElement('i');
            open.className = 'bi bi-box-arrow-up-right';
            link.append(icon, name, open);
            list.append(link);
        });
        empty.hidden = (data.files || []).length > 0;
        upload.hidden = !data.can_upload;
        input.dataset.url = data.upload_url;
        input.dataset.existingCount = String((data.files || []).length);
        input.value = '';
        attachmentModal.hidden = false;
        document.body.style.overflow = 'hidden';
    };

    const filterBoard = () => {
        const query = search.value.trim().toLowerCase();
        const status = filter.value;
        let visibleTasks = 0;

        board.querySelectorAll('[data-board-task]').forEach((task) => {
            const searchable = `${task.dataset.projectName || ''} ${task.textContent}`.toLowerCase();
            const textMatch = !query || searchable.includes(query);
            const statusMatch = !status || (status === 'late' ? task.dataset.late === '1' : task.dataset.status === status);
            task.hidden = !(textMatch && statusMatch);
            if (!task.hidden) visibleTasks++;
        });

        board.querySelectorAll('[data-project-header]').forEach((header) => {
            const projectTasks = tasksForProject(header);
            const visibleInProject = projectTasks.filter((task) => !task.hidden).length;
            const emptyProjectMatch = projectTasks.length === 0 && !status && (!query || (header.dataset.projectName || '').toLowerCase().includes(query));
            header.hidden = visibleInProject === 0 && !emptyProjectMatch;
            const count = header.querySelector('[data-board-visible-count]');
            if (count) count.textContent = visibleInProject;
        });

        const empty = board.querySelector('[data-board-empty]');
        if (empty) empty.hidden = visibleTasks > 0;
    };

    search.addEventListener('input', filterBoard);
    filter.addEventListener('change', filterBoard);
    workspace.querySelectorAll('[data-summary-filter]').forEach((button) => button.addEventListener('click', () => setTimeout(filterBoard)));
    sort?.addEventListener('click', () => {
        ascending = !ascending;
        const groups = [...cardGrid.querySelectorAll('[data-project-header]')].map((header) => ({
            header,
            tasks: tasksForProject(header).sort((first, second) => ascending
                ? (first.dataset.due || '9999-12-31').localeCompare(second.dataset.due || '9999-12-31')
                : (second.dataset.due || '').localeCompare(first.dataset.due || '')),
        }));

        groups.sort((first, second) => {
                const firstDue = first.tasks[0]?.dataset.due || '9999-12-31';
                const secondDue = second.tasks[0]?.dataset.due || '9999-12-31';
                return ascending ? firstDue.localeCompare(secondDue) : secondDue.localeCompare(firstDue);
            })
            .forEach(({header, tasks}) => cardGrid.append(header, ...tasks));
    });

    document.addEventListener('click', async (event) => {
        const attachmentOpen = event.target.closest('[data-open-attachments]');
        if (attachmentOpen) {
            event.preventDefault();
            openAttachmentModal(attachmentOpen.dataset.openAttachments);
            return;
        }
        if (event.target.closest('[data-close-attachments]') || event.target === attachmentModal) {
            closeAttachmentModal();
            return;
        }
        const statusSummary = event.target.closest('[data-board-status-menu] > summary, [data-board-priority-menu] > summary, [data-project-priority-menu] > summary');
        if (statusSummary) {
            const menu = statusSummary.closest('[data-board-status-menu]');
            const wasOpen = menu.hasAttribute('open');
            event.preventDefault();
            closeStatusMenus();
            if (!wasOpen) {
                menu.setAttribute('open', '');
                positionStatusMenu(menu);
            }
            return;
        }

        const projectPriorityOption = event.target.closest('[data-project-priority-value]');
        if (projectPriorityOption) {
            const menu = projectPriorityOption.closest('[data-project-priority-menu]');
            const header = projectPriorityOption.closest('[data-project-header]');
            const value = Number(projectPriorityOption.dataset.projectPriorityValue);
            const meta = projectPriorityMeta[value];
            if (!menu || !header || !meta) return;
            projectPriorityOption.disabled = true;
            request(menu.dataset.url, 'PATCH', {priority: value}).then(() => {
                header.classList.remove('project-tone-low', 'project-tone-medium', 'project-tone-high');
                header.classList.add(meta.tone);
                const summary = menu.querySelector('summary');
                summary.classList.remove(...projectPriorityClasses);
                summary.classList.add(meta.className);
                summary.querySelector('[data-project-priority-label]').textContent = meta.projectLabel;
                menu.querySelectorAll('[data-project-priority-value] .bi-check2').forEach((check) => check.remove());
                projectPriorityOption.insertAdjacentHTML('beforeend', '<span class="bi bi-check2"></span>');
                menu.removeAttribute('open');
                notify('เปลี่ยนความสำคัญโปรเจกต์แล้ว');
            }).catch((error) => notify(error.message, false)).finally(() => projectPriorityOption.disabled = false);
            return;
        }

        const taskPriorityOption = event.target.closest('[data-board-priority-value]');
        if (taskPriorityOption) {
            const menu = taskPriorityOption.closest('[data-board-priority-menu]');
            const task = taskPriorityOption.closest('[data-board-task]');
            const value = Number(taskPriorityOption.dataset.boardPriorityValue);
            const meta = taskPriorityMeta[value];
            if (!menu || !task || !meta) return;
            taskPriorityOption.disabled = true;
            request(endpoint(workspace.dataset.priorityTemplate, task.dataset.taskId), 'POST', {job_priority: value}).then(() => {
                task.classList.remove('task-priority-routine', 'task-priority-important', 'task-priority-urgent', 'task-priority-quick', 'task-priority-flexible');
                task.classList.add(`task-${meta.className}`);
                const summary = menu.querySelector('summary');
                summary.classList.remove(...taskPriorityClasses);
                summary.classList.add(meta.className);
                summary.querySelector('[data-board-priority-label]').textContent = meta.label;
                menu.querySelectorAll('[data-board-priority-value] .bi-check2').forEach((check) => check.remove());
                taskPriorityOption.insertAdjacentHTML('beforeend', '<span class="bi bi-check2"></span>');
                menu.removeAttribute('open');
                notify('เปลี่ยนความสำคัญงานแล้ว');
            }).catch((error) => notify(error.message, false)).finally(() => taskPriorityOption.disabled = false);
            return;
        }

        const statusOption = event.target.closest('[data-board-status-value]');
        if (statusOption) {
            const menu = statusOption.closest('[data-board-status-menu]');
            const task = statusOption.closest('[data-board-task]');
            const value = Number(statusOption.dataset.boardStatusValue);
            const meta = statusMeta[value];
            if (!menu || !task || !meta) return;
            statusOption.disabled = true;
            request(endpoint(workspace.dataset.statusTemplate, task.dataset.taskId), 'PATCH', {job_status: value}).then(() => {
                task.dataset.status = String(value);
                task.dataset.late = '0';
                const summary = menu.querySelector('summary');
                summary.classList.remove(...statusClasses);
                summary.classList.add(meta.className);
                const label = summary.querySelector('[data-board-status-label]');
                if (label) label.textContent = meta.label;
                menu.querySelectorAll('[data-board-status-value] .bi-check2').forEach((check) => check.remove());
                statusOption.insertAdjacentHTML('beforeend', '<span class="bi bi-check2"></span>');
                menu.removeAttribute('open');
                if (value === 4) {
                    const progress = task.querySelector('.board-progress');
                    const bar = progress?.querySelector('b');
                    const text = progress?.querySelector('strong');
                    if (bar) bar.style.width = '100%';
                    if (text) text.textContent = '100%';
                }
                notify('เปลี่ยนสถานะงานแล้ว');
                filterBoard();
            }).catch((error) => notify(error.message, false)).finally(() => statusOption.disabled = false);
            return;
        }

        const attachmentTrigger = event.target.closest('[data-board-pick-attachment]');
        if (attachmentTrigger) {
            const input = attachmentTrigger.closest('.board-task-menu')?.querySelector('[data-board-attachment-input]');
            input?.click();
            return;
        }

        if (!event.target.closest('[data-board-status-menu]')) closeStatusMenus();

        const dueControl = event.target.closest('.board-due-editable');
        if (dueControl && !event.target.matches('input')) {
            const input = dueControl.querySelector('input[type="date"]');
            input?.showPicker?.();
            input?.focus();
        }

        const collapse = event.target.closest('[data-board-collapse]');
        if (collapse) {
            const header = collapse.closest('[data-project-header]');
            const collapsed = header?.classList.toggle('is-collapsed');
            tasksForProject(header).forEach((task) => task.classList.toggle('is-project-collapsed', collapsed));
            collapse.setAttribute('aria-expanded', String(!collapsed));
            return;
        }

        const editProject = event.target.closest('[data-board-edit-project]');
        if (editProject) {
            const header = editProject.closest('[data-project-header]');
            const result = await Swal.fire({title: 'แก้ไขชื่อโปรเจกต์', input: 'text', inputValue: editProject.dataset.name, inputAttributes: {maxlength: 80}, showCancelButton: true, confirmButtonText: 'บันทึก', cancelButtonText: 'ยกเลิก', reverseButtons: true, inputValidator: (value) => value.trim() ? undefined : 'กรุณาระบุชื่อโปรเจกต์'});
            const name = result.value?.trim();
            if (!result.isConfirmed || !name || name === editProject.dataset.name) return;
            editProject.disabled = true;
            request(editProject.dataset.url, 'PATCH', {name}).then(() => {
                header.querySelector(':scope > strong').textContent = name;
                header.dataset.projectName = name;
                tasksForProject(header).forEach((task) => task.dataset.projectName = name);
                editProject.dataset.name = name;
                const deleteProject = header.querySelector('[data-board-delete-project]');
                if (deleteProject) deleteProject.dataset.name = name;
                notify('แก้ไขชื่อโปรเจกต์แล้ว');
            }).catch((error) => notify(error.message, false)).finally(() => editProject.disabled = false);
            return;
        }

        const deleteProject = event.target.closest('[data-board-delete-project]');
        if (deleteProject) {
            const header = deleteProject.closest('[data-project-header]');
            if (!header) return;
            const result = await Swal.fire({icon: 'warning', title: 'ลบโปรเจกต์นี้หรือไม่?', text: `โปรเจกต์ “${deleteProject.dataset.name}” และงานทั้งหมดภายในจะถูกลบ`, showCancelButton: true, confirmButtonText: 'ลบโปรเจกต์', cancelButtonText: 'ยกเลิก', confirmButtonColor: '#dc2626', reverseButtons: true});
            if (!result.isConfirmed) return;
            deleteProject.disabled = true;
            fetch(deleteProject.dataset.url, {
                method: 'DELETE',
                headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest'},
            }).then(async (response) => {
                const data = await response.json().catch(() => ({}));
                if (!response.ok) throw new Error(data.message || 'ลบโปรเจกต์ไม่สำเร็จ');
                tasksForProject(header).forEach((task) => task.remove());
                header.remove();
                notify('ลบโปรเจกต์แล้ว');
            }).catch((error) => {
                deleteProject.disabled = false;
                notify(error.message, false);
            });
            return;
        }

        const deleteTask = event.target.closest('[data-board-delete-task]');
        if (deleteTask) {
            const task = deleteTask.closest('[data-board-task]');
            const projectHeader = task ? headerForTask(task) : null;
            if (!task) return;
            const result = await Swal.fire({icon: 'warning', title: 'ลบรายการนี้หรือไม่?', text: `“${task.dataset.topic}” จะถูกนำออกจากโปรเจกต์`, showCancelButton: true, confirmButtonText: 'ลบรายการ', cancelButtonText: 'ยกเลิก', confirmButtonColor: '#dc2626', reverseButtons: true});
            if (!result.isConfirmed) return;
            deleteTask.disabled = true;
            fetch(deleteTask.dataset.url, {
                method: 'DELETE',
                headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest'},
            }).then(async (response) => {
                const data = await response.json().catch(() => ({}));
                if (!response.ok) throw new Error(data.message || 'ลบงานไม่สำเร็จ');
                if (data.delete_requested) {
                    notify(data.message || 'ส่งคำขอลบให้ผู้ดูแลระบบแล้ว');
                    return;
                }
                task.remove();
                const remaining = tasksForProject(projectHeader).length;
                const count = projectHeader?.querySelector('[data-board-visible-count]');
                if (count) {
                    count.textContent = remaining;
                    count.dataset.boardTotalCount = remaining;
                }
                if (!remaining) projectHeader?.remove();
                notify('ลบงานแล้ว');
            }).catch((error) => {
                deleteTask.disabled = false;
                notify(error.message, false);
            });
            return;
        }

        const trigger = event.target.closest('[data-board-open-task]');
        if (!trigger) return;
        const row = workspace.querySelector(`[data-row][data-id="${trigger.dataset.boardOpenTask}"]`);
        row?.querySelector('[data-open-task-modal]')?.click();
    });

    board.addEventListener('change', async (event) => {
        const attachmentInput = event.target.closest('[data-board-attachment-input]');
        if (attachmentInput) {
            await uploadAttachments(attachmentInput);
            return;
        }

        const control = event.target.closest('[data-board-field]');
        const task = control?.closest('[data-board-task]');
        if (!control || !task) return;
        const field = control.dataset.boardField;
        const id = task.dataset.taskId;
        control.disabled = true;

        try {
            if (field === 'status') {
                await request(endpoint(workspace.dataset.statusTemplate, id), 'PATCH', {job_status: Number(control.value)});
                task.dataset.status = control.value;
                task.dataset.late = '0';
                const wrapper = control.closest('[data-board-status-choice]');
                wrapper.classList.remove('status-todo', 'status-progress', 'status-review', 'status-done', 'status-paused', 'status-late');
                wrapper.classList.add({1:'status-todo',2:'status-progress',3:'status-review',4:'status-done',5:'status-paused'}[control.value] || 'status-todo');
                if (control.value === '4') {
                    const progress = task.querySelector('.board-progress');
                    progress.querySelector('b').style.width = '100%';
                    progress.querySelector('strong').textContent = '100%';
                }
            } else if (field === 'priority') {
                await request(endpoint(workspace.dataset.priorityTemplate, id), 'POST', {job_priority: Number(control.value)});
                const wrapper = control.closest('[data-board-priority-choice]');
                wrapper.classList.remove('priority-low', 'priority-medium', 'priority-high');
                wrapper.classList.add({1:'priority-low',2:'priority-medium',3:'priority-high'}[control.value] || 'priority-medium');
            } else if (field === 'due') {
                await request(endpoint(workspace.dataset.dueTemplate, id), 'POST', {job_due_at: control.value});
                task.dataset.due = control.value;
                const date = new Date(`${control.value}T00:00:00`);
                const label = control.closest('.board-due')?.querySelector('[data-board-due-label]');
                if (label && !Number.isNaN(date.getTime())) label.textContent = new Intl.DateTimeFormat('th-TH', {day:'numeric', month:'short', year:'numeric'}).format(date);
            }
            notify('บันทึกการเปลี่ยนแปลงแล้ว');
            filterBoard();
        } catch (error) {
            notify(error.message, false);
            window.location.reload();
        } finally {
            control.disabled = false;
        }
    });

    attachmentModal?.addEventListener('change', async (event) => {
        const input = event.target.closest('[data-modal-attachment-input]');
        if (input) await uploadAttachments(input);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && attachmentModal && !attachmentModal.hidden) closeAttachmentModal();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeStatusMenus();
    });

    filterBoard();
})();
