/*
 * กล่องเพิ่มงาน (modal)
 *
 * การเปิด/ปิด โฟกัส Escape backdrop และการซ้อนชั้น เป็นของ modal-stack ทั้งหมด
 * ไฟล์นี้จึงดูแลเฉพาะ "เนื้อในกล่อง" คือการเติมค่าเดิมตอนแก้ไข การล้างค่าตอน
 * สร้างใหม่ การสลับช่องที่ขึ้นกับประเภทงาน และการสลับโหมดระหว่าง "ทำครั้งเดียว"
 * กับ "ทำซ้ำทุกวัน"
 *
 * การสลับโหมดเป็นการสลับเนื้อในของกล่องเดียวกัน ไม่ใช่การเปิด overlay ชั้นใหม่
 * เจ้าของสถานะเปิด/ปิดจึงยังมีเจ้าเดียวคือ modal-stack ตามกติกาของโปรเจกต์
 */
import {modalStack} from '../../components/modal-stack.js';
import {refreshParticipantCount} from './participants.js';

/** ช่องที่แสดงเฉพาะบางประเภทงาน เช่น สถานที่ของงานนอกสถานที่ */
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
    const modeBar = modal.querySelector('[data-entry-modes]');
    const attachmentsNode = modal.querySelector('[data-entry-attachments]');

    /**
     * สลับโหมดของกล่อง
     *
     * ไฟล์แนบผูกกับบันทึกที่มีอยู่แล้วเท่านั้น จึงต้องถูกซ่อนไปพร้อมกับโหมด
     * "ทำครั้งเดียว" ไม่งั้นจะค้างอยู่ใต้ฟอร์มงานประจำซึ่งไม่มีอะไรให้แนบ
     */
    const showMode = (mode) => {
        modal.querySelectorAll('[data-entry-panel]').forEach((panel) => {
            panel.hidden = panel.dataset.entryPanel !== mode;
        });

        modal.querySelectorAll('[data-entry-mode]').forEach((button) => {
            const active = button.dataset.entryMode === mode;

            button.classList.toggle('is-active', active);
            button.setAttribute('aria-selected', active ? 'true' : 'false');
        });

        if (attachmentsNode && mode !== 'once') attachmentsNode.hidden = true;
    };

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

        // ไม่มีค่ามา = ใช้ตัวเลือกแรกที่ Blade ติ๊กไว้ให้จาก WorkLogDesign ฝั่งเซิร์ฟเวอร์
        const kinds = [...modal.querySelectorAll('[data-entry-kind]')];
        const kind = presented?.kind || kinds[0]?.value || '';

        kinds.forEach((radio) => {
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

            return;
        }

        const mode = event.target.closest('[data-entry-mode]');

        if (mode) {
            event.preventDefault();
            showMode(mode.dataset.entryMode);
        }
    });

    // modal-stack ส่งเหตุการณ์นี้เมื่อกด Escape ที่ชั้นบนสุด
    modal.addEventListener('modalstack:dismiss', close);

    return {
        showError,
        close,
        showMode,
        /** เปิดเพื่อเพิ่มงานใหม่ — เลือกโหมดได้ทั้งทำครั้งเดียวและทำซ้ำทุกวัน */
        openForCreate({title = '', kind = '', categoryId = '', workDate = ''} = {}, opener = null) {
            form.action = storeAction;
            if (methodNode) methodNode.value = 'POST';
            if (titleNode) titleNode.textContent = 'เพิ่มงาน';
            if (modeBar) modeBar.hidden = false;

            form.reset();
            fillFrom({title, kind, work_date: workDate, category: categoryId ? {id: categoryId} : null});
            showMode('once');
            showError('');
            stack.open(modal, opener);
        },
        /**
         * เปิดเพื่อแก้ไขรายการเดิม
         *
         * ซ่อนแถบโหมดไว้ เพราะบันทึกที่มีอยู่แล้วเปลี่ยนเป็นแม่แบบงานประจำไม่ได้
         * การให้เลือกจึงเป็นทางที่กดแล้วไม่มีอะไรเกิดขึ้น
         */
        openForEdit(presented, opener = null) {
            if (! presented) return;

            form.action = updateActionTemplate.replace('__ID__', String(presented.id));
            if (methodNode) methodNode.value = 'PATCH';
            if (titleNode) titleNode.textContent = 'แก้ไขบันทึกงาน';
            if (modeBar) modeBar.hidden = true;

            showMode('once');
            fillFrom(presented);
            showError('');
            stack.open(modal, opener);
        },
    };
}
