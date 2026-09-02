import {synchronizeTaskSource} from './task-state.js';
import {canDragTask, canTransitionTo, confirmTaskTransition, lockReason, nextStepHint} from './task-transitions.js';

export const selectMobileKanbanStatus = (panel, status, {focus = false} = {}) => {
    const value = String(status);
    const tabs = [...panel.querySelectorAll('[data-kanban-status-tab]')];
    const columns = [...panel.querySelectorAll('[data-kanban-column]')];
    const selectedTab = tabs.find((tab) => tab.dataset.kanbanStatusTab === value);

    if (!selectedTab || !columns.some((column) => column.dataset.kanbanColumn === value)) return false;

    panel.dataset.mobileKanbanStatus = value;
    tabs.forEach((tab) => {
        const selected = tab === selectedTab;
        tab.classList.toggle('is-selected', selected);
        tab.setAttribute('aria-selected', String(selected));
        tab.tabIndex = selected ? 0 : -1;
    });
    columns.forEach((column) => {
        column.classList.toggle('is-mobile-selected', column.dataset.kanbanColumn === value);
    });

    selectedTab.scrollIntoView?.({block: 'nearest', inline: 'nearest'});
    if (focus) selectedTab.focus();
    return true;
};

export const refreshMobileKanbanStatusTabs = (panel) => {
    panel.querySelectorAll('[data-kanban-status-tab]').forEach((tab) => {
        const column = panel.querySelector(`[data-kanban-column="${tab.dataset.kanbanStatusTab}"]`);
        const count = column?.querySelectorAll('[data-kanban-card]').length || 0;
        const countNode = tab.querySelector('[data-kanban-tab-count]');
        if (countNode) countNode.textContent = String(count);
    });
};

