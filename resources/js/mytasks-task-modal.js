import {statusClasses, statusMeta, taskPriorityClasses, taskPriorityMeta, unsupportedStatusMeta} from './pages/mytasks/priority-meta.js';
import {confirmTaskTransition, isModalStatusOptionDisabled, isModalStatusOptionHidden} from './pages/mytasks/task-transitions.js';
import {hasWorkspaceChanges, workspaceChanges, workspaceMenuPosition} from './pages/mytasks/task-workspace-model.js';
import {clearPeopleSelection, initializePeopleSelectors, selectedIdsOf, setExcludedIds} from './components/people-selector.js';
import {modalStack} from './components/modal-stack.js';
import {publishTaskState, synchronizeTaskSource} from './pages/mytasks/task-state.js';
import {taskStateStore} from './pages/mytasks/task-state-store.js';
import {attachmentLimits, attachmentStore, publishTaskFiles} from './pages/mytasks/attachment-store.js';
// ไอคอนตามชนิดไฟล์ใช้ตารางร่วมกับกล่องดาวน์โหลดไฟล์แนบ ไม่มีสำเนาแยกอีกชุด
import {extensionOf, iconFor, toneFor} from './pages/mytasks/attachment-icons.js';
// การลบไฟล์แนบใช้โมดูลร่วมกับกล่องไฟล์แนบที่เปิดจากปุ่มคลิปหนีบ ไม่มีสำเนาแยก
import {confirmAttachmentDelete, deleteTaskAttachment} from './pages/mytasks/attachment-delete.js';
// ป้ายเวลากำหนดส่งใช้ตัวช่วยชุดเดียวกับบอร์ด ตาราง และปฏิทิน ไม่มีสำเนาแยก
import {NO_DUE_LABEL, dueClockLabel, isDueTimeUnset} from './pages/mytasks/due-time.js';
// ป้ายวันแบบ พ.ศ. ใบเดียวกับที่ปฏิทินของช่องวันที่แสดง ผู้ใช้จึงเห็นวันเดียวกันทั้งก่อนและหลังเลือก
import {parseDateValue, thaiDateLabel} from './components/date-picker.js';

const layers = modalStack(document);
initializePeopleSelectors(document);

// SweetAlert2 แปะ container ไว้ที่ <body> ด้วย z-index 1060 ซึ่งต่ำกว่าโมดัลของ workspace ทุกตัว
// กล่องยืนยันที่เปิดจากในโมดัลจึงต้องยกเลเยอร์เสมอ ไม่งั้นจะไปโผล่อยู่ข้างหลังจนกดไม่ได้
const workspaceDialogLayer = {customClass: {container: 'task-workspace-dialog'}};

