/*
 * รายชื่อผู้เข้าร่วมงานในตารางรายงานโปรเจกต์ — popover ใต้ปุ่ม "N คน"
 *
 * เป็น popover ไม่ใช่ modal: ไม่มีฉากหลัง ไม่ล็อกการเลื่อนหน้า วางตามตำแหน่งปุ่ม
 * ปิดเมื่อคลิกข้างนอก กด Escape (คืนโฟกัสให้ปุ่ม) เลื่อนหน้า/ตาราง หรือเปลี่ยนขนาดจอ
 * เปิดได้ทีละอัน เปิดอันใหม่แล้วอันเดิมปิดเอง
 *
 * แผงใช้ position: fixed ตามพิกัดของปุ่ม เพราะตารางอยู่ในกรอบ overflow-x: auto
 * ถ้าวางแบบ absolute แผงของแถวท้าย ๆ จะถูกกรอบตารางตัดทิ้ง
 *
 * รายชื่อ render ฝั่ง server ไว้แล้ว (ค่าผ่าน Blade escape) ไฟล์นี้แค่เปิด/ปิดและวางตำแหน่ง
 * ใช้ event delegation ที่ document และผูกได้ครั้งเดียวต่อ document
 */
const GAP = 6;
const EDGE = 8;
const ready = new WeakSet();

/** ตำแหน่งของแผง: ใต้ปุ่ม ชิดซ้ายกับปุ่ม และไม่ล้นขอบขวา/ล่างของจอ */
export function panelPosition(triggerRect, panelSize, viewport) {
    const left = Math.max(EDGE, Math.min(triggerRect.left, viewport.width - panelSize.width - EDGE));
    const below = triggerRect.bottom + GAP;
    const top = below + panelSize.height > viewport.height - EDGE && triggerRect.top - GAP - panelSize.height >= EDGE
        ? triggerRect.top - GAP - panelSize.height
        : below;

    return {top, left};
}

export function initParticipantsPopovers(doc = document) {
    if (!doc || ready.has(doc)) return null;
    ready.add(doc);

    const win = doc.defaultView;
    let current = null;

    const close = ({restoreFocus = false} = {}) => {
        if (!current) return;

        const {trigger, panel} = current;
        panel.hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
        current = null;
        if (restoreFocus) trigger.focus();
    };

    const open = (trigger) => {
        const panel = trigger.closest('[data-participants]')?.querySelector('[data-participants-panel]');
        if (!panel) return;

        close();
        panel.hidden = false;
        trigger.setAttribute('aria-expanded', 'true');

        const {top, left} = panelPosition(
            trigger.getBoundingClientRect(),
            {width: panel.offsetWidth, height: panel.offsetHeight},
            {width: win?.innerWidth ?? 0, height: win?.innerHeight ?? 0},
        );
        panel.style.top = `${top}px`;
        panel.style.left = `${left}px`;
        current = {trigger, panel};
    };

    doc.addEventListener('click', (event) => {
        const trigger = event.target.closest?.('[data-participants-trigger]');

        if (trigger) {
            if (current?.trigger === trigger) close();
            else open(trigger);

            return;
        }

        if (current && !current.panel.contains(event.target)) close();
    });

    doc.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && current) close({restoreFocus: true});
    });

    // พิกัดแบบ fixed จะไม่ตามปุ่มเมื่อหน้าหรือกรอบตารางเลื่อน จึงปิดแทนการคำนวณใหม่
    win?.addEventListener('scroll', () => close(), true);
    win?.addEventListener('resize', () => close());

    return {close, get open() { return current; }};
}