export const initializeMobileKanbanStatusTabs = (panel) => {
    const tablist = panel.querySelector('[data-kanban-status-tabs]');
    const tabs = [...panel.querySelectorAll('[data-kanban-status-tab]')];
    if (!tablist || tabs.length === 0 || tablist.dataset.initialized === 'true') return;

    tablist.dataset.initialized = 'true';
    tablist.addEventListener('click', (event) => {
        const tab = event.target.closest('[data-kanban-status-tab]');
        if (tab) selectMobileKanbanStatus(panel, tab.dataset.kanbanStatusTab);
    });
    tablist.addEventListener('keydown', (event) => {
        const current = event.target.closest('[data-kanban-status-tab]');
        if (!current || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;

        event.preventDefault();
        const currentIndex = tabs.indexOf(current);
        const nextIndex = event.key === 'Home'
            ? 0
            : event.key === 'End'
                ? tabs.length - 1
                : (currentIndex + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
        selectMobileKanbanStatus(panel, tabs[nextIndex].dataset.kanbanStatusTab, {focus: true});
    });

    const initial = panel.dataset.mobileKanbanStatus
        || tabs.find((tab) => tab.getAttribute('aria-selected') === 'true')?.dataset.kanbanStatusTab
        || tabs[0].dataset.kanbanStatusTab;
    selectMobileKanbanStatus(panel, initial);
    refreshMobileKanbanStatusTabs(panel);
};

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

    const refresh = () => kanban.querySelectorAll('[data-kanban-panel]').forEach((panel) => { let total = 0; panel.querySelectorAll('[data-kanban-column]').forEach((column) => { const count = column.querySelectorAll('[data-kanban-card]').length; column.querySelector('[data-kanban-count]').textContent = count; total += count; }); refreshMobileKanbanStatusTabs(panel); const totalNode = kanban.querySelector('[data-kanban-project-count]'); if (!panel.hidden && totalNode) totalNode.textContent = `${total} งาน`; });

    kanban.querySelector('[data-kanban-project]')?.addEventListener('change', (event) => { kanban.querySelectorAll('[data-kanban-panel]').forEach((panel) => panel.hidden = panel.dataset.kanbanPanel !== event.target.value); const addButton = kanban.querySelector('[data-add-in-group]'); const manageable = event.target.selectedOptions[0]?.dataset.manageable === '1'; if (addButton) { addButton.dataset.listId = manageable ? event.target.value : ''; addButton.disabled = !manageable; } refresh(); });

    document.addEventListener('click', (event) => { const trigger = event.target.closest('[data-open-kanban-task]'); if (!trigger) return; event.preventDefault(); root.querySelector(`[data-row][data-id="${trigger.dataset.openKanbanTask}"] [data-open-task-modal]`)?.click(); });

    kanban.querySelectorAll('[data-kanban-panel]').forEach(initializeMobileKanbanStatusTabs);
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
        // capabilities มาพร้อม event เมื่อการเปลี่ยนสถานะเกิดจากที่อื่น (modal/ตาราง)
        if (task.transitions) management[String(task.id)] = {...management[String(task.id)], transitions: task.transitions};
        paintCardGuidance(card);
        refresh();
    });

    let dragged = null;
    const toast = document.querySelector('[data-toast]');
    const capabilitiesOf = (card) => management[String(card.dataset.id)]?.transitions || {};
    const canDragCard = (card) => canDragTask(Number(card.dataset.status), capabilitiesOf(card));

    // ลากผิดช่องต้องบอกเหตุผล ไม่ใช่เด้งกลับเงียบ ๆ — pattern เดียวกับ table-controls.js
    const notify = (message, ok = false) => {
        if (!toast || !message) return;
        toast.textContent = message;
        toast.style.background = ok ? '#172033' : '#dc2626';
        toast.classList.add('show');
        window.setTimeout(() => toast.classList.remove('show'), 2400);
    };

    const columnLabel = (status) => kanban
        .querySelector(`[data-kanban-column="${status}"] header span`)?.textContent.trim()
        || `สถานะ ${status}`;

    /**
     * เส้นทางเปลี่ยนสถานะเส้นเดียวที่ทั้งการลากและปุ่ม "ขั้นถัดไป" ใช้ร่วมกัน
     *
     * แยกออกมาเพราะบนมือถือลากไม่ได้จริง ๆ — คอลัมน์อื่นถูกซ่อนด้วย is-mobile-selected
     * และ HTML5 drag event ไม่ยิงบน touch ผู้ใช้จึงต้องมีปุ่มกดเป็นทางหลัก ไม่ใช่ทางสำรอง
     */
    const applyTransition = async (card, status) => {
        const previousStatus = Number(card.dataset.status);
        if (previousStatus === status) return;

        const capabilities = capabilitiesOf(card);
        if (!canTransitionTo(previousStatus, status, capabilities)) {
            notify(lockReason(previousStatus, capabilities) || `ย้ายไป "${columnLabel(status)}" ไม่ได้จากสถานะนี้`);
            return;
        }

        const payload = await confirmTaskTransition(previousStatus, status, capabilities);
        if (!payload) return;

        const previousZone = card.parentElement;
        card.dataset.status = String(status);
        kanban.querySelector(`[data-kanban-column="${status}"] .mytasks-kanban__cards`)?.append(card);
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
            if (!response.ok) {
                throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'เปลี่ยนสถานะไม่สำเร็จ');
            }
            if (data.transitions) management[String(card.dataset.id)].transitions = data.transitions;

            const actualStatus = Number(data.job_status ?? status);
            card.dataset.status = String(actualStatus);
            kanban.querySelector(`[data-kanban-column="${actualStatus}"] .mytasks-kanban__cards`)?.append(card);
            refresh();
            paintCardGuidance(card);
            notify(`ย้ายไป "${columnLabel(actualStatus)}" แล้ว`, true);
            synchronizeTaskSource(root, card.dataset.id, {status: actualStatus});
        } catch (error) {
            card.dataset.status = String(previousStatus);
            previousZone?.append(card);
            refresh();
            paintCardGuidance(card);
            notify(error.message);
        }
    };

    /**
     * บอกผู้ใช้ตรง ๆ ว่าการ์ดใบนี้ไปต่อที่ไหนได้ หรือทำไมถึงขยับไม่ได้
     * ทั้งหมดอ่านจาก allowed_statuses ที่ server ส่งมา จึงตรงกับสิ่งที่ระบบยอมรับจริงเสมอ
     */
    const paintCardGuidance = (card) => {
        const status = Number(card.dataset.status);
        const capabilities = capabilitiesOf(card);
        const hint = nextStepHint(status, capabilities);
        const reason = hint ? null : lockReason(status, capabilities);
        // งานที่ปิดแล้วมีขั้นถัดไปก็จริง แต่ต้องทำผ่านเมนู จึงกดจากการ์ดไม่ได้
        const actionable = Boolean(hint) && !hint.viaMenu;

        card.querySelector('[data-kanban-next]')?.remove();

        const badge = document.createElement(actionable ? 'button' : 'span');
        badge.dataset.kanbanNext = '';
        badge.className = `mytasks-kanban__next${actionable ? '' : ' is-locked'}`;

        if (actionable) {
            badge.type = 'button';
            badge.dataset.kanbanNextStatus = String(hint.status);
            badge.textContent = hint.label;
            badge.setAttribute('aria-label', `${hint.label} — ย้ายงานนี้ไป "${columnLabel(hint.status)}"`);
        } else {
            badge.textContent = hint ? `ขั้นถัดไป: ${hint.label}` : reason;
        }

        // การ์ดที่ลากไม่ได้ต้องดูออกว่าลากไม่ได้ แม้จะยังมีขั้นถัดไปผ่านเมนู (เช่นเปิดงานอีกครั้ง)
        const draggable = canDragCard(card);
        card.classList.toggle('is-locked', !draggable);
        const tooltip = reason || (hint?.viaMenu ? 'เปิดงานอีกครั้งได้จากเมนูในรายการงาน' : null);
        if (tooltip) card.title = tooltip;
        else card.removeAttribute('title');

        card.draggable = draggable;
        card.append(badge);
    };

    /**
     * suggest = คำใบ้ก่อนเริ่มลาก (เน้นเฉพาะปลายทางที่แนะนำ)
     * ไม่ suggest = ระหว่างลากจริง (บอกครบว่าช่องไหนวางได้ ช่องไหนวางไม่ได้)
     */
    const markDropTargets = (card, {suggest = false} = {}) => {
        const status = Number(card.dataset.status);
        const capabilities = capabilitiesOf(card);
        const recommended = nextStepHint(status, capabilities);
        kanban.querySelectorAll('[data-kanban-column]').forEach((column) => {
            const target = Number(column.dataset.kanbanColumn);
            const allowed = target !== status && canTransitionTo(status, target, capabilities);
            column.classList.toggle('is-drop-suggested', suggest && allowed && target === recommended?.status);
            if (suggest) return;
            column.classList.toggle('is-drop-allowed', allowed);
            column.classList.toggle('is-drop-blocked', !allowed && target !== status);
        });
    };

    const clearDropTargets = () => kanban.querySelectorAll('[data-kanban-column]').forEach((column) => {
        column.classList.remove('is-drop-allowed', 'is-drop-blocked', 'is-drop-target', 'is-drop-suggested');
    });

    // ปุ่ม "ขั้นถัดไป" ใช้ delegation เพราะการ์ดถูกวาดใหม่ทุกครั้งที่สถานะเปลี่ยน
    kanban.addEventListener('click', (event) => {
        const trigger = event.target.closest('button[data-kanban-next]');
        if (!trigger) return;

        event.preventDefault();
        event.stopPropagation();
        const card = trigger.closest('[data-kanban-card]');
        if (card) applyTransition(card, Number(trigger.dataset.kanbanNextStatus));
    });

    kanban.querySelectorAll('[data-kanban-card]').forEach((card) => {
        paintCardGuidance(card);
        card.querySelectorAll('*').forEach((item) => item.draggable = false);

        // คำใบ้ปลายทางต้องมาตั้งแต่ยังไม่เริ่มลาก คนที่ไม่รู้ว่าการ์ดลากได้จึงจะเห็น
        const previewTarget = () => {
            if (canDragCard(card)) markDropTargets(card, {suggest: true});
        };
        card.addEventListener('mouseenter', previewTarget);
        card.addEventListener('focusin', previewTarget);
        card.addEventListener('mouseleave', clearDropTargets);
        card.addEventListener('focusout', (event) => {
            if (!card.contains(event.relatedTarget)) clearDropTargets();
        });

        card.addEventListener('dragstart', (event) => {
            if (!canDragCard(card)) {
                event.preventDefault();
                return;
            }
            dragged = card;
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', card.dataset.id);
            card.classList.add('is-dragging');
            markDropTargets(card);
        });
        card.addEventListener('dragend', () => {
            card.classList.remove('is-dragging');
            clearDropTargets();
            dragged = null;
        });
    });

    kanban.querySelectorAll('[data-kanban-column]').forEach((column) => {
        const dropZone = column.querySelector('.mytasks-kanban__cards');

        // ปล่อยให้วางได้ทุกช่องแล้วค่อยอธิบายเหตุผล ดีกว่าบล็อก dragover เงียบ ๆ
        // ซึ่งทำให้การ์ดเด้งกลับโดยผู้ใช้ไม่รู้เลยว่าทำไม
        const allowDrop = (event) => {
            if (!dragged) return;
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            const allowed = canTransitionTo(
                Number(dragged.dataset.status),
                Number(column.dataset.kanbanColumn),
                capabilitiesOf(dragged),
            );
            column.classList.toggle('is-drop-target', allowed);
        };

        const drop = async (event) => {
            event.preventDefault();
            column.classList.remove('is-drop-target');
            if (!dragged) return;

            const card = dragged;
            dragged = null;
            await applyTransition(card, Number(column.dataset.kanbanColumn));
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
