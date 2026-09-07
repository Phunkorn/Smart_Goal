/*
 * ไทม์ไลน์ของวัน — ตัวกรองประเภท และเมนูจัดการรายแถว
 *
 * ทุกอย่างผูกด้วย event delegation ที่ระดับ container เพียงจุดเดียว แถวที่ถูก
 * แทรกเข้ามาหลังบันทึกสำเร็จจึงใช้งานได้ทันทีโดยไม่ต้องผูก listener ใหม่
 *
 * เมนู "⋯" เป็น popover ไม่ใช่ modal จึงไม่มี backdrop ไม่ล็อกการเลื่อนหน้า
 * ปิดเมื่อคลิกนอกหรือกด Escape และคืนโฟกัสให้ปุ่มที่เปิดมันเสมอ
 */
import {ALL_KINDS, matchesFilter} from './filters.js';

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
    let menu = null;
    let menuTrigger = null;

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

        // วางเทียบกับการ์ดที่เป็นเจ้าของปุ่ม ไม่ใช่ตำแหน่งบนหน้าจอ เมนูจึงเลื่อน
        // ไปพร้อมเนื้อหาและไม่ต้องคำนวณใหม่เมื่อผู้ใช้เลื่อนหน้า
        card.appendChild(menu);
        menu.style.top = `${trigger.offsetTop + trigger.offsetHeight + 6}px`;
        menu.style.right = '12px';

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
            const card = action.closest('[data-log-card]');
            const logId = card?.dataset.logId;
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

    const handleFilter = (event) => {
        const button = event.target.closest('[data-kind-filter]');

        if (! button || ! root.contains(button)) return;

        const kind = button.dataset.kindFilter || ALL_KINDS;

        root.querySelectorAll('[data-kind-filter]').forEach((node) => {
            node.classList.toggle('is-active', node === button);
        });

        root.querySelectorAll('[data-log-card]').forEach((card) => {
            card.hidden = ! matchesFilter(kind, card.dataset.logKind);
        });
    };

    doc.addEventListener('click', handleClick);
    doc.addEventListener('keydown', handleKeydown);
    root.addEventListener('click', handleFilter);

    return {
        closeMenu,
        /** เพิ่มหรือแทนที่แถวหนึ่งจาก HTML ที่เซิร์ฟเวอร์ render มาให้ */
        upsertCard(html, logId) {
            if (! list || ! html) return;

            const holder = doc.createElement('div');
            holder.innerHTML = html.trim();
            const card = holder.firstElementChild;

            if (! card) return;

            const existing = list.querySelector(`[data-log-card][data-log-id="${logId}"]`);

            if (existing) existing.replaceWith(card);
            else list.appendChild(card);

            list.querySelector('[data-timeline-empty]')?.remove();
        },
        removeCard(logId) {
            list?.querySelector(`[data-log-card][data-log-id="${logId}"]`)?.remove();
        },
        destroy() {
            doc.removeEventListener('click', handleClick);
            doc.removeEventListener('keydown', handleKeydown);
            root.removeEventListener('click', handleFilter);
            delete root.dataset.timelineReady;
        },
    };
}
