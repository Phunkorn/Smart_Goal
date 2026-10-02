import {
    derivePeopleState,
    initializePeopleSelectors,
    normalizeKeyword,
    updateSelection,
} from '../../components/people-selector.js';
import {useDatePickers} from '../../components/date-picker.js';
import {initSelectDropdowns} from '../../components/select-dropdown.js';
import {initAutoSubmitFilters} from '../../components/auto-submit-filter.js';
import {initPeriodRange} from '../../components/period-range.js';

/**
 * ตัวเลือกผู้เข้าร่วมย้ายไปอยู่ที่ components/people-selector.js แล้ว
 * เพื่อให้หน้าประชุมกับหน้าจัดการผู้ร่วมงานของงานใช้ตรรกะชุดเดียวกันจริง
 * ชื่อเดิมยัง export ไว้เพื่อไม่ให้ผู้เรียกและ test ที่มีอยู่ต้องเปลี่ยน import
 */
export const normalizeAttendeeKeyword = normalizeKeyword;
export const deriveAttendeeState = derivePeopleState;
export const updateAttendeeSelection = updateSelection;

export function resolveMeetingModal(root, modalId) {
    if (! root?.getElementById || typeof modalId !== 'string' || modalId.trim() === '') return null;

    const modalElement = root.getElementById(modalId);
    const classList = modalElement?.classList;
    const HTMLElementConstructor = root.defaultView?.HTMLElement ?? modalElement?.ownerDocument?.defaultView?.HTMLElement;

    if (! modalElement || (HTMLElementConstructor && ! (modalElement instanceof HTMLElementConstructor))) return null;
    if (! classList?.contains('modal') || ! classList.contains('meeting-form-modal')) return null;

    return modalElement;
}

export function showMeetingModal(modalElement, bootstrapApi = globalThis.window?.bootstrap, relatedTarget = null) {
    const Modal = bootstrapApi?.Modal;

    if (! modalElement || typeof Modal?.getOrCreateInstance !== 'function') return false;

    Modal.getOrCreateInstance(modalElement).show(relatedTarget);

    return true;
}

export function initializeMeetingModals(root, bootstrapApi = globalThis.window?.bootstrap) {
    if (! root?.querySelectorAll) return;

    root.querySelectorAll('[data-meeting-modal-trigger]').forEach((trigger) => {
        if (trigger.dataset.meetingModalInitialized === 'true') return;

        const modalElement = resolveMeetingModal(root, trigger.dataset.meetingModalTrigger);

        if (! modalElement || typeof bootstrapApi?.Modal?.getOrCreateInstance !== 'function') return;

        trigger.dataset.meetingModalInitialized = 'true';
        trigger.addEventListener('click', () => showMeetingModal(modalElement, bootstrapApi, trigger));
    });
}

function readFeedback(root) {
    const feedbackElement = root.querySelector('[data-meeting-feedback]');

    try {
        return JSON.parse(feedbackElement?.textContent || '{}');
    } catch {
        return {};
    }
}

function showFeedback(root, bootstrapApi = globalThis.window?.bootstrap) {
    const feedback = readFeedback(root);

    if (feedback.success) {
        window.Swal.fire({icon: 'success', title: 'สำเร็จ', text: feedback.success, confirmButtonText: 'ตกลง'});
    } else if (feedback.error) {
        window.Swal.fire({icon: 'error', title: 'ไม่สำเร็จ', text: feedback.error, confirmButtonText: 'ตกลง'});
    }

    if (feedback.open_modal) {
        const modalElement = resolveMeetingModal(root, feedback.open_modal);
        showMeetingModal(modalElement, bootstrapApi);
    }
}

function readLinkedTasks(option) {
    try {
        const tasks = JSON.parse(option?.dataset.tasks || '[]');
        return Array.isArray(tasks) ? tasks : [];
    } catch {
        return [];
    }
}

