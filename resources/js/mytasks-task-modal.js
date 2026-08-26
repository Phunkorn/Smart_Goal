import {statusClasses, statusMeta, taskPriorityClasses, taskPriorityMeta} from './pages/mytasks/priority-meta.js';
import {confirmTaskTransition, isModalStatusOptionDisabled} from './pages/mytasks/task-transitions.js';
import {hasWorkspaceChanges, workspaceChanges, workspaceMenuPosition} from './pages/mytasks/task-workspace-model.js';
import {initializePeopleSelectors, selectedIdsOf, setExcludedIds} from './components/people-selector.js';
import {modalStack} from './components/modal-stack.js';

const layers = modalStack(document);
initializePeopleSelectors(document);

(() => {
    const workspace = document.querySelector('[data-workspace]');
    const modal = document.querySelector('[data-task-modal]');
    const form = modal?.querySelector('[data-task-form]');
    if (!workspace || !modal || !form) return;

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const toast = document.querySelector('[data-toast]');
    const managementNode = document.querySelector('[data-task-management-data]');
    const management = managementNode ? JSON.parse(managementNode.textContent || '{}') : {};
    let activeRow = null;

    const notify = (message, error = false) => {
        if (!toast) return;
        toast.textContent = message;
        toast.classList.toggle('error', error);
        toast.classList.add('show');
        window.setTimeout(() => toast.classList.remove('show'), 2600);
    };

    const endpoint = (template, id) => template.replace('__ID__', id);
    const request = async (url, method, payload) => {
        const response = await fetch(url, {
            method,
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf},
            body: JSON.stringify(payload),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            const validation = data.errors ? Object.values(data.errors).flat()[0] : null;
            throw new Error(validation || data.message || 'บันทึกข้อมูลไม่สำเร็จ');
        }
        return data;
    };

    const setModalStatus = (value) => {
        const meta = statusMeta[value]; const menu = form.querySelector('[data-modal-status-menu]'); if (!meta || !menu) return;
        form.elements.job_status.value = String(value); const summary = menu.querySelector('summary'); summary.classList.remove(...statusClasses); summary.classList.add(meta.className); summary.querySelector('[data-modal-status-label]').textContent = meta.label; menu.querySelectorAll('[data-modal-status-value] .bi-check2').forEach((check) => check.remove()); menu.querySelector(`[data-modal-status-value="${value}"]`)?.insertAdjacentHTML('beforeend', '<span class="bi bi-check2"></span>');
    };
    const setModalPriority = (value) => {
        const meta = taskPriorityMeta[value]; const menu = form.querySelector('[data-modal-priority-menu]'); if (!meta || !menu) return;
        form.elements.job_priority.value = String(value); const summary = menu.querySelector('summary'); summary.classList.remove(...taskPriorityClasses); summary.classList.add(meta.className); summary.querySelector('[data-modal-priority-label]').textContent = meta.label; menu.querySelectorAll('[data-modal-priority-value] .bi-check2').forEach((check) => check.remove()); menu.querySelector(`[data-modal-priority-value="${value}"]`)?.insertAdjacentHTML('beforeend', '<span class="bi bi-check2"></span>');
    };
    const closeModalMenus = (except = null) => form.querySelectorAll('[data-modal-status-menu][open], [data-modal-priority-menu][open]').forEach((menu) => { if (menu !== except) menu.removeAttribute('open'); });

    /**
     * แถบสรุปสูงเพียงแถวเดียวและมี overflow:hidden เมนูจึงถูก render แบบ fixed
     * ตำแหน่งต้องคำนวณจากปุ่มจริง และพลิกขึ้นด้านบนเมื่อพื้นที่ด้านล่างไม่พอ
     * มิฉะนั้นเมนูจะตกขอบจอโดยที่ผู้ใช้เลื่อนตามไม่ได้ เพราะ fixed ไม่เลื่อนตามคอนเทนต์
     */
    const positionWorkspaceMenu = (menu) => {
        const trigger = menu.querySelector('summary');
        const panel = menu.querySelector('div');
        if (!trigger || !panel || !menu.open) return;

        const rect = trigger.getBoundingClientRect();
        const {left, top} = workspaceMenuPosition(
            rect,
            {width: panel.offsetWidth || 164, height: panel.offsetHeight || 0},
            {width: window.innerWidth, height: window.innerHeight},
        );

        menu.style.setProperty('--workspace-menu-left', `${left}px`);
        menu.style.setProperty('--workspace-menu-top', `${top}px`);
    };
    const rowForTrigger = (trigger) => {
        const id = trigger?.dataset.taskId
            || trigger?.closest('[data-board-task]')?.dataset.taskId
            || trigger?.dataset.openKanbanTask;
        return trigger?.closest('[data-row]') || (id ? workspace.querySelector(`[data-row][data-id="${CSS.escape(String(id))}"]`) : null);
    };

    const titleText = form.querySelector('[data-workspace-title-text]');
    const titleInput = form.elements.job_topic;
    const renameButton = form.querySelector('[data-rename-task]');
    const projectLabel = form.querySelector('[data-workspace-project]');
    const assigneeOutput = form.querySelector('[data-workspace-assignee]');
    const staticStatus = form.querySelector('[data-static-status]');
    const staticPriority = form.querySelector('[data-static-priority]');
    const statusMenu = form.querySelector('[data-modal-status-menu]');
    const priorityMenu = form.querySelector('[data-modal-priority-menu]');
    const saveButton = form.querySelector('[data-save-task]');
    let snapshot = null;

    /** ค่าที่ผู้ใช้แก้ได้ทั้งหมด ใช้ทั้งตรวจ Unsaved Changes และตัดสินว่าจะส่งอะไรบ้าง */
    const currentValues = () => ({
        job_topic: titleInput.value.trim(),
        job_status: form.elements.job_status.value,
        job_priority: form.elements.job_priority.value,
        job_start_at: form.elements.job_start_at.value,
        job_due_at: form.elements.job_due_at.value,
    });

    const setRenameMode = (editing) => {
        titleInput.hidden = !editing;
        titleText.hidden = editing;
        renameButton.setAttribute('aria-expanded', String(editing));
        if (editing) requestAnimationFrame(() => { titleInput.focus(); titleInput.select(); });
        else titleText.textContent = titleInput.value.trim() || 'ไม่มีชื่องาน';
    };

    /**
     * สิทธิ์จริงมาจาก Policy ฝั่ง server การซ่อน/แสดงที่นี่เป็นเพียงการสื่อสารกับผู้ใช้
     * เมื่อแก้ไม่ได้ ต้องแสดงเป็นข้อความอ่านอย่างเดียว ไม่ใช่ปุ่มที่กดแล้วไม่เกิดอะไร
     */
    const applyPermissions = (meta) => {
        const transitions = meta.transitions || {};
        const canUpdate = meta.can_update !== false && !transitions.is_final;
        form.dataset.readonly = canUpdate ? 'false' : 'true';

        renameButton.hidden = !canUpdate;
        if (!canUpdate) setRenameMode(false);

        statusMenu.hidden = !canUpdate;
        priorityMenu.hidden = !canUpdate;
        staticStatus.hidden = canUpdate;
        staticPriority.hidden = canUpdate;

        form.elements.job_due_at.readOnly = !canUpdate;
        // วันที่เริ่มแก้ได้เฉพาะหน้าที่มี endpoint ตารางเวลา (Workspace ของผู้ดูแล)
        form.elements.job_start_at.readOnly = !canUpdate || !workspace.dataset.scheduleTemplate;

        const teamButton = form.querySelector('[data-manage-team]');
        if (teamButton) teamButton.textContent = meta.can_manage_team ? 'เพิ่มผู้ร่วมงาน' : 'ดูผู้ร่วมงาน';

        modal.querySelector('[data-review-approve]').hidden = !transitions.can_review;
        modal.querySelector('[data-review-return]').hidden = !transitions.can_review;
        modal.querySelector('[data-reopen-task]').hidden = !transitions.can_reopen;
        saveButton.hidden = !canUpdate;

        form.querySelectorAll('[data-modal-status-value]').forEach((button) => {
            button.disabled = isModalStatusOptionDisabled(
                Number(form.elements.job_status.value),
                Number(button.dataset.modalStatusValue),
                transitions,
            );
        });
    };

    const open = (row, opener = null) => {
        activeRow = row;
        const meta = management[String(row.dataset.id)] || {};

        titleInput.value = row.dataset.topic || '';
        setRenameMode(false);
        if (projectLabel) projectLabel.textContent = meta.project || row.dataset.project || row.dataset.projectName || 'งานทั่วไป';

        form.elements.job_status.value = row.querySelector('[data-field="status"]')?.value || row.dataset.status;
        form.elements.job_priority.value = row.querySelector('[data-field="priority"]')?.value || row.dataset.priority;
        setModalStatus(Number(form.elements.job_status.value));
        setModalPriority(Number(form.elements.job_priority.value));
        form.elements.job_due_at.value = row.querySelector('[data-field="due"]')?.value || row.dataset.due || '';
        form.elements.job_start_at.value = row.dataset.start || '';
        form.elements.assignee.value = row.dataset.assignee || '';
        if (assigneeOutput) assigneeOutput.textContent = row.dataset.assignee || 'ไม่ระบุ';
        if (staticStatus) staticStatus.textContent = statusMeta[Number(form.elements.job_status.value)]?.label || '';
        if (staticPriority) staticPriority.textContent = taskPriorityMeta[Number(form.elements.job_priority.value)]?.label || '';

        applyPermissions(meta);

        const teamButton = form.querySelector('[data-manage-team]');
        if (teamButton) teamButton.dataset.manageTeam = row.dataset.id;

        snapshot = JSON.stringify(currentValues());
        layers.open(modal, opener);
    };

    const close = () => {
        layers.close(modal);
        activeRow = null;
        snapshot = null;
    };

    const hasUnsavedChanges = () => snapshot !== null && hasWorkspaceChanges(JSON.parse(snapshot), currentValues());

    /** ยกเลิกและปุ่ม X ทำงานเหมือนกัน และต้องเตือนก่อนทิ้งการแก้ไขที่ยังไม่บันทึก */
    const requestClose = async () => {
        if (!hasUnsavedChanges()) { close(); return; }

        const message = 'ยังมีการแก้ไขที่ยังไม่ได้บันทึก ต้องการปิดโดยไม่บันทึกใช่หรือไม่?';
        const confirmed = window.Swal
            ? (await window.Swal.fire({
                icon: 'warning',
                title: 'ยังไม่ได้บันทึกการแก้ไข',
                text: message,
                showCancelButton: true,
                confirmButtonText: 'ปิดโดยไม่บันทึก',
                cancelButtonText: 'กลับไปแก้ไข',
                confirmButtonColor: '#dc2626',
                reverseButtons: true,
            })).isConfirmed
            : window.confirm(message);

        if (confirmed) close();
    };

    document.addEventListener('click', async (event) => {
        const statusOption = event.target.closest('[data-modal-status-value]'); if (statusOption) { setModalStatus(Number(statusOption.dataset.modalStatusValue)); closeModalMenus(); return; }
        const priorityOption = event.target.closest('[data-modal-priority-value]'); if (priorityOption) { setModalPriority(Number(priorityOption.dataset.modalPriorityValue)); closeModalMenus(); return; }
        const summary = event.target.closest('[data-modal-status-menu] > summary, [data-modal-priority-menu] > summary');
        if (summary) {
            closeModalMenus(summary.parentElement);
            // <details> สลับ open หลังจบ event นี้ จึงต้องวัดตำแหน่งใน task ถัดไป
            window.setTimeout(() => positionWorkspaceMenu(summary.parentElement));
            return;
        }
        if (!event.target.closest('[data-modal-status-menu], [data-modal-priority-menu]')) closeModalMenus();
        const trigger = event.target.closest('[data-open-task-modal]');
        if (trigger) {
            event.preventDefault();
            const row = rowForTrigger(trigger);
            if (row) open(row, trigger);
            return;
        }

        if (event.target.closest('[data-rename-task]')) {
            event.preventDefault();
            setRenameMode(titleInput.hidden);
            return;
        }
        const workflowAction = event.target.closest('[data-review-approve], [data-review-return], [data-reopen-task]');
        if (workflowAction && activeRow) {
            const current = Number(activeRow.dataset.status);
            const target = workflowAction.matches('[data-review-return], [data-reopen-task]') ? 2 : 4;
            const payload = await confirmTaskTransition(current, target, management[String(activeRow.dataset.id)]?.transitions || {});
            if (!payload) return;
            workflowAction.disabled = true;
            try {
                await request(endpoint(workspace.dataset.statusTemplate, activeRow.dataset.id), 'PATCH', payload);
                window.location.reload();
            } catch (error) {
                notify(error.message, true);
                workflowAction.disabled = false;
            }
            return;
        }
        if ((event.target === modal && layers.isTop(modal)) || event.target.closest('[data-close-task]')) requestClose();
    });

    // ปิดด้วย Escape เฉพาะตอนที่ Task Workspace เป็นชั้นบนสุดจริง ๆ
    modal.addEventListener('modalstack:dismiss', () => requestClose());

    document.addEventListener('keydown', (event) => {
        // Enter ในช่องแก้ชื่อคือการยืนยันชื่อ ไม่ใช่การบันทึกทั้งฟอร์ม
        if (event.key === 'Enter' && !modal.hidden && event.target === titleInput) {
            event.preventDefault();
            setRenameMode(false);
        }
    });

    titleInput.addEventListener('blur', () => { if (!titleInput.hidden) setRenameMode(false); });

    // เมนูเป็น fixed จึงไม่เลื่อนตามคอนเทนต์ ปิดทิ้งเมื่อผังหน้าเปลี่ยนดีกว่าปล่อยให้ลอยผิดที่
    window.addEventListener('resize', () => closeModalMenus());
    modal.addEventListener('scroll', () => closeModalMenus(), true);

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        // กันการกดซ้ำระหว่างกำลังบันทึก และกันการยิงซ้ำหลัง Workspace ถูกปิดไปแล้ว
        if (!activeRow || saveButton.disabled) return;

        setRenameMode(false);
        const id = activeRow.dataset.id;
        const values = currentValues();
        const baseline = JSON.parse(snapshot || '{}');

        if (!values.job_topic) {
            notify('กรุณากรอกชื่อรายการงาน', true);
            return;
        }

        const changed = workspaceChanges(baseline, values);
        const currentStatus = activeRow.querySelector('[data-field="status"]');
        const statusChanged = 'job_status' in changed;
        const statusPayload = statusChanged
            ? await confirmTaskTransition(Number(baseline.job_status), Number(values.job_status), management[String(id)]?.transitions || {})
            : null;
        if (statusChanged && !statusPayload) return;

        saveButton.disabled = true;
        saveButton.textContent = 'กำลังบันทึก...';
        let mutationSucceeded = false;
        try {
            const currentPriority = activeRow.querySelector('[data-field="priority"]');
            const currentDue = activeRow.querySelector('[data-field="due"]');
            const datesChanged = 'job_start_at' in changed || 'job_due_at' in changed;

            // ส่งเฉพาะค่าที่เปลี่ยนจริง — job_details ไม่ถูกส่งอีกต่อไป ข้อมูลเดิมในคอลัมน์จึงคงอยู่
            if ('job_topic' in changed) {
                await request(endpoint(workspace.dataset.detailsTemplate, id), 'PATCH', {job_topic: values.job_topic});
                mutationSucceeded = true;
            }

            const jobs = [];
            if (statusPayload) jobs.push(request(endpoint(workspace.dataset.statusTemplate, id), 'PATCH', statusPayload));
            if ('job_priority' in changed) jobs.push(request(endpoint(workspace.dataset.priorityTemplate, id), 'POST', {job_priority: values.job_priority}));
            if (datesChanged) {
                if (workspace.dataset.scheduleTemplate) {
                    jobs.push(request(endpoint(workspace.dataset.scheduleTemplate, id), 'PATCH', {job_start_at: values.job_start_at, job_due_at: values.job_due_at}));
                } else if (workspace.dataset.dueTemplate) {
                    jobs.push(request(endpoint(workspace.dataset.dueTemplate, id), 'POST', {job_due_at: values.job_due_at}));
                }
            }
            if (jobs.length) {
                await Promise.all(jobs);
                mutationSucceeded = true;
            }

            activeRow.dataset.topic = values.job_topic;
            activeRow.dataset.status = values.job_status;
            activeRow.dataset.priority = values.job_priority;
            activeRow.dataset.due = values.job_due_at;
            activeRow.dataset.start = values.job_start_at;
            const rowTitle = activeRow.querySelector('.row-title strong');
            if (rowTitle) rowTitle.textContent = values.job_topic;
            document.dispatchEvent(new CustomEvent('mytasks:changed', {detail: {id, topic: values.job_topic, status: Number(values.job_status), priority: Number(values.job_priority)}}));
            if (currentStatus) currentStatus.value = values.job_status;
            if (currentPriority) currentPriority.value = values.job_priority;
            if (currentDue) currentDue.value = values.job_due_at;

            snapshot = JSON.stringify(values);
            notify(mutationSucceeded ? 'บันทึกการแก้ไขงานแล้ว' : 'ไม่มีการเปลี่ยนแปลงที่ต้องบันทึก');
            close();
        } catch (error) {
            // บันทึกไม่สำเร็จต้องไม่ปิด Workspace ผู้ใช้จะได้แก้ต่อโดยไม่เสียข้อมูลที่กรอกไว้
            notify(error.message, true);
            if (mutationSucceeded) window.setTimeout(() => window.location.reload(), 700);
        } finally {
            saveButton.disabled = false;
            saveButton.textContent = 'บันทึกการแก้ไข';
        }
    });
})();

