const mutableFields = ['topic', 'status', 'priority', 'start', 'due'];

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
