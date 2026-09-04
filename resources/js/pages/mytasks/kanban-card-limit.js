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

        if (expanding) {
            const cards = column.querySelector('.mytasks-kanban__cards');
            const sixthCard = cards?.querySelectorAll(':scope > [data-kanban-card]')[LIMIT];
            if (cards && sixthCard) cards.scrollTop = Math.max(0, sixthCard.offsetTop - cards.offsetTop - 8);
        }
    });

    // Refresh only after known task mutations. Avoid observing our own DOM changes,
    // which previously caused an endless MutationObserver -> refreshAll loop.
    document.addEventListener('mytasks:changed', () => setTimeout(refreshAll, 0));
    kanban.addEventListener('drop', () => setTimeout(refreshAll, 0));

    refreshAll();
})();
