/**
 * ด่าน "งานย่อยต้องเสร็จก่อน" และตัวนับงานย่อยของทุกมุมมอง
 *
 * งานย่อยคือ WorkOrder จริงที่มี parent_job_id มันจึงมี job_status ของตัวเอง
 * งานแม่ที่ปิดแล้วแต่มีงานย่อยเปิดค้างอยู่ข้างใต้ทำให้บอร์ดและรายงานไม่ตรงความจริง
 * กฎจริงบังคับที่ TaskStatusTransitionService ฝั่ง server ไฟล์นี้มีไว้บอกเหตุผล
 * ให้ผู้ใช้ก่อนยิง request เท่านั้น ไม่ใช่การตัดสินสิทธิ์ซ้ำในฝั่งนี้
 *
 * ตัวเลขอ่านจาก DOM ของแถวงานย่อยในมุมมองบอร์ด ซึ่งถูก render ลงหน้าเสมอ
 * แม้ CSS จะซ่อนมุมมองอื่นอยู่ DOM จึงเป็นความจริงล่าสุดหลังผู้ใช้ทยอยปิดงานย่อย
 * โดยไม่ต้องโหลดหน้าใหม่ ถ้าหน้านั้นไม่มีแถวงานย่อยเลยให้ผู้เรียกตกไปใช้ค่าจาก server
 */

/** สถานะที่ถือว่างานย่อยเคลียร์แล้ว — พักงาน รอตรวจสอบ และล่าช้า ยังนับว่าค้าง */
const DONE_STATUS = 4;

/** สถานะปลายทางที่ต้องเคลียร์งานย่อยให้ครบก่อน — ส่งตรวจ และ ปิดงาน */
const GATED_STATUSES = [3, 4];

/*
 * รหัสงานเป็นตัวเลขเสมอ แต่ค่าที่ส่งเข้ามาอาจมาจาก dataset ซึ่งเป็นสตริง
 * ตัดอักขระที่ไม่ใช่รหัสทิ้งไปเลยแทนการพึ่ง CSS.escape ซึ่งไม่มีในทุกสภาพแวดล้อมที่โมดูลนี้ถูกโหลด
 */
const escapeValue = (value) => String(value).replace(/[^A-Za-z0-9_-]/g, '');

/*
 * ขอบเขตของงานหนึ่งใบ — หัวข้อกับแผงงานย่อยอยู่คนละ element แต่อยู่ในแถวบอร์ดเดียวกัน
 * การหาจึงเริ่มจากหัวข้อแล้วไต่ขึ้นไปที่แถว ไม่ใช่ผูกกับ id ของแผงซึ่งเป็นรายละเอียดของ Blade
 */
const scopeFor = (taskId, root) => {
    const heading = root.querySelector(`[data-task-details][data-work-order-id="${escapeValue(taskId)}"]`);

    return heading ? (heading.closest('[data-board-task]') || heading.parentElement || heading) : null;
};

/**
 * จำนวนงานย่อยของงานใบนี้ แยกเป็นทั้งหมด/เสร็จแล้ว/ค้าง
 * คืน null เมื่อหน้านี้ไม่มีแถวงานย่อยของงานใบนั้นอยู่ใน DOM เลย
 * ผู้เรียกจะได้แยกออกระหว่าง "ไม่มีงานย่อย" กับ "หน้านี้ไม่ได้ render งานย่อย"
 */
export function subtaskCounts(taskId, root = globalThis.document) {
    const scope = root ? scopeFor(taskId, root) : null;
    if (!scope) return null;

    /*
     * นับจาก [data-task-detail] ซึ่งเป็น hook ของแถวงานย่อยทุกแบบ รวมถึงแถวชั่วคราว
     * ที่เพิ่งสร้างและยังไม่มี data-status — แถวแบบนั้นคืองานย่อยที่เพิ่งเปิด จึงนับว่าค้าง
     */
    const rows = [...scope.querySelectorAll('[data-task-detail]')];
    const done = rows.filter((row) => Number(row.dataset.status) === DONE_STATUS).length;

    return {total: rows.length, done, open: rows.length - done};
}