function populateMeetingTaskOptions(taskSelect, tasks) {
    const documentRoot = taskSelect.ownerDocument;
    const placeholder = documentRoot.createElement('option');
    placeholder.value = '';
    placeholder.textContent = 'ไม่ระบุ';
    taskSelect.replaceChildren(placeholder);
    tasks.forEach((task) => {
        const option = documentRoot.createElement('option');
        option.value = String(task.id);
        option.textContent = task.name;
        taskSelect.append(option);
    });
    taskSelect.value = '';
    // ดร็อปดาวน์กลาง (select-dropdown.js) ฟัง change บน <select> เดิมอยู่แล้วเพื่อซิงก์ป้ายบนปุ่ม
    // และอ่าน option สดทุกครั้งที่เปิดแผง จึงไม่ต้องสร้าง/ผูกดร็อปดาวน์ใหม่เองที่นี่
    taskSelect.dispatchEvent(new Event('change', {bubbles: true}));
}

/*
 * ตัวเลือก "งาน" ขึ้นกับโปรเจกต์ที่เลือก — รายการงานของแต่ละโปรเจกต์ฝังเป็น JSON ไว้ใน
 * data-tasks ของแต่ละ <option> โปรเจกต์มาจาก server แล้ว (เหมือน task-request.js::readParentTasks())
 * เปลี่ยนโปรเจกต์จึงแค่สร้าง <option> งานใหม่ ไม่ต้องยิง endpoint เพิ่ม
 */
export function initializeMeetingProjectLinks(root) {
    if (!root?.querySelectorAll) return;

    root.querySelectorAll('[data-meeting-project-link]').forEach((section) => {
        const projectSelect = section.querySelector('[data-meeting-project-select]');
        const taskSelect = section.querySelector('[data-meeting-task-select]');
        if (!projectSelect || !taskSelect || projectSelect.dataset.meetingProjectLinkReady === 'true') return;
        projectSelect.dataset.meetingProjectLinkReady = 'true';

        projectSelect.addEventListener('change', () => {
            populateMeetingTaskOptions(taskSelect, readLinkedTasks(projectSelect.options[projectSelect.selectedIndex]));
        });
    });
}

function renderAttendeesSummary(countElement, selectedIds, emptyText = 'ยังไม่ได้เลือกผู้เข้าร่วม', template = 'เลือกแล้ว :count คน') {
    if (!countElement) return;
    countElement.textContent = selectedIds.length ? template.replace(':count', String(selectedIds.length)) : emptyText;
}

/*
 * ผู้เข้าร่วมถูกย้ายไปเป็น modal แยกต่างหาก เปิดจากปุ่มในฟอร์มหลัก
 *
 * modal ทั้งสองใบต้อง "สลับ" กัน ไม่ใช่ "ซ้อน" กัน — Bootstrap modal ไม่รองรับการเปิดพร้อมกันจริง
 * (ดู node_modules/bootstrap/.../modal.js: _hideModal() ลบ body.modal-open และรีเซ็ต scrollbar
 * แบบไม่มีเงื่อนไขทุกครั้งที่ modal ใบใดใบหนึ่งปิด) ถ้าซ้อนกันแล้วปิดใบในก่อน ใบนอกจะโดนปลดล็อก scroll ไปด้วย
 * ทั้งที่ยังเปิดอยู่ จึงปิดใบนอกก่อนค่อยเปิดใบใน แล้วปิดใบในก็เปิดใบนอกกลับ ไม่เปิดพร้อมกันเด็ดขาด
 */