(() => {
    const workspace = document.querySelector('[data-workspace]');
    const modal = document.querySelector('[data-task-modal]');
    const form = modal?.querySelector('[data-task-form]');
    if (!workspace || !modal || !form) return;

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const toast = document.querySelector('[data-toast]');
    const management = taskStateStore(document);
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
    const assigneeAvatar = form.querySelector('[data-workspace-assignee-avatar]');
    // ไอคอนที่ Blade วาดไว้คือค่าตั้งต้น เก็บไว้คืนเมื่องานที่เปิดถัดไปไม่มีรูปโปรไฟล์
    const assigneeFallbackIcon = assigneeAvatar?.firstElementChild?.cloneNode(true) || null;
    // ชุดเดียวกับกล่องข้อมูลผู้รับผิดชอบและปฏิทิน ทุก URL ผ่าน MediaController แล้ว
    const owners = JSON.parse(document.querySelector('[data-owner-data]')?.textContent || '{}');
    const staticStatus = form.querySelector('[data-static-status]');
    const staticPriority = form.querySelector('[data-static-priority]');
    const statusMenu = form.querySelector('[data-modal-status-menu]');
    const priorityMenu = form.querySelector('[data-modal-priority-menu]');
    const saveButton = form.querySelector('[data-save-task]');
    const dueInput = form.elements.job_due_at;
    const dueUnsetOutput = form.querySelector('[data-workspace-due-unset]');
    let snapshot = null;

    /** วาดรูปโปรไฟล์ของผู้รับผิดชอบลงในช่องไอคอน ไม่มีรูปก็กลับเป็นไอคอนเดิม */
    const paintAssigneeAvatar = (owner) => {
        if (!assigneeAvatar) return;
        const url = owner?.avatar_url || '';
        assigneeAvatar.classList.toggle('has-avatar', Boolean(url));

        if (!url) {
            assigneeAvatar.replaceChildren(...(assigneeFallbackIcon ? [assigneeFallbackIcon.cloneNode(true)] : []));
            return;
        }

        const image = document.createElement('img');
        image.src = url;
        // ชื่อผู้รับผิดชอบอยู่ข้างรูปอยู่แล้ว รูปจึงเป็นของตกแต่งสำหรับโปรแกรมอ่านจอ
        image.alt = '';
        assigneeAvatar.replaceChildren(image);
    };

    /** เจตนา "ไม่ระบุเวลากำหนดส่ง" ของช่องในโมดัล เก็บไว้ที่ dataset ของช่องเหมือนทุกมุมมอง */
    const dueTimeUnset = () => dueInput.dataset.timeUnset === '1';

    /** เจตนา "ไม่มีกำหนดส่ง" (job_due_at เป็น null) — ปฏิทินเขียนลง dataset ของช่องเดียวกัน */
    const dueUnset = () => dueInput.dataset.dueUnset === '1';

    /*
     * วาดเซลล์กำหนดส่งใหม่จากเจตนาที่ช่องถืออยู่
     *
     * ช่องของเบราว์เซอร์แสดงได้แต่ "วัน + เวลา" จึงต้องซ่อนมันแล้วแสดงข้อความแทน
     * เมื่อไม่ระบุเวลา — ไม่ใช่โชว์ 23:59 น. ที่ผู้ใช้ไม่ได้ตั้งไว้เอง
     */
    const paintDueCell = () => {
        const noDue = dueUnset();
        const unset = dueTimeUnset();
        dueInput.hidden = unset || noDue;
        // ช่องที่ซ่อนและว่างแต่ยัง required จะทำให้เบราว์เซอร์บล็อกการ submit โดยไม่บอกผู้ใช้ว่าติดที่ไหน
        dueInput.required = !noDue;
        if (!dueUnsetOutput) return;

        dueUnsetOutput.hidden = !unset && !noDue;
        if (noDue) {
            dueUnsetOutput.textContent = NO_DUE_LABEL;
            return;
        }
        if (!unset) return;

        const [day = ''] = String(dueInput.value || '').split('T');
        const date = parseDateValue(day);
        dueUnsetOutput.textContent = [date ? thaiDateLabel(date) : '', dueClockLabel('', true)]
            .filter(Boolean)
            .join(' · ');
    };

    /** ปฏิทินเขียน data-time-unset ลงช่องแล้วยิง change — เซลล์จึงต้องวาดใหม่ทันที */
    dueInput.addEventListener('change', paintDueCell);

    /** ค่าที่ผู้ใช้แก้ได้ทั้งหมด ใช้ทั้งตรวจ Unsaved Changes และตัดสินว่าจะส่งอะไรบ้าง */
    const currentValues = () => ({
        job_topic: titleInput.value.trim(),
        job_status: form.elements.job_status.value,
        job_priority: form.elements.job_priority.value,
        job_start_at: form.elements.job_start_at.value,
        // ช่องยังค้างวันเดิมไว้ให้ติ๊กกลับได้ แต่ค่าที่ผู้ใช้ตั้งใจคือ "ไม่มี" จึงต้องไม่รายงานวันนั้นออกไป
        job_due_at: dueUnset() ? '' : form.elements.job_due_at.value,
        // เจตนาเป็นค่าที่ผู้ใช้แก้ได้จริง จึงต้องอยู่ในชุดที่ตรวจ Unsaved Changes ด้วย
        // ไม่งั้นการติ๊ก "ไม่ระบุเวลา" อย่างเดียวจะถูกนับว่า "ไม่มีการเปลี่ยนแปลง"
        job_due_time_unset: dueTimeUnset() ? '1' : '0',
        job_due_unset: dueUnset() ? '1' : '0',
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
        const canUpdate = meta.can_work === true && !transitions.is_final;
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
        if (teamButton) teamButton.textContent = meta.can_manage_team ? 'จัดการผู้เข้าร่วม' : 'ดูผู้เข้าร่วม';

        modal.querySelector('[data-review-approve]').hidden = !transitions.can_review;
        modal.querySelector('[data-review-return]').hidden = !transitions.can_review;
        modal.querySelector('[data-reopen-task]').hidden = !transitions.can_reopen;
        saveButton.hidden = !canUpdate;

        form.querySelectorAll('[data-modal-status-value]').forEach((button) => {
            button.hidden = isModalStatusOptionHidden(Number(button.dataset.modalStatusValue), transitions);
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
        /*
         * ช่องในฟอร์มเป็น datetime-local ค่าที่ใส่จึงต้องเป็น 'Y-m-dTH:i' เต็มรูปแบบ
         * dataset เก็บวันกับเวลาแยกกัน (data-due / data-due-time) เพราะปฏิทินและตัวกรอง
         * ทำงานระดับวัน ตรงนี้จึงประกอบคืนก่อนใส่ ไม่งั้นเวลาที่ผู้ใช้ตั้งไว้จะถูกล้างทุกครั้งที่เปิด
         */
        const joinSchedule = (date, time) => (date && time ? `${date}T${time}` : (date || ''));
        form.elements.job_due_at.value = row.querySelector('[data-field="due"]')?.value
            || joinSchedule(row.dataset.due, row.dataset.dueTime);
        form.elements.job_start_at.value = joinSchedule(row.dataset.start, row.dataset.startTime);
        // แถวต้นทางเป็นเจ้าของเจตนา ช่องในโมดัลจึงอ่านจากที่นั่น ไม่เก็บสถานะของตัวเองไว้ข้ามการเปิด
        dueInput.dataset.timeUnset = isDueTimeUnset(row.dataset.dueTimeUnset) ? '1' : '0';
        // data-due ว่างคืองานที่ไม่มีกำหนดส่ง — ไม่มีธงแยกที่แถว (ดู pages/mytasks/due-time.js)
        dueInput.dataset.dueUnset = row.dataset.due ? '0' : '1';
        paintDueCell();
        form.elements.assignee.value = row.dataset.assignee || '';
        if (assigneeOutput) assigneeOutput.textContent = row.dataset.assignee || 'ไม่ระบุ';
        paintAssigneeAvatar(owners[String(row.dataset.id)]);
        if (staticStatus) staticStatus.textContent = statusMeta[Number(form.elements.job_status.value)]?.label || unsupportedStatusMeta.label;
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
                ...workspaceDialogLayer,
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
                const data = await request(endpoint(workspace.dataset.statusTemplate, activeRow.dataset.id), 'PATCH', payload);
                publishTaskState(workspace, activeRow.dataset.id, data, target);
                close();
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
            const datesChanged = 'job_start_at' in changed
                || 'job_due_at' in changed
                || 'job_due_time_unset' in changed
                || 'job_due_unset' in changed;

            // ส่งเฉพาะค่าที่เปลี่ยนจริง — job_details ไม่ถูกส่งอีกต่อไป ข้อมูลเดิมในคอลัมน์จึงคงอยู่
            if ('job_topic' in changed) {
                await request(endpoint(workspace.dataset.detailsTemplate, id), 'PATCH', {job_topic: values.job_topic});
                mutationSucceeded = true;
            }

            let canonicalStatus = Number(values.job_status);
            let canonicalDue = values.job_due_at;
            let canonicalDueUnset = values.job_due_time_unset === '1';
            let canonicalNoDue = values.job_due_unset === '1';
            let canonicalTransitions = null;
            const jobs = [];
            if ('job_priority' in changed) jobs.push(request(endpoint(workspace.dataset.priorityTemplate, id), 'POST', {job_priority: values.job_priority}));
            if (datesChanged) {
                let scheduleResult = null;
                const noDuePayload = values.job_due_unset === '1';
                // งานที่ไม่มีวันส่งไม่มีเวลาให้ "ไม่ระบุ" — server ล้างธงนี้อยู่แล้ว ที่นี่แค่ไม่ส่งค่าที่ขัดกันไป
                const dueUnsetPayload = !noDuePayload && values.job_due_time_unset === '1';
                const duePayload = noDuePayload ? null : values.job_due_at;
                if (workspace.dataset.scheduleTemplate) {
                    scheduleResult = await request(endpoint(workspace.dataset.scheduleTemplate, id), 'PATCH', {job_start_at: values.job_start_at, job_due_at: duePayload, job_due_unset: noDuePayload, job_due_time_unset: dueUnsetPayload});
                } else if (workspace.dataset.dueTemplate) {
                    scheduleResult = await request(endpoint(workspace.dataset.dueTemplate, id), 'POST', {job_due_at: duePayload, job_due_unset: noDuePayload, job_due_time_unset: dueUnsetPayload});
                }
                if (scheduleResult) {
                    canonicalStatus = Number(scheduleResult.job_status ?? canonicalStatus);
                    canonicalDueUnset = isDueTimeUnset(scheduleResult.job_due_time_unset);
                    canonicalNoDue = scheduleResult.job_due_unset === true;
                    // server ตอบกลับวันกับเวลาแยกกัน ประกอบคืนให้ครบก่อนใช้เป็นค่าอ้างอิง
                    // งานที่ไม่มีกำหนดส่งตอบวันกลับมาเป็นค่าว่าง ซึ่งคือค่าจริง ไม่ใช่ "ไม่ได้ส่งมา"
                    canonicalDue = canonicalNoDue
                        ? ''
                        : scheduleResult.job_due_at
                        ? (scheduleResult.job_due_time
                            ? `${scheduleResult.job_due_at}T${scheduleResult.job_due_time}`
                            : scheduleResult.job_due_at)
                        : canonicalDue;
                    canonicalTransitions = scheduleResult.transitions || canonicalTransitions;
                }
                mutationSucceeded = true;
            }
            if (statusPayload) {
                const statusResult = await request(endpoint(workspace.dataset.statusTemplate, id), 'PATCH', statusPayload);
                canonicalStatus = Number(statusResult.job_status ?? canonicalStatus);
                canonicalTransitions = statusResult.transitions || canonicalTransitions;
                mutationSucceeded = true;
            }
            if (jobs.length) {
                await Promise.all(jobs);
                mutationSucceeded = true;
            }
            if (canonicalTransitions && management[String(id)]) {
                management[String(id)].transitions = canonicalTransitions;
            }
            values.job_status = String(canonicalStatus);
            values.job_due_at = canonicalDue;
            values.job_due_time_unset = canonicalDueUnset ? '1' : '0';
            values.job_due_unset = canonicalNoDue ? '1' : '0';

            activeRow.dataset.topic = values.job_topic;
            activeRow.dataset.status = values.job_status;
            activeRow.dataset.priority = values.job_priority;
            // แยกวันกับเวลากลับเข้า dataset ตามรูปแบบที่ปฏิทิน ตัวกรอง และการจัดกลุ่มใช้อยู่
            const [dueDay = '', dueClock = ''] = String(values.job_due_at || '').split('T');
            const [startDay = '', startClock = ''] = String(values.job_start_at || '').split('T');
            activeRow.dataset.due = dueDay;
            activeRow.dataset.dueTime = dueClock;
            activeRow.dataset.dueTimeUnset = canonicalDueUnset ? '1' : '0';
            activeRow.dataset.start = startDay;
            activeRow.dataset.startTime = startClock;
            const rowTitle = activeRow.querySelector('.row-title strong');
            if (rowTitle) rowTitle.textContent = values.job_topic;
            synchronizeTaskSource(workspace, id, {
                topic: values.job_topic,
                status: Number(values.job_status),
                priority: Number(values.job_priority),
                start: startDay,
                due: dueDay,
                startTime: startClock,
                dueTime: dueClock,
                dueTimeUnset: canonicalDueUnset ? '1' : '0',
            });
            if (currentStatus) currentStatus.value = values.job_status;
            if (currentPriority) currentPriority.value = values.job_priority;
            if (currentDue) {
                currentDue.value = values.job_due_at;
                currentDue.dataset.timeUnset = canonicalDueUnset ? '1' : '0';
                currentDue.dataset.dueUnset = canonicalNoDue ? '1' : '0';
            }
            dueInput.dataset.timeUnset = canonicalDueUnset ? '1' : '0';
            dueInput.dataset.dueUnset = canonicalNoDue ? '1' : '0';
            paintDueCell();

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
    let submitting = false;

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

    const avatarMarkup = (person, tone = '') => {
        const toneClass = tone ? ` ${tone}` : '';
        if (person?.avatar_url) {
            return `<span class="team-avatar${toneClass}"><img src="${escapeHtml(person.avatar_url)}" alt=""></span>`;
        }
        return `<span class="team-avatar${toneClass}" aria-hidden="true">${escapeHtml(initials(person?.name))}</span>`;
    };

    /**
     * สถานะของผู้เข้าร่วม — บัญชีที่ถูกปิดต้องบอกให้ชัดว่าเพิ่มซ้ำไม่ได้แล้ว
     *
     * การเพิ่มผู้เข้าร่วมเสร็จทันที สถานะ "รอเปิดงาน" เหลือไว้สำหรับงานที่ตัวมันเองยัง
     * รออนุมัติการมอบหมายข้ามแผนกอยู่ ไม่ใช่การรออนุมัติตัวคน
     */
    const memberState = (person) => {
        if (person.is_active === false) return {className: 'inactive', label: 'ปิดบัญชี'};
        if (person.status === 'accepted') return {className: 'accepted', label: 'เข้าร่วมแล้ว'};
        if (person.status === 'pending') return {className: 'pending', label: 'รอเปิดงาน'};
        if (person.status === 'rejected') return {className: 'rejected', label: 'ไม่ได้เข้าร่วม'};
        return {className: 'unknown', label: 'ไม่ทราบสถานะ'};
    };

    /** ป้ายข้ามแผนก — บอกที่มาของคน ไม่ใช่สถานะรออนุมัติ */
    const crossDepartmentBadge = (person) => person.cross_department
        ? '<span class="team-cross-department">ข้ามแผนก</span>'
        : '';

    const personColumns = (person) => {
        const department = person.department || 'ไม่ระบุแผนก';

        return `<span class="team-person"><strong>${escapeHtml(person.name)}</strong><small>${escapeHtml(person.email || department)}</small></span>`
            + `<span class="team-department">${escapeHtml(department)}</span>`
            + crossDepartmentBadge(person);
    };

    /** คอลัมน์ขวาส่วนบน: ผู้เข้าร่วมโปรเจกต์ แสดงที่เดียวเท่านั้น */
    const renderParticipants = () => {
        const team = activeTeam;
        const canEdit = team.can_manage && !team.locked;
        const participants = team.participants || [];
        const protectedIds = new Set((team.protected_ids || []).map(String));

        count.textContent = `ผู้เข้าร่วม (${participants.length} คน)`;
        empty.hidden = participants.length > 0;
        members.innerHTML = participants.map((person) => {
            const state = memberState(person);
            const removable = canEdit && !protectedIds.has(String(person.id));

            return `<article class="team-member${person.is_assignee ? ' team-member--responsible' : ''}">${avatarMarkup(person)}`
                + personColumns(person)
                + `<span class="team-state ${state.className}"><i></i>${state.label}</span>`
                + (removable ? `<button type="button" data-remove-team-member="${person.id}" title="นำ ${escapeHtml(person.name)} ออกจากโปรเจกต์" aria-label="นำ ${escapeHtml(person.name)} ออกจากโปรเจกต์"><i class="bi bi-x-lg"></i></button>` : '')
                + '</article>';
        }).join('');
    };

    /** ปุ่มหลักบอกจำนวนที่เตรียมเพิ่มเสมอ และกดไม่ได้เมื่อยังไม่ได้เลือกใคร */
    const syncSubmit = () => {
        const staged = selector ? selectedIdsOf(selector) : [];
        const canEdit = activeTeam?.can_manage && !activeTeam?.locked;

        if (submit) submit.disabled = submitting || !canEdit || staged.length === 0;
        if (submitLabel) {
            submitLabel.textContent = submitting
                ? 'กำลังเพิ่มผู้เข้าร่วม...'
                : `เพิ่มผู้เข้าร่วม (${staged.length} คน)`;
        }
    };

    const render = () => {
        const team = activeTeam;
        topic.textContent = team.topic;
        // หัวหน้าโปรเจกต์คือผู้สร้างโปรเจกต์ ไม่เปลี่ยนไปตามการเพิ่มผู้เข้าร่วม
        owner.innerHTML = team.leader
            ? avatarMarkup(team.leader, 'primary')
                + personColumns(team.leader)
                + '<b class="team-role-badge"><i class="bi bi-person-badge-fill"></i> หัวหน้าโปรเจกต์</b>'
            : '<span class="team-person"><strong>ไม่ระบุหัวหน้าโปรเจกต์</strong></span>';

        renderParticipants();

        const canEdit = team.can_manage && !team.locked;
        if (selector) {
            selector.dataset.readonly = canEdit ? 'false' : 'true';
            // หัวหน้าโปรเจกต์ ผู้เข้าร่วมปัจจุบัน creator และ leader ต้องไม่เป็น candidate ตาม server contract
            setExcludedIds(selector, [
                team.leader?.id,
                ...(team.protected_ids || []),
                ...(team.participants || []).map((person) => person.id),
            ].filter(Boolean));
        }

        form.hidden = false;
        notice.hidden = canEdit;
        if (!notice.hidden) notice.textContent = team.locked ? 'งานที่เสร็จแล้วถูกล็อกการจัดการสมาชิก' : 'คุณดูรายชื่อสมาชิกได้ แต่ไม่มีสิทธิ์แก้ไข';
        syncSubmit();
    };

    selector?.addEventListener('peopleselector:change', syncSubmit);

    const open = (id, opener = null) => {
        if (selector) clearPeopleSelection(selector);
        activeTeam = teams[String(id)];
        if (!activeTeam) return;
        render();
        // ส่งปุ่มที่กดเข้าไปตรง ๆ เพื่อให้ focus กลับมาถูกตัวตอนปิด
        // document.activeElement เชื่อไม่ได้ เพราะการคลิกไม่ได้โฟกัสปุ่มเสมอไป
        layers.open(modal, opener);
    };
    const close = () => {
        if (selector) clearPeopleSelection(selector);
        layers.close(modal);
        activeTeam = null;
        submitting = false;
        if (submit) submit.setAttribute('aria-busy', 'false');
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

        const member = (activeTeam.participants || []).find((person) => String(person.id) === remove.dataset.removeTeamMember);
        const confirmed = window.Swal
            ? (await window.Swal.fire({
                ...workspaceDialogLayer,
                icon: 'warning',
                title: 'นำออกจากโปรเจกต์',
                text: `ต้องการนำ ${member?.name || 'สมาชิกคนนี้'} ออกจากโปรเจกต์นี้หรือไม่?`,
                showCancelButton: true,
                confirmButtonText: 'นำออก',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#dc2626',
                reverseButtons: true,
            })).isConfirmed
            : window.confirm(`ต้องการนำ ${member?.name || 'สมาชิกคนนี้'} ออกจากโปรเจกต์นี้หรือไม่?`);
        if (!confirmed) return;

        remove.disabled = true;
        try {
            await request(activeTeam.remove_url.replace('__USER__', remove.dataset.removeTeamMember), 'DELETE');
            activeTeam.participants = (activeTeam.participants || []).filter((person) => String(person.id) !== remove.dataset.removeTeamMember);
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
        const canEdit = activeTeam?.can_manage && !activeTeam?.locked;
        if (!selected.length || !activeTeam || !canEdit || submitting) return;
        submitting = true;
        if (submit) submit.setAttribute('aria-busy', 'true');
        syncSubmit();
        try {
            await request(activeTeam.add_url, 'POST', {collaborators: selected});
            window.location.reload();
        } catch (error) {
            notice.hidden = false;
            notice.textContent = error.message;
            submitting = false;
            if (submit) submit.setAttribute('aria-busy', 'false');
            syncSubmit();
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

    // ใช้อ็อบเจกต์เดียวกับบอร์ดและปฏิทิน ไม่ parse สำเนาของตัวเองอีก
    const data = attachmentStore(document);
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const list = box.querySelector('[data-task-inline-files]');
    const drop = box.querySelector('[data-task-inline-drop]');
    const fileInput = box.querySelector('[data-task-inline-file-input]');
    const status = box.querySelector('[data-attachment-status]');
    const dropZone = box.querySelector('[data-task-drop-zone]');
    const typesHint = box.querySelector('[data-attachment-types]');
    const lockedNotice = box.querySelector('[data-attachment-locked]');
    const downloadTrigger = box.querySelector('[data-open-attachment-download]');
    let taskId = null;
    let uploading = false;
    // dragenter/dragleave ยิงทุกครั้งที่ผ่าน element ลูก ต้องนับชั้นไม่งั้นกรอบกะพริบ
    let dragDepth = 0;

    // เพดานมาจาก AttachmentPolicy ฝั่ง server ไม่ฮาร์ดโค้ดซ้ำที่นี่
    // ถ้า server เปลี่ยนเพดาน หน้าจอจะตามทันทีโดยไม่ต้องแก้ JavaScript
    const limits = attachmentLimits(document);

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
        // ไอคอนบอกชนิดไฟล์ตั้งแต่แรกเห็น ไม่ต้องอ่านนามสกุลท้ายชื่อที่มักถูกตัดด้วย ellipsis
        list.innerHTML = files.length
            ? files.map((file) => `<article><a href="${escape(file.url)}" target="_blank" rel="noopener"><i class="bi ${iconFor(file.name)} file-tone-${toneFor(file.name)}" aria-hidden="true"></i><span>${escape(file.name)}</span></a>${file.delete_url ? `<button type="button" class="attachment-delete" data-delete-attachment="${escape(file.delete_url)}" aria-label="ลบไฟล์ ${escape(file.name)}"><i class="bi bi-trash3" aria-hidden="true"></i></button>` : ''}</article>`).join('')
            : '<p>ยังไม่มีไฟล์แนบ</p>';
        if (drop) drop.hidden = !task?.can_upload;
        // งานที่แนบไฟล์ไม่ได้ต้องไม่ชวนให้ลาก
        if (typesHint) typesHint.hidden = !task?.can_upload;
        if (lockedNotice) lockedNotice.hidden = task?.is_closed !== true;
        if (dropZone) dropZone.classList.toggle('is-droppable', Boolean(task?.can_upload));

        // กล่องดาวน์โหลดเป็นโมดูลแยก จึงรับรู้งานที่เปิดอยู่ผ่าน dataset ของปุ่มที่กด
        // ไม่ใช่ตัวแปร taskId ภายใน IIFE นี้ ที่โมดูลอื่นมองไม่เห็น
        if (downloadTrigger) {
            downloadTrigger.hidden = files.length === 0;
            downloadTrigger.dataset.taskId = String(taskId ?? '');
        }
    };

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-open-task-modal]');
        if (trigger) {
            const id = trigger.dataset.taskId || trigger.closest('[data-board-task]')?.dataset.taskId;
            taskId = trigger.closest('[data-row]')?.dataset.id || id || null;
            setStatus('');
            render();
        }

        const remove = event.target.closest('[data-delete-attachment]');
        if (!remove || !list.contains(remove) || !taskId || remove.disabled) return;

        deleteAttachment(remove);
    });

    const deleteAttachment = async (remove) => {
        if (!(await confirmAttachmentDelete())) return;

        remove.disabled = true;
        setStatus('กำลังลบไฟล์...');
        try {
            const task = data[String(taskId)];
            task.files = await deleteTaskAttachment({
                url: remove.dataset.deleteAttachment,
                csrf,
                taskId,
                files: task.files || [],
            });
            render();
            setStatus('ลบไฟล์แล้ว');
        } catch (error) {
            remove.disabled = false;
            setStatus(error.message || 'ลบไฟล์ไม่สำเร็จ กรุณาลองใหม่อีกครั้ง', true);
        }
    };

    const upload = async (files) => {
        const task = data[String(taskId)];
        // กันการอัปโหลดซ้ำจากการกดหรือวางไฟล์หลายครั้งติดกัน
        if (!task || !files.length || uploading) return;

        if (!task.can_upload) {
            setStatus('คุณไม่มีสิทธิ์แนบไฟล์ในงานนี้', true);
            return;
        }

        // เลือกทั้งโฟลเดอร์จะได้ไฟล์ที่ระบบไม่รองรับติดมาด้วยเป็นเรื่องปกติ
        // จึงคัดออกเงียบ ๆ แล้วบอกจำนวนที่ข้าม แทนที่จะปฏิเสธทั้งชุดจนแนบอะไรไม่ได้เลย
        const picked = [...files];
        const accepted = picked.filter((file) => limits.extensions.includes(extensionOf(file.name)));
        const skipped = picked.length - accepted.length;

        if (!accepted.length) {
            setStatus(`ไม่มีไฟล์ที่รองรับในสิ่งที่เลือก — รองรับเฉพาะ ${limits.typesLabel}`, true);
            return;
        }

        if (task.files.length + accepted.length > limits.maxFiles) {
            setStatus(`แนบได้รวมไม่เกิน ${limits.maxFiles} ไฟล์ (ขณะนี้มี ${task.files.length} ไฟล์)`, true);
            return;
        }

        const oversized = accepted.find((file) => file.size / 1024 > limits.maxKilobytes);
        if (oversized) {
            setStatus(`ไฟล์ “${oversized.name}” มีขนาดเกิน ${limits.maxSizeLabel}`, true);
            return;
        }

        uploading = true;
        if (drop) drop.setAttribute('aria-busy', 'true');
        setStatus('กำลังอัปโหลดไฟล์...');
        try {
            const body = new FormData();
            accepted.forEach((file) => body.append('completion_attachments[]', file));
            const response = await fetch(task.upload_url, {method: 'POST', headers: {Accept: 'application/json', 'X-CSRF-TOKEN': csrf}, body});
            const result = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(result.message || '');

            // อัปเดตในที่เดิม การ reload ทั้งหน้าจะปิด modal ทิ้ง ผู้ใช้เลยไม่เห็นว่าไฟล์ขึ้นแล้ว
            task.files = Array.isArray(result.files) ? result.files : task.files;
            // ปุ่มคลิปหนีบบนการ์ดบอร์ด/ตาราง/kanban ต้องขยับตามทันที ไม่ต้องรอรีโหลด
            publishTaskFiles(taskId, task.files);
            render();
            setStatus(`แนบไฟล์แล้ว ${accepted.length} ไฟล์${skipped ? ` (ข้ามไฟล์ที่ไม่รองรับ ${skipped} ไฟล์)` : ''}`);
        } catch (error) {
            setStatus(error.message || 'แนบไฟล์ไม่สำเร็จ กรุณาลองใหม่อีกครั้ง', true);
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

    const carriesFiles = (event) => [...(event.dataTransfer?.types || [])].includes('Files');
    const endDrag = () => {
        dragDepth = 0;
        dropZone?.classList.remove('is-dragover');
    };

    // ทั้งการ์ดรับไฟล์ ไม่ใช่แค่ปุ่มเล็ก ๆ ที่หัวการ์ด — ผู้ใช้เล็งกรอบว่างกลางการ์ดเป็นธรรมชาติกว่า
    dropZone?.addEventListener('dragenter', (event) => {
        if (!carriesFiles(event) || !data[String(taskId)]?.can_upload) return;
        event.preventDefault();
        dragDepth += 1;
        dropZone.classList.add('is-dragover');
    });
    dropZone?.addEventListener('dragover', (event) => {
        if (!carriesFiles(event) || !data[String(taskId)]?.can_upload) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = 'copy';
    });
    dropZone?.addEventListener('dragleave', () => {
        dragDepth = Math.max(0, dragDepth - 1);
        if (dragDepth === 0) dropZone.classList.remove('is-dragover');
    });
    dropZone?.addEventListener('drop', (event) => {
        if (!carriesFiles(event)) return;
        event.preventDefault();
        endDrag();
        upload(event.dataTransfer.files);
    });

    // วางพลาดนอกกรอบไม่ควรทำให้เบราว์เซอร์เปิดไฟล์ทับหน้างานที่ยังไม่ได้บันทึก
    modal.addEventListener('dragover', (event) => {
        if (carriesFiles(event)) event.preventDefault();
    });
    modal.addEventListener('drop', (event) => {
        if (!carriesFiles(event) || dropZone?.contains(event.target)) return;
        event.preventDefault();
        endDrag();
        setStatus('วางไฟล์ในกรอบ "ไฟล์แนบ" เพื่ออัปโหลด', true);
    });

    document.addEventListener('mytasks:access-changed', (event) => {
        const id = String(event.detail?.id || '');
        const interactions = event.detail?.interactions;
        if (!id || !interactions || !data[id]) return;

        Object.assign(data[id], interactions);
        if (String(taskId) === id) {
            setStatus('');
            render();
        }
    });
})();