(() => {
    const modal = document.querySelector('[data-owner-modal]');
    const source = document.querySelector('[data-owner-data]');
    if (!modal || !source) return;

    const owners = JSON.parse(source.textContent || '{}');
    const avatar = modal.querySelector('[data-owner-avatar]');
    const name = modal.querySelector('[data-owner-name]');

    const close = () => layers.close(modal);

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-open-owner]');
        if (trigger) {
            const owner = owners[String(trigger.dataset.openOwner)];
            if (!owner) return;
            event.preventDefault();
            name.textContent = owner.name;
            avatar.replaceChildren();
            if (owner.avatar_url) {
                const image = document.createElement('img');
                image.src = owner.avatar_url;
                image.alt = owner.name;
                avatar.appendChild(image);
            } else {
                avatar.textContent = owner.initial;
            }
            layers.open(modal, trigger);
            return;
        }
        if ((event.target === modal && layers.isTop(modal)) || event.target.closest('[data-close-owner]')) close();
    });

    modal.addEventListener('modalstack:dismiss', close);
})();

(() => {
    const modal = document.querySelector('[data-team-modal]');
    const source = document.querySelector('[data-team-data]');
    const form = modal?.querySelector('[data-team-form]');
    if (!modal || !source || !form) return;

    const teams = JSON.parse(source.textContent || '{}');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const topic = modal.querySelector('[data-team-topic]');
    const owner = modal.querySelector('[data-team-owner]');
    const members = modal.querySelector('[data-team-members]');
    const count = modal.querySelector('[data-team-count]');
    const empty = modal.querySelector('[data-team-empty]');
    const notice = modal.querySelector('[data-team-notice]');
    const selector = modal.querySelector('[data-people-selector]');
    const submit = form.querySelector('[data-team-submit]');
    const submitLabel = form.querySelector('[data-team-submit-label]');

    /** งานที่กำลังจัดการทีมอยู่ ตั้งค่าตอน open() และล้างตอน close() */
    let activeTeam = null;

    const initials = (name) => Array.from(name || '?').slice(0, 1).join('');
    const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (char) => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));
    const request = async (url, method, payload = null) => {
        const response = await fetch(url, {
            method,
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf},
            body: payload ? JSON.stringify(payload) : null,
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'ดำเนินการไม่สำเร็จ');
        return data;
    };

    /** สถานะของสมาชิกปัจจุบัน — บัญชีที่ถูกปิดต้องบอกให้ชัดว่าเพิ่มซ้ำไม่ได้แล้ว */
    const memberState = (person) => {
        if (person.is_active === false) return {className: 'inactive', label: 'ปิดบัญชี'};
        return person.status === 'accepted'
            ? {className: 'accepted', label: 'เข้าร่วมแล้ว'}
            : {className: 'pending', label: 'รอตอบรับ'};
    };

    /** คอลัมน์ขวาส่วนบน: ทีมปัจจุบัน แสดงที่เดียวเท่านั้น */
    const renderCurrentTeam = () => {
        const team = activeTeam;
        const canEdit = team.can_manage && !team.locked;

        count.textContent = `ทีมปัจจุบัน ${team.collaborators.length} คน`;
        empty.hidden = team.collaborators.length > 0;
        members.innerHTML = team.collaborators.map((person) => {
            const state = memberState(person);
            const removable = canEdit && !team.protected_ids.includes(person.id);

            return `<article class="team-member"><span class="team-avatar">${escapeHtml(initials(person.name))}</span>`
                + `<span class="team-person"><strong>${escapeHtml(person.name)}</strong><small>${escapeHtml(person.department || 'ไม่ระบุแผนก')}</small></span>`
                + `<span class="team-state ${state.className}"><i></i>${state.label}</span>`
                + (removable ? `<button type="button" data-remove-team-member="${person.id}" title="นำ ${escapeHtml(person.name)} ออกจากทีม" aria-label="นำ ${escapeHtml(person.name)} ออกจากทีม"><i class="bi bi-x-lg"></i></button>` : '')
                + '</article>';
        }).join('');
    };

    /** ปุ่มหลักบอกจำนวนที่เตรียมเพิ่มเสมอ และกดไม่ได้เมื่อยังไม่ได้เลือกใคร */
    const syncSubmit = () => {
        const staged = selector ? selectedIdsOf(selector) : [];
        const canEdit = activeTeam?.can_manage && !activeTeam?.locked;

        if (submit) submit.disabled = !canEdit || staged.length === 0;
        if (submitLabel) submitLabel.textContent = staged.length ? `เพิ่มผู้ร่วมงาน ${staged.length} คน` : 'เลือกผู้ร่วมงานก่อน';
    };

    const render = () => {
        const team = activeTeam;
        topic.textContent = team.topic;
        owner.innerHTML = `<span class="team-avatar primary">${escapeHtml(initials(team.assignee.name))}</span><span><strong>${escapeHtml(team.assignee.name)}</strong><small>${escapeHtml(team.assignee.department || 'ไม่ระบุแผนก')}</small></span><b><i class="bi bi-check-circle-fill"></i> ผู้รับผิดชอบหลัก</b>`;

        renderCurrentTeam();

        const canEdit = team.can_manage && !team.locked;
        if (selector) {
            selector.dataset.readonly = canEdit ? 'false' : 'true';
            // ผู้รับผิดชอบหลักและสมาชิกปัจจุบันต้องหายไปจากรายการซ้าย ไม่ใช่โผล่แบบสีจาง
            setExcludedIds(selector, [team.assignee.id, ...team.collaborators.map((person) => person.id)].filter(Boolean));
        }

        form.hidden = false;
        notice.hidden = canEdit;
        if (!notice.hidden) notice.textContent = team.locked ? 'งานที่เสร็จแล้วถูกล็อกการจัดการทีม' : 'คุณดูรายชื่อทีมได้ แต่ไม่มีสิทธิ์แก้ไข';
        syncSubmit();
    };

    selector?.addEventListener('peopleselector:change', syncSubmit);

    const open = (id, opener = null) => {
        activeTeam = teams[String(id)];
        if (!activeTeam) return;
        render();
        // ส่งปุ่มที่กดเข้าไปตรง ๆ เพื่อให้ focus กลับมาถูกตัวตอนปิด
        // document.activeElement เชื่อไม่ได้ เพราะการคลิกไม่ได้โฟกัสปุ่มเสมอไป
        layers.open(modal, opener);
    };
    const close = () => {
        layers.close(modal);
        activeTeam = null;
    };

    document.addEventListener('click', async (event) => {
        const trigger = event.target.closest('[data-manage-team]');
        if (trigger) {
            event.preventDefault();
            trigger.closest('details')?.removeAttribute('open');
            open(trigger.dataset.manageTeam, trigger);
            return;
        }
        if ((event.target === modal && layers.isTop(modal)) || event.target.closest('[data-close-team]')) {
            close();
            return;
        }
        const remove = event.target.closest('[data-remove-team-member]');
        if (!remove || !activeTeam) return;

        const member = activeTeam.collaborators.find((person) => String(person.id) === remove.dataset.removeTeamMember);
        const confirmed = window.Swal
            ? (await window.Swal.fire({
                icon: 'warning',
                title: 'นำออกจากทีม',
                text: `ต้องการนำ ${member?.name || 'สมาชิกคนนี้'} ออกจากทีมงานนี้หรือไม่?`,
                showCancelButton: true,
                confirmButtonText: 'นำออก',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#dc2626',
                reverseButtons: true,
            })).isConfirmed
            : window.confirm(`ต้องการนำ ${member?.name || 'สมาชิกคนนี้'} ออกจากทีมงานนี้หรือไม่?`);
        if (!confirmed) return;

        remove.disabled = true;
        try {
            await request(activeTeam.remove_url.replace('__USER__', remove.dataset.removeTeamMember), 'DELETE');
            activeTeam.collaborators = activeTeam.collaborators.filter((person) => String(person.id) !== remove.dataset.removeTeamMember);
            render();
        } catch (error) {
            notice.hidden = false;
            notice.textContent = error.message;
            remove.disabled = false;
        }
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        // เตรียมเพิ่มคือ selection ฝั่ง client เท่านั้น จะกลายเป็นสมาชิกจริงเมื่อ server ตอบกลับ
        const selected = selector ? selectedIdsOf(selector) : [];
        if (!selected.length || !activeTeam) return;
        const button = submit || form.querySelector('[type="submit"]');
        button.disabled = true;
        try {
            await request(activeTeam.add_url, 'POST', {collaborators: selected});
            window.location.reload();
        } catch (error) {
            notice.hidden = false;
            notice.textContent = error.message;
            button.disabled = false;
        }
    });

    modal.addEventListener('modalstack:dismiss', close);
})();
(() => {
    const modal = document.querySelector('[data-task-modal]');
    const dataNode = document.querySelector('[data-attachment-data]');
    const form = modal?.querySelector('[data-task-form]');
    const box = modal?.querySelector('[data-task-attachments]');
    if (!modal || !dataNode || !form || !box) return;

    const data = JSON.parse(dataNode.textContent || '{}');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const list = box.querySelector('[data-task-inline-files]');
    const drop = box.querySelector('[data-task-inline-drop]');
    const fileInput = box.querySelector('[data-task-inline-file-input]');
    const status = box.querySelector('[data-attachment-status]');
    let taskId = null;
    let uploading = false;

    const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[character]));

    const setStatus = (message, isError = false) => {
        if (!status) return;
        status.textContent = message;
        status.classList.toggle('is-error', isError);
        status.hidden = !message;
    };

    const render = () => {
        const task = data[String(taskId)];
        const files = task?.files || [];
        list.innerHTML = files.length
            ? files.map((file) => `<article><a href="${escape(file.url)}" target="_blank" rel="noopener"><i class="bi bi-file-earmark" aria-hidden="true"></i><span>${escape(file.name)}</span></a>${file.delete_url ? `<button type="button" data-delete-inline-file="${escape(file.delete_url)}" aria-label="ลบไฟล์ ${escape(file.name)}"><i class="bi bi-trash3" aria-hidden="true"></i></button>` : ''}</article>`).join('')
            : '<p>ยังไม่มีไฟล์แนบ</p>';
        if (drop) drop.hidden = !task?.can_upload;
    };

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-open-task-modal]');
        if (trigger) {
            const id = trigger.dataset.taskId || trigger.closest('[data-board-task]')?.dataset.taskId;
            taskId = trigger.closest('[data-row]')?.dataset.id || id || null;
            setStatus('');
            render();
        }

        const remove = event.target.closest('[data-delete-inline-file]');
        if (!remove || !taskId || remove.disabled) return;
        if (!window.confirm('ต้องการลบไฟล์นี้ใช่หรือไม่?')) return;

        remove.disabled = true;
        setStatus('กำลังลบไฟล์...');
        fetch(remove.dataset.deleteInlineFile, {method: 'DELETE', headers: {Accept: 'application/json', 'X-CSRF-TOKEN': csrf}})
            .then((response) => {
                if (!response.ok) throw new Error();
                data[String(taskId)].files = data[String(taskId)].files.filter((file) => file.delete_url !== remove.dataset.deleteInlineFile);
                render();
                setStatus('ลบไฟล์แล้ว');
            })
            .catch(() => {
                remove.disabled = false;
                setStatus('ลบไฟล์ไม่สำเร็จ กรุณาลองใหม่อีกครั้ง', true);
            });
    });

    const upload = async (files) => {
        const task = data[String(taskId)];
        // กันการอัปโหลดซ้ำจากการกดหรือวางไฟล์หลายครั้งติดกัน
        if (!task || !files.length || uploading) return;

        if (task.files.length + files.length > 5 || [...files].some((file) => file.size > 10 * 1024 * 1024)) {
            setStatus('แนบได้รวมไม่เกิน 5 ไฟล์ และไฟล์ละไม่เกิน 10 MB', true);
            return;
        }

        uploading = true;
        if (drop) drop.setAttribute('aria-busy', 'true');
        setStatus('กำลังอัปโหลดไฟล์...');
        try {
            const body = new FormData();
            [...files].forEach((file) => body.append('completion_attachments[]', file));
            const response = await fetch(task.upload_url, {method: 'POST', headers: {Accept: 'application/json', 'X-CSRF-TOKEN': csrf}, body});
            if (!response.ok) throw new Error();
            setStatus('อัปโหลดไฟล์สำเร็จ');
            window.location.reload();
        } catch (_) {
            setStatus('แนบไฟล์ไม่สำเร็จ กรุณาลองใหม่อีกครั้ง', true);
        } finally {
            uploading = false;
            if (drop) drop.removeAttribute('aria-busy');
            if (fileInput) fileInput.value = '';
        }
    };

    fileInput?.addEventListener('change', (event) => upload(event.target.files));
    drop?.addEventListener('dragover', (event) => { event.preventDefault(); event.currentTarget.classList.add('is-dragover'); });
    drop?.addEventListener('dragleave', (event) => event.currentTarget.classList.remove('is-dragover'));
    drop?.addEventListener('drop', (event) => {
        event.preventDefault();
        event.currentTarget.classList.remove('is-dragover');
        upload(event.dataTransfer.files);
    });
})();
