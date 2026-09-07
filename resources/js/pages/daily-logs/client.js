/*
 * การคุยกับเซิร์ฟเวอร์ของหน้าบันทึกงานประจำวัน
 *
 * ทุก endpoint ของฟีเจอร์นี้ตอบกลับด้วยรูปแบบเดียวกันผ่าน jsonOrBack()
 * คือ {ok, message, ...payload} จึงรวมการอ่านผลลัพธ์ไว้ที่นี่ที่เดียว
 * แทนที่จะให้แต่ละโมดูลตีความ response เอง
 */
const csrfToken = (doc) => doc.querySelector('meta[name="csrf-token"]')?.content || '';

/**
 * ส่งฟอร์มแบบ AJAX และคืน payload ที่เซิร์ฟเวอร์ส่งกลับมา
 *
 * ข้อผิดพลาดจาก validation (422) ถูกแปลงเป็น Error ที่มีข้อความภาษาไทยจาก
 * เซิร์ฟเวอร์ เพื่อให้ผู้เรียกนำไปแสดงได้ตรง ๆ โดยไม่ต้องแต่งข้อความเอง
 */
export const submitLogForm = async (form, {fetchImpl = globalThis.fetch, doc = form.ownerDocument} = {}) => {
    const method = form.querySelector('[data-entry-method]')?.value || form.method || 'POST';
    const body = new (doc.defaultView || globalThis).FormData(form);

    // PATCH/DELETE ถูกส่งเป็น POST พร้อม _method ตามที่ Laravel รองรับ
    // เพราะ FormData กับ method override ทำงานร่วมกันได้ดีกว่าการตั้ง method จริง
    if (method.toUpperCase() !== 'POST') {
        body.set('_method', method.toUpperCase());
    }

    const response = await fetchImpl(form.action, {
        method: 'POST',
        body,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(doc),
        },
    });

    const payload = await response.json().catch(() => ({}));

    if (! response.ok) {
        throw new Error(firstErrorMessage(payload) || 'บันทึกไม่สำเร็จ กรุณาลองใหม่');
    }

    return payload;
};

/** ส่งคำสั่งที่ไม่มีฟอร์ม เช่น ลบรายการ หรือเริ่ม/หยุดตัวจับเวลา */
export const sendAction = async (url, {method = 'POST', fields = {}, repeated = {}, fetchImpl = globalThis.fetch, doc = globalThis.document} = {}) => {
    const body = new (doc.defaultView || globalThis).FormData();

    Object.entries(fields).forEach(([key, value]) => body.set(key, value));

    // ค่าที่เป็นรายการ เช่น participants[] ต้อง append ทีละตัว ไม่ใช่ set ทับกัน
    Object.entries(repeated).forEach(([key, values]) => {
        (Array.isArray(values) ? values : []).forEach((value) => body.append(key, value));
    });

    if (method.toUpperCase() !== 'POST') {
        body.set('_method', method.toUpperCase());
    }

    const response = await fetchImpl(url, {
        method: 'POST',
        body,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(doc),
        },
    });

    const payload = await response.json().catch(() => ({}));

    if (! response.ok) {
        throw new Error(firstErrorMessage(payload) || 'ดำเนินการไม่สำเร็จ กรุณาลองใหม่');
    }

    return payload;
};

/**
 * ข้อความผิดพลาดแรกที่อ่านรู้เรื่อง
 *
 * Laravel ตอบ validation error เป็น {message, errors:{field:[...]}} ส่วน
 * jsonOrBack() ตอบ {ok:false, message} ทั้งสองแบบต้องอ่านได้จากที่เดียว
 */
export const firstErrorMessage = (payload) => {
    if (! payload || typeof payload !== 'object') return '';

    const errors = payload.errors;

    if (errors && typeof errors === 'object') {
        const first = Object.values(errors)[0];

        if (Array.isArray(first) && first.length > 0) return String(first[0]);
        if (typeof first === 'string') return first;
    }

    return typeof payload.message === 'string' ? payload.message : '';
};
