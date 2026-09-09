import {syncSubtaskGate} from './subtask-gate.js';

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
        throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'บันทึกงานย่อยไม่สำเร็จ');
    }
    return data;
};

/*
 * แถวงานย่อยเองก็เป็น [data-board-task] (เพื่อให้ปุ่มสถานะ ความสำคัญ วันที่ ไฟล์แนบ
 * และคอมเมนต์ยิงไปที่งานย่อยใบนั้น) การหา "งานแม่" จึงต้องข้ามแถวงานย่อยเสมอ
 */
const taskFor = (element) => element?.closest('[data-board-task]:not([data-board-subtask])');

/*
 * หัวข้องานอยู่ในคอลัมน์ "ชื่องาน" ส่วนรายการงานย่อยเป็นลูกของแถวบอร์ดโดยตรง
 * เพื่อให้กินความกว้างทั้งแถว ทั้งสองส่วนจึงไม่ได้อยู่ใน element เดียวกันอีกต่อไป
 * การค้นหาทุกครั้งต้องเริ่มจากแถว ไม่ใช่จากหัวข้อ
 */
const scopeFor = (node) => taskFor(node) || node?.closest('[data-task-details]');

/*
 * ตัวนับงานย่อยเป็น "เสร็จแล้ว/ทั้งหมด" และมีอยู่สองที่ (หัวแถวบอร์ด กับชิปบนการ์ดในมุมมองตาราง)
 * ทั้งคู่เขียนจาก syncSubtaskGate() ที่เดียว ที่นี่จึงเหลือแค่ป้าย "ยังไม่มีงานย่อย"
 * ซึ่งเป็นของ panel นี้โดยเฉพาะ
 */
const updateShell = (node) => {
    const scope = scopeFor(node);
    if (!scope) return;
    const count = scope.querySelectorAll('[data-task-detail]').length;
    const empty = scope.querySelector('[data-task-details-empty]');
    if (empty) empty.hidden = count > 0;

    const workOrderId = scope.querySelector('[data-task-details]')?.dataset.workOrderId
        ?? scope.dataset.workOrderId;
    if (workOrderId) syncSubtaskGate(workOrderId);
};

const setExpanded = (node, expanded) => {
    const scope = scopeFor(node);
    const toggle = scope?.querySelector('[data-task-details-toggle]');
    const panel = scope?.querySelector('[data-task-details-panel]');
    if (!toggle || !panel) return;
    toggle.setAttribute('aria-expanded', String(expanded));
    panel.hidden = !expanded;
    scope.querySelector('[data-task-details]')?.classList.toggle('is-expanded', expanded);
};

/*
 * แถวชั่วคราวของงานย่อยที่เพิ่งสร้าง
 *
 * แถวจริงถูกวาดโดย tasks/components/task-detail-row.blade.php ซึ่งต้องใช้สิทธิ์และข้อมูล
 * ที่ server ฝังมากับหน้า ที่นี่จึงวาดเพียงชื่อกับสถานะ "กำลังเตรียม" ให้ผู้ใช้เห็นทันที
 * แล้วปล่อยให้การโหลดหน้าใหม่แทนที่ด้วยแถวเต็มที่กดใช้งานได้จริง
 */
const detailElement = (detail) => {
    const item = document.createElement('li');
    item.className = 'board-task-detail is-pending';
    item.dataset.taskDetail = '';
    item.dataset.detailId = String(detail.id);
    item.dataset.workOrderId = String(detail.work_order_id);
    item.dataset.updateUrl = detail.update_url;
    item.dataset.deleteUrl = detail.delete_url;
    item.dataset.moveUrl = detail.move_url;

    const name = document.createElement('span');
    name.className = 'board-task-detail__name';

    const bullet = document.createElement('i');
    bullet.className = 'board-task-detail__bullet bi bi-dash';
    bullet.setAttribute('aria-hidden', 'true');

    /*
     * งานย่อยใบใหม่ยังไม่มีแถวต้นทางในหน้า โมดัลจึงเปิดมันทันทีไม่ได้
     * ปุ่มนี้จึงพาไปที่ deep link ?open_task= ซึ่ง server วาดหน้าใหม่แล้วเปิดโมดัลให้เอง
     */
    const open = document.createElement('button');
    open.type = 'button';
    open.className = 'board-task-detail__title';
    open.dataset.taskDetailOpen = String(detail.id);
    open.title = `เปิดรายละเอียดงานย่อย ${detail.title}`;
    const title = document.createElement('span');
    title.className = 'board-reference-task__title';
    title.dataset.taskDetailTitle = '';
    title.textContent = detail.title;
    open.append(title);
    name.append(bullet, open);

    const pending = document.createElement('span');
    pending.className = 'board-task-detail__pending';
    pending.textContent = 'กำลังเตรียมงานย่อย...';

    item.append(name, pending);
    return item;
};

