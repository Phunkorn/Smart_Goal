import {projectPriorityClasses, statusClasses, taskPriorityClasses, unsupportedStatusMeta} from './pages/mytasks/priority-meta.js';
import {
    boardFloatingMenuSelector,
    boardFloatingMenuSummarySelector,
    calculateBoardFloatingMenuPosition,
    resolveBoardFloatingMenu,
} from './pages/mytasks/board-floating-menu.js';
import {boardFilterStateFrom, boardTaskMatches, parametersForTaskWorkspace} from './pages/mytasks/task-filter-state.js';
import {synchronizeTaskSource} from './pages/mytasks/task-state.js';
import {canTransitionTo, confirmTaskTransition} from './pages/mytasks/task-transitions.js';

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
    const attachmentModal = document.querySelector('[data-board-attachment-modal]');
    const attachmentDataNode = document.querySelector('[data-attachment-data]');
    const attachmentData = attachmentDataNode ? JSON.parse(attachmentDataNode.textContent || '{}') : {};
    const management = JSON.parse(document.querySelector('[data-task-management-data]')?.textContent || '{}');

    const refreshStatusControls = (task) => {
        const capabilities = management[String(task.dataset.taskId)]?.transitions || {};
        task.querySelectorAll('[data-board-status-value]').forEach((button) => {
            button.disabled = !canTransitionTo(Number(task.dataset.status), Number(button.dataset.boardStatusValue), capabilities);
        });
    };

    board.querySelectorAll('[data-board-task]').forEach(refreshStatusControls);
    const endpoint = (template, id) => template.replace('__ID__', id);
    const persistFilterState = workspace.dataset.context === 'user';
    const initialFilterState = persistFilterState
        ? boardFilterStateFrom(new URLSearchParams(window.location.search))
        : {search: '', status: '', dueSort: ''};
    if (search) search.value = initialFilterState.search;
    if (filter) filter.value = initialFilterState.status;
    let dueSort = initialFilterState.dueSort;
    let ascending = dueSort !== 'desc';
    const statusMeta = {
        2: {className: 'status-progress', label: 'กำลังทำ'},
        3: {className: 'status-review', label: 'รอตรวจสอบ'},
        4: {className: 'status-done', label: 'เสร็จแล้ว'},
        5: {className: 'status-paused', label: 'พักงาน'},
        6: {className: 'status-late', label: 'ล่าช้า'},
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

    const applyTaskChange = (change) => {
        const task = board.querySelector(`[data-board-task][data-task-id='${CSS.escape(String(change.id))}']`);
        if (!task) return;

        if (Object.hasOwn(change, 'topic')) {
            task.dataset.topic = String(change.topic || '');
            const title = task.querySelector('.board-reference-task__title');
            if (title) title.textContent = task.dataset.topic;
        }
        if (Object.hasOwn(change, 'due')) task.dataset.due = String(change.due || '');

        if (Object.hasOwn(change, 'priority') && taskPriorityMeta[Number(change.priority)]) {
            const priority = Number(change.priority);
            const meta = taskPriorityMeta[priority];
            task.dataset.priority = String(priority);
            task.classList.remove('task-priority-routine', 'task-priority-important', 'task-priority-urgent', 'task-priority-quick', 'task-priority-flexible');
            task.classList.add(`task-${meta.className}`);
            const summary = task.querySelector('[data-board-priority-menu] > summary');
            summary?.classList.remove(...taskPriorityClasses);
            summary?.classList.add(meta.className);
            const label = summary?.querySelector('[data-board-priority-label]');
            if (label) label.textContent = meta.label;
        }

        if (Object.hasOwn(change, 'status') && statusMeta[Number(change.status)]) {
            const status = Number(change.status);
            const meta = statusMeta[status];
            task.dataset.status = String(status);
            task.dataset.late = status === 6 ? '1' : '0';
            const summary = task.querySelector('[data-board-status-menu] > summary');
            summary?.classList.remove(...statusClasses);
            summary?.classList.add(meta.className);
            const label = summary?.querySelector('[data-board-status-label]');
            if (label) label.textContent = meta.label;
        }

        refreshStatusControls(task);
        filterBoard(false);
    };

    const tasksForProject = (header) => header
        ? [...cardGrid.querySelectorAll('[data-board-task]')].filter((task) => task.dataset.projectKey === header.dataset.projectKey)
        : [];

    const headerForTask = (task) => [...cardGrid.querySelectorAll('[data-project-header]')]
        .find((header) => header.dataset.projectKey === task.dataset.projectKey);

    const closeBoardMenu = (menu) => {
        if (!menu) return;
        menu.removeAttribute('open');
        menu.style.removeProperty('--floating-menu-left');
        menu.style.removeProperty('--floating-menu-top');
    };

    const closeBoardMenus = (except = null) => {
        board.querySelectorAll(boardFloatingMenuSelector).forEach((menu) => {
            if (menu !== except && menu.hasAttribute('open')) closeBoardMenu(menu);
        });
    };

    const positionBoardFloatingMenu = (menu) => {
        const summary = menu.querySelector(':scope > summary');
        const panel = menu.querySelector(':scope > div');
        if (!summary || !panel) return false;

        const position = calculateBoardFloatingMenuPosition(
            summary.getBoundingClientRect(),
            panel.getBoundingClientRect(),
            {width: window.innerWidth, height: window.innerHeight},
            {align: menu.matches('.board-reference-menu') ? 'end' : 'start'},
        );
        menu.style.setProperty('--floating-menu-left', position.left + 'px');
        menu.style.setProperty('--floating-menu-top', position.top + 'px');

        return true;
    };

    const closeMenusForViewportChange = () => closeBoardMenus();
    const closeMenusForScroll = (event) => {
        if (event.target?.closest?.(boardFloatingMenuSelector)) return;
        closeBoardMenus();
    };
    window.addEventListener('resize', closeMenusForViewportChange, {passive: true});
    window.addEventListener('scroll', closeMenusForScroll, {capture: true, passive: true});
    document.addEventListener('mytasks:viewchange', closeMenusForViewportChange);

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
        const list = attachmentModal.querySelector('[data-board-attachment-list]');
        const empty = attachmentModal.querySelector('[data-board-attachment-empty]');
        const upload = attachmentModal.querySelector('[data-board-attachment-upload]');
        const input = attachmentModal.querySelector('[data-board-modal-attachment-input]');
        attachmentModal.querySelector('[data-board-attachment-topic]').textContent = data.topic || '';
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

    const currentFilterState = () => ({
        search: search?.value || '',
        status: filter?.value || '',
        dueSort,
    });

    const synchronizeFilterUrl = () => {
        if (!persistFilterState) return;
        const url = new URL(window.location.href);
        url.search = parametersForTaskWorkspace(
            url.searchParams,
            currentFilterState(),
            workspace.dataset.taskScope || 'all',
        ).toString();
        window.history.replaceState({}, '', url);
    };

    const filterBoard = (synchronizeUrl = true) => {
        const state = currentFilterState();
        const query = state.search.trim().toLowerCase();
        const status = state.status;
        let visibleTasks = 0;

        board.querySelectorAll('[data-board-task]').forEach((task) => {
            task.hidden = !boardTaskMatches({
                searchable: (task.dataset.projectName || '') + ' ' + task.textContent,
                status: task.dataset.status,
                late: task.dataset.late,
            }, state);
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
        if (synchronizeUrl) synchronizeFilterUrl();
    };

    const sortBoard = () => {
        const groups = [...cardGrid.querySelectorAll('[data-project-header]')].map((header) => ({
            header,
            tasks: tasksForProject(header).sort((first, second) => ascending
                ? (first.dataset.due || '9999-12-31').localeCompare(second.dataset.due || '9999-12-31')
                : (second.dataset.due || '').localeCompare(first.dataset.due || '')),
            completedGroup: [...cardGrid.querySelectorAll('[data-completed-group]')]
                .find((group) => group.dataset.projectKey === header.dataset.projectKey),
        }));

        groups.sort((first, second) => {
                const firstDue = first.tasks[0]?.dataset.due || '9999-12-31';
                const secondDue = second.tasks[0]?.dataset.due || '9999-12-31';
                return ascending ? firstDue.localeCompare(secondDue) : secondDue.localeCompare(firstDue);
            })
            .forEach(({header, tasks, completedGroup}) => {
                const activeTasks = tasks.filter((task) => !task.closest('[data-completed-group]'));
                const completedTasks = tasks.filter((task) => task.closest('[data-completed-group]'));
                const completedRows = completedGroup?.querySelector('.board-completed-group__rows');

                if (completedRows) completedRows.append(...completedTasks);
                cardGrid.append(header, ...activeTasks);
                if (completedGroup) cardGrid.append(completedGroup);
            });
    };

    search?.addEventListener('input', () => filterBoard());
    filter?.addEventListener('change', () => filterBoard());
    workspace.querySelectorAll('[data-summary-filter]').forEach((button) => button.addEventListener('click', () => setTimeout(filterBoard)));
    sort?.addEventListener('click', () => {
        ascending = !ascending;
        dueSort = ascending ? 'asc' : 'desc';
        sortBoard();
        synchronizeFilterUrl();
    });

    if (dueSort) sortBoard();
    filterBoard(false);
    document.addEventListener('mytasks:changed', (event) => applyTaskChange(event.detail || {}));

    document.addEventListener('click', async (event) => {
        const nativeSummary = event.target.closest('summary');
        if (nativeSummary && board.contains(nativeSummary) && !nativeSummary.matches(boardFloatingMenuSummarySelector)) {
            closeBoardMenus();
            return;
        }

        if (!event.target.closest(boardFloatingMenuSelector)) closeBoardMenus();

        const attachmentOpen = event.target.closest('[data-board-open-attachments]');
        if (attachmentOpen) {
            event.preventDefault();
            openAttachmentModal(attachmentOpen.dataset.boardOpenAttachments);
            return;
        }
        if (event.target.closest('[data-close-board-attachments]') || event.target === attachmentModal) {
            closeAttachmentModal();
            return;
        }
        const menuSummary = event.target.closest(boardFloatingMenuSummarySelector);
        if (menuSummary && board.contains(menuSummary)) {
            const menu = resolveBoardFloatingMenu(menuSummary);
            if (!menu || !board.contains(menu)) return;
            const wasOpen = menu.hasAttribute('open');
            event.preventDefault();
            closeBoardMenus(menu);
            if (!wasOpen) {
                menu.setAttribute('open', '');
                if (!positionBoardFloatingMenu(menu)) closeBoardMenu(menu);
            } else {
                closeBoardMenu(menu);
            }
            return;
        }

        const manageMenuAction = event.target.closest('.board-reference-menu > div button, .board-reference-menu > div a');
        if (manageMenuAction) closeBoardMenu(manageMenuAction.closest('.board-reference-menu'));

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
                closeBoardMenu(menu);
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
                closeBoardMenu(menu);
                task.dataset.priority = String(value);
                synchronizeTaskSource(workspace, task.dataset.taskId, {priority: value});
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
            const payload = await confirmTaskTransition(Number(task.dataset.status), value, management[String(task.dataset.taskId)]?.transitions || {});
            if (!payload) { statusOption.disabled = false; return; }
            request(endpoint(workspace.dataset.statusTemplate, task.dataset.taskId), 'PATCH', payload).then((data) => {
                if (data.transitions) management[String(task.dataset.taskId)].transitions = data.transitions;
                const actualStatus = Number(data.job_status ?? value);
                const actualMeta = statusMeta[actualStatus] || meta;
                task.dataset.status = String(actualStatus);
                task.dataset.late = actualStatus === 6 ? '1' : '0';
                const summary = menu.querySelector('summary');
                summary.classList.remove(...statusClasses);
                summary.classList.add(actualMeta.className);
                const label = summary.querySelector('[data-board-status-label]');
                if (label) label.textContent = actualMeta.label;
                menu.querySelectorAll('[data-board-status-value] .bi-check2').forEach((check) => check.remove());
                statusOption.insertAdjacentHTML('beforeend', '<span class="bi bi-check2"></span>');
                closeBoardMenu(menu);
                synchronizeTaskSource(workspace, task.dataset.taskId, {status: actualStatus});
                refreshStatusControls(task);
                notify('เปลี่ยนสถานะงานแล้ว');
                filterBoard();
            }).catch((error) => notify(error.message, false)).finally(() => refreshStatusControls(task));
            return;
        }

        const attachmentTrigger = event.target.closest('[data-board-pick-attachment]');
        if (attachmentTrigger) {
            const input = attachmentTrigger.closest('.board-task-menu')?.querySelector('[data-board-attachment-input]');
            input?.click();
            return;
        }

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
            board.querySelector(`[data-completed-group][data-project-key="${CSS.escape(header.dataset.projectKey)}"]`)?.classList.toggle('is-project-collapsed', collapsed);
            collapse.setAttribute('aria-expanded', String(!collapsed));
            return;
        }

        const editProject = event.target.closest('[data-board-edit-project]');
        if (editProject) {
            const header = editProject.closest('[data-project-header]')
                || [...cardGrid.querySelectorAll('[data-project-header]')].find((candidate) => candidate.dataset.projectKey === editProject.dataset.projectKey);
            if (!header) return;
            const result = await Swal.fire({title: 'แก้ไขชื่อโปรเจกต์', input: 'text', inputValue: editProject.dataset.name, inputAttributes: {maxlength: 80}, showCancelButton: true, confirmButtonText: 'บันทึก', cancelButtonText: 'ยกเลิก', reverseButtons: true, inputValidator: (value) => value.trim() ? undefined : 'กรุณาระบุชื่อโปรเจกต์'});
            const name = result.value?.trim();
            if (!result.isConfirmed || !name || name === editProject.dataset.name) return;
            editProject.disabled = true;
            request(editProject.dataset.url, 'PATCH', {name}).then(() => {
                header.querySelector(':scope > strong').textContent = name;
                header.dataset.projectName = name;
                tasksForProject(header).forEach((task) => task.dataset.projectName = name);
                cardGrid.querySelectorAll('[data-board-edit-project]').forEach((button) => {
                    if (!button.dataset.projectKey || button.dataset.projectKey === header.dataset.projectKey) button.dataset.name = name;
                });
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
            const total = Number(deleteProject.dataset.totalCount) || tasksForProject(header).length;
            const result = await Swal.fire({icon: 'warning', title: 'ลบโปรเจกต์นี้หรือไม่?', text: `โปรเจกต์ “${deleteProject.dataset.name}” พร้อมงานทั้งหมด ${total} รายการจะถูกลบ`, showCancelButton: true, confirmButtonText: 'ลบโปรเจกต์', cancelButtonText: 'ยกเลิก', confirmButtonColor: '#dc2626', reverseButtons: true});
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

        const renameTask = event.target.closest('[data-board-rename-task]');
        if (renameTask) {
            const task = renameTask.closest('[data-board-task]');
            if (!task) return;
            const result = await Swal.fire({title: 'แก้ไขชื่อรายการงาน', input: 'text', inputValue: renameTask.dataset.name, inputAttributes: {maxlength: 255}, showCancelButton: true, confirmButtonText: 'บันทึก', cancelButtonText: 'ยกเลิก', reverseButtons: true, inputValidator: (value) => value.trim() ? undefined : 'กรุณาระบุชื่อรายการงาน'});
            const name = result.value?.trim();
            if (!result.isConfirmed || !name || name === renameTask.dataset.name) return;
            renameTask.disabled = true;
            request(renameTask.dataset.url, 'PATCH', {job_topic: name}).then(() => {
                task.dataset.topic = name;
                const title = task.querySelector('.board-reference-task__title');
                if (title) title.textContent = name;
                renameTask.dataset.name = name;
                synchronizeTaskSource(workspace, task.dataset.taskId, {topic: name});
                notify('แก้ไขชื่อรายการงานแล้ว');
            }).catch((error) => notify(error.message, false)).finally(() => renameTask.disabled = false);
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
                const data = await request(endpoint(workspace.dataset.statusTemplate, id), 'PATCH', {job_status: Number(control.value)});
                if (data.transitions) management[String(id)].transitions = data.transitions;
                const actualStatus = Number(data.job_status ?? control.value);
                task.dataset.status = String(actualStatus);
                task.dataset.late = actualStatus === 6 ? '1' : '0';
                const wrapper = control.closest('[data-board-status-choice]');
                wrapper.classList.remove(...statusClasses);
                wrapper.classList.add(statusMeta[actualStatus]?.className || unsupportedStatusMeta.className);
                synchronizeTaskSource(workspace, id, {status: actualStatus});
            } else if (field === 'priority') {
                await request(endpoint(workspace.dataset.priorityTemplate, id), 'POST', {job_priority: Number(control.value)});
                task.dataset.priority = control.value;
                const wrapper = control.closest('[data-board-priority-choice]');
                wrapper.classList.remove('priority-low', 'priority-medium', 'priority-high');
                wrapper.classList.add({1:'priority-low',2:'priority-medium',3:'priority-high'}[control.value] || 'priority-medium');
                synchronizeTaskSource(workspace, id, {priority: Number(control.value)});
            } else if (field === 'due') {
                const data = await request(endpoint(workspace.dataset.dueTemplate, id), 'POST', {job_due_at: control.value});
                if (data.transitions) management[String(id)].transitions = data.transitions;
                task.dataset.due = data.job_due_at ?? control.value;
                const date = new Date(`${control.value}T00:00:00`);
                const label = control.closest('.board-due')?.querySelector('[data-board-due-label]');
                if (label && !Number.isNaN(date.getTime())) label.textContent = new Intl.DateTimeFormat('th-TH', {day:'numeric', month:'short', year:'numeric'}).format(date);
                synchronizeTaskSource(workspace, id, {due: task.dataset.due, status: Number(data.job_status ?? task.dataset.status)});
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
        const input = event.target.closest('[data-board-modal-attachment-input]');
        if (input) await uploadAttachments(input);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && attachmentModal && !attachmentModal.hidden) closeAttachmentModal();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeBoardMenus();
    });

    filterBoard();
})();
