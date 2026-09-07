/*
 * หน้าจัดการหมวดงาน (admin)
 *
 * เป็นหน้าตั้งค่าที่ใช้ไม่บ่อย ฟอร์มจึงส่งแบบปกติและโหลดหน้าใหม่ ไม่ทำ AJAX
 * สิ่งเดียวที่ต้องมีคือการยืนยันก่อนลบ ซึ่งต้องผ่าน window.Swal ตามกติกาของ
 * โปรเจกต์ที่ห้ามใช้กล่องยืนยันของเบราว์เซอร์
 *
 * หมวดที่มีบันทึกงานใช้อยู่จะถูกเซิร์ฟเวอร์ปฏิเสธการลบอยู่แล้ว ฝั่งนี้เพียงบอก
 * ล่วงหน้าเพื่อไม่ให้ผู้ใช้เสียเวลากดแล้วเจอ error
 */
export function initCategoryPage({doc = document, swal = globalThis.Swal} = {}) {
    const page = doc.querySelector('[data-category-page]');

    if (! page || page.dataset.categoryReady === 'on') return null;

    page.dataset.categoryReady = 'on';

    page.addEventListener('submit', async (event) => {
        const form = event.target.closest('[data-category-delete]');

        if (! form) return;

        event.preventDefault();

        const usage = Number(form.dataset.usage || 0);

        if (usage > 0) {
            await swal?.fire({
                icon: 'info',
                title: 'ลบหมวดนี้ไม่ได้',
                text: `มีบันทึกงานใช้หมวดนี้อยู่ ${usage} รายการ — ให้ปิดใช้งานแทนเพื่อเก็บประวัติไว้`,
            });

            return;
        }

        const confirmed = await swal?.fire({
            icon: 'warning',
            title: 'ลบหมวดงานนี้?',
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
        document.addEventListener('DOMContentLoaded', () => initCategoryPage());
    } else {
        initCategoryPage();
    }
}
