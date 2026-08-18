(() => {
    const kanban = document.querySelector('[data-kanban]');
    if (!kanban) return;

    const LIMIT = 5;

    if (!document.querySelector('[data-kanban-card-limit-style]')) {
        const style = document.createElement('style');
        style.dataset.kanbanCardLimitStyle = '';
        style.textContent = `
            .my-tasks-page .mytasks-kanban__cards.is-expanded {
                max-height: min(62vh, 620px);
                overflow-y: auto;
                padding-right: 4px;
                scrollbar-width: thin;
                scrollbar-color: rgba(100,116,139,.20) transparent;
            }
            .my-tasks-page .mytasks-kanban__cards.is-expanded::-webkit-scrollbar { width: 4px; }
            .my-tasks-page .mytasks-kanban__cards.is-expanded::-webkit-scrollbar-track { background: transparent; }
            .my-tasks-page .mytasks-kanban__cards.is-expanded::-webkit-scrollbar-thumb { background: rgba(100,116,139,.18); border-radius: 999px; }
            .my-tasks-page .mytasks-kanban__cards.is-expanded:hover::-webkit-scrollbar-thumb { background: rgba(100,116,139,.34); }
            .my-tasks-page .mytasks-kanban__more {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 5px;
                width: 100%;
                margin-top: 10px;
                padding: 7px 8px;
                border: 0;
                background: transparent;
                color: #2563eb;
                font: inherit;
                font-size: 11px;
                font-weight: 700;
                cursor: pointer;
            }
            .my-tasks-page .mytasks-kanban__more:hover { color: #1d4ed8; }
            .my-tasks-page .mytasks-kanban__more[hidden] { display: none; }
        `;
        document.head.append(style);
    }

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

    const observer = new MutationObserver((mutations) => {
        if (mutations.some((mutation) => mutation.type === 'childList')) refreshAll();
    });

    observer.observe(kanban, { childList: true, subtree: true });
    refreshAll();
})();
