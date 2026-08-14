(() => {
    const workspace = document.querySelector('[data-workspace]');
    const board = workspace?.querySelector('[data-project-board]');
    if (!workspace || !board) return;
    const search = workspace.querySelector('[data-search]');
    const filter = workspace.querySelector('[data-filter]');

    const filterBoard = () => {
        const query = search.value.trim().toLowerCase();
        const status = filter.value;
        let projects = 0;
        board.querySelectorAll('[data-project-card]').forEach((project) => {
            let tasks = 0;
            project.querySelectorAll('[data-board-task]').forEach((task) => {
                const textMatch = !query || task.textContent.toLowerCase().includes(query) || project.dataset.projectName.toLowerCase().includes(query);
                const statusMatch = !status || (status === 'late' ? task.dataset.late === '1' : task.dataset.status === status);
                task.hidden = !(textMatch && statusMatch);
                if (!task.hidden) tasks++;
            });
            project.hidden = tasks === 0;
            if (!project.hidden) projects++;
        });
        board.querySelector('[data-board-empty]').hidden = projects > 0;
    };

    search.addEventListener('input', filterBoard);
    filter.addEventListener('change', filterBoard);
    workspace.querySelectorAll('[data-summary-filter]').forEach((button) => button.addEventListener('click', () => setTimeout(filterBoard))); 
    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-board-open-task]');
        if (!trigger) return;
        const row = workspace.querySelector(`[data-row][data-id="${trigger.dataset.boardOpenTask}"]`);
        row?.querySelector('[data-open-task-modal]')?.click();
    });
    filterBoard();
})();
