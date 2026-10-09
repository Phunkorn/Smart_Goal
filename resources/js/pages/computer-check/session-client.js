/*
 * การคุยกับเซิร์ฟเวอร์ของหน้าตรวจเช็กคอมพิวเตอร์
 *
 * ทุก endpoint ของฟีเจอร์นี้ตอบกลับด้วยรูปแบบเดียวกันผ่าน jsonOrBack()
 * คือ {ok, message, ...payload} จึงรวมการอ่านผลลัพธ์ไว้ที่นี่ที่เดียว
 * แทนที่จะให้แต่ละโมดูลตีความ response เอง (รูปแบบเดียวกับ daily-logs/client.js)
 */

const csrfToken = (doc) => doc.querySelector('meta[name="csrf-token"]')?.content || '';

/**
 * ส่งคำสั่งไปยังเซิร์ฟเวอร์แล้วคืน payload ที่ตอบกลับมา
 *
 * PATCH/DELETE ถูกส่งเป็น POST พร้อม _method ตามที่ Laravel รองรับ เพราะ
 * FormData กับ method override ทำงานร่วมกันได้ดีกว่าการตั้ง method จริง
 *
 * @param {object} options.form      ส่งทั้งฟอร์ม (ใช้ตอนเปิดรอบ)
 * @param {object} options.fields    ค่าเดี่ยว
 * @param {object} options.repeated  ค่าที่เป็นรายการ เช่น workstations[]
 */
export const sendForm = async (url, {
    form = null,
    method = 'POST',
    fields = {},
    repeated = {},
    fetchImpl = globalThis.fetch,
    doc = globalThis.document,
} = {}) => {
    const view = doc.defaultView || globalThis;
    const body = form ? new view.FormData(form) : new view.FormData();

    Object.entries(fields).forEach(([key, value]) => body.set(key, value));

    // ค่าที่เป็นรายการต้อง append ทีละตัว ไม่ใช่ set ทับกัน
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
        const error = new Error(firstErrorMessage(payload) || 'ดำเนินการไม่สำเร็จ กรุณาลองใหม่');
        error.status = response.status;
        error.payload = payload;

        throw error;
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
