/**
 * แถบเมนูด้านข้างของจอต่ำกว่า 992px (layouts/components/mobile-sidebar.blade.php)
 *
 * เจ้าของสถานะเปิด/ปิด โฟกัส Escape และ inert มีที่เดียวคือไฟล์นี้ — นำพฤติกรรม off-canvas
 * ของ sidebar.js เดิมกลับมาเฉพาะส่วนที่จอเล็กใช้ ไม่มีโหมดย่อ/ขยายของเดสก์ท็อปอีก
 *
 * - ปุ่ม ☰ เปิด · ปุ่ม X / ฉากหลัง / Escape / คลิกลิงก์เมนู ปิด
 * - เปิดแล้วโฟกัสไปเมนูของหน้าปัจจุบัน (หรือปุ่มปิด) ปิดแล้วคืนโฟกัสให้ปุ่ม ☰ (ยกเว้นตอนกดลิงก์ไปหน้าอื่น)
 * - ตอนปิด แถบเมนูเป็น inert จึง Tab เข้าไม่ได้ ตอนเปิด Topbar กับเนื้อหาหลักเป็น inert แทน
 * - ล็อกการเลื่อนหน้าด้วยคลาส mobile-sidebar-open บน body (CSS) ไม่แก้ style ตรง ๆ
 * - กลุ่มเมนูเป็น accordion: aria-expanded + inert ของแผงที่พับอยู่ แอนิเมชันอยู่ใน CSS
 * - จอขยายจนถึง 992px ขณะเปิดอยู่ → ปิดเอง (เดสก์ท็อปใช้เมนูแนวนอน)
 *
 * เรียกซ้ำได้โดยไม่ผูก listener ซ้ำ และ destroy() ถอด listener ทั้งหมด
 */
export const DESKTOP_QUERY = '(min-width: 992px)';

export function initMobileSidebar(doc = document, {win = doc.defaultView} = {}) {
    const sidebar = doc.querySelector('[data-mobile-sidebar]');
    const opener = doc.querySelector('[data-mobile-sidebar-open]');
    if (! sidebar || ! opener || sidebar.dataset.mobileSidebarReady === 'true') return null;
    sidebar.dataset.mobileSidebarReady = 'true';

    const body = doc.body;
    const listeners = new win.AbortController();
    const {signal} = listeners;
    const background = () => [...doc.querySelectorAll('header.topbar, main.main')];
    const isOpen = () => body.classList.contains('mobile-sidebar-open');

    const setOpen = (open) => {
        body.classList.toggle('mobile-sidebar-open', open);
        sidebar.toggleAttribute('inert', ! open);
        background().forEach((element) => element.toggleAttribute('inert', open));
        opener.setAttribute('aria-expanded', String(open));
    };

    const open = () => {
        if (isOpen()) return;
        // ดร็อปดาวน์หรือกล่องค้นหาที่เปิดค้างอยู่บน Topbar ต้องปิดก่อน เพราะ Topbar กำลังจะเป็น inert
        doc.querySelectorAll('[data-bs-toggle="dropdown"][aria-expanded="true"]').forEach((toggle) => {
            win.bootstrap?.Dropdown?.getOrCreateInstance(toggle)?.hide();
        });
        setOpen(true);
        // เมนูของหน้าปัจจุบัน ถ้าอยู่ในกลุ่มที่กางอยู่ (กลุ่มที่พับเป็น inert จึงโฟกัสไม่ได้)
        const current = [...sidebar.querySelectorAll('[aria-current]')].find((link) => ! link.closest('[inert]'));
        (current ?? sidebar.querySelector('[data-mobile-sidebar-close]'))?.focus();
    };

    const close = ({restoreFocus = true} = {}) => {
        if (! isOpen()) return;
        setOpen(false);
        if (restoreFocus) opener.focus();
    };

    const toggleGroup = (toggle) => {
        const group = toggle.closest('[data-mobile-nav-group]');
        const panel = group?.querySelector('[data-mobile-nav-panel]');
        if (! panel) return;
        const expand = toggle.getAttribute('aria-expanded') !== 'true';
        toggle.setAttribute('aria-expanded', String(expand));
        group.classList.toggle('is-open', expand);
        panel.toggleAttribute('inert', ! expand);
    };

    opener.addEventListener('click', () => (isOpen() ? close() : open()), {signal});

    doc.querySelectorAll('[data-mobile-sidebar-close]').forEach((target) => {
        target.addEventListener('click', () => close(), {signal});
    });

    sidebar.addEventListener('click', (event) => {
        const toggle = event.target.closest('[data-mobile-nav-toggle]');
        if (toggle) {
            toggleGroup(toggle);
            return;
        }
        // ลิงก์พาไปหน้าอื่น — ปิดแถบเมนูโดยไม่ดึงโฟกัสกลับ เพราะหน้ากำลังเปลี่ยน
        if (event.target.closest('a[href]')) close({restoreFocus: false});
    }, {signal});

    doc.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && isOpen()) {
            event.preventDefault();
            close();
        }
    }, {signal});

    const desktop = win.matchMedia?.(DESKTOP_QUERY);
    desktop?.addEventListener?.('change', (event) => {
        if (event.matches) close({restoreFocus: false});
    }, {signal});

    // เริ่มต้นปิดเสมอ (Blade ใส่ inert ไว้แล้ว) — ยืนยันสถานะของพื้นหลังและปุ่มให้ตรงกัน
    setOpen(false);

    return {
        open,
        close,
        isOpen,
        destroy() {
            listeners.abort();
            delete sidebar.dataset.mobileSidebarReady;
        },
    };
}

if (typeof document !== 'undefined') initMobileSidebar(document);
