/**
 * แถบข้าง (layouts/app.blade.php) — เดสก์ท็อป (≥992px) กาง/ย่อ · จอเล็กเป็น off-canvas
 *
 * - ปุ่มเมนูบน Topbar ([data-sidebar-open]) ย่อ/กางบนเดสก์ท็อป และเปิด/ปิด drawer บนจอเล็ก
 *   สถานะย่อจำใน localStorage (สคริปต์สั้นใน <body> คืนค่าก่อน render เพื่อไม่ให้กระตุก)
 * - โหมดย่อซ่อนข้อความเมนู จึงใส่ title ให้ไอคอนเป็น tooltip (ชื่อเมนูยังอยู่ใน DOM ให้ screen reader)
 * - กดหัวกลุ่มพับได้ (.report-nav) ตอนย่อ → กางแถบพร้อมเปิดกลุ่ม แทนการพับ/กางที่มองไม่เห็น
 * - drawer จอเล็ก: เปิดแล้วโฟกัสไปปุ่มปิด · ปิดด้วยปุ่มปิด/backdrop/Escape แล้วคืนโฟกัสปุ่มเมนู · คลิกลิงก์แล้วปิด
 *   CSS ซ่อน drawer ด้วย transform อย่างเดียว ลิงก์จึงยังรับ Tab ได้ — ใส่ inert ให้ drawer ตอนปิด
 *   และให้ Topbar/เนื้อหาหลักตอนเปิด เพื่อให้ Tab อยู่เฉพาะส่วนที่มองเห็น
 *   Escape มีผลเฉพาะตอน drawer เปิด — ไม่ย่อแถบข้างบนเดสก์ท็อป (Escape ที่ใช้ปิด modal อื่นไม่กระทบเมนู)
 *   resize ปิด drawer เฉพาะเมื่อข้ามไปขนาดเดสก์ท็อป (แถบ URL ของมือถือหด/ขยายตอนเลื่อนก็เกิด resize)
 *
 * เรียกซ้ำได้โดยไม่ผูก listener ซ้ำ
 */
export const DESKTOP_MIN_WIDTH = 992;
const STORAGE_KEY = 'smartgoal.sidebar.collapsed';

export function initSidebar(doc = document, {win = doc.defaultView, storage = safeStorage(win)} = {}) {
    const body = doc.body;
    const sidebar = doc.getElementById('appSidebar');
    if (! body || ! sidebar || body.dataset.sidebarReady === 'true') return null;
    body.dataset.sidebarReady = 'true';

    const openButton = doc.querySelector('[data-sidebar-open]');
    const closeButton = sidebar.querySelector('.sidebar-close');
    const background = [...doc.querySelectorAll('.topbar, main.main')];
    const isDesktop = () => win.innerWidth >= DESKTOP_MIN_WIDTH;
    let wasDesktop = isDesktop();

    const remember = (collapsed) => {
        try {
            storage?.setItem(STORAGE_KEY, collapsed ? '1' : '0');
        } catch {
            // เขียน localStorage ไม่ได้ — จำสถานะเฉพาะหน้านี้
        }
    };

    const syncState = () => {
        const collapsed = isDesktop() && body.classList.contains('sidebar-collapsed');
        const drawerOpen = ! isDesktop() && body.classList.contains('sidebar-open');

        sidebar.toggleAttribute('inert', ! isDesktop() && ! drawerOpen);
        background.forEach((element) => element.toggleAttribute('inert', drawerOpen));

        sidebar.querySelectorAll('.nav-item').forEach((link) => {
            const label = link.querySelector('.nav-item__label')?.textContent.trim();
            if (! label) return;
            if (collapsed) link.setAttribute('title', label);
            else link.removeAttribute('title');
        });

        openButton?.setAttribute('aria-expanded', isDesktop()
            ? String(! collapsed)
            : String(body.classList.contains('sidebar-open')));
    };

    const openDrawer = () => {
        body.classList.add('sidebar-open');
        syncState();
        (closeButton ?? sidebar.querySelector('a.nav-item'))?.focus();
    };

    const closeDrawer = ({restoreFocus = true} = {}) => {
        if (! body.classList.contains('sidebar-open')) return;
        body.classList.remove('sidebar-open');
        syncState();
        if (restoreFocus) openButton?.focus();
    };

    openButton?.addEventListener('click', () => {
        if (isDesktop()) {
            remember(body.classList.toggle('sidebar-collapsed'));
            syncState();
        } else if (body.classList.contains('sidebar-open')) {
            closeDrawer();
        } else {
            openDrawer();
        }
    });

    // ปุ่มปิด (จอเล็ก) และ backdrop
    doc.querySelectorAll('[data-sidebar-close]').forEach((target) => target.addEventListener('click', () => {
        if (isDesktop()) {
            body.classList.add('sidebar-collapsed');
            remember(true);
            syncState();
        } else {
            closeDrawer();
        }
    }));

    sidebar.querySelectorAll('.report-nav').forEach((group) => group.querySelector('.report-nav__toggle')?.addEventListener('click', (event) => {
        if (! isDesktop() || ! body.classList.contains('sidebar-collapsed')) return;
        event.preventDefault();
        body.classList.remove('sidebar-collapsed');
        group.open = true;
        remember(false);
        syncState();
    }));

    sidebar.querySelectorAll('a.nav-item').forEach((link) => link.addEventListener('click', () => {
        if (! isDesktop()) closeDrawer({restoreFocus: false});
    }));

    doc.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && ! isDesktop() && body.classList.contains('sidebar-open')) closeDrawer();
    });

    win.addEventListener('resize', () => {
        const desktop = isDesktop();
        if (desktop && ! wasDesktop) body.classList.remove('sidebar-open');
        wasDesktop = desktop;
        syncState();
    });

    syncState();

    return {syncState};
}

function safeStorage(win) {
    try {
        return win?.localStorage ?? null;
    } catch {
        // บางเบราว์เซอร์ปิด localStorage — จำสถานะเฉพาะหน้านี้
        return null;
    }
}

if (typeof document !== 'undefined') initSidebar(document);
