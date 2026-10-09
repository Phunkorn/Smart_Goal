/*
 * ชื่อ dataset ที่มุมมองต่าง ๆ ใช้ร่วมกัน — 'startTime'/'dueTime' คือ data-start-time /
 * data-due-time ซึ่งเก็บเวลาไทยแยกจากวัน เพราะปฏิทินและการจัดกลุ่มทำงานระดับวัน
 */
import {taskStateStore} from './task-state-store.js';
// ป้ายเวลากำหนดส่งใช้ตัวช่วยชุดเดียวกับบอร์ด ตาราง และปฏิทิน ไม่มีสำเนาแยก
import {dueWithinLabel, isDueTimeUnset} from './due-time.js';

/* 'dueTimeUnset' คือ data-due-time-unset — เจตนา "ไม่ระบุเวลากำหนดส่ง" ซึ่งแยกจากค่าเวลา */
const mutableFields = ['topic', 'status', 'priority', 'start', 'due', 'startTime', 'dueTime', 'dueTimeUnset'];

export const synchronizeTaskManagement = (management, id, response = {}, eventTarget = globalThis.document) => {
    const meta = management?.[String(id)];
    if (!meta) return null;

    if (response.transitions) {
        meta.transitions = response.transitions;
        meta.can_work = response.transitions.can_edit === true;
        meta.status = Number(response.job_status ?? meta.status);
    }

    if (response.interactions) {
        meta.can_comment = response.interactions.can_comment === true;
        meta.comment_url = response.interactions.comment_url || null;
    }

    eventTarget?.dispatchEvent?.(new CustomEvent('mytasks:access-changed', {
        detail: {
            id: String(id),
            status: Number(response.job_status ?? meta.status),
            transitions: response.transitions || meta.transitions,
            interactions: response.interactions || null,
        },
    }));

    return meta;
};

const completedGroupFor = (cardGrid, projectKey) => [...cardGrid.querySelectorAll('[data-completed-group]')]
    .find((group) => group.dataset.projectKey === projectKey);

const createCompletedGroup = (cardGrid, task) => {
    const document = cardGrid.ownerDocument;
    const group = document.createElement('details');
    group.className = 'board-completed-group';
    group.dataset.completedGroup = '';
    group.dataset.projectKey = task.dataset.projectKey;

    const summary = document.createElement('summary');
    const icon = document.createElement('i');
    icon.className = 'bi bi-caret-right-fill';
    const title = document.createElement('strong');
    title.textContent = 'งานที่เสร็จแล้ว';
    const count = document.createElement('span');
    summary.append(icon, title, count);

    const rows = document.createElement('div');
    rows.className = 'board-completed-group__rows';
    group.append(summary, rows);

    const children = [...cardGrid.children];
    const headerIndex = children.findIndex((element) => (
        element.matches('[data-project-header]')
        && element.dataset.projectKey === task.dataset.projectKey
    ));
    const nextHeader = children.slice(headerIndex + 1)
        .find((element) => element.matches('[data-project-header]'));
    cardGrid.insertBefore(group, nextHeader || null);

    const header = headerIndex >= 0 ? children[headerIndex] : null;
    group.classList.toggle('is-project-collapsed', header?.classList.contains('is-collapsed') === true);

    return group;
};

export const synchronizeCompletedTaskGroup = (cardGrid, task, status) => {
    if (!cardGrid || !task?.dataset?.projectKey) return null;

    /*
     * งานย่อยไม่เคยย้ายเข้ากลุ่ม "งานที่เสร็จแล้ว" ของโปรเจกต์
     *
     * งานย่อยเป็น [data-board-task] ของตัวเองเหมือนงานแม่ แต่มันเป็น <li> ที่อยู่ใน
     * แผงงานย่อยของแถวงานแม่ การย้ายมันออกไปไว้ในกลุ่มงานที่เสร็จแล้วจึงดึงมันหลุด
     * ออกจาก <ol> ต้นทาง ผู้ใช้เห็นเป็น "ปิดงานย่อยแล้วมันหายไปเลย"
     *
     * กลุ่มงานที่เสร็จแล้วเก็บเป็น "งาน" ทั้งใบ งานย่อยที่ปิดแล้วจึงตามงานแม่เข้าไป
     * พร้อมแถวของมันเองอยู่แล้ว เพราะแผงงานย่อยเป็นลูกของ .board-reference-row
     */
    if (task.matches?.('[data-board-subtask]')) return null;

    let group = completedGroupFor(cardGrid, task.dataset.projectKey);
    if (Number(status) === 4) {
        group ||= createCompletedGroup(cardGrid, task);
        group.querySelector('.board-completed-group__rows')?.append(task);
    } else if (group?.contains(task)) {
        group.before(task);
    }

    if (!group) return null;
    const rows = group.querySelector('.board-completed-group__rows');
    const count = rows?.querySelectorAll('[data-board-task]:not([data-board-subtask])').length || 0;
    if (count === 0) {
        group.remove();
        return null;
    }

    const countLabel = group.querySelector(':scope > summary span');
    if (countLabel) countLabel.textContent = `${count} งาน`;
    return group;
};

