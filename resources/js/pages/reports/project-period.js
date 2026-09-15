/*
 * ช่วงเวลาของรายงานโปรเจกต์ — เลือกเดือน หรือ "กำหนดช่วงวันที่เอง"
 *
 * ดร็อปดาวน์ช่วงเวลาส่งฟอร์มทันทีเหมือนช่องอื่นในหัวรายงาน (auto-submit-filter.js)
 * ยกเว้นตัวเลือก "กำหนดช่วงวันที่เอง" ซึ่งติด data-auto-submit-skip ไว้ เลือกแล้วจึงแค่เปิด
 * ช่องวันเริ่ม/วันสิ้นสุดให้กรอก แล้วรอปุ่ม "แสดงผล"
 *
 * กติกาที่สำคัญที่สุดของไฟล์นี้: ช่องวันที่ต้องถูก disable ทุกครั้งที่ส่งฟอร์มโดยไม่ได้เลือก
 * ช่วงกำหนดเอง server เลือกช่วงกำหนดเองก่อนเดือนเสมอ (ReportPeriod::fromRequest) ถ้าปล่อยให้
 * from/to ติดไปกับการเปลี่ยนเดือนหรือเปลี่ยนคน รายงานจะค้างอยู่ที่ช่วงเดิมทั้งที่ผู้ใช้เลือกเดือนแล้ว
 * จึงตรวจตอน submit (ก่อนเบราว์เซอร์เก็บค่าในฟอร์ม) ไม่ขึ้นกับว่า listener ตัวไหนทำงานก่อน
 *
 * ผูกได้ครั้งเดียวต่อฟอร์ม (data-period-ready) เรียกซ้ำจึงไม่ซ้อน listener
 */
export const CUSTOM_PERIOD = 'custom';

/**
 * @param {ParentNode} root
 * @param {{formatDate?: (value: string) => string}} options  ตัวแปลงค่า Y-m-d เป็นป้ายวันที่ของระบบ
 */
export function initProjectPeriod(root = document, {formatDate} = {}) {
    root.querySelectorAll('select[data-period-select]').forEach((select) => {
        const form = select.form;
        if (! form || form.dataset.periodReady === '1') return;
        form.dataset.periodReady = '1';

        const range = form.querySelector('[data-period-range]');
        const inputs = [...form.querySelectorAll('[data-period-input]')];

        const sync = () => {
            const custom = select.value === CUSTOM_PERIOD;

            if (range) range.hidden = ! custom;
            inputs.forEach((input) => { input.disabled = ! custom; });

            return custom;
        };

        select.addEventListener('change', sync);
        form.addEventListener('submit', sync);

        if (typeof formatDate === 'function') {
            inputs.forEach((input) => {
                const label = form.querySelector(`[data-period-label="${input.dataset.periodInput}"]`);
                const refresh = () => { if (label) label.textContent = formatDate(input.value); };

                input.addEventListener('change', refresh);
                input.addEventListener('input', refresh);
            });
        }

        sync();
    });
}