/** งานย่อยที่ยังไม่เสร็จ — null เมื่อหน้านี้ไม่ได้ render งานย่อยของงานใบนั้น */
export function openSubtaskCount(taskId, root = globalThis.document) {
    return subtaskCounts(taskId, root)?.open ?? null;
}

/**
 * เหตุผลที่เปลี่ยนสถานะไม่ได้ — null คือผ่าน
 * เป็น pure function เพื่อให้ทดสอบกฎได้โดยไม่ต้องมี DOM
 */
export function subtaskGateMessage(targetStatus, openCount) {
    const open = Number(openCount) || 0;
    if (open <= 0) return null;
    if (!GATED_STATUSES.includes(Number(targetStatus))) return null;

    return Number(targetStatus) === DONE_STATUS
        ? `ยังเคลียร์งานย่อยไม่ครบ เหลืออีก ${open} งาน ต้องปิดงานย่อยให้ครบก่อนจึงจะปิดงานนี้ได้`
        : `ยังเคลียร์งานย่อยไม่ครบ เหลืออีก ${open} งาน ต้องปิดงานย่อยให้ครบก่อนจึงจะส่งตรวจได้`;
}

/**
 * งานย่อยที่ยังไม่เสร็จ โดยเชื่อ DOM ก่อน แล้วค่อยตกไปใช้ค่าที่ server ส่งมากับ capabilities
 * ใช้ตอนตัดสินใจว่าจะเด้ง SweetAlert หรือปล่อยผ่าน
 */
export function openSubtasksFor(capabilities = {}, root = globalThis.document) {
    const taskId = capabilities.task_id;
    const fromDom = taskId === undefined || taskId === null ? null : openSubtaskCount(taskId, root);

    return fromDom ?? Number(capabilities.open_child_count || 0);
}

/**
 * อัปเดตตัวนับงานย่อยทุกที่ที่แสดงอยู่ ให้ตรงกับ DOM ปัจจุบัน
 *
 * ตัวนับมีสองที่ — หัวแถวในมุมมองบอร์ด และชิปบนการ์ดในมุมมองตาราง
 * ทั้งคู่ถูกเขียนจากฟังก์ชันนี้ที่เดียว จะได้ไม่มีสองแหล่งความจริงที่เพี้ยนจากกันได้
 * และเขียนค่ากลับเข้า management เพื่อให้ด่านทำงานถูกโดยไม่ต้องโหลดหน้าใหม่
 */
export function syncSubtaskGate(taskId, management = null, root = globalThis.document) {
    const counts = subtaskCounts(taskId, root);
    if (!counts) return null;

    const id = escapeValue(taskId);
    const complete = counts.total > 0 && counts.open === 0;

    const board = root.querySelector(`[data-task-details][data-work-order-id="${id}"]`);
    const boardProgress = board?.querySelector('[data-task-details-progress]');
    const boardDone = board?.querySelector('[data-task-details-count]');
    const boardTotal = board?.querySelector('[data-task-details-total]');
    if (boardDone) boardDone.textContent = String(counts.done);
    if (boardTotal) boardTotal.textContent = String(counts.total);
    if (boardProgress) {
        boardProgress.classList.toggle('is-complete', complete);
        boardProgress.classList.toggle('is-pending', !complete);
    }

    const chip = root.querySelector(`[data-kanban-subtasks="${id}"]`);
    if (chip) {
        const chipDone = chip.querySelector('[data-kanban-subtask-done]');
        const chipTotal = chip.querySelector('[data-kanban-subtask-total]');
        if (chipDone) chipDone.textContent = String(counts.done);
        if (chipTotal) chipTotal.textContent = String(counts.total);
        chip.classList.toggle('is-complete', complete);
        chip.classList.toggle('is-pending', !complete);
        // การ์ดที่ไม่เหลืองานย่อยเลยไม่ต้องมีชิป แต่ต้องไม่หายไปจนกริดของ footer ขยับ
        chip.hidden = counts.total === 0;
        const label = `งานย่อยเสร็จแล้ว ${counts.done} จาก ${counts.total} งาน`;
        chip.setAttribute('title', label);
        chip.setAttribute('aria-label', label);
    }

    const entry = management?.[String(taskId)]?.transitions;
    if (entry) {
        entry.child_count = counts.total;
        entry.open_child_count = counts.open;
    }

    return counts;
}
