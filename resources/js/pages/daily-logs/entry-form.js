/*
 * กล่องบันทึกงาน (modal) — ฟอร์มแบบเต็ม และงานประจำของฉัน
 *
 * การเปิด/ปิด โฟกัส Escape backdrop และการซ้อนชั้น เป็นของ modal-stack ทั้งหมด
 * ไฟล์นี้ดูแลเฉพาะ "เนื้อในกล่อง": เนื้อในไหนแสดง (บันทึกงาน หรืองานประจำของฉัน),
 * ประเภทงาน, ช่องที่ขึ้นกับประเภทงาน, การเติมค่าเดิมตอนแก้ไข หรือค่าที่กรอกไว้ในเพิ่มงานใหม่
 *
 * ป้ายชื่อประเภทงานมาจาก Blade (WorkLogDesign) ไฟล์นี้จึงไม่มีข้อความชื่อประเภทงานของตัวเอง
 */
import {resetMultipleDates} from '../../components/date-picker.js';
import {modalStack} from '../../components/modal-stack.js';
import {refreshParticipantCount} from './participants.js';

const buildSteps = (form, routine = false) => {
    const body = form?.querySelector('.log-modal__body');
    if (! body || body.dataset.wizardReady) return;
    body.dataset.wizardReady = 'on';
    const doc = body.ownerDocument;
    const step = (number, label) => {
        const node = doc.createElement('div');
        node.className = 'log-modal__step';
        node.dataset.wizardStep = String(number);
        node.innerHTML = `<p class="log-modal__step-heading"><span>${number}</span> ${label}</p>`;
        body.appendChild(node);
        return node;
    };
    const info = step(2, 'ข้อมูลงาน');
    const schedule = step(3, 'วันและเวลา');
    const move = (node, target) => { if (node) target.appendChild(node); };
    const field = (selector) => form.querySelector(selector)?.closest('.log-field');
    const firstRow = body.querySelector('.log-field-row');
    if (routine) {
        move(field('[name="work_log_category_id"]'), info);
        move(field('[name="title"]'), info);
        move(field('[name="details"]'), info);
        move(form.querySelector('[data-routine-plan-date]'), schedule);
        move(form.querySelector('[data-routine-weekdays]'), schedule);
        move(form.querySelector('.routine-form__window'), schedule);
        move(form.querySelector('[data-participant-picker]'), schedule);
        const project = field('[name="work_order_list_id"]');
        const task = field('[name="job_id"]');
        move(project, schedule); move(task, schedule);
    } else {
        move(field('[data-entry-category]'), info);
        move(field('[data-entry-title]'), info);
        move(field('[data-entry-location]'), info);
        move(field('[data-entry-details]'), info);
        move(field('[data-entry-date]'), schedule);
        const timeRow = [...body.children].find((node) => node !== firstRow && node.classList.contains('log-field-row'));
        if (timeRow && timeRow !== firstRow) move(timeRow, schedule);
        move(body.querySelector('.log-field__hint'), schedule);
        move(field('[data-entry-project]'), schedule);
        move(field('[data-entry-task]'), schedule);
        move(form.querySelector('[data-participant-picker]'), schedule);
    }
    firstRow?.remove();
    body.querySelectorAll('details.routine-form__more').forEach((node) => node.remove());
    schedule.hidden = true;
};

const showStep = (form, number) => {
    if (! form) return;
    form.querySelectorAll('[data-wizard-step]').forEach((node) => { node.hidden = node.dataset.wizardStep !== String(number); });
    const back = form.querySelector('[data-entry-step-back]');
    const next = form.querySelector('[data-entry-step-next]');
    const submit = form.querySelector('button[type="submit"]:not([data-entry-modal-close])');
    if (back) back.hidden = number === 2;
    if (next) next.hidden = number === 3;
    if (submit) submit.hidden = number !== 3;
};

