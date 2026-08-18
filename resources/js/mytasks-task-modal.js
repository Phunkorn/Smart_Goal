import {statusClasses, statusMeta, taskPriorityClasses, taskPriorityMeta} from './pages/mytasks/priority-meta.js';

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
    const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (char) => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));
    const rowForTrigger = (trigger) => {
        const id = trigger?.dataset.taskId
            || trigger?.closest('[data-board-task]')?.dataset.taskId
            || trigger?.dataset.openKanbanTask;
        return trigger?.closest('[data-row]') || (id ? workspace.querySelector(`[data-row][data-id="${CSS.escape(String(id))}"]`) : null);
    };
    const renderSubtasks = () => {
        const panel = modal.querySelector('[data-task-subtasks]');
        if (!panel || !activeRow) return;
        const meta = management[String(activeRow.dataset.id)];
        const items = meta?.subtasks || [];
        const list = panel.querySelector('[data-subtask-list]');
        list.innerHTML = items.map((item) => `<article class="${item.completed ? 'is-completed' : ''}"><button type="button" data-toggle-subtask="${item.id}" aria-label="${item.completed ? 'เปิดงานย่อยอีกครั้ง' : 'ทำงานย่อยเสร็จ'}"><i class="bi ${item.completed ? 'bi-check-circle-fill' : 'bi-circle'}"></i></button><span><strong>${escapeHtml(item.title)}</strong>${item.details ? `<small>${escapeHtml(item.details)}</small>` : ''}</span><button type="button" data-edit-subtask="${item.id}" aria-label="แก้ไขงานย่อย"><i class="bi bi-pencil"></i></button></article>`).join('');
        panel.querySelector('[data-subtask-empty]').hidden = items.length > 0;
    };
    const open = (row) => {
        activeRow = row;
        form.elements.job_topic.value = row.dataset.topic || '';
        form.elements.job_details.value = row.dataset.details || '';
        form.elements.job_status.value = row.querySelector('[data-field="status"]')?.value || row.dataset.status;
        form.elements.job_priority.value = row.querySelector('[data-field="priority"]')?.value || row.dataset.priority;
        setModalStatus(Number(form.elements.job_status.value));
        setModalPriority(Number(form.elements.job_priority.value));
        form.elements.job_due_at.value = row.querySelector('[data-field="due"]')?.value || row.dataset.due || '';
        form.elements.job_start_at.value = row.dataset.start || '';
        form.elements.assignee.value = row.dataset.assignee || '';
        const meta = management[String(row.dataset.id)] || {};
        const progress = form.elements.job_progress;
        if (progress) {
            progress.value = meta.progress ?? row.dataset.progress ?? 0;
            progress.disabled = !meta.can_override_progress;
            const help = form.querySelector('[data-modal-progress-help]');
            if (help) help.textContent = meta.can_override_progress ? 'กำหนดได้ 0–99%' : 'คำนวณอัตโนมัติจากงานย่อยหรือสถานะงาน';
        }
        const teamButton = form.querySelector('[data-manage-team]');
        if (teamButton) teamButton.dataset.manageTeam = row.dataset.id;
        const deleteButton = form.querySelector('[data-delete-active-task]');
        if (deleteButton) deleteButton.hidden = !(meta.can_delete && meta.delete_url);
        renderSubtasks();
        modal.hidden = false;
        document.body.classList.add('modal-open');
        requestAnimationFrame(() => form.elements.job_topic.focus());
    };

    const close = () => {
        modal.hidden = true;
        document.body.classList.remove('modal-open');
        activeRow = null;
    };

    document.addEventListener('click', async (event) => {
        const statusOption = event.target.closest('[data-modal-status-value]'); if (statusOption) { setModalStatus(Number(statusOption.dataset.modalStatusValue)); closeModalMenus(); return; }
        const priorityOption = event.target.closest('[data-modal-priority-value]'); if (priorityOption) { setModalPriority(Number(priorityOption.dataset.modalPriorityValue)); closeModalMenus(); return; }
        const summary = event.target.closest('[data-modal-status-menu] > summary, [data-modal-priority-menu] > summary'); if (summary) { closeModalMenus(summary.parentElement); return; }
        if (!event.target.closest('[data-modal-status-menu], [data-modal-priority-menu]')) closeModalMenus();
        const trigger = event.target.closest('[data-open-task-modal]');
        if (trigger) {
            event.preventDefault();
            const row = rowForTrigger(trigger);
            if (row) open(row);
            return;
        }
        const addSubtask = event.target.closest('[data-add-subtask]');
        if (addSubtask && activeRow) {
            const panel = addSubtask.closest('[data-task-subtasks]');
            const title = panel.querySelector('[data-subtask-title]').value.trim();
            const details = panel.querySelector('[data-subtask-details]').value.trim();
            if (!title) return;
            const meta = management[String(activeRow.dataset.id)];
            const editId = addSubtask.dataset.updateSubtask || '';
            const item = meta?.subtasks.find((candidate) => String(candidate.id) === editId);
            addSubtask.disabled = true;
            try {
                await request(editId ? item.update_url : meta.store_subtask_url, editId ? 'PATCH' : 'POST', {title, details});
                window.location.reload();
            } catch (error) {
                addSubtask.disabled = false;
                notify(error.message, true);
            }
            return;
        }
        const editSubtask = event.target.closest('[data-edit-subtask]');
        if (editSubtask && activeRow) {
            const panel = editSubtask.closest('[data-task-subtasks]');
            const item = management[String(activeRow.dataset.id)]?.subtasks.find((candidate) => String(candidate.id) === editSubtask.dataset.editSubtask);
            if (!item) return;
            panel.querySelector('[data-subtask-title]').value = item.title;
            panel.querySelector('[data-subtask-details]').value = item.details || '';
            const add = panel.querySelector('[data-add-subtask]');
            add.dataset.updateSubtask = item.id;
            add.innerHTML = '<i class="bi bi-check-lg"></i> บันทึกงานย่อย';
            panel.querySelector('[data-subtask-title]').focus();
            return;
        }
        const toggleSubtask = event.target.closest('[data-toggle-subtask]');
        if (toggleSubtask && activeRow) {
            const item = management[String(activeRow.dataset.id)]?.subtasks.find((candidate) => String(candidate.id) === toggleSubtask.dataset.toggleSubtask);
            if (!item) return;
            toggleSubtask.disabled = true;
            try {
                await request(item.toggle_url, 'PATCH', {completed: !item.completed});
                window.location.reload();
            } catch (error) {
                toggleSubtask.disabled = false;
                notify(error.message, true);
            }
            return;
        }
        const deleteTask = event.target.closest('[data-delete-active-task]');
        if (deleteTask && activeRow) {
            const meta = management[String(activeRow.dataset.id)];
            const result = await window.Swal.fire({icon: 'warning', title: 'ลบงานนี้หรือไม่?', text: `“${activeRow.dataset.topic}” จะถูกนำออกจากโปรเจกต์`, showCancelButton: true, confirmButtonText: 'ลบงาน', cancelButtonText: 'ยกเลิก', confirmButtonColor: '#dc2626', reverseButtons: true});
            if (!result.isConfirmed) return;
            deleteTask.disabled = true;
            try {
                await request(meta.delete_url, 'DELETE', {});
                window.location.reload();
            } catch (error) {
                deleteTask.disabled = false;
                notify(error.message, true);
            }
            return;
        }
        if (event.target === modal || event.target.closest('[data-close-task]')) close();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.hidden) close();
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!activeRow) return;
        const button = form.querySelector('[type="submit"]');
        const id = activeRow.dataset.id;
        const values = Object.fromEntries(new FormData(form));
        button.disabled = true;
        button.textContent = 'กำลังบันทึก...';
        let mutationSucceeded = false;
        try {
            await request(endpoint(workspace.dataset.detailsTemplate, id), 'PATCH', {
                job_topic: values.job_topic,
                job_details: values.job_details,
            });
            mutationSucceeded = true;
            const jobs = [];
            const currentStatus = activeRow.querySelector('[data-field="status"]');
            const currentPriority = activeRow.querySelector('[data-field="priority"]');
            const currentDue = activeRow.querySelector('[data-field="due"]');
            const currentStart = activeRow.dataset.start || '';
            if (currentStatus && currentStatus.value !== values.job_status) jobs.push(request(endpoint(workspace.dataset.statusTemplate, id), 'PATCH', {job_status: values.job_status}));
            if (currentPriority && currentPriority.value !== values.job_priority) jobs.push(request(endpoint(workspace.dataset.priorityTemplate, id), 'POST', {job_priority: values.job_priority}));
            if (workspace.dataset.scheduleTemplate && (currentStart !== values.job_start_at || (currentDue?.value || activeRow.dataset.due || '') !== values.job_due_at)) {
                jobs.push(request(endpoint(workspace.dataset.scheduleTemplate, id), 'PATCH', {job_start_at: values.job_start_at, job_due_at: values.job_due_at}));
            } else if (!workspace.dataset.scheduleTemplate && currentDue && currentDue.value !== values.job_due_at) {
                jobs.push(request(endpoint(workspace.dataset.dueTemplate, id), 'POST', {job_due_at: values.job_due_at}));
            }
            const meta = management[String(id)];
            if (meta?.can_override_progress && Number(values.job_status) !== 4 && values.job_progress !== undefined && Number(values.job_progress) !== Number(meta.progress)) {
                jobs.push(request(endpoint(workspace.dataset.progressTemplate, id), 'POST', {progress: Number(values.job_progress), note: 'Admin อัปเดตความคืบหน้าจาก Member Workspace'}));
            }
            await Promise.all(jobs);

            activeRow.dataset.topic = values.job_topic;
            activeRow.dataset.details = values.job_details;
            activeRow.dataset.status = values.job_status;
            activeRow.dataset.priority = values.job_priority;
            activeRow.dataset.due = values.job_due_at;
            activeRow.dataset.start = values.job_start_at;
            activeRow.querySelector('.row-title strong').textContent = values.job_topic;
            document.dispatchEvent(new CustomEvent('mytasks:changed', {detail: {id, topic: values.job_topic, status: Number(values.job_status), priority: Number(values.job_priority)}}));
            const details = activeRow.querySelector('.row-title small');
            if (details) details.textContent = values.job_details || 'ยังไม่มีรายละเอียดงาน';
            if (currentStatus) currentStatus.value = values.job_status;
            if (currentPriority) currentPriority.value = values.job_priority;
            if (currentDue) currentDue.value = values.job_due_at;
            notify('บันทึกการแก้ไขงานแล้ว');
            close();
        } catch (error) {
            notify(error.message, true);
            if (mutationSucceeded) window.setTimeout(() => window.location.reload(), 700);
        } finally {
            button.disabled = false;
            button.textContent = 'บันทึกการแก้ไข';
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

    const close = () => {
        modal.hidden = true;
        document.body.classList.remove('modal-open');
    };

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
            modal.hidden = false;
            document.body.classList.add('modal-open');
            return;
        }
        if (event.target === modal || event.target.closest('[data-close-owner]')) close();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.hidden) close();
    });
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
    const select = form.elements['collaborators[]'];
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

    const render = () => {
        const team = activeTeam;
        topic.textContent = team.topic;
        owner.innerHTML = `<span class="team-avatar primary">${escapeHtml(initials(team.assignee.name))}</span><span><strong>${escapeHtml(team.assignee.name)}</strong><small>${escapeHtml(team.assignee.department || 'ไม่ระบุแผนก')}</small></span><b><i class="bi bi-check-circle-fill"></i> ผู้รับผิดชอบหลัก</b>`;
        count.textContent = `${team.collaborators.length} คน`;
        empty.hidden = team.collaborators.length > 0;
        members.innerHTML = team.collaborators.map((person) => {
            const pending = person.status !== 'accepted';
            const status = pending ? 'รอตอบรับ' : 'เข้าร่วมแล้ว';
            return `<article class="team-member"><span class="team-avatar">${escapeHtml(initials(person.name))}</span><span class="team-person"><strong>${escapeHtml(person.name)}</strong><small>${escapeHtml(person.department || 'ไม่ระบุแผนก')}</small></span><span class="team-state ${pending ? 'pending' : 'accepted'}"><i></i>${status}</span>${team.can_manage && !team.locked ? `<button type="button" data-remove-team-member="${person.id}" title="นำ ${escapeHtml(person.name)} ออกจากทีม"><i class="bi bi-x-lg"></i></button>` : ''}</article>`;
        }).join('');

        [...select.options].forEach((option) => {
            option.disabled = team.collaborators.some((person) => String(person.id) === option.value) || String(team.assignee.id) === option.value;
            option.selected = false;
        });
        const canEdit = team.can_manage && !team.locked;
        form.hidden = false;
        select.disabled = !canEdit;
        form.querySelector('[type="submit"]').disabled = !canEdit;
        notice.hidden = canEdit;
        if (!notice.hidden) notice.textContent = team.locked ? 'งานที่เสร็จแล้วถูกล็อกการจัดการทีม' : 'คุณดูรายชื่อทีมได้ แต่ไม่มีสิทธิ์แก้ไข';
    };

    const open = (id) => {
        activeTeam = teams[String(id)];
        if (!activeTeam) return;
        render();
        modal.hidden = false;
        document.body.classList.add('modal-open');
    };
    const close = () => {
        modal.hidden = true;
        activeTeam = null;
        document.body.classList.remove('modal-open');
    };

    document.addEventListener('click', async (event) => {
        const trigger = event.target.closest('[data-manage-team]');
        if (trigger) {
            event.preventDefault();
            trigger.closest('details')?.removeAttribute('open');
            open(trigger.dataset.manageTeam);
            return;
        }
        if (event.target === modal || event.target.closest('[data-close-team]')) {
            close();
            return;
        }
        const remove = event.target.closest('[data-remove-team-member]');
        if (!remove || !activeTeam) return;
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
        const selected = [...select.selectedOptions].map((option) => Number(option.value));
        if (!selected.length || !activeTeam) return;
        const button = form.querySelector('[type="submit"]');
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

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.hidden) close();
    });
})();
(() => {
    const modal = document.querySelector('[data-task-modal]'); const dataNode = document.querySelector('[data-attachment-data]'); const form = modal?.querySelector('[data-task-form]'); const box = modal?.querySelector('[data-task-attachments]');
    if (!modal || !dataNode || !form || !box) return;
    const data = JSON.parse(dataNode.textContent || '{}'); let taskId = null; const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const escape = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const render = () => { const list = box.querySelector('[data-task-inline-files]'); const files = data[String(taskId)]?.files || []; list.innerHTML = files.length ? files.map((file) => `<article><a href="${escape(file.url)}" target="_blank" rel="noopener"><i class="bi bi-file-earmark"></i><span>${escape(file.name)}</span></a>${file.delete_url ? `<button type="button" data-delete-inline-file="${escape(file.delete_url)}" aria-label="ลบไฟล์ ${escape(file.name)}"><i class="bi bi-three-dots-vertical"></i></button>` : ''}</article>`).join('') : '<p>ยังไม่มีไฟล์แนบ</p>'; box.querySelector('[data-task-inline-drop]').hidden = !data[String(taskId)]?.can_upload; };
    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-open-task-modal]');
        if (trigger) {
            const id = trigger.dataset.taskId || trigger.closest('[data-board-task]')?.dataset.taskId;
            taskId = trigger.closest('[data-row]')?.dataset.id || id || null;
            render();
        }
        const remove = event.target.closest('[data-delete-inline-file]'); if (!remove || !taskId) return; if (!window.confirm('ต้องการลบไฟล์นี้ใช่หรือไม่?')) return; fetch(remove.dataset.deleteInlineFile, {method:'DELETE', headers:{Accept:'application/json','X-CSRF-TOKEN':csrf}}).then((response) => { if (!response.ok) throw new Error(); data[String(taskId)].files = data[String(taskId)].files.filter((file) => file.delete_url !== remove.dataset.deleteInlineFile); render(); }).catch(() => window.Swal?.fire({icon:'error',title:'ลบไฟล์ไม่สำเร็จ'})); });
    const upload = async (files) => { const task = data[String(taskId)]; if (!task || !files.length) return; if (task.files.length + files.length > 5 || [...files].some((file) => file.size > 10 * 1024 * 1024)) { window.Swal?.fire({icon:'warning',title:'ตรวจสอบจำนวนหรือขนาดไฟล์',text:'แนบได้รวมไม่เกิน 5 ไฟล์ และไฟล์ละไม่เกิน 10 MB'}); return; } const body = new FormData(); [...files].forEach((file) => body.append('completion_attachments[]', file)); const response = await fetch(task.upload_url, {method:'POST',headers:{Accept:'application/json','X-CSRF-TOKEN':csrf},body}); if (!response.ok) throw new Error(); window.location.reload(); };
    box.querySelector('[data-task-inline-file-input]')?.addEventListener('change', (event) => upload(event.target.files).catch(() => window.Swal?.fire({icon:'error',title:'แนบไฟล์ไม่สำเร็จ'})));
    box.querySelector('[data-task-inline-drop]')?.addEventListener('dragover', (event) => { event.preventDefault(); event.currentTarget.classList.add('is-dragover'); });
    box.querySelector('[data-task-inline-drop]')?.addEventListener('dragleave', (event) => event.currentTarget.classList.remove('is-dragover'));
    box.querySelector('[data-task-inline-drop]')?.addEventListener('drop', (event) => { event.preventDefault(); event.currentTarget.classList.remove('is-dragover'); upload(event.dataTransfer.files).catch(() => window.Swal?.fire({icon:'error',title:'แนบไฟล์ไม่สำเร็จ'})); });
})();