const editableTasks = (projectHeader = null) => [...board.querySelectorAll('[data-board-task][data-detail-target="1"]:not([data-board-subtask])')]
    .filter((task) => !projectHeader || task.dataset.projectKey === projectHeader.dataset.projectKey);

const chooseTargetTask = async (tasks, currentId = '') => {
    if (!tasks.length) {
        notify('โปรเจกต์นี้ยังไม่มีงานที่คุณย้ายงานย่อยเข้าไปได้', false);
        return null;
    }
    if (tasks.length === 1) return tasks[0];

    const inputOptions = {};
    tasks.forEach((task) => {
        const project = task.dataset.projectName || 'งานทั่วไป';
        inputOptions[task.dataset.taskId] = `${project} — ${task.dataset.topic}${task.dataset.taskId === currentId ? ' (งานปัจจุบัน)' : ''}`;
    });
    const result = await Swal.fire({
        title: 'ย้ายงานย่อยไปที่งาน',
        input: 'select',
        inputOptions,
        inputPlaceholder: 'เลือกชื่องานปลายทาง',
        showCancelButton: true,
        confirmButtonText: 'ย้ายงานย่อย',
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
    const targetList = targetTask.querySelector('[data-task-details-list]');
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
        updateShell(sourceTask);
        updateShell(targetTask);
        setExpanded(targetTask, true);
        notify(data.message || 'ย้ายงานย่อยแล้ว');
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
            setExpanded(toggle, toggle.getAttribute('aria-expanded') !== 'true');
            return;
        }

        const pendingOpen = event.target.closest('[data-task-detail-open]');
        if (pendingOpen) {
            const url = new URL(window.location.href);
            url.searchParams.set('open_task', pendingOpen.dataset.taskDetailOpen);
            window.location.assign(url.toString());
            return;
        }

        const item = event.target.closest('[data-task-detail]');
        if (!item) return;

        if (event.target.closest('[data-task-detail-edit]')) {
            const titleNode = item.querySelector('[data-task-detail-title]');
            const result = await Swal.fire({
                title: 'แก้ไขชื่องานย่อย',
                input: 'text',
                inputValue: titleNode?.textContent || '',
                inputAttributes: {maxlength: 255},
                showCancelButton: true,
                confirmButtonText: 'บันทึก',
                cancelButtonText: 'ยกเลิก',
                reverseButtons: true,
                inputValidator: (value) => value.trim() ? undefined : 'กรุณาระบุชื่องานย่อย',
            });
            const title = result.value?.trim();
            if (!result.isConfirmed || !title || title === titleNode?.textContent) return;

            try {
                const data = await request(item.dataset.updateUrl, 'PATCH', {title});
                titleNode.textContent = data.detail?.title || title;
                notify(data.message || 'แก้ไขงานย่อยแล้ว');
            } catch (error) {
                notify(error.message, false);
            }
            return;
        }

        if (event.target.closest('[data-task-detail-delete]')) {
            const title = item.querySelector('[data-task-detail-title]')?.textContent || '';
            const result = await Swal.fire({
                icon: 'warning',
                title: 'ลบงานย่อยนี้หรือไม่?',
                text: `“${title}” จะถูกลบออกจากงานนี้`,
                showCancelButton: true,
                confirmButtonText: 'ลบ',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#dc2626',
                reverseButtons: true,
            });
            if (!result.isConfirmed) return;

            try {
                const data = await request(item.dataset.deleteUrl, 'DELETE');
                const scope = scopeFor(item);
                item.remove();
                updateShell(scope);
                notify(data.message || 'ลบงานย่อยแล้ว');
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
            const scope = scopeFor(form);
            scope.querySelector('[data-task-details-list]')?.append(detailElement(data.detail));
            input.value = '';
            updateShell(scope);
            setExpanded(scope, true);
            notify(data.message || 'เพิ่มงานย่อยแล้ว');
            /*
             * ปุ่มสถานะ ความสำคัญ วันที่ ไฟล์แนบ และคอมเมนต์ของแถวงานย่อย อ่านสิทธิ์และ
             * ข้อมูลจาก JSON ที่ server ฝังมากับหน้า งานย่อยใบใหม่จึงยังไม่มีข้อมูลชุดนั้น
             * โหลดหน้าใหม่หนึ่งครั้งดีกว่าปล่อยให้ผู้ใช้เจอแถวที่กดปุ่มแล้วไม่มีอะไรเกิดขึ้น
             */
            window.setTimeout(() => window.location.reload(), 700);
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