export function initializeMeetingAttendeeModals(root, bootstrapApi = globalThis.window?.bootstrap) {
    if (! root?.querySelectorAll || typeof bootstrapApi?.Modal?.getOrCreateInstance !== 'function') return;

    root.querySelectorAll('[data-meeting-attendees-field]').forEach((field) => {
        if (field.dataset.attendeesFieldInitialized === 'true') return;

        const ownerModal = field.closest('.meeting-form-modal');
        const attendeesModal = root.getElementById?.(field.dataset.meetingAttendeesField);
        const trigger = field.querySelector('.meeting-form-modal__attendees-trigger');
        const countElement = field.querySelector('.meeting-form-modal__attendees-count');
        const peopleSelectorRoot = attendeesModal?.querySelector('[data-people-selector]');
        if (! ownerModal || ! attendeesModal || ! trigger || ! peopleSelectorRoot) return;

        field.dataset.attendeesFieldInitialized = 'true';

        trigger.addEventListener('click', () => {
            ownerModal.addEventListener('hidden.bs.modal', () => {
                bootstrapApi.Modal.getOrCreateInstance(attendeesModal).show();
            }, {once: true});
            bootstrapApi.Modal.getOrCreateInstance(ownerModal).hide();
        });

        // ปิดใบในไม่ว่าจะด้วยปุ่ม, Escape, หรือคลิก backdrop ต้องพากลับไปฟอร์มหลักเหมือนกันหมด
        attendeesModal.addEventListener('hidden.bs.modal', () => {
            bootstrapApi.Modal.getOrCreateInstance(ownerModal).show();
        });

        peopleSelectorRoot.addEventListener('peopleselector:change', (event) => {
            renderAttendeesSummary(countElement, event.detail.selectedIds);
        });
    });
}

/*
 * ปุ่มแก้ไขบนการ์ดรายการประชุมเดิมเป็น <a href> พาไปหน้า meetings.show เต็มหน้า ซึ่งพาผู้ใช้
 * ออกจาก Workspace ที่ฝังอยู่โดยไม่จำเป็นแค่เพื่อเปิดฟอร์มแก้ไข — ตอนนี้โหลดฟอร์มแก้ไขของการประชุม
 * นั้นด้วย AJAX มาเติมลง slot เดียวกันทุกครั้ง (แทนที่ของเดิม) แล้วเปิดเป็น editMeetingModal ตามปกติ
 * ใช้ id คงที่ซ้ำกับหน้า meetings.show โดยตั้งใจ ไม่สร้าง modal แยกต่อการ์ด เพราะการ์ดอาจมีหลายสิบใบ
 * ต่อหน้า การ render ตัวเลือกผู้เข้าร่วมพร้อม avatar ซ้ำทุกใบจะทำให้หน้าหนักเกินจำเป็น
 */
export function initializeMeetingEditTriggers(root, bootstrapApi = globalThis.window?.bootstrap) {
    if (! root?.querySelectorAll) return;

    const slot = root.querySelector('[data-meeting-edit-modal-slot]');
    if (! slot || slot.dataset.meetingEditTriggersInitialized === 'true') return;
    slot.dataset.meetingEditTriggersInitialized = 'true';

    let pending = false;

    root.addEventListener('click', async (event) => {
        const trigger = event.target.closest('[data-meeting-edit-trigger]');
        if (! trigger || pending) return;

        const url = trigger.dataset.meetingEditUrl;
        if (! url) return;

        pending = true;
        trigger.disabled = true;

        try {
            const response = await fetch(url, {headers: {'X-Requested-With': 'XMLHttpRequest'}});
            if (! response.ok) throw new Error(`HTTP ${response.status}`);

            // คืน instance เดิมก่อนทิ้ง DOM เดิม กัน listener ของ Bootstrap ค้างอยู่กับ element ที่ถูกลบไปแล้ว
            bootstrapApi?.Modal?.getInstance?.(slot.querySelector('#editMeetingModal'))?.dispose?.();

            slot.innerHTML = await response.text();
            initializePeopleSelectors(slot);
            initializeMeetingAttendeeModals(slot, bootstrapApi);
            initializeMeetingProjectLinks(slot);
            initSelectDropdowns(slot);

            if (! showMeetingModal(resolveMeetingModal(root, 'editMeetingModal'), bootstrapApi, trigger)) {
                throw new Error('ไม่พบฟอร์มแก้ไขหลังโหลดเสร็จ');
            }
        } catch {
            window.Swal.fire({
                icon: 'error',
                title: 'ไม่สำเร็จ',
                text: 'โหลดฟอร์มแก้ไขไม่สำเร็จ กรุณาลองใหม่อีกครั้ง',
                confirmButtonText: 'ตกลง',
            });
        } finally {
            pending = false;
            trigger.disabled = false;
        }
    });
}

