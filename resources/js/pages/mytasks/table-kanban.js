import {synchronizeTaskSource} from './task-state.js';
import {canDragTask, canTransitionTo, confirmTaskTransition} from './task-transitions.js';

(() => {
    const root = document.querySelector('[data-workspace]'); const kanban = root?.querySelector('[data-kanban]'); if (!root || !kanban) return;
    const management = JSON.parse(document.querySelector('[data-task-management-data]')?.textContent || '{}');

    const priorityLabels = {
        1: 'routine',
        2: 'สำคัญไม่ด่วน',
        3: 'สำคัญด่วน',
        4: 'ด่วนไม่ค่อยสำคัญ',
        5: 'ไม่รีบ ไม่มีกำหนด',
    };

    const refresh = () => kanban.querySelectorAll('[data-kanban-panel]').forEach((panel) => { let total = 0; panel.querySelectorAll('[data-kanban-column]').forEach((column) => { const count = column.querySelectorAll('[data-kanban-card]').length; column.querySelector('[data-kanban-count]').textContent = count; total += count; }); const totalNode = kanban.querySelector('[data-kanban-project-count]'); if (!panel.hidden && totalNode) totalNode.textContent = `${total} งาน`; });

    kanban.querySelector('[data-kanban-project]')?.addEventListener('change', (event) => { kanban.querySelectorAll('[data-kanban-panel]').forEach((panel) => panel.hidden = panel.dataset.kanbanPanel !== event.target.value); const addButton = kanban.querySelector('[data-add-in-group]'); const manageable = event.target.selectedOptions[0]?.dataset.manageable === '1'; if (addButton) { addButton.dataset.listId = manageable ? event.target.value : ''; addButton.disabled = !manageable; } refresh(); });

    document.addEventListener('click', (event) => { const trigger = event.target.closest('[data-open-kanban-task]'); if (!trigger) return; event.preventDefault(); root.querySelector(`[data-row][data-id="${trigger.dataset.openKanbanTask}"] [data-open-task-modal]`)?.click(); });

    refresh();

    document.addEventListener('mytasks:changed', (event) => {
        const task = event.detail;
        const card = kanban.querySelector(`[data-kanban-card][data-id="${task.id}"]`);
        if (!card) return;

        card.dataset.status = task.status;
        card.dataset.priority = task.priority;
        card.className = `mytasks-kanban__card priority-${task.priority}`;
        card.querySelector('[data-kanban-title]').textContent = task.topic;

        const priorityBadge = card.querySelector('.mytasks-kanban__priority');
        if (priorityBadge) {
            priorityBadge.className = `mytasks-kanban__priority priority-tone-${task.priority}`;
            priorityBadge.textContent = priorityLabels[task.priority] || priorityLabels[2];
        }

        kanban.querySelector(`[data-kanban-column="${task.status}"] .mytasks-kanban__cards`)?.append(card);
        refresh();
    });

    let dragged = null;
    const canDragCard = (card) => canDragTask(
        Number(card.dataset.status),
        management[String(card.dataset.id)]?.transitions || {}
    );

    kanban.querySelectorAll('[data-kanban-card]').forEach((card) => {
        card.draggable = canDragCard(card);
        card.querySelectorAll('*').forEach((item) => item.draggable = false);
        card.addEventListener('dragstart', (event) => {
            if (!canDragCard(card)) {
                event.preventDefault();
                return;
            }
            dragged = card;
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', card.dataset.id);
            card.classList.add('is-dragging');
        });
        card.addEventListener('dragend', () => { card.classList.remove('is-dragging'); dragged = null; });
    });

    kanban.querySelectorAll('[data-kanban-column]').forEach((column) => {
        const dropZone = column.querySelector('.mytasks-kanban__cards');
        const allowDrop = (event) => {
            const capabilities = management[String(dragged?.dataset.id)]?.transitions || {};
            if (!canTransitionTo(Number(dragged?.dataset.status), Number(column.dataset.kanbanColumn), capabilities)) return;
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            column.classList.add('is-drop-target');
        };
        const drop = async (event) => {
            event.preventDefault();
            column.classList.remove('is-drop-target');
            if (!dragged) return;

            const card = dragged;
            const status = Number(column.dataset.kanbanColumn);
            const previousZone = card.parentElement;
            const previousStatus = card.dataset.status;
            if (Number(previousStatus) === status) return;
            const capabilities = management[String(card.dataset.id)]?.transitions || {};
            if (!canTransitionTo(Number(previousStatus), status, capabilities)) return;
            const payload = await confirmTaskTransition(Number(previousStatus), status, management[String(card.dataset.id)]?.transitions || {});
            if (!payload) return;

            card.dataset.status = String(status);
            dropZone?.append(card);
            refresh();

            try {
                const response = await fetch(root.dataset.statusTemplate.replace('__ID__', card.dataset.id), {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                    body: JSON.stringify(payload),
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'status update failed');
                if (data.transitions) management[String(card.dataset.id)].transitions = data.transitions;
                card.draggable = canDragCard(card);
                synchronizeTaskSource(root, card.dataset.id, {status});
            } catch (error) {
                card.dataset.status = previousStatus;
                card.draggable = canDragCard(card);
                previousZone?.append(card);
                refresh();
            }
        };
        [column, dropZone].filter(Boolean).forEach((target) => {
            target.addEventListener('dragover', allowDrop);
            target.addEventListener('dragenter', allowDrop);
            target.addEventListener('drop', drop);
            target.addEventListener('dragleave', (event) => {
                if (!column.contains(event.relatedTarget)) column.classList.remove('is-drop-target');
            });
        });
    });
const projectPriorityMeta = {
    1: { label: 'สำคัญ/ต่ำ', className: 'priority-low' },
    2: { label: 'สำคัญ/กลาง', className: 'priority-medium' },
    3: { label: 'สำคัญ/สูง', className: 'priority-high' },
};

const projectPriorityMenu = kanban.querySelector('[data-kanban-project-priority]');
const projectSelect = kanban.querySelector('[data-kanban-project]');

const syncProjectPriority = () => {
    if (!projectPriorityMenu || !projectSelect) return;

    const option = projectSelect.selectedOptions[0];
    if (!option) return;

    const value = Number(option.dataset.priority || 2);
    const meta = projectPriorityMeta[value] || projectPriorityMeta[2];

    projectPriorityMenu.dataset.url = option.dataset.updateUrl || '';

    const summary = projectPriorityMenu.querySelector('summary');
    const label = projectPriorityMenu.querySelector('[data-kanban-project-priority-label]');

    summary?.classList.remove('priority-low', 'priority-medium', 'priority-high');
    summary?.classList.add(meta.className);

    if (label) label.textContent = meta.label;

    projectPriorityMenu
        .querySelectorAll('[data-kanban-project-priority-value] .bi-check2')
        .forEach((check) => check.remove());

    projectPriorityMenu
        .querySelector(`[data-kanban-project-priority-value="${value}"]`)
        ?.insertAdjacentHTML('beforeend', '<span class="bi bi-check2"></span>');
};

projectSelect?.addEventListener('change', syncProjectPriority);

projectPriorityMenu?.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-kanban-project-priority-value]');
    if (!button) return;

    const value = Number(button.dataset.kanbanProjectPriorityValue);
    const meta = projectPriorityMeta[value];
    const url = projectPriorityMenu.dataset.url;

    if (!meta || !url) return;

    button.disabled = true;

    try {
        const response = await fetch(url, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN':
                    document.querySelector('meta[name="csrf-token"]')?.content || '',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                priority: value,
            }),
        });

        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(data.message || 'เปลี่ยนความสำคัญโปรเจกต์ไม่สำเร็จ');
        }

        const option = projectSelect?.selectedOptions[0];
        if (option) {
            option.dataset.priority = String(value);
        }

        syncProjectPriority();
        projectPriorityMenu.removeAttribute('open');
    } catch (error) {
        window.Swal?.fire({
            icon: 'error',
            title: 'บันทึกไม่สำเร็จ',
            text: error.message,
        });
    } finally {
        button.disabled = false;
    }
});

syncProjectPriority();
})();