/** ช่องที่แสดงเฉพาะบางประเภทงาน เช่น สถานที่ของงานนอกสถานที่ (และเป็นช่องบังคับของประเภทนั้น) */
const syncKindFields = (modal, kind) => {
    modal.querySelectorAll('[data-entry-only-kind]').forEach((field) => {
        const active = field.dataset.entryOnlyKind === kind;
        field.hidden = ! active;
        field.querySelectorAll('input, select, textarea').forEach((input) => {
            input.required = active;
        });
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
    const kindNode = modal.querySelector('[data-entry-kind]');
    const categoryNode = modal.querySelector('[data-entry-category]');
    const moreNode = modal.querySelector('[data-entry-more]');
    const attachmentsNode = modal.querySelector('[data-entry-attachments]');
    const routineForm = modal.querySelector('[data-routine-form]');
    const dateNode = modal.querySelector('[data-entry-date]');
    buildSteps(form);
    buildSteps(routineForm, true);

    /** เนื้อในที่แสดง — ไฟล์แนบผูกกับบันทึกที่มีอยู่แล้ว จึงซ่อนเมื่อไม่ใช่ฟอร์มบันทึกงาน */
    const showMode = (mode) => {
        modal.querySelectorAll('[data-entry-panel]').forEach((panel) => {
            panel.hidden = panel.dataset.entryPanel !== mode;
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

    const setKind = (kind) => {
        const value = kind || kindNode?.defaultValue || '';
        if (kindNode) kindNode.value = value;
        syncKindFields(modal, value);
    };

    const fillFrom = (presented) => {
        const set = (selector, value) => {
            const node = modal.querySelector(selector);
            if (! node) return;
            node.value = value ?? '';
            // ดร็อปดาวน์แบบสไลด์อ่านป้ายจาก change ของ <select> ตั้งค่าตรง ๆ แล้วป้ายจะค้างค่าเก่า
            if (node.tagName === 'SELECT') node.dispatchEvent(new node.ownerDocument.defaultView.Event('change', {bubbles: true}));
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
        setKind(presented?.kind);

        // ผู้ร่วมงานเป็น checkbox หลายตัว form.reset() คืนค่าเป็นสถานะตอนโหลดหน้า
        // ไม่ใช่สถานะของบันทึกที่กำลังเปิด จึงต้องตั้งให้ตรงเองทุกครั้ง
        const picker = modal.querySelector('[data-participant-picker]');
        const chosen = new Set((presented?.participants || []).map((person) => String(person.id)));

        if (picker) {
            picker.querySelectorAll('[data-participant-checkbox]').forEach((input) => {
                input.checked = chosen.has(String(input.value));
            });
            refreshParticipantCount(picker);
        }

        // ข้อมูลเสริมที่มีค่าอยู่แล้วต้องมองเห็นตอนแก้ไข ไม่ถูกพับซ่อนไว้
        if (moreNode) {
            moreNode.open = Boolean(presented?.project?.id || presented?.task?.id || presented?.details || chosen.size);
        }
    };

    const setMultipleDateMode = (enabled, value = '') => resetMultipleDates(dateNode, {enabled, value});

    modal.addEventListener('click', (event) => {
        const choice = event.target.closest('[data-entry-select-kind]');
        if (choice) {
            const kind = choice.dataset.entrySelectKind;
            if (kind === 'routine') {
                routineForm?.reset();
                if (routineForm) routineForm.action = routineForm.dataset.routineStoreUrl;
                const method = routineForm?.querySelector('[data-routine-method]');
                if (method) method.value = 'POST';
                const dateField = routineForm?.querySelector('[data-routine-plan-date]');
                if (dateField) dateField.hidden = false;
                const planDate = dateField?.querySelector('input');
                if (planDate) {
                    planDate.disabled = false;
                    planDate.required = true;
                    if (planDate.value < planDate.min) planDate.value = planDate.min;
                    // งานประจำใหม่เลือกได้หลายวัน — ล้างวันที่ค้างจากรอบก่อนทุกครั้งที่เปิด
                    resetMultipleDates(planDate, {enabled: true, value: planDate.value});
                }
                const weekdays = routineForm?.querySelector('[data-routine-weekdays]');
                if (weekdays) weekdays.hidden = true;
                weekdays?.querySelectorAll('input').forEach((input) => { input.disabled = true; });
                const category = routineForm?.querySelector('[name="work_log_category_id"]');
                if (category) category.required = true;
                const label = routineForm?.querySelector('[data-routine-submit-label]');
                if (label) label.textContent = routineForm.dataset.routineCreateLabel;
                const cancel = routineForm?.querySelector('[data-routine-edit-cancel]');
                if (cancel) cancel.hidden = true;
                showMode('routine');
                showStep(routineForm, 2);
            } else {
                setKind('field');
                showMode('once');
                showStep(form, 2);
            }
            return;
        }
        const next = event.target.closest('[data-entry-step-next]');
        if (next) {
            const activeForm = next.closest('form');
            const current = activeForm?.querySelector('[data-wizard-step="2"]');
            const invalid = [...(current?.querySelectorAll('input, select, textarea') || [])]
                .find((input) => ! input.checkValidity());
            if (invalid) { invalid.reportValidity(); return; }
            showStep(activeForm, 3);
            return;
        }
        const back = event.target.closest('[data-entry-step-back]');
        if (back) { showStep(back.closest('form'), 2); return; }
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
        showMode,
        /**
         * เปิดเพื่อสร้างใหม่
         *
         * mode "once" = ฟอร์มบันทึกงานแบบเต็ม (values = ค่าที่กรอกไว้ในเพิ่มงานใหม่)
         * mode "routine" = งานประจำของฉัน
         * หมวดงานเป็นช่องบังคับของรายการใหม่ ส่วนรายการเก่าที่ไม่มีหมวดยังแก้ไขได้ตามเดิม
         */
        openForCreate({mode = 'once', kind = '', title = '', workDate = '', values = {}} = {}, opener = null) {
            form.action = storeAction;
            if (methodNode) methodNode.value = 'POST';
            if (titleNode) titleNode.textContent = title || opener?.dataset.entryTitle || 'บันทึกงาน';

            form.reset();
            setMultipleDateMode(true, workDate);
            fillFrom({kind, work_date: workDate, ...values});
            if (categoryNode) categoryNode.required = true;
            showMode(mode);
            if (mode === 'choice') showStep(form, 2);
            if (mode === 'once') showStep(form, 2);
            if (mode === 'routine') {
                showStep(routineForm, 2);
            }
            showError('');
            stack.open(modal, opener);
        },
        /** เปิดเพื่อแก้ไขบันทึกเดิม — ประเภทงานคงตามรายการเดิม */
        openForEdit(presented, opener = null) {
            if (! presented) return;

            form.action = updateActionTemplate.replace('__ID__', String(presented.id));
            if (methodNode) methodNode.value = 'PATCH';
            if (titleNode) titleNode.textContent = 'แก้ไขบันทึกงาน';
            if (categoryNode) categoryNode.required = false;

            showMode('once');
            showStep(form, 2);
            setMultipleDateMode(false);
            fillFrom(presented);
            showError('');
            stack.open(modal, opener);
        },
    };
}
