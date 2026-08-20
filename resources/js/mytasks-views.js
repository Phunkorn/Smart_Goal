import {boardFilterStateFrom, normalizeTaskScope, parametersForTaskWorkspace} from './pages/mytasks/task-filter-state.js';

(() => {
    const workspace = document.querySelector('[data-workspace]');
    if (!workspace) return;
    const database = workspace.querySelector('.notion-database');
    const groupSelect = workspace.querySelector('[data-group]');
    const buttons = [...workspace.querySelectorAll('[data-view]')];
    const boardToolbar = workspace.querySelector('[data-board-toolbar]');
    const scopeControl = workspace.querySelector('[data-task-scope-control]');
    const scopeSelect = workspace.querySelector('[data-task-scope]');
    if (!database) return;
    if (!buttons.length) {
        database.dataset.view = 'table';
        return;
    }

    let tableGrouping = groupSelect?.value || 'project';
    const setView = (view, announce = true) => {
        if (!['table', 'board', 'calendar'].includes(view)) view = 'table';
        database.dataset.view = view;
        if (boardToolbar) boardToolbar.hidden = view !== 'board';
        if (scopeControl) scopeControl.hidden = view === 'calendar';
        buttons.forEach((button) => {
            const active = button.dataset.view === view;
            button.classList.toggle('active', active);
            button.setAttribute('aria-selected', String(active));
        });
        workspace.querySelectorAll('[data-view-panel]').forEach((panel) => {
            panel.setAttribute('aria-hidden', String(panel.dataset.viewPanel !== view));
        });

        if (groupSelect) groupSelect.disabled = false;
        groupSelect?.closest('.notion-group')?.classList.remove('is-locked');
        if (view === 'table' && groupSelect && groupSelect.value !== tableGrouping) {
            groupSelect.value = tableGrouping;
            groupSelect.dispatchEvent(new Event('change', {bubbles: true}));
        }

        try { localStorage.setItem('smart-goal-my-tasks-view', view); } catch (_) {}
        document.dispatchEvent(new CustomEvent('mytasks:viewchange', {detail: {view}}));
        if (announce) document.querySelector('[data-toast]')?.dispatchEvent(new CustomEvent('viewchange'));
    };

    buttons.forEach((button) => {
        button.onclick = () => setView(button.dataset.view);
    });

    scopeSelect?.addEventListener('change', () => {
        if (workspace.dataset.context !== 'user') return;

        const url = new URL(window.location.href);
        const state = boardFilterStateFrom(url.searchParams);
        url.search = parametersForTaskWorkspace(
            url.searchParams,
            state,
            normalizeTaskScope(scopeSelect.value),
        ).toString();
        window.location.assign(url);
    });

    groupSelect?.addEventListener('change', () => {
        if (database.dataset.view !== 'board') tableGrouping = groupSelect.value;
    });

    let saved = 'table';
    try { saved = localStorage.getItem('smart-goal-my-tasks-view') || 'table'; } catch (_) {}
    setView(saved, false);
})();
