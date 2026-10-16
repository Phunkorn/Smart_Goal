import {projectPriorityClasses, statusClasses, taskPriorityClasses, unsupportedStatusMeta} from './pages/mytasks/priority-meta.js';
import {
    boardFloatingMenuSelector,
    boardFloatingMenuSummarySelector,
    calculateBoardFloatingMenuPosition,
    resolveBoardFloatingMenu,
} from './pages/mytasks/board-floating-menu.js';
import {boardFilterStateFrom, boardTaskMatches, parametersForTaskWorkspace} from './pages/mytasks/task-filter-state.js';
import {OWNER_FILTER_EVENT, initializeOwnerFilter, ownerFilterState} from './pages/mytasks/owner-filter.js';
import {publishTaskState, synchronizeCompletedTaskGroup, synchronizeTaskManagement, synchronizeTaskSource} from './pages/mytasks/task-state.js';
import {taskStateStore} from './pages/mytasks/task-state-store.js';
import {attachmentLimits, attachmentStore, publishTaskFiles} from './pages/mytasks/attachment-store.js';
// ไอคอนตามชนิดไฟล์ใช้ตารางเดียวกับการ์ดไฟล์แนบและกล่องดาวน์โหลด ไม่มีสำเนาแยก
import {iconFor, toneFor} from './pages/mytasks/attachment-icons.js';
// ปุ่มลบไฟล์แนบใช้โมดูลเดียวกับการ์ด "ไฟล์แนบ" ใน Task Workspace ไม่มีสำเนาแยก
import {attachmentDeleteButton, confirmAttachmentDelete, deleteTaskAttachment} from './pages/mytasks/attachment-delete.js';
import {canTransitionTo, confirmTaskTransition} from './pages/mytasks/task-transitions.js';
import {syncSubtaskGate} from './pages/mytasks/subtask-gate.js';
// ป้ายเวลากำหนดส่งใช้ตัวช่วยชุดเดียวกับตาราง ปฏิทิน และโมดัล ไม่มีสำเนาแยก
import {NO_DUE_LABEL, dueClockLabel, isDueTimeUnset} from './pages/mytasks/due-time.js';
import {expandedProjectKeys, rememberProjectExpanded} from './pages/mytasks/board-project-collapse.js';

