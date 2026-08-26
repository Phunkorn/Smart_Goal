/**
 * ตรรกะบริสุทธิ์ของ Task Workspace แยกออกมาเพื่อให้ทดสอบได้โดยไม่ต้องมี DOM
 *
 * ครอบสามเรื่องที่พลาดง่ายและกระทบข้อมูลจริง:
 * ส่งเฉพาะค่าที่เปลี่ยน, เตือนเมื่อยังไม่บันทึก และกันการส่งอัปเดตซ้ำ
 */

export const WORKSPACE_FIELDS = Object.freeze([
    'job_topic',
    'job_status',
    'job_priority',
    'job_start_at',
    'job_due_at',
]);

/**
 * คืนเฉพาะฟิลด์ที่ค่าต่างจากตอนเปิด Workspace
 * ใช้ตัดสินว่าจะยิง request ไหนบ้าง เพื่อไม่ให้เขียนทับข้อมูลที่ผู้ใช้ไม่ได้แตะ
 */
export function workspaceChanges(baseline = {}, current = {}) {
    const changes = {};

    WORKSPACE_FIELDS.forEach((field) => {
        const before = String(baseline?.[field] ?? '');
        const after = String(current?.[field] ?? '');
        if (before !== after) changes[field] = after;
    });

    return changes;
}

export function hasWorkspaceChanges(baseline, current) {
    return Object.keys(workspaceChanges(baseline, current)).length > 0;
}

/**
 * ตำแหน่งของเมนูสถานะ/ความสำคัญในแถบสรุป
 *
 * แถบสรุปสูงเพียงแถวเดียวและมี overflow:hidden เมนูจึงต้องเป็น fixed เพื่อไม่ให้ถูกกรอบตัด
 * เมื่อเป็น fixed แล้วจะไม่มีการแก้ขอบให้อัตโนมัติ จึงต้องคำนวณเองทั้งแกนนอนและแกนตั้ง
 * ไม่มีที่ด้านล่างพอและด้านบนพอ ให้พลิกขึ้น มิฉะนั้นหนีบให้อยู่ในจอ
 */
export function workspaceMenuPosition(trigger, panel, viewport, gutter = 8) {
    const below = trigger.bottom + 6;
    const fitsBelow = below + panel.height <= viewport.height - gutter;
    const fitsAbove = trigger.top - panel.height - 6 >= gutter;

    const top = fitsBelow || ! fitsAbove
        ? Math.min(below, Math.max(gutter, viewport.height - panel.height - gutter))
        : trigger.top - panel.height - 6;

    return {
        left: Math.max(gutter, Math.min(trigger.left, viewport.width - panel.width - gutter)),
        top,
    };
}

/**
 * ส่งอัปเดตได้ก็ต่อเมื่อมีข้อความจริง มีปลายทาง และยังไม่มีคำขอค้างอยู่
 * เงื่อนไข pending คือสิ่งที่ทำให้กดปุ่มรัว ๆ แล้วไม่เกิดข้อความซ้ำ
 */
export function shouldSendUpdate({taskId, url, message, pending} = {}) {
    if (pending) return false;
    if (!taskId || !url) return false;

    return String(message ?? '').trim() !== '';
}
