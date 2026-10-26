/**
 * หน้ารายละเอียด Ticket
 *
 * ทุกการดำเนินงานเป็น form จริงที่ server ตรวจสิทธิ์และสถานะเอง ไฟล์นี้เพิ่มเพียง:
 *   1. กันการกดซ้ำของ form การดำเนินงาน ([data-ticket-action-form])
 *   2. ปุ่ม "ติดตามงาน" และ "ยกเลิกงาน" ([data-ticket-confirm-open]) ถาม SweetAlert ก่อน แล้วจึงเปิด modal ของปุ่ม (data-bs-target)
 *      — modal นั้นให้ระบุเหตุผลและส่งฟอร์มจริง
 *   3. เปิด modal เดิมอีกครั้งเมื่อ server ตีกลับด้วย validation error (data-open-on-load)
 *      ยกเว้น modal ที่เป็นขั้นตอน (ปิดงาน — components/close-modal) ซึ่ง tickets/stepper.js เป็นเจ้าของการเปิด/ขั้น/hash
 *
 * ปุ่มที่แสดงมาจาก TicketPolicy ฝั่ง server — ไม่มีการตัดสินสิทธิ์ที่นี่
 * ฟอร์มปิดงาน (มีรูป) ใช้ tickets/form.js ชุดเดียวกับฟอร์มแจ้งปัญหา
 */

import {initTicketStepper} from './stepper.js';

function setBusy(form, busy) {
    form.dataset.submitting = busy ? 'true' : 'false';
    form.querySelectorAll('button[type="submit"]').forEach((button) => {
        button.disabled = busy;
        button.setAttribute('aria-busy', busy ? 'true' : 'false');
    });
}

function handleSubmit(event) {
    const form = event.target.closest('[data-ticket-action-form]');
    if (! form) return;

    if (form.dataset.submitting === 'true') {
        event.preventDefault();

        return;
    }

    setBusy(form, true);
}

/** ถามยืนยันด้วย SweetAlert แล้วเปิด modal ของปุ่ม — ไม่มี SweetAlert ก็เปิดเลย (ห้ามใช้ confirm() ของเบราว์เซอร์) */
function confirmThenOpen(trigger, page, swal, bootstrapApi) {
    const modal = page.querySelector(trigger.dataset.bsTarget);
    const open = () => {
        if (modal && bootstrapApi?.Modal) bootstrapApi.Modal.getOrCreateInstance(modal).show();
    };
    const dialog = swal();
    if (! dialog) {
        open();

        return;
    }

    dialog.fire({
        icon: 'question',
        title: 'ยืนยันการดำเนินการ',
        text: trigger.dataset.ticketConfirm,
        showCancelButton: true,
        confirmButtonText: 'ยืนยัน',
        cancelButtonText: 'ยกเลิก',
        reverseButtons: true,
    }).then((result) => {
        if (result?.isConfirmed) open();
    });
}

function openModalAfterValidationError(page, bootstrapApi) {
    const modal = Array.from(page.querySelectorAll('[data-ticket-modal][data-open-on-load="true"]'))
        .find((candidate) => ! candidate.querySelector('[data-ticket-stepper]'));
    if (! modal || ! bootstrapApi?.Modal) return false;

    bootstrapApi.Modal.getOrCreateInstance(modal).show();

    return true;
}

/**
 * เรียกซ้ำได้โดยไม่ผูก listener ซ้ำ
 *
 * @param {ParentNode} root
 * @param {{bootstrapApi?: any, swal?: () => any}} options
 */
export function initTicketDetail(root = document, {
    bootstrapApi = globalThis.window?.bootstrap,
    swal = () => globalThis.window?.Swal,
} = {}) {
    const page = root.querySelector('[data-ticket-detail]');
    if (! page || page.dataset.ticketDetailReady === 'true') return false;
    page.dataset.ticketDetailReady = 'true';

    page.addEventListener('submit', handleSubmit);

    page.addEventListener('click', (event) => {
        const confirmOpen = event.target.closest('[data-ticket-confirm-open]');
        if (! confirmOpen) return;
        event.preventDefault();
        confirmThenOpen(confirmOpen, page, swal, bootstrapApi);
    });

    // กลับมาด้วยปุ่มย้อนกลับ (bfcache) ปุ่มต้องกดได้อีก
    globalThis.window?.addEventListener('pageshow', (event) => {
        if (! event.persisted) return;
        page.querySelectorAll('[data-ticket-action-form]').forEach((form) => setBusy(form, false));
    });

    openModalAfterValidationError(page, bootstrapApi);
    initTicketStepper(page, {modalApi: bootstrapApi?.Modal});

    return true;
}

if (typeof document !== 'undefined') initTicketDetail(document);
