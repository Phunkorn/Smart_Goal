/**
 * เมนูหลักแนวนอนบน Topbar (layouts/components/top-navigation.blade.php)
 *
 * Bootstrap dropdown ดูแลการเปิด/ปิดกลุ่มเมนูอยู่แล้ว: คลิกเปิด คลิกซ้ำปิด คลิกนอกเมนูปิด
 * Escape ปิดแล้วคืนโฟกัสปุ่ม ลูกศรขึ้น/ลงเลื่อนในเมนู และเปิดกลุ่มใหม่จะปิดกลุ่มเดิมเอง
 * ไฟล์นี้เติมเฉพาะส่วนที่ Bootstrap ไม่มี:
 *
 * - ลูกศรซ้าย/ขวา Home/End ย้ายโฟกัสระหว่างเมนูหลัก (ทั้งจากตัวเมนูหลักและจากในดร็อปดาวน์ที่เปิดอยู่
 *   ซึ่งจะถูกปิดก่อนย้าย) ตามแบบแผนของแถบเมนูแนวนอน
 * - จอแคบที่แถวเมนูเลื่อนแนวนอนได้ เลื่อนให้เห็นเมนูของหน้าปัจจุบันตั้งแต่โหลดหน้า
 *
 * สถานะการมองเห็นทั้งหมดเป็น class/ARIA ที่ Bootstrap ตั้ง (show, aria-expanded) ไม่มีการแก้ style ที่นี่
 * เรียกซ้ำได้โดยไม่ผูก listener ซ้ำ และ destroy() ถอด listener ทั้งหมด
 */
const NEXT_KEYS = new Set(['ArrowRight']);
const PREVIOUS_KEYS = new Set(['ArrowLeft']);

export function initTopNavigation(doc = document, {win = doc.defaultView} = {}) {
    const nav = doc.querySelector('[data-top-nav]');
    if (! nav || nav.dataset.topNavReady === 'true') return null;
    nav.dataset.topNavReady = 'true';

    const listeners = new win.AbortController();
    const entries = () => [...nav.querySelectorAll('[data-top-nav-entry]')];

    const closeOpenGroup = (entry) => {
        const toggle = entry?.closest('.dropdown')?.querySelector('[data-bs-toggle="dropdown"]');
        if (toggle?.getAttribute('aria-expanded') !== 'true') return;
        win.bootstrap?.Dropdown?.getOrCreateInstance(toggle)?.hide();
    };

    nav.addEventListener('keydown', (event) => {
        const fromEntry = event.target.closest('[data-top-nav-entry]');
        const fromMenu = event.target.closest('.dropdown-menu');
        if (! fromEntry && ! fromMenu) return;

        const all = entries();
        const current = fromEntry ?? fromMenu.closest('.dropdown')?.querySelector('[data-top-nav-entry]');
        const index = all.indexOf(current);
        if (index === -1) return;

        let target = null;
        if (NEXT_KEYS.has(event.key)) target = all[(index + 1) % all.length];
        else if (PREVIOUS_KEYS.has(event.key)) target = all[(index - 1 + all.length) % all.length];
        else if (fromEntry && event.key === 'Home') target = all[0];
        else if (fromEntry && event.key === 'End') target = all[all.length - 1];
        if (! target) return;

        event.preventDefault();
        closeOpenGroup(current);
        target.focus();
    }, {signal: listeners.signal});

    revealCurrentEntry(nav);

    return {
        destroy() {
            listeners.abort();
            delete nav.dataset.topNavReady;
        },
    };
}

/**
 * แถวเมนูบนจอแคบเลื่อนแนวนอนได้ — เลื่อนแถวเอง (ไม่ใช่ scrollIntoView ที่เลื่อนทั้งหน้าตามไปด้วย)
 * ให้เมนูของหน้าปัจจุบันอยู่กลางแถว ถ้าแถวไม่ล้นก็ไม่ทำอะไร
 */
export function revealCurrentEntry(nav) {
    const list = nav.querySelector('[data-top-nav-list]');
    const active = nav.querySelector('[data-top-nav-entry].is-active');
    if (! list || ! active || list.scrollWidth <= list.clientWidth) return;

    /*
     * วัดด้วย getBoundingClientRect ไม่ใช่ offsetLeft — แถวเมนูบนจอแคบต้องไม่เป็น positioned element
     * (ดร็อปดาวน์จึงยึดกับ Topbar และไม่ถูกแถวที่เลื่อนได้ตัดทิ้ง) offsetLeft จึงนับจาก Topbar ไม่ใช่จากแถว
     */
    const item = (active.closest('.top-nav__item') ?? active).getBoundingClientRect();
    const row = list.getBoundingClientRect();
    list.scrollLeft = Math.max(0, list.scrollLeft + item.left - row.left - (list.clientWidth - item.width) / 2);
}

if (typeof document !== 'undefined') initTopNavigation(document);