/**
 * @param {object} state  canonical state จาก server (transitions/interactions) ที่ต้องเดินทาง
 *                        ไปกับ event ด้วย ไม่เช่นนั้นมุมมองที่ฟัง mytasks:changed จะวาดป้ายใหม่
 *                        จาก capabilities ชุดเก่า แล้วปุ่มของมันจะค้างอยู่ในสถานะก่อนเปลี่ยน
 */
export const synchronizeTaskSource = (workspace, id, changes, eventTarget = document, state = {}) => {
    const row = [...workspace.querySelectorAll('[data-workspace-task-source] [data-row]')]
        .find((candidate) => String(candidate.dataset.id) === String(id));
    if (!row) return null;

    mutableFields.forEach((field) => {
        if (Object.hasOwn(changes, field)) row.dataset[field] = String(changes[field] ?? '');
    });

    if (Object.hasOwn(changes, 'status')) {
        const input = row.querySelector('input[data-field="status"]');
        if (input) input.value = String(changes.status);
    }
    if (Object.hasOwn(changes, 'priority')) {
        const input = row.querySelector('input[data-field="priority"]');
        if (input) input.value = String(changes.priority);
    }
    if (Object.hasOwn(changes, 'due')) {
        // ช่องกำหนดส่งของแถวตารางเป็น datetime-local จึงต้องได้ค่ารวมวันกับเวลา
        const input = row.querySelector('input[data-field="due"]');
        const clock = Object.hasOwn(changes, 'dueTime') ? changes.dueTime : row.dataset.dueTime;
        const unset = isDueTimeUnset(row.dataset.dueTimeUnset);
        const due = String(changes.due ?? '');
        if (input) {
            input.value = due && clock ? `${due}T${clock}` : due;
            // ช่องต้องถือเจตนาเดียวกับแถว ไม่งั้นการแก้ครั้งถัดไปจะส่งเจตนาเก่ากลับไปทับ
            input.dataset.timeUnset = unset ? '1' : '0';
        }

        const timeLabel = row.querySelector('[data-due-time-label]');
        if (timeLabel) timeLabel.textContent = dueWithinLabel(clock, unset);
    }
    if (Object.hasOwn(changes, 'topic')) {
        const title = row.querySelector('.row-title strong');
        if (title) title.textContent = String(changes.topic ?? '');
    }

    const detail = {
        id: String(row.dataset.id),
        topic: row.dataset.topic || '',
        status: Number(row.dataset.status) || 0,
        priority: Number(row.dataset.priority) || 2,
        start: row.dataset.start || '',
        due: row.dataset.due || '',
        startTime: row.dataset.startTime || '',
        dueTime: row.dataset.dueTime || '',
        dueTimeUnset: isDueTimeUnset(row.dataset.dueTimeUnset),
    };
    if (state.transitions) detail.transitions = state.transitions;
    if (state.interactions) detail.interactions = state.interactions;
    eventTarget.dispatchEvent(new CustomEvent('mytasks:changed', {detail}));
    return detail;
};

/**
 * เส้นทางเดียวที่ทุกการเปลี่ยนสถานะงานต้องผ่าน
 *
 *   mutation สำเร็จ → canonical state จาก server → เขียน store กลาง → ยิง event
 *   → ทุกมุมมองที่มี representation ของงานใบนั้นวาดใหม่จากความจริงชุดเดียวกัน
 *
 * เดิมแต่ละทางเข้า (เมนูบอร์ด, การ์ด kanban, เมนูตาราง, โมดัล) เรียก
 * synchronizeTaskManagement() กับสำเนา capabilities ของตัวเองแล้วแก้ DOM ของตัวเองต่อ
 * มุมมองอื่นจึงได้แต่สถานะใหม่ แต่ไม่ได้สิทธิ์ใหม่ ปุ่มของมันจึงค้างอยู่ในสถานะก่อนเปลี่ยน
 *
 * งานแม่ถูกอัปเดตด้วยเมื่อ server ส่ง parent มา (ปิด/เปิดงานย่อยทำให้ด่านงานย่อย
 * ของงานแม่เปลี่ยน) ตัวเลขด่านจึงมาจาก server ไม่ใช่การนับจาก DOM
 */
export const publishTaskState = (workspace, id, response = {}, fallbackStatus = null, eventTarget = document) => {
    const store = taskStateStore(workspace?.ownerDocument || globalThis.document);
    const status = Number(response.job_status ?? fallbackStatus ?? store[String(id)]?.status ?? 0);

    synchronizeTaskManagement(store, id, response, eventTarget);
    const detail = synchronizeTaskSource(workspace, id, {status}, eventTarget, response);

    if (response.parent?.job_id) {
        const parentId = String(response.parent.job_id);
        synchronizeTaskManagement(store, parentId, {
            job_status: response.parent.job_status,
            transitions: response.parent.transitions,
        }, eventTarget);
        /*
         * สถานะของงานแม่ไม่ได้เปลี่ยนจากการปิด/เปิดงานย่อย แต่ป้ายและตัวนับของมันเปลี่ยน
         * จึงยิง mytasks:changed ของงานแม่ด้วย เพื่อให้ทุกมุมมองวาดด่านงานย่อยใหม่
         */
        synchronizeTaskSource(workspace, parentId, {
            status: Number(response.parent.job_status ?? store[parentId]?.status ?? 0),
        }, eventTarget, response.parent);
    }

    return detail;
};
