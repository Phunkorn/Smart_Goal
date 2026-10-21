/*
 * ลากชื่องานเพื่อเลื่อนลำดับภายในโปรเจกต์ (มุมมองตาราง data-view="board")
 *
 * ลำดับงานแม่บนบอร์ดมาจาก server (สถานะ แล้วกำหนดส่ง) และไม่มีคอลัมน์ลำดับใน work_orders
 * งานนี้เป็นการปรับหน้าจอเท่านั้น จึงจำลำดับที่ผู้ใช้จัดไว้ในเบราว์เซอร์ของผู้ใช้เอง
 * ไม่เขียนอะไรกลับไปที่ server และไม่กระทบสิทธิ์หรือข้อมูลงานของใคร
 *
 * ที่จับคือหัวข้อชื่องาน [data-task-reorder-handle] ทั้งช่อง ไม่มีไอคอนแยก
 * กดเมาส์ค้างแล้วลากเกินระยะสั้น ๆ จึงเริ่มลาก คลิกปกติยังกางงานย่อยเหมือนเดิม
 *
 * ใช้ pointer events ไม่ใช่ HTML5 drag and drop เพราะงานย่อยบนบอร์ดเดียวกันใช้ HTML5 DnD
 * อยู่แล้ว (task-details.js) สองระบบจึงไม่แย่ง dragstart/dragover กัน
 *
 * ขอบเขตการย้ายคือ "โปรเจกต์เดียวกัน และกลุ่มเดียวกัน" — แถวถูกย้ายได้เฉพาะในหมู่แถวที่มี
 * data-project-key เดียวกันและอยู่ใน parent เดียวกัน (กลุ่มงานที่ยังไม่เสร็จ หรือกลุ่ม
 * "งานที่เสร็จแล้ว") จึงไม่มีทางหลุดไปอยู่ใต้หัวโปรเจกต์อื่น
 */

const rowSelector = '[data-board-task]:not([data-board-subtask])';
const handleSelector = '[data-task-reorder-handle]';
// ปุ่มเปิดงาน ลิงก์ และช่องกรอกในหัวข้อเป็นของการคลิก ไม่ใช่ที่จับลาก
const ignoredSelector = '[data-open-task-modal], a, input, select, textarea';
// ระยะที่ต้องลากก่อนจะนับว่าเป็นการลาก ไม่ใช่การคลิกที่มือขยับนิดหน่อย
const dragThreshold = 6;
const edgeScrollZone = 56;
const edgeScrollStep = 14;

// แยกตาม pathname เพราะ "งานของฉัน" กับ Workspace ของสมาชิกแต่ละคนเป็นบอร์ดคนละใบ
const storageKey = (view) => `smartgoal:board-task-order:${view.location.pathname}`;

// localStorage โยน error ได้ (โหมดส่วนตัว หรือถูกปิดสิทธิ์) หน้าต้องทำงานต่อได้โดยไม่จำอะไร
export const readSavedOrders = (view = window) => {
    try {
        const saved = JSON.parse(view.localStorage.getItem(storageKey(view)) || '{}');
        return saved && typeof saved === 'object' && !Array.isArray(saved) ? saved : {};
    } catch {
        return {};
    }
};

export const saveProjectOrder = (projectKey, ids, view = window) => {
    if (!projectKey) return;
    const saved = readSavedOrders(view);
    saved[projectKey] = ids.map(String);
    try {
        view.localStorage.setItem(storageKey(view), JSON.stringify(saved));
    } catch {
        // จำไม่ได้ก็ไม่เป็นไร ลำดับแค่กลับไปเป็นของ server หลังโหลดหน้าใหม่
    }
};

/*
 * เรียงตามลำดับที่ผู้ใช้จัดไว้ โดยงานที่ไม่เคยถูกจัด (เช่นงานที่เพิ่งสร้าง) อยู่ช่องเดิมตาม server
 *
 * งานที่เคยจัดไว้ใช้ "ช่อง" เดิมของกลุ่มตัวเองแล้วเรียงกันใหม่ในช่องเหล่านั้น
 * id ที่ไม่มีในหน้าแล้ว (ถูกลบ ย้ายโปรเจกต์) ถูกข้ามไปเอง
 */
export const orderBySaved = (ids, saved = []) => {
    const rank = new Map(saved.map((id, index) => [String(id), index]));
    const ranked = ids.filter((id) => rank.has(String(id)))
        .sort((first, second) => rank.get(String(first)) - rank.get(String(second)));
    let next = 0;

    return ids.map((id) => (rank.has(String(id)) ? ranked[next++] : id));
};

