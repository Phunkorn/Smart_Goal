import {projectPriorityClasses, projectPriorityMeta, statusClasses, statusMeta, taskPriorityClasses, taskPriorityMeta} from './priority-meta.js';
import {confirmTaskTransition} from './task-transitions.js';

(() => {
    const workspace = document.querySelector('[data-workspace]');
    const table = workspace?.querySelector('[data-table]');
    if (!workspace || !table) return;

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const toast = document.querySelector('[data-toast]');
    const endpoint = (template, id) => template.replace('__ID__', id);
    const management = JSON.parse(document.querySelector('[data-task-management-data]')?.textContent || '{}');

    const notify = (message, ok = true) => {
        if (!toast) return;
        toast.textContent = message;
        toast.style.background = ok ? '#172033' : '#dc2626';
        toast.classList.add('show');
        window.setTimeout(() => toast.classList.remove('show'), 2400);
    };

    const request = async (url, method, payload) => {
        const response = await fetch(url, {
            method,
            headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest'},
            body: JSON.stringify(payload),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'บันทึกไม่สำเร็จ');
        return data;
    };

    const closeTableMenus = (except = null) => {
        table.querySelectorAll('[data-table-status-menu][open], [data-table-priority-menu][open], [data-table-project-priority-menu][open]').forEach((menu) => {
            if (menu !== except) menu.removeAttribute('open');
        });
    };

    const positionMenu = (menu, leftVariable, topVariable, width) => {
        const summary = menu.querySelector('summary');
        if (!summary || !menu.open) return;
        const rect = summary.getBoundingClientRect();
        menu.style.setProperty(leftVariable, `${Math.max(8, Math.min(rect.left, window.innerWidth - width - 8))}px`);
        menu.style.setProperty(topVariable, `${rect.bottom + 6}px`);
    };

    const updateStatusVisual = (row, menu, value) => {
        const meta = statusMeta[value];
        if (!meta) return;
        row.dataset.status = String(value);
        row.dataset.late = '0';
        const summary = menu.querySelector('summary');
        summary.classList.remove(...statusClasses);
        summary.classList.add(meta.className);
        summary.querySelector('[data-table-status-label]').textContent = meta.label;
        menu.querySelectorAll('[data-table-status-value] .bi-check2').forEach((check) => check.remove());
        menu.querySelector(`[data-table-status-value="${value}"]`)?.insertAdjacentHTML('beforeend', '<span class="bi bi-check2"></span>');
        if (value === 4) {
            const progress = row.querySelector('.row-progress');
            const bar = progress?.querySelector('b');
            const input = progress?.querySelector('input');
            if (bar) bar.style.width = '100%';
            if (input) { input.value = '100'; input.max = '100'; }
        }
    };

    const updatePriorityVisual = (row, menu, value) => {
        const meta = taskPriorityMeta[value];
        if (!meta) return;
        row.dataset.priority = String(value);
        const summary = menu.querySelector('summary');
        summary.classList.remove(...taskPriorityClasses);
        summary.classList.add(meta.className);
        summary.querySelector('[data-table-priority-label]').textContent = meta.label;
        menu.querySelectorAll('[data-table-priority-value] .bi-check2').forEach((check) => check.remove());
        menu.querySelector(`[data-table-priority-value="${value}"]`)?.insertAdjacentHTML('beforeend', '<span class="bi bi-check2"></span>');
    };

    const projectPriorityMenuMarkup = (value, url) => {
        const meta = projectPriorityMeta[value] || projectPriorityMeta[2];
        const options = Object.entries(projectPriorityMeta).map(([optionValue, optionMeta]) => `<button type="button" class="${optionMeta.className}" data-table-project-priority-value="${optionValue}"><i class="bi bi-flag-fill"></i>${optionMeta.projectLabel}${Number(optionValue) === value ? '<span class="bi bi-check2"></span>' : ''}</button>`).join('');
        return `<details class="board-project-priority-menu table-project-priority-menu" data-table-project-priority-menu data-url="${url}"><summary class="board-project-priority ${meta.className}"><i class="bi bi-flag-fill"></i><span data-table-project-priority-label>${meta.projectLabel}</span><i class="bi bi-chevron-down"></i></summary><div>${options}</div></details>`;
    };

    const restoreProjectPriorityControls = () => {
        if (workspace.querySelector('[data-group]')?.value !== 'project') return;
        table.querySelectorAll('[data-group-section]').forEach((section) => {
            const header = section.querySelector('header');
            const row = section.querySelector('[data-row][data-list-id]');
            if (!header || !row?.dataset.listUpdateUrl || header.querySelector('[data-table-project-priority-menu]')) return;
            const priority = Number(row.dataset.listPriority) || 2;
            header.querySelector('.project-pill')?.insertAdjacentHTML('afterend', projectPriorityMenuMarkup(priority, row.dataset.listUpdateUrl));
        });
    };

    new MutationObserver(restoreProjectPriorityControls).observe(table.querySelector('[data-groups]'), {childList: true, subtree: true});
    restoreProjectPriorityControls();

    document.addEventListener('mytasks:changed', (event) => {
        const task = event.detail;
        const row = table.querySelector(`[data-row][data-id="${task.id}"]`);
        if (!row) return;
        const statusMenu = row.querySelector('[data-table-status-menu]');
        const priorityMenu = row.querySelector('[data-table-priority-menu]');
        if (statusMenu && statusMeta[task.status]) updateStatusVisual(row, statusMenu, Number(task.status));
        if (priorityMenu && taskPriorityMeta[task.priority]) updatePriorityVisual(row, priorityMenu, Number(task.priority));
        const statusInput = row.querySelector('input[data-field="status"]');
        const priorityInput = row.querySelector('input[data-field="priority"]');
        if (statusInput) statusInput.value = String(task.status);
        if (priorityInput) priorityInput.value = String(task.priority);
    });
    table.addEventListener('click', async (event) => {
        const summary = event.target.closest('[data-table-status-menu] > summary, [data-table-priority-menu] > summary, [data-table-project-priority-menu] > summary');
        if (summary) {
            const menu = summary.parentElement;
            closeTableMenus(menu);
            window.setTimeout(() => {
                if (menu.matches('[data-table-status-menu]')) positionMenu(menu, '--table-menu-left', '--table-menu-top', 164);
                else if (menu.matches('[data-table-priority-menu]')) positionMenu(menu, '--table-menu-left', '--table-menu-top', 190);
                else positionMenu(menu, '--table-priority-menu-left', '--table-priority-menu-top', 166);
            });
            return;
        }

        const statusOption = event.target.closest('[data-table-status-value]');
        if (statusOption) {
            const menu = statusOption.closest('[data-table-status-menu]');
            const row = statusOption.closest('[data-row]');
            const value = Number(statusOption.dataset.tableStatusValue);
            if (!menu || !row || !statusMeta[value]) return;
            statusOption.disabled = true;
            try {
                const payload = await confirmTaskTransition(Number(row.dataset.status), value, management[String(row.dataset.id)]?.transitions || {});
                if (!payload) return;
                await request(endpoint(workspace.dataset.statusTemplate, row.dataset.id), 'PATCH', payload);
                updateStatusVisual(row, menu, value);
                row.querySelector('input[data-field="status"]')?.setAttribute('value', String(value));
                menu.removeAttribute('open');
                document.dispatchEvent(new CustomEvent('mytasks:changed', {detail: {id: row.dataset.id, topic: row.dataset.topic, status: value, priority: Number(row.dataset.priority)}}));
                notify('เปลี่ยนสถานะงานแล้ว');
            } catch (error) {
                notify(error.message, false);
            } finally {
                statusOption.disabled = false;
            }
            return;
        }

        const priorityOption = event.target.closest('[data-table-priority-value]');
        if (priorityOption) {
            const menu = priorityOption.closest('[data-table-priority-menu]');
            const row = priorityOption.closest('[data-row]');
            const value = Number(priorityOption.dataset.tablePriorityValue);
            if (!menu || !row || !taskPriorityMeta[value]) return;
            priorityOption.disabled = true;
            try {
                await request(endpoint(workspace.dataset.priorityTemplate, row.dataset.id), 'POST', {job_priority: value});
                updatePriorityVisual(row, menu, value);
                row.querySelector('input[data-field="priority"]')?.setAttribute('value', String(value));
                menu.removeAttribute('open');
                document.dispatchEvent(new CustomEvent('mytasks:changed', {detail: {id: row.dataset.id, topic: row.dataset.topic, status: Number(row.dataset.status), priority: value}}));
                notify('เปลี่ยนความสำคัญงานแล้ว');
            } catch (error) {
                notify(error.message, false);
            } finally {
                priorityOption.disabled = false;
            }
            return;
        }

        const projectOption = event.target.closest('[data-table-project-priority-value]');
        if (projectOption) {
            const menu = projectOption.closest('[data-table-project-priority-menu]');
            const section = projectOption.closest('[data-group-section]');
            const value = Number(projectOption.dataset.tableProjectPriorityValue);
            const meta = projectPriorityMeta[value];
            if (!menu || !section || !meta) return;
            projectOption.disabled = true;
            try {
                await request(menu.dataset.url, 'PATCH', {priority: value});
                const projectSummary = menu.querySelector('summary');
                projectSummary.classList.remove(...projectPriorityClasses);
                projectSummary.classList.add(meta.className);
                projectSummary.querySelector('[data-table-project-priority-label]').textContent = meta.projectLabel;
                section.querySelectorAll('[data-row]').forEach((row) => row.dataset.listPriority = String(value));
                menu.querySelectorAll('[data-table-project-priority-value] .bi-check2').forEach((check) => check.remove());
                projectOption.insertAdjacentHTML('beforeend', '<span class="bi bi-check2"></span>');
                menu.removeAttribute('open');
                notify('เปลี่ยนความสำคัญโปรเจกต์แล้ว');
            } catch (error) {
                notify(error.message, false);
            } finally {
                projectOption.disabled = false;
            }
            return;
        }

        if (!event.target.closest('[data-table-status-menu], [data-table-priority-menu], [data-table-project-priority-menu]')) closeTableMenus();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeTableMenus();
    });
})();
