const mutableFields = ['topic', 'status', 'priority', 'start', 'due'];

export const synchronizeTaskManagement = (management, id, response = {}) => {
    const meta = management?.[String(id)];
    if (!meta || !response.transitions) return meta || null;

    meta.transitions = response.transitions;
    meta.can_work = response.transitions.can_edit === true;

    return meta;
};

const completedGroupFor = (cardGrid, projectKey) => [...cardGrid.querySelectorAll('[data-completed-group]')]
    .find((group) => group.dataset.projectKey === projectKey);

const createCompletedGroup = (cardGrid, task) => {
    const document = cardGrid.ownerDocument;
    const group = document.createElement('details');
    group.className = 'board-completed-group';
    group.dataset.completedGroup = '';
    group.dataset.projectKey = task.dataset.projectKey;

    const summary = document.createElement('summary');
    const icon = document.createElement('i');
    icon.className = 'bi bi-caret-right-fill';
    const title = document.createElement('strong');
    title.textContent = 'งานที่เสร็จแล้ว';
    const count = document.createElement('span');
    summary.append(icon, title, count);

    const rows = document.createElement('div');
    rows.className = 'board-completed-group__rows';
    group.append(summary, rows);

    const children = [...cardGrid.children];
    const headerIndex = children.findIndex((element) => (
        element.matches('[data-project-header]')
        && element.dataset.projectKey === task.dataset.projectKey
    ));
    const nextHeader = children.slice(headerIndex + 1)
        .find((element) => element.matches('[data-project-header]'));
    cardGrid.insertBefore(group, nextHeader || null);

    const header = headerIndex >= 0 ? children[headerIndex] : null;
    group.classList.toggle('is-project-collapsed', header?.classList.contains('is-collapsed') === true);

    return group;
};

export const synchronizeCompletedTaskGroup = (cardGrid, task, status) => {
    if (!cardGrid || !task?.dataset?.projectKey) return null;

    let group = completedGroupFor(cardGrid, task.dataset.projectKey);
    if (Number(status) === 4) {
        group ||= createCompletedGroup(cardGrid, task);
        group.querySelector('.board-completed-group__rows')?.append(task);
    } else if (group?.contains(task)) {
        group.before(task);
    }

    if (!group) return null;
    const rows = group.querySelector('.board-completed-group__rows');
    const count = rows?.querySelectorAll('[data-board-task]').length || 0;
    if (count === 0) {
        group.remove();
        return null;
    }

    const countLabel = group.querySelector(':scope > summary span');
    if (countLabel) countLabel.textContent = `${count} งาน`;
    return group;
};

export const synchronizeTaskSource = (workspace, id, changes, eventTarget = document) => {
    const row = [...workspace.querySelectorAll('[data-workspace-task-source] [data-row]')]
        .find((candidate) => String(candidate.dataset.id) === String(id));
    if (!row) return null;

    mutableFields.forEach((field) => {
        if (Object.hasOwn(changes, field)) row.dataset[field] = String(changes[field] ?? '');
    });

    if (Object.hasOwn(changes, 'status')) {
        const input = row.querySelector('input[data-field="status"]');
        if (input) input.value = String(changes.status);
    }
    if (Object.hasOwn(changes, 'priority')) {
        const input = row.querySelector('input[data-field="priority"]');
        if (input) input.value = String(changes.priority);
    }
    if (Object.hasOwn(changes, 'due')) {
        const input = row.querySelector('input[data-field="due"]');
        if (input) input.value = String(changes.due ?? '');
    }
    if (Object.hasOwn(changes, 'topic')) {
        const title = row.querySelector('.row-title strong');
        if (title) title.textContent = String(changes.topic ?? '');
    }

    const detail = {
        id: String(row.dataset.id),
        topic: row.dataset.topic || '',
        status: Number(row.dataset.status) || 0,
        priority: Number(row.dataset.priority) || 2,
        start: row.dataset.start || '',
        due: row.dataset.due || '',
    };
    eventTarget.dispatchEvent(new CustomEvent('mytasks:changed', {detail}));
    return detail;
};