/*
 * ตำแหน่งที่แถวควรไปอยู่ เมื่อเมาส์อยู่ที่ pointerY
 * midpoints คือจุดกึ่งกลางแนวตั้งของแถวอื่นในกลุ่ม (ไม่รวมแถวที่กำลังลาก) เรียงจากบนลงล่าง
 * คืนดัชนีของแถวแรกที่เมาส์ยังอยู่เหนือจุดกึ่งกลาง หรือความยาวทั้งหมดถ้าอยู่ล่างสุด
 */
export const insertionIndex = (midpoints, pointerY) => {
    const index = midpoints.findIndex((middle) => pointerY < middle);
    return index < 0 ? midpoints.length : index;
};

const rowsOf = (board, projectKey) => [...board.querySelectorAll(rowSelector)]
    .filter((row) => row.dataset.projectKey === projectKey);

// แถวที่ย้ายด้วยกันได้ — โปรเจกต์เดียวกันและอยู่ในกลุ่มเดียวกัน (ไม่ข้ามเข้า/ออกกลุ่มงานที่เสร็จแล้ว)
const siblingRows = (row) => [...row.parentElement.children]
    .filter((candidate) => candidate.matches(rowSelector) && candidate.dataset.projectKey === row.dataset.projectKey);

// แถวที่ถูกตัวกรองซ่อน หรือโปรเจกต์ที่พับอยู่ ไม่ใช่ที่วาง ผู้ใช้จึงเห็นแถวขยับทุกครั้งที่ลากผ่าน
const visibleSiblingRows = (row) => siblingRows(row)
    .filter((candidate) => !candidate.hidden && !candidate.classList.contains('is-project-collapsed'));

const reorderRows = (rows, orderedRows) => {
    const placeholders = rows.map((row) => {
        const placeholder = row.ownerDocument.createComment('task-reorder');
        row.replaceWith(placeholder);
        return placeholder;
    });
    placeholders.forEach((placeholder, index) => placeholder.replaceWith(orderedRows[index]));
};

export const applySavedOrders = (board, view = window) => {
    const saved = readSavedOrders(view);
    Object.entries(saved).forEach(([projectKey, ids]) => {
        if (!Array.isArray(ids)) return;
        const containers = new Set(rowsOf(board, projectKey).map((row) => row.parentElement));
        containers.forEach((container) => {
            const rows = [...container.children]
                .filter((row) => row.matches(rowSelector) && row.dataset.projectKey === projectKey);
            const byId = new Map(rows.map((row) => [row.dataset.taskId, row]));
            const ordered = orderBySaved(rows.map((row) => row.dataset.taskId), ids).map((id) => byId.get(id));
            reorderRows(rows, ordered);
        });
    });
};

// วางแถวไว้ตามตำแหน่งเมาส์ในหมู่พี่น้องที่มองเห็น คืนค่าว่า DOM เปลี่ยนจริงหรือไม่
const placeRowAt = (row, pointerY) => {
    const others = visibleSiblingRows(row).filter((candidate) => candidate !== row);
    if (!others.length) return false;
    const midpoints = others.map((candidate) => {
        const rect = candidate.getBoundingClientRect();
        return rect.top + rect.height / 2;
    });
    const before = others[insertionIndex(midpoints, pointerY)];
    const last = others[others.length - 1];
    if (before ? row.nextElementSibling === before : last.nextElementSibling === row) return false;

    if (before) before.before(row);
    else last.after(row);
    return true;
};

const orderKey = (row) => siblingRows(row).map((candidate) => candidate.dataset.taskId).join(',');

/*
 * ข้อความบนป้ายลูกศรระหว่างลาก — บอกผู้ใช้ว่างานกำลังเลื่อนขึ้นหรือลง กี่ลำดับ และจะไปอยู่ลำดับที่เท่าไร
 * นับจากแถวที่มองเห็นในกลุ่มเดียวกัน เลขจึงตรงกับสิ่งที่ผู้ใช้เห็นบนจอ
 */
export const reorderHint = (originIndex, currentIndex, total) => {
    const steps = Math.abs(originIndex - currentIndex);
    const position = `ลำดับที่ ${currentIndex + 1} จาก ${total}`;
    if (!steps) return {direction: 'same', steps, label: 'ตำแหน่งเดิม', position};
    if (currentIndex < originIndex) {
        return {direction: 'up', steps, label: currentIndex === 0 ? 'เลื่อนขึ้นบนสุด' : `เลื่อนขึ้น ${steps} ลำดับ`, position};
    }
    return {direction: 'down', steps, label: currentIndex === total - 1 ? 'เลื่อนลงล่างสุด' : `เลื่อนลง ${steps} ลำดับ`, position};
};

