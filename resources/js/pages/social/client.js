/*
 * การคุยกับเซิร์ฟเวอร์ของหน้าชุมชน — ที่เดียว
 *
 * ทุก endpoint ตอบรูปแบบเดียวกัน {ok, message, ...payload} (jsonOrBack ของ server)
 * ข้อผิดพลาดทุกชนิดถูกแปลงเป็น Error ที่มีข้อความภาษาไทยพร้อมแสดงให้ผู้ใช้
 * PATCH/PUT/DELETE ส่งเป็น POST + _method เพื่อให้ FormData (ไฟล์) ใช้ได้ทุก method
 */

const NETWORK_ERROR = 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ กรุณาตรวจสอบอินเทอร์เน็ตแล้วลองใหม่';
const GENERIC_ERROR = 'เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง';

export const csrfToken = (doc) => doc?.querySelector('meta[name="csrf-token"]')?.content || '';

/** ข้อความแรกจาก validation error ของ Laravel ({errors: {field: [msg]}}) */
export function firstErrorMessage(payload) {
    const errors = payload && typeof payload === 'object' ? payload.errors : null;

    if (errors && typeof errors === 'object') {
        for (const messages of Object.values(errors)) {
            const message = Array.isArray(messages) ? messages[0] : messages;
            if (typeof message === 'string' && message !== '') return message;
        }
    }

    return typeof payload?.message === 'string' && payload.message !== '' ? payload.message : null;
}

/** ข้อความที่ผู้ใช้เห็นตามสถานะของ response — ข้อความจาก server มาก่อนเสมอเมื่อมี */
export function errorMessageFor(status, payload = {}) {
    switch (status) {
        case 403:
            return 'คุณไม่มีสิทธิ์ทำรายการนี้';
        case 404:
            return 'ไม่พบรายการนี้ อาจถูกลบไปแล้ว';
        case 422:
            return firstErrorMessage(payload) || 'ข้อมูลไม่ถูกต้อง กรุณาตรวจสอบอีกครั้ง';
        case 429:
            return payload?.message || 'ทำรายการถี่เกินไป กรุณารอสักครู่แล้วลองใหม่';
        default:
            return payload?.message || GENERIC_ERROR;
    }
}

export class RequestError extends Error {
    constructor(message, status = 0, payload = {}) {
        super(message);
        this.name = 'RequestError';
        this.status = status;
        this.payload = payload;
    }
}

export async function request(url, {method = 'GET', body = null, fetchImpl = globalThis.fetch, doc = globalThis.document, signal} = {}) {
    const verb = method.toUpperCase();
    const headers = {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'};
    const init = {method: verb === 'GET' ? 'GET' : 'POST', headers, credentials: 'same-origin', signal};

    if (verb !== 'GET') {
        const FormDataImpl = doc?.defaultView?.FormData || globalThis.FormData;
        const form = body instanceof FormDataImpl ? body : new FormDataImpl();

        if (verb !== 'POST') form.set('_method', verb);
        headers['X-CSRF-TOKEN'] = csrfToken(doc);
        init.body = form;
    }

    let response;
    try {
        response = await fetchImpl(url, init);
    } catch (error) {
        if (error?.name === 'AbortError') throw error;
        throw new RequestError(NETWORK_ERROR);
    }

    const payload = await response.json().catch(() => ({}));

    if (! response.ok || payload?.ok === false) {
        throw new RequestError(errorMessageFor(response.status, payload), response.status, payload);
    }

    return payload;
}

/** แปลง HTML จาก server (เรนเดอร์ผ่าน Blade ที่ escape แล้ว) เป็น element */
export function htmlToElements(doc, html) {
    const template = doc.createElement('template');
    template.innerHTML = String(html || '').trim();

    return [...template.content.children];
}

export function formatCount(value) {
    return new Intl.NumberFormat('en-US').format(Math.max(0, Number(value) || 0));
}

/** ข้อความแจ้งผลสั้น ๆ มุมจอ — SweetAlert2 ชุดเดียวกับทั้งระบบ */
export function toast(swal, icon, title) {
    swal()?.fire?.({toast: true, position: 'top-end', icon, title, showConfirmButton: false, timer: 2600, timerProgressBar: true});
}

export function alertError(swal, message) {
    swal()?.fire?.({icon: 'error', title: 'ไม่สำเร็จ', text: message, confirmButtonText: 'ตกลง'});
}

export async function confirmDanger(swal, {title, text, confirmButtonText = 'ยืนยันลบ'}) {
    const result = await swal()?.fire?.({
        icon: 'warning',
        title,
        text,
        showCancelButton: true,
        confirmButtonText,
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#dc2626',
        reverseButtons: true,
        focusCancel: true,
    });

    return Boolean(result?.isConfirmed);
}

/** สถานะกำลังทำงานของปุ่ม — กันกดซ้ำและบอกผู้ใช้ว่าระบบกำลังทำงาน */
export function setBusy(button, busy, busyLabel = null) {
    if (! button) return;

    button.disabled = busy;
    button.setAttribute('aria-busy', busy ? 'true' : 'false');
    button.querySelector('[data-social-spinner]')?.toggleAttribute('hidden', ! busy);

    const label = button.querySelector('[data-social-post-submit-label], [data-social-load-more-label]');
    if (label && busyLabel !== null) {
        if (busy) {
            label.dataset.idleLabel ??= label.textContent;
            label.textContent = busyLabel;
        } else if (label.dataset.idleLabel) {
            label.textContent = label.dataset.idleLabel;
            delete label.dataset.idleLabel;
        }
    }
}