(() => {
    const workspace = document.querySelector('[data-workspace]');
    const board = workspace?.querySelector('[data-project-board]');
    const cardGrid = board?.querySelector('[data-board-list-body]');
    if (!workspace || !board || !cardGrid) return;

    const search = workspace.querySelector('[data-search]');
    const filter = workspace.querySelector('[data-filter]');
    const sort = workspace.querySelector('[data-sort]');
    // ตัวกรอง "งานของใคร" และผู้รับผิดชอบ — มีเฉพาะหน้า "งานของฉัน" หน้าอื่นได้ค่าว่าง (ไม่กรอง) เสมอ
    initializeOwnerFilter(workspace);
    const toast = document.querySelector('[data-toast]');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const attachmentModal = document.querySelector('[data-board-attachment-modal]');
    const completedProjectsModal = document.querySelector('[data-completed-projects-modal]');
    const projectPreviewModal = document.querySelector('[data-project-preview-modal]');
    let lastPreviewTrigger = null;
    // อ็อบเจกต์เดียวกับโมดัลรายละเอียดงานและปฏิทิน การแนบไฟล์จากที่ใดก็ตามจึงเห็นตรงกัน
    const attachmentData = attachmentStore(document);
    // อ็อบเจกต์เดียวกับโมดัล ตาราง kanban และไทม์ไลน์ ทุกมุมมองจึงเห็นสิทธิ์ชุดเดียวกันทันที
    const management = taskStateStore(document);

    /*
     * งานย่อยเป็น [data-board-task] ของตัวเองและซ้อนอยู่ในแถวงานแม่
     * การค้นหาปุ่มจึงต้องตัดปุ่มของงานย่อยออก ไม่งั้นสิทธิ์ของงานแม่จะไปคุมเมนูของงานย่อย
     */
    const ownControls = (task, selector) => [...task.querySelectorAll(selector)]
        .filter((node) => node.closest('[data-board-task]') === task);

    const refreshStatusControls = (task) => {
        const capabilities = management[String(task.dataset.taskId)]?.transitions || {};
        const currentStatus = Number(task.dataset.status);
        const isFinal = capabilities.is_final === true || currentStatus === 4;
        const normalOptions = ownControls(task, '[data-board-normal-status-option]');
        const reopenOption = ownControls(task, '[data-board-reopen-option]')[0];

        normalOptions.forEach((button) => {
            button.hidden = isFinal;
            button.querySelector('.bi-check2')?.remove();
        });
        const selected = normalOptions.find((button) => Number(button.dataset.boardStatusValue) === currentStatus);
        if (selected && !isFinal) selected.insertAdjacentHTML('beforeend', '<span class="bi bi-check2"></span>');
        if (reopenOption) reopenOption.hidden = !isFinal || capabilities.can_reopen !== true;

        ownControls(task, '[data-board-status-value]').forEach((button) => {
            button.disabled = !canTransitionTo(currentStatus, Number(button.dataset.boardStatusValue), capabilities);
        });

        /*
         * ความสำคัญและกำหนดการแก้ได้เมื่อ "ทำงานกับงานใบนี้ได้ตอนนี้" ซึ่งเป็นเท็จเสมอ
         * เมื่องานปิดแล้ว (WorkOrderPolicy::work) Blade วาดกล่องพวกนี้ไว้ตั้งแต่ต้นเมื่อ
         * ผู้ใช้เป็นผู้ที่เปิดงานอีกครั้งได้ แล้วปล่อยให้สิทธิ์จาก server เป็นตัวเปิด/ปิด
         * ที่นี่ที่เดียว เปิดงานอีกครั้งแล้วจึงกลับมาแก้ได้ทันทีโดยไม่ต้องโหลดหน้าใหม่
         */
        const canEdit = capabilities.can_edit === true;
        ownControls(task, '[data-board-priority-value]').forEach((button) => {
            button.disabled = !canEdit;
        });
        ownControls(task, '[data-board-priority-menu]').forEach((menu) => {
            menu.classList.toggle('is-locked', !canEdit);
            if (!canEdit) menu.removeAttribute('open');
        });
        // ช่องวันที่ถูกปฏิเสธโดย components/date-picker.js เองเมื่อ disabled จึงไม่ต้องถอด listener
        ownControls(task, 'input[data-board-field]').forEach((input) => {
            input.disabled = !canEdit;
            input.closest('label')?.classList.toggle('is-locked', !canEdit);
        });

        task.classList.toggle('is-readonly', capabilities.can_edit !== true && capabilities.can_reopen !== true);
    };

    board.querySelectorAll('[data-board-task]').forEach(refreshStatusControls);
    const endpoint = (template, id) => template.replace('__ID__', id);
    // ทุก Workspace อ่านตัวกรองตั้งต้นจาก URL ได้ (ลิงก์ "ดูในบอร์ด" จากหน้าคำขออนุมัติส่ง
    // ?status=cross_department มา) แต่เขียนกลับลง URL เฉพาะหน้า "งานของฉัน" เหมือนเดิม
    const persistFilterState = workspace.dataset.context === 'user';
    const initialFilterState = boardFilterStateFrom(new URLSearchParams(window.location.search));
    if (search) search.value = initialFilterState.search;
    if (filter) filter.value = initialFilterState.status;
    let dueSort = initialFilterState.dueSort;
    let ascending = dueSort !== 'desc';
    const statusMeta = {
        2: {className: 'status-progress', label: 'กำลังทำ'},
        3: {className: 'status-review', label: 'รอตรวจสอบ'},
        4: {className: 'status-done', label: 'เสร็จแล้ว'},
        5: {className: 'status-paused', label: 'พักงาน'},
        6: {className: 'status-late', label: 'ล่าช้า'},
    };
    const projectPriorityMeta = {
        1: {className: 'priority-low', tone: 'project-tone-low', label: 'ต่ำ', projectLabel: 'สำคัญ/ต่ำ'},
        2: {className: 'priority-medium', tone: 'project-tone-medium', label: 'กลาง', projectLabel: 'สำคัญ/กลาง'},
        3: {className: 'priority-high', tone: 'project-tone-high', label: 'สูง', projectLabel: 'สำคัญ/สูง'},
    };
    const taskPriorityMeta = {
        2: {className: 'priority-important', label: 'สำคัญไม่ด่วน'},
        3: {className: 'priority-urgent', label: 'สำคัญด่วน'},
        4: {className: 'priority-quick', label: 'ด่วนไม่ค่อยสำคัญ'},
        5: {className: 'priority-flexible', label: 'ไม่รีบ ไม่มีกำหนด'},
    };

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

    const applyTaskChange = (change) => {
        const task = board.querySelector(`[data-board-task][data-task-id='${CSS.escape(String(change.id))}']`);
        if (!task) return;

        if (Object.hasOwn(change, 'topic')) {
            task.dataset.topic = String(change.topic || '');
            const title = ownControls(task, '.board-reference-task__title')[0];
            if (title) title.textContent = task.dataset.topic;
        }
        /*
         * กำหนดการที่ถูกแก้จากที่อื่น (โมดัลรายละเอียดงาน ตาราง ปฏิทิน) ต้องวาดป้ายของบอร์ดใหม่
         *
         * ของเดิมเขียนแค่ data-due แล้วไม่วาดป้าย คอลัมน์กำหนดส่งและคอลัมน์เวลาจึงค้าง
         * ค่าเก่าไว้จนผู้ใช้โหลดหน้าใหม่ และหลังมีตัวเลือก "ไม่ระบุเวลากำหนดส่ง"
         * ความค้างนั้นกลายเป็นสองมุมมองที่บอกคนละอย่างสำหรับงานใบเดียวกัน
         */
        if (Object.hasOwn(change, 'due') || Object.hasOwn(change, 'start')) {
            if (Object.hasOwn(change, 'due')) task.dataset.due = String(change.due || '');
            if (Object.hasOwn(change, 'dueTime')) task.dataset.dueTime = String(change.dueTime || '');
            if (Object.hasOwn(change, 'start')) task.dataset.start = String(change.start || '');
            if (Object.hasOwn(change, 'startTime')) task.dataset.startTime = String(change.startTime || '');
            if (Object.hasOwn(change, 'dueTimeUnset')) applyDueTimeUnset(task, isDueTimeUnset(change.dueTimeUnset));
            paintScheduleLabels(task);
        }

        if (Object.hasOwn(change, 'priority') && taskPriorityMeta[Number(change.priority)]) {
            const priority = Number(change.priority);
            const meta = taskPriorityMeta[priority];
            task.dataset.priority = String(priority);
            task.classList.remove('task-priority-important', 'task-priority-urgent', 'task-priority-quick', 'task-priority-flexible');
            task.classList.add(`task-${meta.className}`);
            const summary = ownControls(task, '[data-board-priority-menu] > summary')[0];
            summary?.classList.remove(...taskPriorityClasses);
            summary?.classList.add(meta.className);
            const label = summary?.querySelector('[data-board-priority-label]');
            if (label) label.textContent = meta.label;
        }

        if (Object.hasOwn(change, 'status') && statusMeta[Number(change.status)]) {
            const status = Number(change.status);
            const meta = statusMeta[status];
            task.dataset.status = String(status);
            task.dataset.late = status === 6 ? '1' : '0';
            synchronizeCompletedTaskGroup(cardGrid, task, status);
            const summary = ownControls(task, '[data-board-status-menu] > summary')[0];
            summary?.classList.remove(...statusClasses);
            summary?.classList.add(meta.className);
            const label = summary?.querySelector('[data-board-status-label]');
            if (label) label.textContent = meta.label;
        }

        refreshStatusControls(task);
        // การเปลี่ยนสถานะจากที่อื่น (โมดัล ตาราง ปฏิทิน) วิ่งเข้ามาทางนี้
        // งานย่อยที่ถูกปิดจากโมดัลจึงต้องอัปเดตตัวนับของงานแม่ด้วยเช่นกัน
        if (task.matches('[data-board-subtask]') && task.dataset.workOrderId) {
            syncSubtaskGate(task.dataset.workOrderId, management);
        }
        refreshProjectArchiveAction(task);
        filterBoard(false);
    };

    /*
     * วาดป้ายวันที่ทั้งสองข้างใหม่จาก dataset ของแถว
     *
     * ต้องวาดทั้งคู่เสมอ เพราะการแก้ปลายทางข้างเดียวอาจลากอีกข้างขยับตามไปด้วย
     * (เลื่อนวันเริ่มไปหลังกำหนดส่ง กำหนดส่งจะถูกดันตาม และกลับกัน)
     */
    const thaiDate = new Intl.DateTimeFormat('th-TH', {day: 'numeric', month: 'short', year: 'numeric'});

    /*
     * ค่ากำหนดการของแถวถูกเก็บเป็นสองส่วน: data-start / data-due เป็นวัน ('Y-m-d')
     * และ data-start-time / data-due-time เป็นเวลาไทย ('H:i')
     *
     * แยกกันเพราะปฏิทิน ตัวกรอง และการจัดกลุ่มทั้งหมดทำงานที่ความละเอียดระดับวัน
     * ถ้ารวมเป็นสตริงเดียวโค้ดพวกนั้นต้องตัดสตริงเองทุกจุด ส่วนช่องกรอกต้องการค่ารวม
     * จึงประกอบตอนใช้งานด้วยตัวช่วยคู่นี้ที่เดียว
     */
    const scheduleValue = (task, field) => {
        const date = task.dataset[field] || '';
        const time = task.dataset[field === 'start' ? 'startTime' : 'dueTime'] || '';

        return date && time ? `${date}T${time}` : date;
    };

    const applyScheduleValue = (task, field, value) => {
        const [date = '', time = ''] = String(value || '').split('T');
        task.dataset[field] = date;
        task.dataset[field === 'start' ? 'startTime' : 'dueTime'] = time;
    };

    /*
     * เจตนา "ไม่ระบุเวลากำหนดส่ง" อยู่คนละที่กับค่าเวลา
     *
     * data-due-time ยังถือ 23:59 ไว้เพราะเป็นขณะเวลาที่ตัดสินความล่าช้า ส่วนสิ่งที่บอกว่า
     * ผู้ใช้ไม่ได้ตั้งเวลานั้นเองคือธงนี้ ช่องกรอกทุกช่องของกำหนดส่งต้องถือธงเดียวกัน
     * ไม่งั้นช่องที่ถือค่าเก่าจะส่งเจตนาเก่ากลับไปทับในการแก้ครั้งถัดไป
     */
    const applyDueTimeUnset = (task, unset) => {
        task.dataset.dueTimeUnset = unset ? '1' : '0';
        ownControls(task, '[data-board-field="due"]').forEach((input) => {
            input.dataset.timeUnset = unset ? '1' : '0';
        });
    };

    const dueTimeIsUnset = (task) => task.dataset.dueTimeUnset === '1';

    /*
     * "ไม่มีกำหนดส่ง" ไม่มีธงของตัวเองที่แถว — data-due ว่างคือคำตอบ (job_due_at เป็น null)
     *
     * ต่างจาก "ไม่ระบุเวลา" ที่ต้องมีธงเพราะ data-due-time ยังถือ 23:59 ไว้ งานที่ไม่มีกำหนดส่ง
     * ไม่มีค่าใดค้างให้ตีความผิด ส่วนช่องกรอกถือ data-due-unset ไว้ให้ปฏิทินรู้ว่าต้องติ๊กแถวไหน
     */
    const dueIsUnset = (task) => !task.dataset.due;

    const paintScheduleLabels = (task) => {
        [['start', '[data-board-start-label]'], ['due', '[data-board-due-label]']].forEach(([field, selector]) => {
            const value = task.dataset[field] || '';

            /*
             * เขียนค่าลงทุกช่องของ field นั้น ไม่ใช่แค่ช่องแรก
             *
             * กำหนดส่งมีสองช่องที่แก้ได้ — ช่องวันที่ และช่องเวลา — ทั้งคู่ผูกกับ job_due_at
             * ค่าเดียวกัน ถ้าอัปเดตแค่ช่องแรก อีกช่องจะยังถือค่าเก่า แล้วการกดแก้ครั้งถัดไป
             * จะส่งค่าเก่ากลับไปทับค่าที่เพิ่งบันทึก
             */
            const next = scheduleValue(task, field);
            ownControls(task, `[data-board-field="${field}"]`).forEach((input) => {
                input.value = next;
                // ช่องกำหนดส่งทุกช่องต้องถือเจตนาเดียวกับแถว ไม่งั้นปฏิทินจะเปิดมาพร้อมแถวติ๊กที่ไม่ตรงความจริง
                if (field === 'due') input.dataset.dueUnset = value ? '0' : '1';
            });

            const label = ownControls(task, selector)[0];
            const date = new Date(`${value}T00:00:00`);
            if (label && value && !Number.isNaN(date.getTime())) label.textContent = thaiDate.format(date);
            else if (label && field === 'due' && !value) label.textContent = NO_DUE_LABEL;
        });

        // คอลัมน์เวลากำหนดส่งอ่านค่าจาก dataset ชุดเดียวกัน จึงตามการแก้ไขทุกครั้งโดยไม่ต้องรีโหลด
        const dueTime = ownControls(task, '[data-board-due-time]')[0];
        if (dueTime) {
            dueTime.textContent = dueIsUnset(task)
                ? '-'
                : dueClockLabel(task.dataset.dueTime || '', dueTimeIsUnset(task));
        }

        // งานที่ไม่มีกำหนดส่งเลยกำหนดไม่ได้และใกล้ครบกำหนดไม่ได้ สีเตือนที่ server วาดไว้จึงต้องหายไปด้วย
        if (dueIsUnset(task)) {
            ownControls(task, '.board-due, .board-due-time').forEach((cell) => cell.classList.remove('is-late', 'is-soon'));
        }
    };

    const tasksForProject = (header) => header
        ? [...cardGrid.querySelectorAll('[data-board-task]:not([data-board-subtask])')].filter((task) => task.dataset.projectKey === header.dataset.projectKey)
        : [];

    const headerForTask = (task) => [...cardGrid.querySelectorAll('[data-project-header]')]
        .find((header) => header.dataset.projectKey === task.dataset.projectKey);

    /*
     * ที่เดียวที่พับหรือกางโปรเจกต์ — หัวโปรเจกต์ แถวงาน และกลุ่มงานที่เสร็จแล้วต้องไปด้วยกันเสมอ
     * ทั้งปุ่มลูกศร การคืนค่าที่ผู้ใช้กางค้างไว้ และตัวกรอง เรียกผ่านฟังก์ชันนี้ทั้งหมด
     */
    const setProjectCollapsed = (header, collapsed) => {
        header.classList.toggle('is-collapsed', collapsed);
        tasksForProject(header).forEach((task) => task.classList.toggle('is-project-collapsed', collapsed));
        [...cardGrid.querySelectorAll('[data-completed-group]')]
            .find((group) => group.dataset.projectKey === header.dataset.projectKey)
            ?.classList.toggle('is-project-collapsed', collapsed);
        header.querySelector('[data-board-collapse]')?.setAttribute('aria-expanded', String(!collapsed));
    };

    // ค้นหาหรือกรองสถานะคือการ "หางาน" ผลลัพธ์จึงต้องเห็นได้เลยโดยไม่ต้องไล่กางทีละโปรเจกต์
    // ปุ่ม "เฉพาะงานของฉัน" เป็นการเลือกขอบเขตของบอร์ด ไม่ใช่การหางาน จึงยังพับตามค่าเริ่มต้น
    const filterRevealsProjects = (state) => Boolean(state.search.trim() || state.status);

    // Blade วาดทุกโปรเจกต์แบบพับ ที่นี่กางเฉพาะโปรเจกต์ที่ผู้ใช้เปิดค้างไว้ก่อนหน้าโหลดใหม่
    const rememberedExpanded = expandedProjectKeys();
    cardGrid.querySelectorAll('[data-project-header].is-collapsed').forEach((header) => {
        if (rememberedExpanded.has(header.dataset.projectKey)) setProjectCollapsed(header, false);
    });

    const refreshProjectArchiveAction = (taskOrHeader) => {
        const header = taskOrHeader?.matches?.('[data-project-header]')
            ? taskOrHeader
            : headerForTask(taskOrHeader);
        const action = header?.querySelector('[data-archive-project]');
        if (!action) return;

        const projectTasks = tasksForProject(header);
        const allCompleted = projectTasks.length > 0 && projectTasks.every((task) => {
            const subtasks = [...task.querySelectorAll('[data-board-subtask]')];

            return Number(task.dataset.status) === 4
                && subtasks.every((subtask) => Number(subtask.dataset.status) === 4);
        });

        action.hidden = !allCompleted;
    };

    const closeBoardMenu = (menu) => {
        if (!menu) return;
        menu.removeAttribute('open');
        menu.style.removeProperty('--floating-menu-left');
        menu.style.removeProperty('--floating-menu-top');
    };

    const closeBoardMenus = (except = null) => {
        board.querySelectorAll(boardFloatingMenuSelector).forEach((menu) => {
            if (menu !== except && menu.hasAttribute('open')) closeBoardMenu(menu);
        });
    };

    const positionBoardFloatingMenu = (menu) => {
        const summary = menu.querySelector(':scope > summary');
        const panel = menu.querySelector(':scope > div');
        if (!summary || !panel) return false;

        const position = calculateBoardFloatingMenuPosition(
            summary.getBoundingClientRect(),
            panel.getBoundingClientRect(),
            {width: window.innerWidth, height: window.innerHeight},
            {align: menu.matches('.board-reference-menu') ? 'end' : 'start'},
        );
        menu.style.setProperty('--floating-menu-left', position.left + 'px');
        menu.style.setProperty('--floating-menu-top', position.top + 'px');

        return true;
    };

    const closeMenusForViewportChange = () => closeBoardMenus();
    const closeMenusForScroll = (event) => {
        if (event.target?.closest?.(boardFloatingMenuSelector)) return;
        closeBoardMenus();
    };
    window.addEventListener('resize', closeMenusForViewportChange, {passive: true});
    window.addEventListener('scroll', closeMenusForScroll, {capture: true, passive: true});
    document.addEventListener('mytasks:viewchange', closeMenusForViewportChange);

    const uploadAttachments = async (input) => {
        const files = [...(input.files || [])];
        if (!files.length) return;

        // ปุ่มแนบมีสองที่: เมนูบนการ์ด (data-task-id ที่แถวงาน) และ modal ไฟล์แนบ
        const taskId = input.dataset.taskId
            || input.closest('[data-board-task]')?.dataset.taskId
            || attachmentModal?.dataset.taskId
            || '';

        // เพดานมาจาก AttachmentPolicy ฝั่ง server ผ่านโหนดข้อมูลเดียวกับที่โมดัลใช้
        const limits = attachmentLimits(document);
        const extensionOf = (name) => String(name ?? '').split('.').pop().toLowerCase();

        // ปุ่ม "เพิ่มทั้งโฟลเดอร์" จะได้ไฟล์ที่ไม่รองรับติดมาด้วยเสมอ คัดออกแทนการปฏิเสธทั้งชุด
        const accepted = files.filter((file) => limits.extensions.includes(extensionOf(file.name)));
        const skipped = files.length - accepted.length;

        if (!accepted.length) {
            input.value = '';
            notify(`ไม่มีไฟล์ที่รองรับในสิ่งที่เลือก — รองรับเฉพาะ ${limits.typesLabel}`, false);
            return;
        }

        const existingCount = Number(input.dataset.existingCount || 0);
        if (existingCount + accepted.length > limits.maxFiles) {
            input.value = '';
            notify(`แนบได้รวมไม่เกิน ${limits.maxFiles} ไฟล์ (ขณะนี้มี ${existingCount} ไฟล์)`, false);
            return;
        }

        const oversized = accepted.find((file) => file.size / 1024 > limits.maxKilobytes);
        if (oversized) {
            input.value = '';
            notify(`ไฟล์ “${oversized.name}” มีขนาดเกิน ${limits.maxSizeLabel}`, false);
            return;
        }

        const menu = input.closest('.board-task-menu');
        const trigger = menu?.querySelector('[data-board-pick-attachment]');
        const formData = new FormData();
        accepted.forEach((file) => formData.append('completion_attachments[]', file));
        if (trigger) trigger.disabled = true;

        try {
            const response = await fetch(input.dataset.url, {
                method: 'POST',
                headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest'},
                body: formData,
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'แนบไฟล์ไม่สำเร็จ');
            notify(skipped ? `แนบไฟล์แล้ว ${accepted.length} ไฟล์ (ข้ามไฟล์ที่ไม่รองรับ ${skipped} ไฟล์)` : 'แนบไฟล์เรียบร้อยแล้ว');
            // เดิมรีโหลดทั้งหน้า ซึ่งทำให้ modal ไฟล์แนบปิดและเสียตำแหน่ง scroll
            // ตอนนี้อัปเดตข้อมูลกลางแล้วให้ทุกมุมมองวาดใหม่เอง
            publishTaskFiles(taskId, Array.isArray(data.files) ? data.files : []);
            if (attachmentModal && !attachmentModal.hidden) openAttachmentModal(taskId);
        } catch (error) {
            notify(error.message, false);
            if (trigger) trigger.disabled = false;
            input.value = '';
        }
    };

    const closeAttachmentModal = () => {
        if (!attachmentModal) return;
        attachmentModal.hidden = true;
        document.body.style.overflow = '';
    };

    const openAttachmentModal = (taskId) => {
        const data = attachmentData[String(taskId)];
        if (!attachmentModal || !data) return;
        const list = attachmentModal.querySelector('[data-board-attachment-list]');
        const empty = attachmentModal.querySelector('[data-board-attachment-empty]');
        const upload = attachmentModal.querySelector('[data-board-attachment-upload]');
        const locked = attachmentModal.querySelector('[data-board-attachment-locked]');
        const input = attachmentModal.querySelector('[data-board-modal-attachment-input]');
        const download = attachmentModal.querySelector('[data-open-attachment-download]');
        attachmentModal.querySelector('[data-board-attachment-topic]').textContent = data.topic || '';
        list.replaceChildren();
        (data.files || []).forEach((file) => {
            const link = document.createElement('a');
            link.href = file.url;
            link.target = '_blank';
            link.rel = 'noopener';
            const icon = document.createElement('i');
            icon.className = `bi ${iconFor(file.name)} file-tone-${toneFor(file.name)}`;
            icon.setAttribute('aria-hidden', 'true');
            const name = document.createElement('span');
            name.textContent = file.name;
            const open = document.createElement('i');
            open.className = 'bi bi-box-arrow-up-right';
            open.setAttribute('aria-hidden', 'true');
            link.append(icon, name, open);
            // แถวไฟล์ครอบลิงก์กับปุ่มลบไว้ด้วยกัน เพราะปุ่มลบต้องไม่อยู่ในลิงก์เปิดไฟล์
            const row = document.createElement('div');
            row.className = 'board-attachment-modal__row';
            row.append(link);
            const remove = attachmentDeleteButton(file, document);
            if (remove) row.append(remove);
            list.append(row);
        });
        empty.hidden = (data.files || []).length > 0;
        // ปุ่มดาวน์โหลดเปิดกล่องเลือกไฟล์ใบเดียวกับการ์ดไฟล์แนบใน Task Workspace
        // โมดูลนั้นอ่านงานที่เปิดอยู่จาก dataset ของปุ่มที่กด ไม่ใช่ตัวแปรในไฟล์นี้
        if (download) {
            download.hidden = (data.files || []).length === 0;
            download.dataset.taskId = String(taskId);
        }
        upload.hidden = !data.can_upload;
        if (locked) locked.hidden = data.is_closed !== true;
        [input].filter(Boolean).forEach((field) => {
            field.dataset.url = data.upload_url;
            field.dataset.existingCount = String((data.files || []).length);
            field.value = '';
        });
        attachmentModal.dataset.taskId = String(taskId);
        attachmentModal.hidden = false;
        document.body.style.overflow = 'hidden';
    };

    const currentFilterState = () => ({
        search: search?.value || '',
        status: filter?.value || '',
        dueSort,
        // ตัวกรองเองคือแหล่งความจริงเดียว แบบเดียวกับ search.value และ filter.value
        // ไม่เก็บสถานะไว้ในตัวแปรระดับโมดูลอีกชุดให้หลุดกันได้
        ...ownerFilterState(workspace),
    });

    const synchronizeFilterUrl = () => {
        if (!persistFilterState) return;
        const url = new URL(window.location.href);
        url.search = parametersForTaskWorkspace(
            url.searchParams,
            currentFilterState(),
            workspace.dataset.taskScope || 'all',
        ).toString();
        window.history.replaceState({}, '', url);
    };

    const filterBoard = (synchronizeUrl = true) => {
        const state = currentFilterState();
        const query = state.search.trim().toLowerCase();
        const status = state.status;
        const assigneeFilterActive = Boolean(state.assignee);
        let visibleTasks = 0;

        // ตัวกรองทำงานกับงานระดับบนสุด งานย่อยติดตามงานแม่ไปเสมอ
        board.querySelectorAll('[data-board-task]:not([data-board-subtask])').forEach((task) => {
            const subtasks = [...task.querySelectorAll('[data-board-subtask]')];

            const taskMatches = boardTaskMatches({
                searchable: task.dataset.searchText || ((task.dataset.projectName || '') + ' ' + task.textContent),
                status: task.dataset.status,
                canReview: task.dataset.canReview,
                late: task.dataset.late,
                crossDepartment: task.dataset.crossDepartment,
                assignee: task.dataset.assignee,
            }, state);
            const parentSearchable = String(task.dataset.searchText || ((task.dataset.projectName || '') + ' ' + task.textContent)).toLowerCase();
            const reviewSubtasks = status === 'my_review'
                ? subtasks.filter((subtask) => {
                    const subtaskSearchable = String(subtask.dataset.searchText || ((subtask.dataset.projectName || '') + ' ' + subtask.textContent)).toLowerCase();
                    const isPendingReview = boardTaskMatches({
                        searchable: subtaskSearchable,
                        status: subtask.dataset.status,
                        canReview: subtask.dataset.canReview,
                        late: subtask.dataset.late,
                    }, {...state, search: '', assignee: ''});

                    return isPendingReview && (!query || parentSearchable.includes(query) || subtaskSearchable.includes(query));
                })
                : [];

            subtasks.forEach((subtask) => {
                subtask.classList.toggle('is-review-match', reviewSubtasks.includes(subtask));
            });
            task.hidden = !taskMatches && reviewSubtasks.length === 0;

            const panel = task.querySelector('[data-task-details-panel]');
            const toggle = task.querySelector('[data-task-details-toggle]');
            const details = task.querySelector('[data-task-details]');
            if (reviewSubtasks.length > 0 && panel && toggle) {
                if (panel.hidden) panel.dataset.reviewAutoExpanded = '1';
                panel.hidden = false;
                toggle.setAttribute('aria-expanded', 'true');
                details?.classList.add('is-expanded');
            } else if (panel?.dataset.reviewAutoExpanded === '1') {
                panel.hidden = true;
                delete panel.dataset.reviewAutoExpanded;
                toggle?.setAttribute('aria-expanded', 'false');
                details?.classList.remove('is-expanded');
            }
            if (!task.hidden) visibleTasks++;
        });

        board.querySelectorAll('[data-project-header]').forEach((header) => {
            const projectTasks = tasksForProject(header);
            const visibleInProject = projectTasks.filter((task) => !task.hidden).length;
            // โปรเจกต์ที่ไม่มีงานเลยเคยถูกคงไว้เพื่อให้ผู้ใช้เพิ่มงานใบแรกได้
            // แต่ระหว่างกรองผู้รับผิดชอบ มันคือโปรเจกต์ที่ไม่มีงานตรงตัวกรอง จึงต้องหายไปด้วย
            const emptyProjectMatch = projectTasks.length === 0 && !status && !assigneeFilterActive
                && (!query || (header.dataset.projectName || '').toLowerCase().includes(query));
            header.hidden = visibleInProject === 0 && !emptyProjectMatch;
            const count = header.querySelector('[data-board-visible-count]');
            if (count) count.textContent = visibleInProject;

            /*
             * โปรเจกต์ที่พับอยู่และมีผลลัพธ์ ถูกกางให้ชั่วคราวระหว่างค้นหาหรือกรองสถานะ
             * แล้วพับกลับเมื่อล้างตัวกรอง — data-filter-reveal จำว่าใครเป็นคนกาง
             * 'auto' คือตัวกรองกางให้ 'user' คือผู้ใช้กดลูกศรเองระหว่างกรอง ซึ่งตัวกรองต้องไม่ไปยุ่งอีก
             */
            if (filterRevealsProjects(state)) {
                if (visibleInProject > 0 && header.classList.contains('is-collapsed') && !header.dataset.filterReveal) {
                    setProjectCollapsed(header, false);
                    header.dataset.filterReveal = 'auto';
                }
            } else if (header.dataset.filterReveal) {
                if (header.dataset.filterReveal === 'auto') setProjectCollapsed(header, true);
                delete header.dataset.filterReveal;
            }
        });

        // กลุ่มงานเสร็จแล้วต้องหายไปพร้อมแถวข้างในเมื่อกำลังดูคิวตรวจหรือสถานะอื่น
        // มิฉะนั้นจะเหลือหัวกลุ่มว่างจำนวนมากและผู้ใช้ยังต้องเลื่อนผ่านเหมือนเดิม
        board.querySelectorAll('[data-completed-group]').forEach((group) => {
            const visibleCompleted = [...group.querySelectorAll('[data-board-task]:not([data-board-subtask])')]
                .some((task) => !task.hidden);
            group.hidden = Boolean(status || query || assigneeFilterActive) && !visibleCompleted;
        });

        const empty = board.querySelector('[data-board-empty]');
        if (empty) empty.hidden = visibleTasks > 0;
        if (synchronizeUrl) synchronizeFilterUrl();
    };

    const sortBoard = () => {
        const groups = [...cardGrid.querySelectorAll('[data-project-header]')].map((header) => ({
            header,
            tasks: tasksForProject(header).sort((first, second) => ascending
                ? (first.dataset.due || '9999-12-31').localeCompare(second.dataset.due || '9999-12-31')
                : (second.dataset.due || '').localeCompare(first.dataset.due || '')),
            completedGroup: [...cardGrid.querySelectorAll('[data-completed-group]')]
                .find((group) => group.dataset.projectKey === header.dataset.projectKey),
        }));

        groups.sort((first, second) => {
                const firstDue = first.tasks[0]?.dataset.due || '9999-12-31';
                const secondDue = second.tasks[0]?.dataset.due || '9999-12-31';
                return ascending ? firstDue.localeCompare(secondDue) : secondDue.localeCompare(firstDue);
            })
            .forEach(({header, tasks, completedGroup}) => {
                const activeTasks = tasks.filter((task) => !task.closest('[data-completed-group]'));
                const completedTasks = tasks.filter((task) => task.closest('[data-completed-group]'));
                const completedRows = completedGroup?.querySelector('.board-completed-group__rows');

                if (completedRows) completedRows.append(...completedTasks);
                cardGrid.append(header, ...activeTasks);
                if (completedGroup) cardGrid.append(completedGroup);
            });
    };

    search?.addEventListener('input', () => filterBoard());
    filter?.addEventListener('change', () => filterBoard());
    // filterBoard() ตามค่าเริ่มต้นจะ sync URL ให้เอง เหมือน handler ของช่องค้นหาและสถานะ
    workspace.addEventListener(OWNER_FILTER_EVENT, () => filterBoard());
    workspace.querySelectorAll('[data-summary-filter]').forEach((button) => button.addEventListener('click', () => setTimeout(filterBoard)));
    sort?.addEventListener('click', () => {
        ascending = !ascending;
        dueSort = ascending ? 'asc' : 'desc';
        sortBoard();
        synchronizeFilterUrl();
    });

    if (dueSort) sortBoard();
    filterBoard(false);
    document.addEventListener('mytasks:changed', (event) => applyTaskChange(event.detail || {}));

    // โมดัลรายละเอียดงานแนบ/ลบไฟล์ได้เอง บอร์ดต้องวาด modal ไฟล์แนบใหม่ถ้ากำลังเปิดงานเดียวกันอยู่
    document.addEventListener('mytasks:attachments-changed', (event) => {
        const changedId = String(event.detail?.id || '');
        if (attachmentModal && !attachmentModal.hidden && attachmentModal.dataset.taskId === changedId) {
            openAttachmentModal(changedId);
        }
    });

    document.addEventListener('mytasks:access-changed', (event) => {
        const changedId = String(event.detail?.id || '');
        const interactions = event.detail?.interactions;
        if (!changedId || !interactions || !attachmentData[changedId]) return;

        Object.assign(attachmentData[changedId], interactions);
        if (attachmentModal && !attachmentModal.hidden && attachmentModal.dataset.taskId === changedId) {
            openAttachmentModal(changedId);
        }
    });

    document.addEventListener('click', async (event) => {
        const completedToggle = event.target.closest('[data-completed-toggle]');
        if (completedToggle && board.contains(completedToggle)) {
            event.preventDefault();
            const completedGroup = completedToggle.closest('[data-completed-group]');
            if (completedGroup) {
                completedGroup.open = !completedGroup.open;
                completedToggle.setAttribute('aria-expanded', String(completedGroup.open));
            }
            closeBoardMenus();
            return;
        }

        const nativeSummary = event.target.closest('summary');
        if (nativeSummary && board.contains(nativeSummary) && !nativeSummary.matches(boardFloatingMenuSummarySelector)) {
            closeBoardMenus();
            return;
        }

        if (!event.target.closest(boardFloatingMenuSelector)) closeBoardMenus();

        // ปุ่มคลิปหนีบมีสามที่ (การ์ดบอร์ด, แถวตาราง, การ์ด kanban) ทุกที่ใช้โมดัลเดียวกัน
        // เดิมมีโมดัลไฟล์แนบตัวที่สองไว้ให้ตาราง/kanban แต่ไม่มีโมดูลใดผูก JavaScript กับมันเลย
        const attachmentOpen = event.target.closest('[data-board-open-attachments], [data-open-attachments]');
        if (attachmentOpen) {
            event.preventDefault();
            openAttachmentModal(attachmentOpen.dataset.boardOpenAttachments || attachmentOpen.dataset.openAttachments);
            return;
        }
        // ลบไฟล์จากกล่องไฟล์แนบ — ทางเข้าหลักของจอมือถือ ใช้สิทธิ์ delete_url จาก server
        const removeAttachment = event.target.closest('[data-delete-attachment]');
        if (removeAttachment && attachmentModal?.contains(removeAttachment)) {
            event.preventDefault();
            if (removeAttachment.disabled) return;
            const removeTaskId = attachmentModal.dataset.taskId;
            if (!(await confirmAttachmentDelete())) return;

            removeAttachment.disabled = true;
            try {
                await deleteTaskAttachment({
                    url: removeAttachment.dataset.deleteAttachment,
                    csrf,
                    taskId: removeTaskId,
                    files: attachmentData[String(removeTaskId)]?.files || [],
                });
                notify('ลบไฟล์แล้ว');
                openAttachmentModal(removeTaskId);
            } catch (error) {
                removeAttachment.disabled = false;
                notify(error.message, false);
            }
            return;
        }

        if (event.target.closest('[data-close-board-attachments]') || event.target === attachmentModal) {
            closeAttachmentModal();
            return;
        }
        const menuSummary = event.target.closest(boardFloatingMenuSummarySelector);
        if (menuSummary && board.contains(menuSummary)) {
            const menu = resolveBoardFloatingMenu(menuSummary);
            if (!menu || !board.contains(menu)) return;
            const wasOpen = menu.hasAttribute('open');
            event.preventDefault();
            closeBoardMenus(menu);
            if (!wasOpen) {
                menu.setAttribute('open', '');
                if (!positionBoardFloatingMenu(menu)) closeBoardMenu(menu);
            } else {
                closeBoardMenu(menu);
            }
            return;
        }

        const manageMenuAction = event.target.closest('.board-reference-menu > div button, .board-reference-menu > div a');
        if (manageMenuAction) closeBoardMenu(manageMenuAction.closest('.board-reference-menu'));

        const projectPriorityOption = event.target.closest('[data-project-priority-value]');
        if (projectPriorityOption) {
            const menu = projectPriorityOption.closest('[data-project-priority-menu]');
            const header = projectPriorityOption.closest('[data-project-header]');
            const value = Number(projectPriorityOption.dataset.projectPriorityValue);
            const meta = projectPriorityMeta[value];
            if (!menu || !header || !meta) return;
            projectPriorityOption.disabled = true;
            request(menu.dataset.url, 'PATCH', {priority: value}).then(() => {
                header.classList.remove('project-tone-low', 'project-tone-medium', 'project-tone-high');
                header.classList.add(meta.tone);
                const summary = menu.querySelector('summary');
                summary.classList.remove(...projectPriorityClasses);
                summary.classList.add(meta.className);
                summary.querySelector('[data-project-priority-label]').textContent = meta.projectLabel;
                menu.querySelectorAll('[data-project-priority-value] .bi-check2').forEach((check) => check.remove());
                projectPriorityOption.insertAdjacentHTML('beforeend', '<span class="bi bi-check2"></span>');
                closeBoardMenu(menu);
                notify('เปลี่ยนความสำคัญโปรเจกต์แล้ว');
            }).catch((error) => notify(error.message, false)).finally(() => projectPriorityOption.disabled = false);
            return;
        }

        const taskPriorityOption = event.target.closest('[data-board-priority-value]');
        if (taskPriorityOption) {
            const menu = taskPriorityOption.closest('[data-board-priority-menu]');
            const task = taskPriorityOption.closest('[data-board-task]');
            const value = Number(taskPriorityOption.dataset.boardPriorityValue);
            const meta = taskPriorityMeta[value];
            if (!menu || !task || !meta) return;
            taskPriorityOption.disabled = true;
            request(endpoint(workspace.dataset.priorityTemplate, task.dataset.taskId), 'POST', {job_priority: value}).then(() => {
                task.classList.remove('task-priority-important', 'task-priority-urgent', 'task-priority-quick', 'task-priority-flexible');
                task.classList.add(`task-${meta.className}`);
                const summary = menu.querySelector('summary');
                summary.classList.remove(...taskPriorityClasses);
                summary.classList.add(meta.className);
                summary.querySelector('[data-board-priority-label]').textContent = meta.label;
                menu.querySelectorAll('[data-board-priority-value] .bi-check2').forEach((check) => check.remove());
                taskPriorityOption.insertAdjacentHTML('beforeend', '<span class="bi bi-check2"></span>');
                closeBoardMenu(menu);
                task.dataset.priority = String(value);
                synchronizeTaskSource(workspace, task.dataset.taskId, {priority: value});
                notify('เปลี่ยนความสำคัญงานแล้ว');
            }).catch((error) => notify(error.message, false)).finally(() => taskPriorityOption.disabled = false);
            return;
        }

        const statusOption = event.target.closest('[data-board-status-value]');
        if (statusOption) {
            const menu = statusOption.closest('[data-board-status-menu]');
            const task = statusOption.closest('[data-board-task]');
            const value = Number(statusOption.dataset.boardStatusValue);
            const meta = statusMeta[value];
            if (!menu || !task || !meta) return;
            statusOption.disabled = true;
            const payload = await confirmTaskTransition(Number(task.dataset.status), value, management[String(task.dataset.taskId)]?.transitions || {});
            if (!payload) { statusOption.disabled = false; return; }
            request(endpoint(workspace.dataset.statusTemplate, task.dataset.taskId), 'PATCH', payload).then((data) => {
                closeBoardMenu(menu);
                /*
                 * ไม่แก้ป้ายและปุ่มของแถวนี้เองอีกแล้ว — publishTaskState() เขียน state กลาง
                 * แล้วยิง event ที่ applyTaskChange() รับไปวาดแถวนี้พร้อมกับทุกมุมมองอื่น
                 * ของเดิมแก้สองที่ (ที่นี่กับ listener) ซึ่งเป็นสองแหล่งความจริงของป้ายเดียวกัน
                 */
                publishTaskState(workspace, task.dataset.taskId, data, value);
                notify('เปลี่ยนสถานะงานแล้ว');
            }).catch((error) => notify(error.message, false)).finally(() => refreshStatusControls(task));
            return;
        }

        const attachmentTrigger = event.target.closest('[data-board-pick-attachment]');
        if (attachmentTrigger) {
            const input = attachmentTrigger.closest('.board-task-menu')?.querySelector('[data-board-attachment-input]');
            input?.click();
            return;
        }

        /*
         * ช่องวันที่ไม่เรียกปฏิทินของเบราว์เซอร์อีกต่อไป
         *
         * ปฏิทินของเบราว์เซอร์ใช้ ค.ศ. ตามเครื่องผู้ใช้ วางตำแหน่งเองโดยไม่อิงกับปุ่มที่กด
         * และ API ที่ใช้เรียกมันก็ไม่มีในทุกเบราว์เซอร์ บางเครื่องจึงกดแล้วเงียบไปเฉย ๆ
         * ตอนนี้ใช้ตัวเลือกวันที่ของระบบ (resources/js/components/date-picker.js)
         * ซึ่งผูกไว้แบบ delegated ที่ document ผ่าน data-date-picker บนตัว <input> เดิม
         * มันเขียนค่าลง input ตัวเดิมแล้ว dispatch 'change' ตัวจัดการด้านล่างจึงทำงานเหมือนเดิม
         */

        const collapse = event.target.closest('[data-board-collapse]');
        if (collapse) {
            const header = collapse.closest('[data-project-header]');
            if (!header) return;
            const collapsed = !header.classList.contains('is-collapsed');
            setProjectCollapsed(header, collapsed);
            // ผู้ใช้กดเองระหว่างกรอง ตัวกรองจึงต้องไม่กางซ้ำหรือพับกลับให้อีก
            if (filterRevealsProjects(currentFilterState())) header.dataset.filterReveal = 'user';
            rememberProjectExpanded(header.dataset.projectKey, !collapsed);
            return;
        }

        const editProject = event.target.closest('[data-board-edit-project]');
        if (editProject) {
            const header = editProject.closest('[data-project-header]')
                || [...cardGrid.querySelectorAll('[data-project-header]')].find((candidate) => candidate.dataset.projectKey === editProject.dataset.projectKey);
            if (!header) return;
            const result = await Swal.fire({title: 'แก้ไขชื่อโปรเจกต์', input: 'text', inputValue: editProject.dataset.name, inputAttributes: {maxlength: 80}, showCancelButton: true, confirmButtonText: 'บันทึก', cancelButtonText: 'ยกเลิก', reverseButtons: true, inputValidator: (value) => value.trim() ? undefined : 'กรุณาระบุชื่อโปรเจกต์'});
            const name = result.value?.trim();
            if (!result.isConfirmed || !name || name === editProject.dataset.name) return;
            editProject.disabled = true;
            request(editProject.dataset.url, 'PATCH', {name}).then(() => {
                header.querySelector(':scope > strong').textContent = name;
                header.dataset.projectName = name;
                tasksForProject(header).forEach((task) => task.dataset.projectName = name);
                cardGrid.querySelectorAll('[data-board-edit-project]').forEach((button) => {
                    if (!button.dataset.projectKey || button.dataset.projectKey === header.dataset.projectKey) button.dataset.name = name;
                });
                const deleteProject = header.querySelector('[data-board-delete-project]');
                if (deleteProject) deleteProject.dataset.name = name;
                notify('แก้ไขชื่อโปรเจกต์แล้ว');
            }).catch((error) => notify(error.message, false)).finally(() => editProject.disabled = false);
            return;
        }

        const deleteProject = event.target.closest('[data-board-delete-project]');
        if (deleteProject) {
            const header = deleteProject.closest('[data-project-header]');
            if (!header) return;
            const total = Number(deleteProject.dataset.totalCount) || tasksForProject(header).length;
            const result = await Swal.fire({icon: 'warning', title: 'ลบโปรเจกต์นี้หรือไม่?', text: `โปรเจกต์ “${deleteProject.dataset.name}” พร้อมงานทั้งหมด ${total} รายการจะถูกลบ`, showCancelButton: true, confirmButtonText: 'ลบโปรเจกต์', cancelButtonText: 'ยกเลิก', confirmButtonColor: '#dc2626', reverseButtons: true});
            if (!result.isConfirmed) return;
            deleteProject.disabled = true;
            fetch(deleteProject.dataset.url, {
                method: 'DELETE',
                headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest'},
            }).then(async (response) => {
                const data = await response.json().catch(() => ({}));
                if (!response.ok) throw new Error(data.message || 'ลบโปรเจกต์ไม่สำเร็จ');
                tasksForProject(header).forEach((task) => task.remove());
                header.remove();
                notify('ลบโปรเจกต์แล้ว');
            }).catch((error) => {
                deleteProject.disabled = false;
                notify(error.message, false);
            });
            return;
        }

        const archiveProject = event.target.closest('[data-archive-project]');
        if (archiveProject) {
            const result = await Swal.fire({
                icon: 'question',
                title: 'จัดเก็บโปรเจกต์นี้หรือไม่?',
                text: `โปรเจกต์ “${archiveProject.dataset.name}” จะย้ายไปอยู่ในโปรเจกต์ที่เสร็จแล้ว และนำกลับมาเปิดได้ทุกเมื่อ`,
                showCancelButton: true,
                confirmButtonText: 'จัดเก็บโปรเจกต์',
                cancelButtonText: 'ไว้ก่อน',
                confirmButtonColor: '#2563eb',
                reverseButtons: true,
            });
            if (!result.isConfirmed) return;
            archiveProject.disabled = true;
            request(archiveProject.dataset.url, 'PATCH', {})
                .then(() => window.location.reload())
                .catch((error) => {
                    archiveProject.disabled = false;
                    notify(error.message, false);
                });
            return;
        }

        const renameTask = event.target.closest('[data-board-rename-task]');
        if (renameTask) {
            const task = renameTask.closest('[data-board-task]');
            if (!task) return;
            const result = await Swal.fire({title: 'แก้ไขชื่อรายการงาน', input: 'text', inputValue: renameTask.dataset.name, inputAttributes: {maxlength: 255}, showCancelButton: true, confirmButtonText: 'บันทึก', cancelButtonText: 'ยกเลิก', reverseButtons: true, inputValidator: (value) => value.trim() ? undefined : 'กรุณาระบุชื่อรายการงาน'});
            const name = result.value?.trim();
            if (!result.isConfirmed || !name || name === renameTask.dataset.name) return;
            renameTask.disabled = true;
            request(renameTask.dataset.url, 'PATCH', {job_topic: name}).then(() => {
                task.dataset.topic = name;
                const title = task.querySelector('.board-reference-task__title');
                if (title) title.textContent = name;
                renameTask.dataset.name = name;
                synchronizeTaskSource(workspace, task.dataset.taskId, {topic: name});
                notify('แก้ไขชื่อรายการงานแล้ว');
            }).catch((error) => notify(error.message, false)).finally(() => renameTask.disabled = false);
            return;
        }

        const deleteTask = event.target.closest('[data-board-delete-task]');
        if (deleteTask) {
            const task = deleteTask.closest('[data-board-task]');
            const projectHeader = task ? headerForTask(task) : null;
            if (!task) return;
            const result = await Swal.fire({icon: 'warning', title: 'ลบรายการนี้หรือไม่?', text: `“${task.dataset.topic}” จะถูกนำออกจากโปรเจกต์`, showCancelButton: true, confirmButtonText: 'ลบรายการ', cancelButtonText: 'ยกเลิก', confirmButtonColor: '#dc2626', reverseButtons: true});
            if (!result.isConfirmed) return;
            deleteTask.disabled = true;
            fetch(deleteTask.dataset.url, {
                method: 'DELETE',
                headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest'},
            }).then(async (response) => {
                const data = await response.json().catch(() => ({}));
                if (!response.ok) throw new Error(data.message || 'ลบงานไม่สำเร็จ');
                if (data.delete_requested) {
                    notify(data.message || 'ส่งคำขอลบให้ผู้ดูแลระบบแล้ว');
                    return;
                }
                task.remove();
                const remaining = tasksForProject(projectHeader).length;
                const count = projectHeader?.querySelector('[data-board-visible-count]');
                if (count) {
                    count.textContent = remaining;
                    count.dataset.boardTotalCount = remaining;
                }
                refreshProjectArchiveAction(projectHeader);
                notify('ลบงานแล้ว');
            }).catch((error) => {
                deleteTask.disabled = false;
                notify(error.message, false);
            });
            return;
        }


    });

    board.addEventListener('change', async (event) => {
        const attachmentInput = event.target.closest('[data-board-attachment-input]');
        if (attachmentInput) {
            await uploadAttachments(attachmentInput);
            return;
        }

        const control = event.target.closest('[data-board-field]');
        const task = control?.closest('[data-board-task]');
        if (!control || !task) return;
        const field = control.dataset.boardField;
        const id = task.dataset.taskId;
        // ข้อความแจ้งเฉพาะกรณีที่ระบบขยับปลายทางอีกข้างให้เอง ปกติจะว่างและใช้ข้อความมาตรฐาน
        let scheduleNotice = '';
        control.disabled = true;

        try {
            if (field === 'status') {
                const data = await request(endpoint(workspace.dataset.statusTemplate, id), 'PATCH', {job_status: Number(control.value)});
                const actualStatus = Number(data.job_status ?? control.value);
                const wrapper = control.closest('[data-board-status-choice]');
                wrapper.classList.remove(...statusClasses);
                wrapper.classList.add(statusMeta[actualStatus]?.className || unsupportedStatusMeta.className);
                publishTaskState(workspace, id, data, actualStatus);
            } else if (field === 'priority') {
                await request(endpoint(workspace.dataset.priorityTemplate, id), 'POST', {job_priority: Number(control.value)});
                task.dataset.priority = control.value;
                const wrapper = control.closest('[data-board-priority-choice]');
                wrapper.classList.remove('priority-low', 'priority-medium', 'priority-high');
                wrapper.classList.add({1:'priority-low',2:'priority-medium',3:'priority-high'}[control.value] || 'priority-medium');
                synchronizeTaskSource(workspace, id, {priority: Number(control.value)});
            } else if (field === 'start') {
                if (!control.value) throw new Error('ต้องเลือกวันที่เริ่ม');

                /*
                 * เลื่อนวันเริ่มไปไกลกว่ากำหนดส่งได้ โดยลากกำหนดส่งตามไปด้วย
                 *
                 * ของเดิมช่องวันเริ่มถูกครอบด้วย max = กำหนดส่ง ปฏิทินจึงปิดทุกวันหลังกำหนดส่ง
                 * งานที่เริ่มและครบกำหนดวันเดียวกัน (ซึ่งเป็นค่าเริ่มต้นของงานที่สร้างใหม่)
                 * จะเลื่อนวันเริ่มไปข้างหน้าไม่ได้เลย ต้องไปแก้กำหนดส่งก่อนทุกครั้ง
                 * ตอนนี้เลือกวันไหนก็ได้ แล้วปลายทางอีกข้างขยับตามให้ พร้อมบอกผู้ใช้ว่าขยับให้แล้ว
                 * กติกา "กำหนดส่งต้องไม่ก่อนวันเริ่ม" ยังถูกบังคับที่ server เหมือนเดิม
                 */
                // เทียบเป็นสตริง 'Y-m-dTH:i' ได้ตรง ๆ เพราะรูปแบบนี้เรียงตามเวลาอยู่แล้ว
                // และตอนนี้ต้องเทียบถึงระดับเวลา ไม่ใช่แค่วัน งานที่เริ่มบ่ายแล้วส่งเช้าวันเดียวกันจึงถูกจับได้
                const currentDue = scheduleValue(task, 'due');
                const shiftedDue = currentDue && control.value > currentDue ? control.value : currentDue;
                const dueMoved = shiftedDue !== currentDue;

                /*
                 * แก้วันเริ่มต้องไม่ล้างเจตนา "ไม่ระบุเวลากำหนดส่ง" ของอีกข้าง
                 * endpoint เดียวกันเขียนทั้งสองค่า คีย์นี้จึงต้องเดินทางไปทุกครั้ง ไม่ใช่เฉพาะตอนแก้กำหนดส่ง
                 */
                const data = await request(endpoint(workspace.dataset.scheduleTemplate, id), 'PATCH', {
                    job_start_at: control.value,
                    job_due_at: shiftedDue || null,
                    // เช่นเดียวกัน: แก้วันเริ่มของงานที่ไม่มีกำหนดส่งต้องไม่ถูกปฏิเสธว่าไม่ได้ส่งกำหนดส่งมา
                    job_due_unset: dueIsUnset(task),
                    job_due_time_unset: dueTimeIsUnset(task),
                });
                synchronizeTaskManagement(management, id, data);
                applyScheduleValue(task, 'start', data.job_start_at ? `${data.job_start_at}T${data.job_start_time ?? ''}` : control.value);
                if (data.job_due_at) applyScheduleValue(task, 'due', `${data.job_due_at}T${data.job_due_time ?? ''}`);
                else applyScheduleValue(task, 'due', shiftedDue);
                applyDueTimeUnset(task, data.job_due_time_unset === true);
                task.dataset.status = String(data.job_status ?? task.dataset.status);
                paintScheduleLabels(task);
                scheduleNotice = dueMoved ? 'เลื่อนวันที่เริ่มแล้ว และเลื่อนกำหนดส่งตามไปด้วย' : '';
                synchronizeTaskSource(workspace, id, {start: task.dataset.start, due: task.dataset.due, startTime: task.dataset.startTime || '', dueTime: task.dataset.dueTime || '', dueTimeUnset: task.dataset.dueTimeUnset, status: Number(task.dataset.status)});
            } else if (field === 'due') {
                // "ไม่มีกำหนดส่ง" มาจากช่องที่ผู้ใช้เพิ่งกดเช่นกัน — ปฏิทินเขียน data-due-unset ก่อนยิง change
                // ค่าที่ค้างในช่องตอนนั้นคือวันเดิม ซึ่งต้องไม่ถูกส่งไปและต้องไม่ลากวันเริ่มตาม
                const dueUnset = control.dataset.dueUnset === '1';
                if (!dueUnset && !control.value) throw new Error('ต้องเลือกกำหนดส่ง');

                // เหตุผลเดียวกับช่องวันเริ่ม: เลือกวันไหนก็ได้ แล้วลากปลายทางอีกข้างตามมา
                const currentStart = scheduleValue(task, 'start');
                const shiftedStart = !dueUnset && currentStart && control.value < currentStart ? control.value : currentStart;
                const startMoved = shiftedStart !== currentStart;

                // ธงมาจากช่องที่ผู้ใช้เพิ่งกด ไม่ใช่จากแถว — ปฏิทินเขียน data-time-unset ลงช่องนั้นก่อนยิง change
                const data = await request(endpoint(workspace.dataset.scheduleTemplate, id), 'PATCH', {
                    job_start_at: shiftedStart,
                    job_due_at: dueUnset ? null : control.value,
                    job_due_unset: dueUnset,
                    job_due_time_unset: !dueUnset && control.dataset.timeUnset === '1',
                });
                synchronizeTaskManagement(management, id, data);
                if (data.job_start_at) applyScheduleValue(task, 'start', `${data.job_start_at}T${data.job_start_time ?? ''}`);
                else applyScheduleValue(task, 'start', shiftedStart);
                // คำตอบของ server เป็นค่าจริง: job_due_unset บอกว่างานไม่มีกำหนดส่งแล้ว ไม่ใช่ว่า server ลืมส่งวันกลับมา
                if (data.job_due_unset === true) applyScheduleValue(task, 'due', '');
                else applyScheduleValue(task, 'due', data.job_due_at ? `${data.job_due_at}T${data.job_due_time ?? ''}` : control.value);
                applyDueTimeUnset(task, data.job_due_time_unset === true);
                task.dataset.status = String(data.job_status ?? task.dataset.status);
                paintScheduleLabels(task);
                scheduleNotice = startMoved ? 'เลื่อนกำหนดส่งแล้ว และเลื่อนวันที่เริ่มตามมาด้วย' : '';
                synchronizeTaskSource(workspace, id, {start: task.dataset.start, due: task.dataset.due, startTime: task.dataset.startTime || '', dueTime: task.dataset.dueTime || '', dueTimeUnset: task.dataset.dueTimeUnset, status: Number(task.dataset.status)});
            }
            notify(scheduleNotice || 'บันทึกการเปลี่ยนแปลงแล้ว');
            filterBoard();
        } catch (error) {
            notify(error.message, false);
            if (field === 'start') control.value = scheduleValue(task, 'start');
            else if (field === 'due') {
                control.value = scheduleValue(task, 'due');
                // บันทึกไม่สำเร็จ ช่องต้องกลับไปถือเจตนาเดิมของแถว ไม่ใช่ค้างค่าที่ผู้ใช้เพิ่งติ๊ก
                applyDueTimeUnset(task, dueTimeIsUnset(task));
                ownControls(task, '[data-board-field="due"]').forEach((input) => {
                    input.dataset.dueUnset = dueIsUnset(task) ? '1' : '0';
                });
            } else {
                window.location.reload();
            }
        } finally {
            control.disabled = false;
        }
    });

    attachmentModal?.addEventListener('change', async (event) => {
        const input = event.target.closest('[data-board-modal-attachment-input]');
        if (input) await uploadAttachments(input);
    });

    // ปุ่มเปิดคลังโปรเจกต์อยู่ในแถบควบคุมมุมมอง ซึ่งอยู่นอก [data-project-board]
    workspace.querySelector('[data-open-completed-projects]')?.addEventListener('click', () => {
        if (completedProjectsModal) completedProjectsModal.hidden = false;
    });

    /*
     * เปิดดูเนื้อหาโปรเจกต์ที่จัดเก็บโดยไม่ต้องกู้คืนก่อน
     * แผงของทุกโปรเจกต์ถูกเรนเดอร์ไว้แล้ว ที่นี่จึงมีหน้าที่แค่สลับว่าอันไหนแสดง
     */
    const openProjectPreview = (projectId) => {
        if (!projectPreviewModal) return;
        let opened = null;
        projectPreviewModal.querySelectorAll('[data-project-preview-content]').forEach((panel) => {
            const match = panel.dataset.projectPreviewContent === String(projectId);
            panel.hidden = !match;
            if (match) opened = panel;
        });
        if (!opened) return;
        const title = projectPreviewModal.querySelector('[data-project-preview-title]');
        if (title) title.textContent = opened.dataset.previewProjectName || 'เนื้อหาในโปรเจกต์';
        projectPreviewModal.hidden = false;
        projectPreviewModal.querySelector('[data-close-project-preview]')?.focus();
    };

    const closeProjectPreview = () => {
        if (!projectPreviewModal || projectPreviewModal.hidden) return;
        projectPreviewModal.hidden = true;
        // คลังโปรเจกต์ยังเปิดอยู่ข้างหลัง โฟกัสจึงกลับไปที่ปุ่มที่เรียกโมดัลนี้
        lastPreviewTrigger?.focus();
        lastPreviewTrigger = null;
    };

    projectPreviewModal?.addEventListener('click', (event) => {
        if (event.target === projectPreviewModal || event.target.closest('[data-close-project-preview]')) closeProjectPreview();
    });

    completedProjectsModal?.addEventListener('click', async (event) => {
        if (event.target === completedProjectsModal || event.target.closest('[data-close-completed-projects]')) {
            closeProjectPreview();
            completedProjectsModal.hidden = true;
            return;
        }

        const preview = event.target.closest('[data-preview-project]');
        if (preview) {
            lastPreviewTrigger = preview;
            openProjectPreview(preview.dataset.previewProject);
            return;
        }

        const restore = event.target.closest('[data-restore-project]');
        if (!restore) return;
        const result = await Swal.fire({
            icon: 'question',
            title: 'เปิดโปรเจกต์อีกครั้งหรือไม่?',
            text: `โปรเจกต์ “${restore.dataset.name}” จะกลับไปอยู่ในบอร์ดพร้อมข้อมูลเดิมทั้งหมด`,
            showCancelButton: true,
            confirmButtonText: 'เปิดโปรเจกต์',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#2563eb',
            reverseButtons: true,
            // กล่องนี้เปิดจากในโมดัลคลังโปรเจกต์ ถ้าไม่ยกชั้นจะไปอยู่ข้างหลังโมดัล
            customClass: {container: 'project-archive-dialog'},
        });
        if (!result.isConfirmed) return;
        restore.disabled = true;
        request(restore.dataset.url, 'PATCH', {})
            .then(() => window.location.reload())
            .catch((error) => {
                restore.disabled = false;
                notify(error.message, false);
            });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && attachmentModal && !attachmentModal.hidden) closeAttachmentModal();
        // โมดัลดูเนื้อหาซ้อนอยู่บนคลังโปรเจกต์ Escape จึงต้องปิดทีละชั้นจากบนลงล่าง
        if (event.key === 'Escape' && projectPreviewModal && !projectPreviewModal.hidden) {
            closeProjectPreview();
            return;
        }
        if (event.key === 'Escape' && completedProjectsModal && !completedProjectsModal.hidden) completedProjectsModal.hidden = true;
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeBoardMenus();
    });

    filterBoard();
})();