const hintIcons = {up: 'bi-arrow-up', down: 'bi-arrow-down', same: 'bi-arrow-down-up'};

/*
 * ป้ายลูกศรที่ลอยตามเมาส์ระหว่างลาก — มีใบเดียวต่อบอร์ด สร้างครั้งแรกที่ใช้
 *
 * วางไว้ใต้ .my-tasks-page (ที่ประกาศ token สีของธีม) ไม่ใช่ในแถวงาน เพราะช่องชื่องานเป็น
 * container query ซึ่งทำให้ position: fixed ของลูกถูกนับจากช่องนั้นแทนหน้าจอ
 */
const hintFor = (board) => {
    const host = board.closest('.my-tasks-page') || board.ownerDocument.body;
    let hint = host.querySelector(':scope > [data-task-reorder-hint]');
    if (hint) return hint;

    const document = board.ownerDocument;
    hint = document.createElement('div');
    hint.className = 'board-task-reorder-hint';
    hint.dataset.taskReorderHint = '';
    hint.hidden = true;
    hint.setAttribute('aria-hidden', 'true');
    const icon = document.createElement('i');
    icon.className = 'bi';
    const text = document.createElement('span');
    const label = document.createElement('strong');
    const position = document.createElement('small');
    text.append(label, position);
    hint.append(icon, text);
    host.append(hint);
    return hint;
};

const showHint = (hint, state, pointerX, pointerY, view) => {
    hint.classList.remove('is-up', 'is-down', 'is-same');
    hint.classList.add(`is-${state.direction}`);
    hint.querySelector('i').className = `bi ${hintIcons[state.direction]}`;
    hint.querySelector('strong').textContent = state.label;
    hint.querySelector('small').textContent = state.position;
    hint.hidden = false;
    // ชิดขวาล่างของเคอร์เซอร์ แต่ไม่ล้นขอบจอ (วัดขนาดจริงหลังเปลี่ยนข้อความแล้ว)
    const rect = hint.getBoundingClientRect();
    const left = Math.max(8, Math.min(pointerX + 18, view.innerWidth - rect.width - 8));
    const top = Math.max(8, Math.min(pointerY + 18, view.innerHeight - rect.height - 8));
    hint.style.setProperty('--reorder-hint-left', `${left}px`);
    hint.style.setProperty('--reorder-hint-top', `${top}px`);
};

const rememberProject = (board, projectKey, view) => {
    saveProjectOrder(projectKey, rowsOf(board, projectKey).map((row) => row.dataset.taskId), view);
};

const notify = (document, message) => {
    const toast = document.querySelector('[data-toast]');
    if (!toast) return;
    toast.textContent = message;
    toast.style.background = '#172033';
    toast.classList.add('show');
    document.defaultView?.setTimeout(() => toast.classList.remove('show'), 2000);
};

const highlight = (row) => {
    row.classList.remove('is-reordered');
    // บังคับ reflow เพื่อให้แอนิเมชันไฮไลต์เล่นซ้ำได้เมื่อย้ายแถวเดิมติดกันหลายครั้ง
    void row.offsetWidth;
    row.classList.add('is-reordered');
};

