import {boardFilterStateFrom} from './task-filter-state.js';

/*
 * ตัวกรองผู้รับผิดชอบ (เจ้าของงาน = work_orders.user_id)
 * markup: resources/views/tasks/partials/mine-filter.blade.php
 *
 * โมดูลนี้เป็นเจ้าของสถานะของตัวกรองเพียงจุดเดียว — มุมมองตาราง (mytasks-project-board.js)
 * และมุมมองบอร์ด kanban (table-kanban.js) อ่านค่าผ่าน ownerFilterState() แล้วฟัง
 * event เดียวกัน ไม่มีใครเก็บสำเนาสถานะของตัวเอง select จึงเป็นแหล่งความจริงเดียว
 */
export const OWNER_FILTER_EVENT = 'mytasks:ownerfilterchange';

const assigneeSelector = '[data-board-assignee-filter]';

/** มุมมองที่ตัวกรองนี้มีผล — server ประกาศไว้ที่ data-owner-filter-views */
export const ownerFilterViews = (root) => String(
    root?.querySelector('[data-board-mine-filter]')?.dataset.ownerFilterViews || '',
).split(/\s+/).filter(Boolean);

/**
 * ค่าปัจจุบันของตัวกรอง — หน้าที่ไม่มีตัวกรองนี้ได้ค่าว่าง (ไม่กรอง) เสมอ
 *
 * ระบุ view เมื่อถามแทนมุมมองใดมุมมองหนึ่ง มุมมองที่ตัวกรองไม่มีผล (ปุ่มถูกซ่อน) ต้องได้ค่าว่าง
 * ไม่งั้นผู้ใช้ที่เลือกผู้รับผิดชอบในมุมมองตาราง แล้วสลับไปมุมมองที่ไม่มีตัวกรองนี้
 * จะเห็นบอร์ดว่างโดยไม่มีทางรู้ว่าอะไรซ่อนงานไว้
 */
export const ownerFilterState = (root, view = null) => {
    if (view !== null && !ownerFilterViews(root).includes(view)) {
        return {assignee: ''};
    }

    return {assignee: root?.querySelector(assigneeSelector)?.value || ''};
};

const selectAssignee = (root, assignee) => {
    const select = root.querySelector(assigneeSelector);
    if (!select) return;

    // ผู้รับผิดชอบที่ไม่มีงานอยู่ในหน้านี้แล้ว (ลิงก์เก่า) ตกกลับเป็น "ทุกคน" ไม่ใช่ตัวเลือกว่าง
    const exists = [...select.options].some((option) => option.value === assignee);
    select.value = exists ? assignee : '';
};

/**
 * ผูกตัวกรองหนึ่งครั้งต่อ workspace — เรียกซ้ำได้โดยไม่ผูก listener ซ้อน
 * ครั้งแรกอ่านค่าตั้งต้นจาก URL (?assignee=) ลิงก์ที่แชร์กันจึงเปิดมาเจอตัวกรองเดิม
 */
export function initializeOwnerFilter(root, search = globalThis.window?.location?.search || '') {
    const control = root?.querySelector('[data-board-mine-filter]');
    if (!control) return null;
    if (control.dataset.ownerFilterReady === 'true') return control;

    control.dataset.ownerFilterReady = 'true';
    const initial = boardFilterStateFrom(new URLSearchParams(search));
    selectAssignee(control, initial.assignee);

    const announce = () => root.dispatchEvent(new CustomEvent(OWNER_FILTER_EVENT, {
        detail: ownerFilterState(root),
    }));

    control.querySelector(assigneeSelector)?.addEventListener('change', announce);

    return control;
}
