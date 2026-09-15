/*
 * ไทม์ไลน์ของวัน — ตัวกรองประเภท และเมนูจัดการรายแถว
 *
 * ทุกอย่างผูกด้วย event delegation ที่ระดับ container เพียงจุดเดียว แถวที่ถูก
 * แทรกเข้ามาหลังบันทึกสำเร็จจึงใช้งานได้ทันทีโดยไม่ต้องผูก listener ใหม่
 *
 * เมนู "⋯" เป็น popover ไม่ใช่ modal จึงไม่มี backdrop ไม่ล็อกการเลื่อนหน้า
 * ปิดเมื่อคลิกนอกหรือกด Escape และคืนโฟกัสให้ปุ่มที่เปิดมันเสมอ
 */
const MENU_MARKUP = `
    <button type="button" class="log-row-menu__item" data-log-action="edit">
        <i class="bi bi-pencil" aria-hidden="true"></i> แก้ไข
    </button>
    <button type="button" class="log-row-menu__item log-row-menu__item--danger" data-log-action="delete">
        <i class="bi bi-trash" aria-hidden="true"></i> ลบรายการ
    </button>`;

export function initTimeline({
    root = document.querySelector('[data-daily-log]'),
    onEdit = () => {},
    onDelete = () => {},
} = {}) {
    if (! root || root.dataset.timelineReady === 'on') return null;

    // ธงกันการผูก listener ซ้ำ เมื่อ initializer ถูกเรียกมากกว่าหนึ่งครั้ง
    root.dataset.timelineReady = 'on';

    const doc = root.ownerDocument;
    const list = root.querySelector('[data-timeline-list]');
    const statusFilter = root.querySelector('[data-status-filter]');

    /**
     * ปรับหัวกลุ่มให้ตรงกับจำนวนแถวที่อยู่ในกลุ่มนั้นจริง
     *
     * นับจาก DOM ไม่ใช่จากตัวเลขที่ส่งมาพร้อม payload เพราะแถวถูกย้ายข้ามกลุ่ม
     * ได้ทันทีที่กดยืนยัน การมีตัวนับสองแหล่งจะทำให้หัวข้อกับรายการไม่ตรงกัน
     */
    const refreshGroups = () => {
        if (! list) return;
        const cards = [...list.querySelectorAll('[data-log-card]')];
        const count = root.querySelector('[data-timeline-count]');
        const empty = root.querySelector('[data-timeline-empty]');
        if (count) count.textContent = `(${cards.length} รายการ)`;
        cards.forEach((card) => {
            card.hidden = Boolean(statusFilter && statusFilter.value !== 'all'
                && card.dataset.logStatus !== statusFilter.value);
        });
        if (empty) empty.hidden = cards.some((card) => ! card.hidden);
    };
    let menu = null;
    let menuTrigger = null;
    const view = doc.defaultView;

    /** วางเมนูใต้ปุ่ม ชิดขวาของปุ่ม แล้วพลิกขึ้นด้านบนหรือขยับเข้าในจอเมื่อพื้นที่ไม่พอ */
    const placeMenu = (trigger) => {
        const margin = 8;
        const rect = trigger.getBoundingClientRect();
        const box = menu.getBoundingClientRect();
        let top = rect.bottom + 6;
        let placement = 'bottom';

        if (top + box.height > view.innerHeight - margin && rect.top - box.height - 6 >= margin) {
            top = rect.top - box.height - 6;
            placement = 'top';
        }

        const left = Math.min(
            Math.max(margin, rect.right - box.width),
            Math.max(margin, view.innerWidth - box.width - margin)
        );

        menu.style.top = `${Math.round(top)}px`;
        menu.style.left = `${Math.round(left)}px`;
        menu.dataset.placement = placement;
    };

    const closeMenu = ({restoreFocus = true} = {}) => {
        if (! menu) return;

        menu.remove();
        menu = null;

        if (menuTrigger) {
            menuTrigger.setAttribute('aria-expanded', 'false');
            if (restoreFocus && menuTrigger.isConnected) menuTrigger.focus();
            menuTrigger = null;
        }
    };

    const openMenu = (trigger) => {
        const card = trigger.closest('[data-log-card]');

        if (! card) return;

        // เปิดซ้ำที่ปุ่มเดิม = ปิด เพื่อให้กดสองครั้งแล้วกลับสู่สถานะเดิม
        if (menuTrigger === trigger) {
            closeMenu();
            return;
        }

        closeMenu({restoreFocus: false});

        menu = doc.createElement('div');
        menu.className = 'log-row-menu';
        menu.setAttribute('role', 'menu');
        menu.dataset.logMenu = card.dataset.logId || '';
        menu.innerHTML = MENU_MARKUP;

        // ห้ามวางเป็นลูกของการ์ด — รายการอยู่ใน .log-timeline__scroll ที่มี overflow
        // เมนูของแถวท้าย ๆ จึงถูกตัดและไปซ่อนอยู่ในกรอบรายการ วางที่ root แล้วลอยแบบ fixed แทน
        root.appendChild(menu);
        placeMenu(trigger);

        menuTrigger = trigger;
        trigger.setAttribute('aria-expanded', 'true');
        menu.querySelector('[data-log-action]')?.focus();
    };

    const handleClick = (event) => {
        const trigger = event.target.closest('[data-log-menu-trigger]');

        if (trigger && root.contains(trigger)) {
            event.preventDefault();
            openMenu(trigger);
            return;
        }

        const action = event.target.closest('[data-log-action]');

        if (action && menu && menu.contains(action)) {
            event.preventDefault();
            // เมนูไม่ได้อยู่ในการ์ดแล้ว จึงหาแถวจาก id ที่ผูกไว้กับเมนู
            const logId = menu.dataset.logMenu;
            const card = root.querySelector(`[data-log-card][data-log-id="${logId}"]`);
            const name = action.dataset.logAction;

            closeMenu({restoreFocus: false});

            if (name === 'edit') onEdit(logId, card);
            if (name === 'delete') onDelete(logId, card);

            return;
        }

        // คลิกที่อื่นใดในหน้า = ปิด popover แต่ไม่ดึงโฟกัสกลับ เพราะผู้ใช้
        // กำลังตั้งใจไปทำอย่างอื่น
        if (menu && ! menu.contains(event.target)) {
            closeMenu({restoreFocus: false});
        }
    };

    const handleKeydown = (event) => {
        if (event.key === 'Escape' && menu) {
            event.preventDefault();
            closeMenu();
        }
    };

    // เมนูลอยแบบ fixed เลื่อนหน้าหรือเลื่อนรายการแล้วตำแหน่งปุ่มเปลี่ยน ปิดแทนการคำนวณใหม่ทุกเฟรม
    const handleViewportChange = () => closeMenu({restoreFocus: false});

    doc.addEventListener('click', handleClick);
    doc.addEventListener('keydown', handleKeydown);
    view?.addEventListener('scroll', handleViewportChange, true);
    view?.addEventListener('resize', handleViewportChange);
    statusFilter?.addEventListener('change', refreshGroups);
    refreshGroups();

    return {
        closeMenu,
        refreshGroups,
        /**
         * เพิ่มหรือแทนที่แถวหนึ่งจาก HTML ที่เซิร์ฟเวอร์ render มาให้
         *
         * กลุ่มปลายทางมาจาก data-log-status ของการ์ดที่เซิร์ฟเวอร์ส่งมา ไม่ใช่การ
         * เดาฝั่ง client แถวที่เพิ่งถูกยืนยันจึงย้ายจาก "ที่ต้องทำ" ไป "ทำแล้ว"
         * ได้เองโดยไม่ต้องโหลดหน้าใหม่
         */
        upsertCard(html, logId) {
            if (! html) return;

            const holder = doc.createElement('div');
            holder.innerHTML = html.trim();
            const card = holder.firstElementChild;

            if (! card) return;

            if (! list) return;

            // แถวเดิมอาจอยู่คนละกลุ่มกับปลายทาง จึงค้นทั้งหน้าไม่ใช่แค่ในกลุ่มเดียว
            const existing = root.querySelector(`[data-log-card][data-log-id="${logId}"]`);

            existing?.remove();
            list.appendChild(card);
            refreshGroups();
        },
        removeCard(logId) {
            root.querySelector(`[data-log-card][data-log-id="${logId}"]`)?.remove();
            refreshGroups();
        },
        destroy() {
            doc.removeEventListener('click', handleClick);
            doc.removeEventListener('keydown', handleKeydown);
            view?.removeEventListener('scroll', handleViewportChange, true);
            view?.removeEventListener('resize', handleViewportChange);
            statusFilter?.removeEventListener('change', refreshGroups);
            delete root.dataset.timelineReady;
        },
    };
}
