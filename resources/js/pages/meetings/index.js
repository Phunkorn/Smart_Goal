import {
    derivePeopleState,
    initializePeopleSelectors,
    normalizeKeyword,
    updateSelection,
} from '../../components/people-selector.js';

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
    initializePeopleSelectors(root);
    showFeedback(root, bootstrapApi);
}

if (typeof document !== 'undefined') initializeMeetingPage(document);