export const initTaskReorder = (board, view = window) => {
    if (!board || board.dataset.taskReorderReady === '1') return;
    board.dataset.taskReorderReady = '1';
    const document = board.ownerDocument;

    /*
     * ผู้ใช้ที่เลือก "เรียงตามกำหนดส่ง" ไว้ใน URL ต้องเห็นลำดับตามกำหนดส่งตามที่เลือก
     * ลำดับที่จัดเองจึงถูกใช้เฉพาะเมื่อไม่มีการเรียงแบบนั้นอยู่
     */
    if (!new URL(view.location.href).searchParams.get('due_sort')) applySavedOrders(board, view);

    let drag = null;
    let suppressClick = false;

    const hintState = () => {
        const visible = visibleSiblingRows(drag.row);
        return reorderHint(drag.originIndex, visible.indexOf(drag.row), visible.length);
    };

    const updateHint = () => showHint(hintFor(board), hintState(), drag.lastX, drag.lastY, view);

    const finish = (commit) => {
        if (!drag) return;
        const {row, origin, started} = drag;
        const finalHint = started ? hintState() : null;
        if (started) hintFor(board).hidden = true;
        view.cancelAnimationFrame?.(drag.frame);
        view.removeEventListener('pointermove', onMove);
        view.removeEventListener('pointerup', onUp);
        view.removeEventListener('pointercancel', onCancel);
        document.removeEventListener('keydown', onKey, true);
        drag = null;
        if (!started) return;

        board.classList.remove('is-task-reordering');
        row.classList.remove('is-reorder-dragging');
        // การปล่อยเมาส์หลังลากจะยิง click ตามมา ต้องไม่ให้มันไปกางงานย่อยของแถวนั้น
        suppressClick = true;
        view.setTimeout(() => { suppressClick = false; }, 0);

        // มีแค่แถวที่ลากเท่านั้นที่ขยับ node อื่น (รวม whitespace) อยู่ที่เดิม จึงคืนที่เดิมได้ตรงตำแหน่ง
        if (!commit) {
            origin.parent.insertBefore(row, origin.next);
            return;
        }
        if (orderKey(row) === origin.order) return;
        rememberProject(board, row.dataset.projectKey, view);
        highlight(row);
        notify(document, `${finalHint.label}แล้ว`);
    };

    // เมาส์ค้างใกล้ขอบจอระหว่างลาก — เลื่อนหน้าให้เอง โปรเจกต์ยาวจึงลากไปบนสุดได้ในครั้งเดียว
    const autoScroll = () => {
        if (!drag?.started) return;
        const y = drag.lastY;
        const step = y < edgeScrollZone ? -edgeScrollStep : (y > view.innerHeight - edgeScrollZone ? edgeScrollStep : 0);
        if (step) {
            view.scrollBy(0, step);
            if (placeRowAt(drag.row, y)) updateHint();
        }
        drag.frame = view.requestAnimationFrame?.(autoScroll);
    };

    function onMove(event) {
        if (!drag) return;
        drag.lastX = event.clientX;
        drag.lastY = event.clientY;
        if (!drag.started) {
            if (Math.hypot(event.clientX - drag.startX, event.clientY - drag.startY) < dragThreshold) return;
            if (visibleSiblingRows(drag.row).length < 2) {
                finish(false);
                return;
            }
            drag.started = true;
            drag.originIndex = visibleSiblingRows(drag.row).indexOf(drag.row);
            view.getSelection?.()?.removeAllRanges();
            board.classList.add('is-task-reordering');
            drag.row.classList.add('is-reorder-dragging');
            drag.frame = view.requestAnimationFrame?.(autoScroll);
        }
        event.preventDefault?.();
        placeRowAt(drag.row, event.clientY);
        updateHint();
    }

    function onUp() {
        finish(true);
    }

    function onCancel() {
        finish(false);
    }

    // Escape ระหว่างลาก = ยกเลิก แถวกลับที่เดิม ฟังเฉพาะช่วงที่ลากอยู่เท่านั้น
    function onKey(event) {
        if (event.key !== 'Escape' || !drag?.started) return;
        event.preventDefault();
        event.stopPropagation();
        finish(false);
    }

    board.addEventListener('pointerdown', (event) => {
        // pointerType ว่างได้ในเบราว์เซอร์เก่า/สภาพแวดล้อมทดสอบ ซึ่งถือเป็นเมาส์
        if (drag || event.button !== 0 || (event.pointerType && event.pointerType !== 'mouse')) return;
        const handle = event.target.closest(handleSelector);
        if (!handle || event.target.closest(ignoredSelector)) return;
        const row = handle.closest(rowSelector);
        if (!row || !board.contains(row)) return;

        drag = {
            row,
            startX: event.clientX,
            startY: event.clientY,
            lastX: event.clientX,
            lastY: event.clientY,
            originIndex: 0,
            started: false,
            frame: null,
            origin: {parent: row.parentElement, next: row.nextSibling, order: orderKey(row)},
        };
        view.addEventListener('pointermove', onMove);
        view.addEventListener('pointerup', onUp);
        view.addEventListener('pointercancel', onCancel);
        document.addEventListener('keydown', onKey, true);
    });

    // capture เพื่อให้ตัดหน้า handler กางงานย่อยใน task-details.js ซึ่งผูกไว้ที่บอร์ดเดียวกัน
    board.addEventListener('click', (event) => {
        if (!suppressClick) return;
        suppressClick = false;
        event.preventDefault();
        event.stopPropagation();
    }, true);

    board.addEventListener('animationend', (event) => {
        if (event.target.matches?.('.is-reordered')) event.target.classList.remove('is-reordered');
    });
};

initTaskReorder(document.querySelector('[data-project-board]'));
