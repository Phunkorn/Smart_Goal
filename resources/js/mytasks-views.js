(() => {
    const workspace = document.querySelector('[data-workspace]');
    if (!workspace) return;
    const database = workspace.querySelector('.notion-database');
    const groupSelect = workspace.querySelector('[data-group]');
    const buttons = [...workspace.querySelectorAll('[data-view]')];
    if (!database || !groupSelect || !buttons.length) return;

    let tableGrouping = groupSelect.value;
    const setView = (view, announce = true) => {
        if (!['table', 'board'].includes(view)) view = 'table';
        database.dataset.view = view;
        buttons.forEach((button) => {
            const active = button.dataset.view === view;
            button.classList.toggle('active', active);
            button.setAttribute('aria-selected', String(active));
        });

        groupSelect.disabled = false;
        groupSelect.closest('.notion-group')?.classList.remove('is-locked');
        if (view === 'table' && groupSelect.value !== tableGrouping) {
            groupSelect.value = tableGrouping;
            groupSelect.dispatchEvent(new Event('change', {bubbles: true}));
        }

        try { localStorage.setItem('smart-goal-my-tasks-view', view); } catch (_) {}
        if (announce) document.querySelector('[data-toast]')?.dispatchEvent(new CustomEvent('viewchange'));
    };

    buttons.forEach((button) => {
        button.onclick = () => setView(button.dataset.view);
    });

    groupSelect.addEventListener('change', () => {
        if (database.dataset.view !== 'board') tableGrouping = groupSelect.value;
    });

    let saved = 'table';
    try { saved = localStorage.getItem('smart-goal-my-tasks-view') || 'table'; } catch (_) {}
    setView(saved, false);
})();
