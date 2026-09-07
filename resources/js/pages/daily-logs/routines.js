/*
 * หน้าจัดการแม่แบบงานประจำ
 *
 * เป็นหน้าที่ใช้ไม่บ่อย (ตั้งครั้งเดียวแล้วจบ) จึงไม่ทำ AJAX ให้ซับซ้อน
 * ฟอร์มสร้างส่งแบบปกติและโหลดหน้าใหม่ ส่วนการลบต้องยืนยันผ่าน Swal
 * ตามกติกาของโปรเจกต์ที่ห้ามใช้ confirm() ของเบราว์เซอร์
 */
export function initRoutinePage({doc = document, swal = globalThis.Swal} = {}) {
    const page = doc.querySelector('[data-routine-page]');

    if (! page || page.dataset.routineReady === 'on') return null;

    page.dataset.routineReady = 'on';

    // delegation ที่ระดับหน้า เพื่อให้การ์ดที่เพิ่มเข้ามาภายหลังใช้งานได้เช่นกัน
    page.addEventListener('submit', async (event) => {
        const form = event.target.closest('[data-routine-delete]');

        if (! form) return;

        event.preventDefault();

        const title = form.closest('[data-routine-card]')
            ?.querySelector('.routine-card__title')?.textContent?.trim() || '';

        const confirmed = await swal?.fire({
            icon: 'warning',
            title: 'ลบงานประจำนี้?',
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

    return {page};
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => initRoutinePage());
    } else {
        initRoutinePage();
    }
}