/*
 * กรองมุมมองผู้เข้าร่วมในหน้ารายละเอียดตามแผนก — เปิดหน้ามาเห็น "ทั้งหมด" เสมอ ผู้ใช้เลือกเองว่า
 * จะดูเฉพาะแผนกไหน เพราะการประชุมหนึ่งอาจมีคนข้ามแผนกเข้าร่วม การกรองแค่ซ่อน/แสดงฝั่ง client
 * (เหมือนตัวกรองแผนกใน people-selector.js) ไม่ตัดรายชื่อทิ้งและไม่กระทบจำนวนในหัวข้อ "ผู้เข้าร่วม N คน"
 */
export function initializeAttendeeDepartmentFilter(root) {
    root.querySelectorAll('[data-attendee-department-filter]').forEach((filterGroup) => {
        if (filterGroup.dataset.attendeeFilterInitialized === 'true') return;
        filterGroup.dataset.attendeeFilterInitialized = 'true';

        const list = filterGroup.parentElement?.querySelector('[data-attendee-list]');
        const buttons = [...filterGroup.querySelectorAll('[data-attendee-department]')];
        const rows = list ? [...list.querySelectorAll('[data-attendee-department]')] : [];
        if (! list || ! rows.length) return;

        buttons.forEach((button) => {
            button.addEventListener('click', () => {
                const selected = button.dataset.attendeeDepartment;

                buttons.forEach((candidate) => {
                    const isActive = candidate === button;
                    candidate.classList.toggle('is-active', isActive);
                    candidate.setAttribute('aria-pressed', String(isActive));
                });
                rows.forEach((row) => {
                    row.hidden = selected !== '' && row.dataset.attendeeDepartment !== selected;
                });
            });
        });
    });
}

function initializeDeleteConfirmation(root) {
    root.querySelectorAll('[data-meeting-delete]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const result = await window.Swal.fire({
                icon: 'warning',
                title: 'ยืนยันการลบการประชุม',
                text: `ต้องการลบการประชุม “${form.dataset.meetingTitle || ''}” หรือไม่?`,
                showCancelButton: true,
                confirmButtonText: 'ยืนยันลบ',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#dc2626',
                reverseButtons: true,
            });

            if (result.isConfirmed) form.submit();
        });
    });
}

export function initializeMeetingPage(root = document, bootstrapApi = globalThis.window?.bootstrap) {
    initializeMeetingModals(root, bootstrapApi);
    initializeDeleteConfirmation(root);
    initializeAttendeeDepartmentFilter(root);
    initializePeopleSelectors(root);
    initializeMeetingAttendeeModals(root, bootstrapApi);
    initializeMeetingEditTriggers(root, bootstrapApi);
    initSelectDropdowns(root);
    initializeMeetingProjectLinks(root);
    // ตัวกรองรายการประชุม (ค้นหา/ช่วงเวลา/พนักงาน) ส่งผลทันทีไม่ต้องกดปุ่ม "แสดงผล"
    initAutoSubmitFilters(root);
    initPeriodRange(root);
    /*
     * ช่องเวลาเริ่ม/สิ้นสุดของการประชุมใช้ตัวเลือกวันที่ชุดเดียวกับหน้างาน
     * ต่างกันแค่ประชุมเป็น datetime-local จึงมีแถวเลือกเวลาเพิ่มมาให้
     * ส่วนงานในโปรเจกต์เป็นความละเอียดระดับวัน ไม่มีแถวเวลา
     * ผู้ใช้ทุกบทบาทจึงเห็นปฏิทิน พ.ศ. หน้าตาเดียวกันทุกที่ในระบบ
     */
    useDatePickers();
    showFeedback(root, bootstrapApi);
}

if (typeof document !== 'undefined') initializeMeetingPage(document);
