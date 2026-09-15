/*
 * งานประจำของฉัน — เนื้อในหนึ่งของกล่องในหน้าบันทึกงานประจำวัน
 *
 * การเปิด/ปิดกล่อง backdrop โฟกัส Escape และการซ้อนชั้นเป็นของ modal-stack
 * ผ่าน entry-form.js เพียงเจ้าเดียว ไฟล์นี้จึงดูแลการเติมฟอร์มตอนแก้ไข
 * และยืนยันการลบผ่าน Swal ตามกติกาของโปรเจกต์ที่ห้ามใช้ confirm() ของเบราว์เซอร์
 */
import {resetMultipleDates} from '../../components/date-picker.js';
import {DAILY_LOG_DIALOG_CLASS} from './client.js';

export function initRoutinePanel({
    root = document.querySelector('[data-daily-log]'),
    swal = globalThis.Swal,
} = {}) {
    const panel = root?.querySelector('[data-entry-panel="routine"]');

    if (! root || ! panel || panel.dataset.routineReady === 'on') return null;

    panel.dataset.routineReady = 'on';
    const editor = panel.querySelector('[data-routine-form]');
    const method = editor?.querySelector('[data-routine-method]');
    const submitLabel = editor?.querySelector('[data-routine-submit-label]');
    const cancel = editor?.querySelector('[data-routine-edit-cancel]');
    const planDate = editor?.querySelector('[name="plan_date"]');
    const planMinDate = planDate?.min || '';
    const dateField = editor?.querySelector('[data-routine-plan-date]');
    const weekdays = editor?.querySelector('[data-routine-weekdays]');
    const category = editor?.querySelector('[name="work_log_category_id"]');
    const setPlanMode = (singleDate, allowPast = false) => {
        if (dateField) dateField.hidden = ! singleDate;
        if (planDate) {
            planDate.disabled = ! singleDate;
            planDate.required = singleDate;
            planDate.min = allowPast ? '' : planMinDate;
        }
        if (weekdays) weekdays.hidden = singleDate;
        weekdays?.querySelectorAll('input').forEach((input) => { input.disabled = singleDate; });
        if (category) category.required = singleDate;
    };

    const resetEditor = () => {
        if (! editor) return;
        editor.reset();
        editor.action = editor.dataset.routineStoreUrl;
        if (method) method.value = 'POST';
        if (submitLabel) submitLabel.textContent = editor.dataset.routineCreateLabel;
        if (cancel) cancel.hidden = true;
        setPlanMode(true);
        resetMultipleDates(planDate, {enabled: true, value: planDate?.value || ''});
    };

    root.addEventListener('click', (event) => {
        if (event.target.closest('[data-routine-edit-cancel]')) {
            resetEditor();
            return;
        }

        const button = event.target.closest('[data-routine-edit]');
        const row = button?.closest('[data-routine-row]');
        if (! row || ! editor) return;

        const values = JSON.parse(row.dataset.routineValues || '{}');
        editor.action = row.dataset.routineEditUrl;
        if (method) method.value = 'PATCH';
        if (submitLabel) submitLabel.textContent = 'บันทึกการแก้ไข';
        if (cancel) cancel.hidden = false;
        const set = (name, value) => {
            const input = editor.elements.namedItem(name);
            if (! input) return;
            input.value = value ?? '';
            // ป้ายของดร็อปดาวน์แบบสไลด์ตามค่า <select> ผ่าน change เท่านั้น
            if (input.tagName === 'SELECT') input.dispatchEvent(new input.ownerDocument.defaultView.Event('change', {bubbles: true}));
        };
        set('title', values.title); set('default_start_time', values.start); set('default_end_time', values.end);
        set('work_log_category_id', values.category); set('work_order_list_id', values.project);
        set('job_id', values.task); set('details', values.details);
        if (planDate) planDate.value = values.plan_date || '';
        // แก้ไขแม่แบบเดิมได้ทีละหนึ่งวัน การเลือกหลายวันมีเฉพาะตอนสร้างใหม่
        resetMultipleDates(planDate, {enabled: false});
        setPlanMode(Boolean(values.plan_date), true);
        editor.querySelectorAll('[name="weekdays[]"]').forEach((input) => { input.checked = (values.weekdays || []).includes(Number(input.value)); });
        editor.querySelectorAll('[name="participants[]"]').forEach((input) => { input.checked = (values.participants || []).includes(Number(input.value)); });
        editor.scrollIntoView({behavior: 'smooth', block: 'start'});
        editor.querySelector('[name="title"]')?.focus();
    });

    root.addEventListener('submit', async (event) => {
        const form = event.target.closest('[data-routine-delete]');

        if (! form) return;

        event.preventDefault();

        const title = form.closest('[data-routine-row]')
            ?.querySelector('[data-routine-title]')?.textContent?.trim() || '';

        const confirmed = await swal?.fire({
            customClass: DAILY_LOG_DIALOG_CLASS,
            icon: 'warning',
            title: 'ลบรายการนี้?',
            // ชื่องานเป็นข้อความที่ผู้ใช้ตั้งเอง ต้องเป็น text ไม่ใช่ html
            text: `${title} — รายการที่บันทึกไว้แล้วจะยังอยู่ ระบบจะหยุดสร้างรายการใหม่`,
            showCancelButton: true,
            confirmButtonText: 'ลบ',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#dc3545',
            reverseButtons: true,
            focusCancel: true,
        });

        if (confirmed?.isConfirmed) form.submit();
    });

    return {panel, resetEditor};
}
