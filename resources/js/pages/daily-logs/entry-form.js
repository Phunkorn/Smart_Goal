/*
 * ฟอร์มเต็มของบันทึกงาน (modal)
 *
 * การเปิด/ปิด โฟกัส Escape backdrop และการซ้อนชั้น เป็นของ modal-stack ทั้งหมด
 * ไฟล์นี้จึงดูแลเฉพาะ "เนื้อในฟอร์ม" คือการเติมค่าเดิมตอนแก้ไข การล้างค่าตอน
 * สร้างใหม่ และการสลับช่องที่ขึ้นกับประเภทงาน
 */
import {modalStack} from '../../components/modal-stack.js';
import {refreshParticipantCount} from './participants.js';

/** ช่องที่แสดงเฉพาะบางประเภทงาน (สถานที่ของงานนอกสถานที่ / ผู้แจ้งของงานแทรก) */
const syncKindFields = (modal, kind) => {
    modal.querySelectorAll('[data-entry-only-kind]').forEach((field) => {
        field.hidden = field.dataset.entryOnlyKind !== kind;
    });
};

export function initEntryForm({
    root = document.querySelector('[data-daily-log]'),
    stack = modalStack(),
    storeAction = '',
    updateActionTemplate = '',
} = {}) {
    const modal = root?.querySelector('[data-log-entry-modal]');
    const form = modal?.querySelector('[data-log-entry-form]');

    if (! root || ! modal || ! form || modal.dataset.entryReady === 'on') return null;

    modal.dataset.entryReady = 'on';

    const errorBox = modal.querySelector('[data-entry-error]');
    const titleNode = modal.querySelector('[data-entry-modal-title]');
    const methodNode = modal.querySelector('[data-entry-method]');

    const showError = (message) => {
        if (! errorBox) return;

        errorBox.textContent = message || '';
        errorBox.hidden = ! message;
    };

    const close = () => {
        stack.close(modal);
        showError('');
    };

    const fillFrom = (presented) => {
        const set = (selector, value) => {
            const node = modal.querySelector(selector);
            if (node) node.value = value ?? '';
        };

        set('[data-entry-title]', presented?.title);
        set('[data-entry-category]', presented?.category?.id);
        set('[data-entry-date]', presented?.work_date);
        set('[data-entry-start]', presented?.started_time);
        set('[data-entry-end]', presented?.ended_time);
        set('[data-entry-duration]', '');
        set('[data-entry-location]', presented?.location);
        set('[data-entry-requester]', presented?.requester_name);
        set('[data-entry-project]', presented?.project?.id);
        set('[data-entry-task]', presented?.task?.id);
        set('[data-entry-details]', presented?.details);

        const kind = presented?.kind || 'routine';
        modal.querySelectorAll('[data-entry-kind]').forEach((radio) => {
            radio.checked = radio.value === kind;
        });
        syncKindFields(modal, kind);

        // ผู้ร่วมงานเป็น checkbox หลายตัว form.reset() คืนค่าเป็นสถานะตอนโหลดหน้า
        // ไม่ใช่สถานะของบันทึกที่กำลังเปิด จึงต้องตั้งให้ตรงเองทุกครั้ง
        const picker = modal.querySelector('[data-participant-picker]');

        if (picker) {
            const chosen = new Set((presented?.participants || []).map((person) => String(person.id)));

            picker.querySelectorAll('[data-participant-checkbox]').forEach((input) => {
                input.checked = chosen.has(String(input.value));
            });
            refreshParticipantCount(picker);
        }
    };

    // ประเภทงานเปลี่ยน → สลับช่องเฉพาะประเภททันที
    modal.addEventListener('change', (event) => {
        if (event.target.matches('[data-entry-kind]')) {
            syncKindFields(modal, event.target.value);
        }
    });

    modal.addEventListener('click', (event) => {
        if (event.target.closest('[data-entry-modal-close]')) {
            event.preventDefault();
            close();
        }
    });

    // modal-stack ส่งเหตุการณ์นี้เมื่อกด Escape ที่ชั้นบนสุด
    modal.addEventListener('modalstack:dismiss', close);

    return {
        showError,
        close,
        /** เปิดเพื่อสร้างรายการใหม่ โดยยกค่าที่พิมพ์ไว้ในช่องบันทึกเร็วมาต่อให้ */
        openForCreate({title = '', kind = 'routine', categoryId = '', workDate = ''} = {}, opener = null) {
            form.action = storeAction;
            if (methodNode) methodNode.value = 'POST';
            if (titleNode) titleNode.textContent = 'บันทึกงาน';

            form.reset();
            fillFrom({title, kind, work_date: workDate, category: categoryId ? {id: categoryId} : null});
            showError('');
            stack.open(modal, opener);
        },
        /** เปิดเพื่อแก้ไขรายการเดิม */
        openForEdit(presented, opener = null) {
            if (! presented) return;

            form.action = updateActionTemplate.replace('__ID__', String(presented.id));
            if (methodNode) methodNode.value = 'PATCH';
            if (titleNode) titleNode.textContent = 'แก้ไขบันทึกงาน';

            fillFrom(presented);
            showError('');
            stack.open(modal, opener);
        },
    };
}
