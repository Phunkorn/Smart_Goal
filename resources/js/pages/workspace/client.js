/*
 * การคุยกับเซิร์ฟเวอร์ของหน้าวาด
 *
 * มีสอง endpoint เท่านั้น คือ "อ่านเนื้อหาฉบับล่าสุด" กับ "บันทึกทับ" ทั้งคู่
 * ตอบด้วยรูปแบบเดียวกับทั้งระบบผ่าน jsonOrBack() คือ {ok, message, ...payload}
 *
 * การชนเวอร์ชัน (409) ถูกแปลงเป็น Error ที่มีธง isVersionConflict และพก
 * เนื้อหาฉบับล่าสุดมาด้วย ผู้เรียกจึงกู้คืนได้ในคำขอเดียวโดยไม่ต้องยิง GET
 * ตามอีกรอบขณะที่ผู้ใช้กำลังรออยู่หน้ากล่องยืนยัน
 */

const csrfToken = (doc) => doc.querySelector('meta[name="csrf-token"]')?.content || '';

/** ข้อผิดพลาดจากการชนเวอร์ชัน แยกจาก error ทั่วไปเพราะต้องจัดการคนละแบบ */
export class VersionConflictError extends Error {
    constructor(payload) {
        super(payload?.message || 'มีคนอื่นบันทึกกระดานนี้ไปแล้ว');
        this.name = 'VersionConflictError';
        this.isVersionConflict = true;
        this.payload = payload || {};
    }
}

export const createClient = ({routes, fetchImpl = globalThis.fetch, doc = globalThis.document}) => ({
    /** เนื้อหาฉบับล่าสุดบนเซิร์ฟเวอร์ (ปุ่มรีเฟรช) */
    async load() {
        const response = await fetchImpl(routes.document, {
            headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
        });

        const payload = await response.json().catch(() => ({}));

        if (! response.ok) {
            throw new Error(payload?.message || 'โหลดกระดานไม่สำเร็จ');
        }

        return payload;
    },

    /**
     * บันทึกเนื้อหาทับของเดิม
     *
     * ส่งเป็น JSON ไม่ใช่ FormData เพราะเนื้อหาเป็นโครงสร้างซ้อนชั้น การแปลง
     * เป็น FormData จะทำให้ต้องเข้ารหัสเป็นสตริงแล้วถอดกลับที่ฝั่งเซิร์ฟเวอร์อยู่ดี
     */
    async save({document: payloadDocument, baseVersion}) {
        const response = await fetchImpl(routes.save, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken(doc),
            },
            // PUT ถูกส่งเป็น POST พร้อม _method ตามที่ Laravel รองรับ และตรงกับ
            // วิธีที่ส่วนอื่นของระบบทำ
            body: JSON.stringify({
                _method: 'PUT',
                base_version: baseVersion,
                document: payloadDocument,
            }),
        });

        const payload = await response.json().catch(() => ({}));

        if (response.status === 409) {
            throw new VersionConflictError(payload);
        }

        if (! response.ok) {
            throw new Error(firstErrorMessage(payload) || 'บันทึกไม่สำเร็จ');
        }

        return payload;
    },
});

const firstErrorMessage = (payload) => {
    if (typeof payload?.message === 'string' && payload.message !== '') {
        return payload.message;
    }

    const first = Object.values(payload?.errors || {}).flat()[0];

    return typeof first === 'string' ? first : '';
};
