const board = document.querySelector('[data-project-board]');
const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
const toast = document.querySelector('[data-toast]');
let draggedDetail = null;

const notify = (message, ok = true) => {
    if (!toast) return;
    toast.textContent = message;
    toast.style.background = ok ? '#172033' : '#dc2626';
    toast.classList.add('show');
    window.setTimeout(() => toast.classList.remove('show'), 2400);
};

const request = async (url, method, payload = null) => {
    const response = await fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf,
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: payload ? JSON.stringify(payload) : null,
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'บันทึกรายละเอียดงานไม่สำเร็จ');
    }
    return data;
};

const taskFor = (element) => element?.closest('[data-board-task]');
const shellFor = (task) => task?.querySelector('[data-task-details]');

const updateShell = (shell) => {
    if (!shell) return;
    const count = shell.querySelectorAll('[data-task-detail]').length;
    const countNode = shell.querySelector('[data-task-details-count]');
    const empty = shell.querySelector('[data-task-details-empty]');
    if (countNode) countNode.textContent = String(count);
    if (empty) empty.hidden = count > 0;
};

const setExpanded = (shell, expanded) => {
    const toggle = shell?.querySelector('[data-task-details-toggle]');
    const panel = shell?.querySelector('[data-task-details-panel]');
    if (!toggle || !panel) return;
    toggle.setAttribute('aria-expanded', String(expanded));
    panel.hidden = !expanded;
    shell.classList.toggle('is-expanded', expanded);
};

const detailElement = (detail) => {
    const item = document.createElement('li');
    item.className = 'board-task-detail';
    item.draggable = true;
    item.dataset.taskDetail = '';
    item.dataset.detailId = String(detail.id);
    item.dataset.workOrderId = String(detail.work_order_id);
    item.dataset.updateUrl = detail.update_url;
    item.dataset.deleteUrl = detail.delete_url;
    item.dataset.moveUrl = detail.move_url;

    const drag = document.createElement('button');
    drag.type = 'button';
    drag.className = 'board-task-detail__drag';
    drag.dataset.taskDetailDrag = '';
    drag.title = 'ลากไปวางที่งานหรือโปรเจกต์อื่น';
    drag.setAttribute('aria-label', `ลากเพื่อย้ายรายละเอียดงาน ${detail.title}`);
    drag.innerHTML = '<i class="bi bi-grip-vertical" aria-hidden="true"></i>';

    const title = document.createElement('span');
    title.dataset.taskDetailTitle = '';
    title.textContent = detail.title;

    const actions = document.createElement('span');
    actions.className = 'board-task-detail__actions';
    [
        ['taskDetailMove', 'bi-arrow-left-right', 'ย้ายรายละเอียดงาน', 'ย้ายไปงานอื่น'],
        ['taskDetailEdit', 'bi-pencil', 'แก้ไขรายละเอียดงาน', 'แก้ไข'],
        ['taskDetailDelete', 'bi-trash3', 'ลบรายละเอียดงาน', 'ลบ'],
    ].forEach(([dataKey, icon, label, tooltip]) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.dataset[dataKey] = '';
        button.title = tooltip;
        button.setAttribute('aria-label', `${label} ${detail.title}`);
        button.innerHTML = `<i class="bi ${icon}" aria-hidden="true"></i>`;
        actions.append(button);
    });

    item.append(drag, title, actions);
    return item;
};

const editableTasks = (projectHeader = null) => [...board.querySelectorAll('[data-board-task][data-detail-target="1"]')]
    .filter((task) => !projectHeader || task.dataset.projectKey === projectHeader.dataset.projectKey);

const chooseTargetTask = async (tasks, currentId = '') => {
    if (!tasks.length) {
        notify('โปรเจกต์นี้ยังไม่มีงานที่คุณย้ายรายละเอียดเข้าไปได้', false);
        return null;
    }
    if (tasks.length === 1) return tasks[0];

    const inputOptions = {};
    tasks.forEach((task) => {
        const project = task.dataset.projectName || 'งานทั่วไป';
        inputOptions[task.dataset.taskId] = `${project} — ${task.dataset.topic}${task.dataset.taskId === currentId ? ' (งานปัจจุบัน)' : ''}`;
    });
    const result = await Swal.fire({
        title: 'ย้ายรายละเอียดไปที่งาน',
        input: 'select',
        inputOptions,
        inputPlaceholder: 'เลือกชื่องานปลายทาง',
        showCancelButton: true,
        confirmButtonText: 'ย้ายรายละเอียด',
        cancelButtonText: 'ยกเลิก',
        reverseButtons: true,
        inputValidator: (value) => value ? undefined : 'กรุณาเลือกชื่องาน',
    });

    return result.isConfirmed
        ? tasks.find((task) => task.dataset.taskId === String(result.value)) || null
        : null;
};

const moveDetail = async (item, targetTask, targetItem = null) => {
    if (!item || !targetTask || targetTask.dataset.detailTarget !== '1') return;
    const sourceTask = taskFor(item);
    const sourceShell = shellFor(sourceTask);
    const targetShell = shellFor(targetTask);
    const targetList = targetShell?.querySelector('[data-task-details-list]');
    if (!targetList) return;

    const ordered = [...targetList.querySelectorAll('[data-task-detail]')].filter((candidate) => candidate !== item);
    const position = targetItem && targetItem !== item ? Math.max(0, ordered.indexOf(targetItem)) : ordered.length;

    item.classList.add('is-moving');
    try {
        const data = await request(item.dataset.moveUrl, 'PATCH', {
            target_work_order_id: Number(targetTask.dataset.taskId),
            position,
        });

        if (targetItem && targetItem !== item) targetList.insertBefore(item, targetItem);
        else targetList.append(item);
        item.dataset.workOrderId = String(data.detail?.work_order_id || targetTask.dataset.taskId);
        updateShell(sourceShell);
        updateShell(targetShell);
        setExpanded(targetShell, true);
        notify(data.message || 'ย้ายรายละเอียดงานแล้ว');
    } catch (error) {
        notify(error.message, false);
    } finally {
        item.classList.remove('is-moving');
    }
};

