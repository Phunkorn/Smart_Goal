/**
 * ปุ่มเปิด/พับแผงตัวกรองเพิ่มเติม — ใช้ร่วมกันระหว่างคิวงาน (/tickets) และรายงาน Smart Ticket
 *
 * แผงเป็นส่วนหนึ่งของฟอร์มเดิม ไม่ใช่ popover จึงไม่ต้องจัดการ outside click
 * ไม่มี JavaScript: แผงแสดงอยู่เสมอและปุ่มยังถูกซ่อน (hidden) ตัวกรองจึงใช้ได้ครบ เรียกซ้ำได้โดยไม่ผูก listener ซ้ำ
 */
export function initTicketFilterToggle(root = document) {
    const toggle = root.querySelector('[data-ticket-filter-toggle]');
    const panel = root.querySelector('[data-ticket-filter-panel]');
    if (! toggle || ! panel || toggle.dataset.ticketFilterReady === 'true') return false;
    toggle.dataset.ticketFilterReady = 'true';

    const setOpen = (open) => {
        panel.hidden = ! open;
        toggle.setAttribute('aria-expanded', String(open));
    };

    // เปิดไว้เองเมื่อ server บอกว่ามีตัวกรองในแผงใช้อยู่ (data-open) ไม่เช่นนั้นเริ่มแบบพับ
    setOpen(panel.hasAttribute('data-open'));
    toggle.hidden = false;
    toggle.addEventListener('click', () => setOpen(panel.hidden));

    return true;
}
