/*
 * ฟอร์มตัวกรองแบบ GET ที่ส่งทันทีเมื่อเปลี่ยนค่า — ใช้ร่วมกันทุกหน้ารายงาน
 *
 * <select data-auto-submit> ภายในฟอร์มที่ตรงกับ selector ส่งฟอร์มเมื่อ change
 * ดร็อปดาวน์ของระบบ (select-dropdown.js) ยิง change บน <select> เดิม จึงทำงานร่วมกันได้ทันที
 * ปุ่ม "แสดงผล" ใน <noscript> ยังอยู่ให้ใช้เมื่อ JavaScript ไม่ทำงาน
 *
 * ตัวเลือกที่ต้องกรอกต่อก่อนส่ง (เช่น "กำหนดช่วงวันที่เอง") ติด data-auto-submit-skip ไว้ เลือกแล้วจึงไม่ส่ง
 *
 * ผูกได้ครั้งเดียวต่อฟอร์ม (ตรวจด้วย data-auto-submit-ready) เรียกซ้ำจึงไม่ซ้อน listener
 */
export function initAutoSubmitFilters(root, formSelector = '[data-auto-submit-form]') {
    root.querySelectorAll(formSelector).forEach((form) => {
        if (form.dataset.autoSubmitReady === '1') return;
        form.dataset.autoSubmitReady = '1';

        form.querySelectorAll('select[data-auto-submit]').forEach((select) => {
            select.addEventListener('change', () => {
                if (select.selectedOptions[0]?.hasAttribute('data-auto-submit-skip')) return;

                if (typeof form.requestSubmit === 'function') form.requestSubmit();
                else form.submit();
            });
        });
    });
}
