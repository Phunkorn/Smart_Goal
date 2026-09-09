/*
 * งานประจำ — โหมดหนึ่งในกล่องเพิ่มงานของหน้าบันทึกงานประจำวัน
 *
 * เดิมเป็นหน้าแยก แล้วเป็นกล่องของตัวเองที่มีปุ่มเปิดอยู่บนหัวหน้าจอ ตอนนี้ถูกยุบ
 * มาเป็นแท็บ "ทำซ้ำทุกวัน" ในกล่องเดียวกับฟอร์มบันทึกงาน เพราะตอนกดปุ่มเพิ่มงาน
 * ผู้ใช้ยังไม่ได้ตัดสินใจว่าสิ่งนี้จะทำครั้งเดียวหรือทำทุกวัน
 *
 * การเปิด/ปิดกล่อง backdrop โฟกัส Escape และการซ้อนชั้นเป็นของ modal-stack
 * ผ่าน entry-form.js เพียงเจ้าเดียว ไฟล์นี้จึงไม่แตะเรื่องพวกนั้นเลย เหลือหน้าที่
 * เดียวคือยืนยันการลบผ่าน Swal ตามกติกาของโปรเจกต์ที่ห้ามใช้ confirm() ของเบราว์เซอร์
 *
 * ฟอร์มเพิ่มส่งแบบปกติแล้วโหลดหน้าใหม่ ไม่ทำ AJAX เพราะผลลัพธ์ที่ผู้ใช้ต้องเห็น
 * คือรายการของวันนี้ที่ระบบเพิ่งวางให้ ซึ่งอยู่หลังกล่องอยู่แล้ว
 */
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

    const resetEditor = () => {
        if (! editor) return;
        editor.reset();
        editor.action = editor.dataset.routineStoreUrl;
        if (method) method.value = 'POST';
        if (submitLabel) submitLabel.textContent = 'เพิ่มงานประจำ';
        if (cancel) cancel.hidden = true;
    };

    panel.addEventListener('click', (event) => {
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
        const set = (name, value) => { const input = editor.elements.namedItem(name); if (input) input.value = value ?? ''; };
        set('title', values.title); set('default_start_time', values.start); set('default_end_time', values.end);
        set('work_log_category_id', values.category); set('work_order_list_id', values.project);
        set('job_id', values.task); set('details', values.details);
        editor.querySelectorAll('[name="kind"]').forEach((input) => { input.checked = input.value === values.kind; });
        editor.querySelectorAll('[name="weekdays[]"]').forEach((input) => { input.checked = (values.weekdays || []).includes(Number(input.value)); });
        editor.querySelectorAll('[name="participants[]"]').forEach((input) => { input.checked = (values.participants || []).includes(Number(input.value)); });
        editor.scrollIntoView({behavior: 'smooth', block: 'start'});
        editor.querySelector('[name="title"]')?.focus();
    });

    panel.addEventListener('submit', async (event) => {
        const form = event.target.closest('[data-routine-delete]');

        if (! form) return;

        event.preventDefault();

        const title = form.closest('.routine-row')
            ?.querySelector('.routine-row__title')?.textContent?.trim() || '';

        const confirmed = await swal?.fire({
            customClass: DAILY_LOG_DIALOG_CLASS,
            icon: 'warning',
            title: 'ลบรายการนี้?',
            html: `${title}<br><small>รายการที่บันทึกไว้แล้วจะยังอยู่ ระบบจะหยุดสร้างรายการใหม่เท่านั้น</small>`,
            showCancelButton: true,
            confirmButtonText: 'ลบ',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#dc3545',
            reverseButtons: true,
            focusCancel: true,
        });

        if (confirmed?.isConfirmed) form.submit();
    });

    return {panel};
}
