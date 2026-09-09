/*
 * ไฟล์แนบของบันทึกงาน
 *
 * URL ของไฟล์มาจากเซิร์ฟเวอร์เสมอ (route ที่ตรวจสิทธิ์แล้ว) ฝั่งนี้ไม่ประกอบ
 * เส้นทางไฟล์เอง เพราะไฟล์เป็นไฟล์ส่วนตัวที่ต้องผ่าน MediaController ทุกครั้ง
 * และ path จริงใน storage ต้องไม่หลุดออกมาถึงเบราว์เซอร์
 */
import {DAILY_LOG_DIALOG_CLASS, sendAction} from './client.js';

/** ไอคอนตามชนิดไฟล์ พอให้แยกออกด้วยตาโดยไม่ต้องอ่านนามสกุล */
const iconFor = (type = '') => {
    if (type.startsWith('image/')) return 'bi-file-earmark-image';
    if (type.includes('pdf')) return 'bi-file-earmark-pdf';
    if (type.includes('sheet') || type.includes('excel')) return 'bi-file-earmark-spreadsheet';
    if (type.includes('word')) return 'bi-file-earmark-word';
    if (type.includes('zip')) return 'bi-file-earmark-zip';

    return 'bi-file-earmark';
};

export function initAttachments({
    root = document.querySelector('[data-daily-log]'),
    destroyTemplate = '',
    storeTemplate = '',
    onChange = () => {},
    swal = globalThis.Swal,
} = {}) {
    const section = root?.querySelector('[data-entry-attachments]');
    const form = section?.querySelector('[data-attachment-form]');
    const list = section?.querySelector('[data-attachment-list]');

    if (! root || ! section || ! form || section.dataset.attachmentsReady === 'on') return null;

    section.dataset.attachmentsReady = 'on';

    const doc = root.ownerDocument;
    const errorBox = section.querySelector('[data-attachment-error]');
    let currentLogId = null;

    const showError = (message) => {
        if (! errorBox) return;

        errorBox.textContent = message || '';
        errorBox.hidden = ! message;
    };

    const render = (attachments = []) => {
        if (! list) return;

        list.replaceChildren();

        attachments.forEach((attachment) => {
            const item = doc.createElement('li');
            item.className = 'log-attachments__item';
            item.dataset.attachmentId = String(attachment.id);

            const link = doc.createElement('a');
            link.href = attachment.url;
            link.target = '_blank';
            link.rel = 'noopener';
            link.className = 'log-attachments__link';
            link.innerHTML = `<i class="bi ${iconFor(attachment.type)}" aria-hidden="true"></i>`;
            // ใช้ textContent เพื่อไม่ให้ชื่อไฟล์ที่ผู้ใช้ตั้งเองกลายเป็น HTML
            link.append(doc.createTextNode(attachment.name));

            const remove = doc.createElement('button');
            remove.type = 'button';
            remove.className = 'log-attachments__remove';
            remove.dataset.attachmentRemove = String(attachment.id);
            remove.setAttribute('aria-label', `ลบไฟล์ ${attachment.name}`);
            remove.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';

            item.append(link, remove);
            list.append(item);
        });
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        showError('');

        if (currentLogId === null) return;

        const input = form.querySelector('[data-attachment-input]');

        if (! input?.files?.length) {
            showError('กรุณาเลือกไฟล์ก่อน');

            return;
        }

        const submit = form.querySelector('[data-attachment-submit]');
        submit?.setAttribute('disabled', 'disabled');

        try {
            const body = new (doc.defaultView || globalThis).FormData(form);
            const response = await fetch(storeTemplate.replace('__ID__', String(currentLogId)), {
                method: 'POST',
                body,
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': doc.querySelector('meta[name="csrf-token"]')?.content || '',
                },
            });

            const payload = await response.json().catch(() => ({}));

            if (! response.ok) {
                throw new Error(
                    payload?.errors?.attachments?.[0]
                        || payload?.message
                        || 'แนบไฟล์ไม่สำเร็จ'
                );
            }

            form.reset();
            render(payload?.log?.attachments || []);
            onChange(payload);
        } catch (error) {
            showError(error.message);
        } finally {
            submit?.removeAttribute('disabled');
        }
    });

    // delegation ที่ระดับ section เพื่อให้รายการที่ render ใหม่ยังกดลบได้
    section.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-attachment-remove]');

        if (! button || currentLogId === null) return;

        event.preventDefault();

        // ไฟล์แนบถูกลบจากในกล่องเพิ่มงาน กล่องยืนยันจึงต้องอยู่เหนือกล่องนั้น
        const confirmed = await swal?.fire({
            customClass: DAILY_LOG_DIALOG_CLASS,
            icon: 'warning',
            title: 'ลบไฟล์แนบนี้?',
            showCancelButton: true,
            confirmButtonText: 'ลบ',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#dc3545',
            reverseButtons: true,
            focusCancel: true,
        });

        if (! confirmed?.isConfirmed) return;

        try {
            const payload = await sendAction(
                destroyTemplate
                    .replace('__ID__', String(currentLogId))
                    .replace('__ATTACHMENT__', button.dataset.attachmentRemove),
                {method: 'DELETE', doc}
            );

            render(payload?.log?.attachments || []);
            onChange(payload);
        } catch (error) {
            showError(error.message);
        }
    });

    return {
        /** เปิดบล็อกไฟล์แนบสำหรับบันทึกที่มีอยู่แล้ว */
        attachTo(log) {
            currentLogId = log?.id ?? null;
            section.hidden = currentLogId === null;
            showError('');
            form.reset();
            render(log?.attachments || []);
        },
        /** ซ่อนตอนสร้างรายการใหม่ เพราะยังไม่มีบันทึกให้แนบไฟล์เข้าไป */
        detach() {
            currentLogId = null;
            section.hidden = true;
            showError('');
            render([]);
        },
    };
}