const clearDropState = () => {
    board?.querySelectorAll('.is-detail-drop-target').forEach((element) => element.classList.remove('is-detail-drop-target'));
};

if (board) {
    board.addEventListener('click', async (event) => {
        const toggle = event.target.closest('[data-task-details-toggle]');
        if (toggle) {
            const shell = toggle.closest('[data-task-details]');
            setExpanded(shell, toggle.getAttribute('aria-expanded') !== 'true');
            return;
        }

        const item = event.target.closest('[data-task-detail]');
        if (!item) return;

        if (event.target.closest('[data-task-detail-edit]')) {
            const titleNode = item.querySelector('[data-task-detail-title]');
            const result = await Swal.fire({
                title: 'แก้ไขรายละเอียดงาน',
                input: 'text',
                inputValue: titleNode?.textContent || '',
                inputAttributes: {maxlength: 255},
                showCancelButton: true,
                confirmButtonText: 'บันทึก',
                cancelButtonText: 'ยกเลิก',
                reverseButtons: true,
                inputValidator: (value) => value.trim() ? undefined : 'กรุณาระบุรายละเอียดงาน',
            });
            const title = result.value?.trim();
            if (!result.isConfirmed || !title || title === titleNode?.textContent) return;

            try {
                const data = await request(item.dataset.updateUrl, 'PATCH', {title});
                titleNode.textContent = data.detail?.title || title;
                notify(data.message || 'แก้ไขรายละเอียดงานแล้ว');
            } catch (error) {
                notify(error.message, false);
            }
            return;
        }

        if (event.target.closest('[data-task-detail-delete]')) {
            const title = item.querySelector('[data-task-detail-title]')?.textContent || '';
            const result = await Swal.fire({
                icon: 'warning',
                title: 'ลบรายละเอียดงานนี้หรือไม่?',
                text: `“${title}” จะถูกลบออกจากชื่องาน`,
                showCancelButton: true,
                confirmButtonText: 'ลบ',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#dc2626',
                reverseButtons: true,
            });
            if (!result.isConfirmed) return;

            try {
                const data = await request(item.dataset.deleteUrl, 'DELETE');
                const shell = item.closest('[data-task-details]');
                item.remove();
                updateShell(shell);
                notify(data.message || 'ลบรายละเอียดงานแล้ว');
            } catch (error) {
                notify(error.message, false);
            }
            return;
        }

        if (event.target.closest('[data-task-detail-move]')) {
            const currentTask = taskFor(item);
            const target = await chooseTargetTask(editableTasks(), currentTask?.dataset.taskId || '');
            if (target) await moveDetail(item, target);
        }
    });

    board.addEventListener('submit', async (event) => {
        const form = event.target.closest('[data-task-detail-create]');
        if (!form) return;
        event.preventDefault();
        const input = form.elements.title;
        const title = input.value.trim();
        if (!title) {
            input.focus();
            return;
        }
        const submit = form.querySelector('[type="submit"]');
        submit.disabled = true;
        try {
            const data = await request(form.dataset.url, 'POST', {title});
            const shell = form.closest('[data-task-details]');
            shell.querySelector('[data-task-details-list]')?.append(detailElement(data.detail));
            input.value = '';
            updateShell(shell);
            setExpanded(shell, true);
            notify(data.message || 'เพิ่มรายละเอียดงานแล้ว');
        } catch (error) {
            notify(error.message, false);
        } finally {
            submit.disabled = false;
        }
    });

    board.addEventListener('dragstart', (event) => {
        const item = event.target.closest('[data-task-detail][draggable="true"]');
        if (!item) return;
        draggedDetail = item;
        item.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', item.dataset.detailId);
    });

    board.addEventListener('dragover', (event) => {
        if (!draggedDetail) return;
        const task = event.target.closest('[data-board-task][data-detail-target="1"]');
        const project = event.target.closest('[data-project-header][data-detail-project-target="1"]');
        const target = task || project;
        if (!target) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        clearDropState();
        target.classList.add('is-detail-drop-target');
    });

    board.addEventListener('drop', async (event) => {
        if (!draggedDetail) return;
        const detail = draggedDetail;
        const task = event.target.closest('[data-board-task][data-detail-target="1"]');
        const project = event.target.closest('[data-project-header][data-detail-project-target="1"]');
        if (!task && !project) return;
        event.preventDefault();
        clearDropState();

        const targetTask = task || await chooseTargetTask(editableTasks(project), taskFor(detail)?.dataset.taskId || '');
        const targetItem = task ? event.target.closest('[data-task-detail]') : null;
        if (targetTask) await moveDetail(detail, targetTask, targetItem);
    });

    board.addEventListener('dragend', () => {
        draggedDetail?.classList.remove('is-dragging');
        draggedDetail = null;
        clearDropState();
    });

    board.querySelectorAll('[data-task-details]').forEach(updateShell);
}

export {detailElement, updateShell};
