/*
 * คอลัมน์ของมุมมองตารางแสดงการ์ดได้ 5 ใบ ที่เหลือซ่อนไว้หลังปุ่ม "ดูเพิ่ม"
 *
 * สไตล์ของทั้งชุดอยู่ใน task-workspace/kanban.css ไม่ใช่ <style> ที่ฉีดจากที่นี่
 * ของเดิมฉีดจาก JS ทำให้กฎ CSS ของคอลัมน์อยู่คนละที่กับกฎอื่นของคอลัมน์เดียวกัน
 * และแก้ปัญหาการตัดขอบไม่ได้เพราะมองไม่เห็นว่ามีกฎซ้อนกันอยู่
 */
(() => {
    const kanban = document.querySelector('[data-kanban]');
    if (!kanban) return;

    const LIMIT = 5;


    /**
     * ตั้งความสูงของกล่องที่กางแล้วให้เท่ากับการ์ดห้าใบพอดี
     *
     * วัดจากขอบบนของการ์ดใบที่หกจริง ไม่ใช่คูณความสูงเฉลี่ย เพราะการ์ดแต่ละใบ
     * สูงไม่เท่ากัน (ชื่องานยาวไม่เท่ากัน บางใบมีปุ่มสถานะเพิ่ม) ค่าคงที่อย่าง
     * min(62vh, 620px) แบบเดิมจึงไปตกกลางการ์ดเสมอ
     *
     * ลบ gap ออกหนึ่งช่วง เพื่อให้ขอบล่างอยู่ที่รอยต่อพอดี ไม่เหลือช่องว่างลอย
     * แล้วเผยขอบบนของใบที่หกโผล่มานิดหนึ่ง
     */
    const applyExpandedHeight = (cards, items, expanded) => {
        if (!expanded || items.length <= LIMIT) {
            cards.style.removeProperty('--kanban-expanded-height');

            return;
        }

        const sixth = items[LIMIT];
        // getBoundingClientRect ไม่ขึ้นกับ offsetParent จึงได้ค่าถูกต้องแม้กล่องแม่
        // จะถูกจัดวางด้วย transform หรือ position ที่ต่างกันในแต่ละมุมมอง
        const gap = parseFloat(getComputedStyle(cards).rowGap) || 0;
        const height = sixth.getBoundingClientRect().top
            - cards.getBoundingClientRect().top
            - gap;

        if (height > 0) cards.style.setProperty('--kanban-expanded-height', `${Math.round(height)}px`);
    };

    const refreshColumn = (column) => {
        const cards = column.querySelector('.mytasks-kanban__cards');
        if (!cards) return;

        const items = [...cards.querySelectorAll(':scope > [data-kanban-card]')];
        let more = column.querySelector(':scope > [data-kanban-more]');

        if (!more) {
            more = document.createElement('button');
            more.type = 'button';
            more.className = 'mytasks-kanban__more';
            more.dataset.kanbanMore = '';
            more.dataset.expanded = '0';
            column.append(more);
        }

        const overflow = Math.max(0, items.length - LIMIT);
        const expanded = more.dataset.expanded === '1' && overflow > 0;

        items.forEach((card, index) => {
            card.hidden = !expanded && index >= LIMIT;
        });

        cards.classList.toggle('is-expanded', expanded);
        applyExpandedHeight(cards, items, expanded);
        more.hidden = overflow === 0;
        more.innerHTML = expanded
            ? '<i class="bi bi-chevron-up"></i> ย่อรายการ'
            : `<i class="bi bi-chevron-down"></i> ดูเพิ่มอีก ${overflow} งาน`;
    };

    const refreshAll = () => {
        kanban.querySelectorAll('[data-kanban-column]').forEach(refreshColumn);
    };

    kanban.addEventListener('click', (event) => {
        const button = event.target.closest('[data-kanban-more]');
        if (!button) return;

        const column = button.closest('[data-kanban-column]');
        if (!column) return;

        const expanding = button.dataset.expanded !== '1';
        button.dataset.expanded = expanding ? '1' : '0';
        refreshColumn(column);

        // ไม่เลื่อนตำแหน่งให้เอง
        //
        // กล่องเปิดมาที่ใบแรกเสมอ ผู้ใช้จึงเห็นห้าใบเดิมที่กำลังมองอยู่ไม่หายไปไหน
        // แล้วเลื่อนลงดูส่วนที่เพิ่งเปิดเองตามจังหวะของตัวเอง การสั่งเลื่อนให้มีแต่จะ
        // ดึงตำแหน่งหนีจากจุดที่กำลังมองอยู่
    });

    // Refresh only after known task mutations. Avoid observing our own DOM changes,
    // which previously caused an endless MutationObserver -> refreshAll loop.
    document.addEventListener('mytasks:changed', () => setTimeout(refreshAll, 0));
    kanban.addEventListener('drop', () => setTimeout(refreshAll, 0));

    refreshAll();
})();
