/*
 * ช่วงเวลาแบบเลือกพรีเซ็ต หรือ "กำหนดช่วงวันที่เอง" — ใช้ร่วมกันระหว่างหัวรายงานโปรเจกต์
 * และตัวกรองหน้าประชุม (reports/project-period.js เป็นแค่ทางเข้าเดิมที่ยัง re-export จากที่นี่)
 *
 * ดร็อปดาวน์ช่วงเวลาส่งฟอร์มทันทีเหมือนช่องอื่นในหัวตัวกรอง (auto-submit-filter.js)
 * ยกเว้นตัวเลือก "กำหนดช่วงวันที่เอง" ซึ่งติด data-auto-submit-skip ไว้ เลือกแล้วจึงแค่เปิด
 * ช่องวันเริ่ม/วันสิ้นสุดให้กรอก
 *
 * กติกาที่สำคัญที่สุดของไฟล์นี้: ช่องวันที่ต้องถูก disable ทุกครั้งที่ส่งฟอร์มโดยไม่ได้เลือก
 * ช่วงกำหนดเอง server เลือกช่วงกำหนดเองก่อนพรีเซ็ตเสมอ ถ้าปล่อยให้ from/to ติดไปกับการเปลี่ยน
 * พรีเซ็ตหรือเปลี่ยนคน รายการจะค้างอยู่ที่ช่วงเดิมทั้งที่ผู้ใช้เปลี่ยนพรีเซ็ตแล้ว จึงตรวจตอน submit
 * (ก่อนเบราว์เซอร์เก็บค่าในฟอร์ม) ไม่ขึ้นกับว่า listener ตัวไหนทำงานก่อน
 *
 * ฟอร์มที่ติด data-period-auto-submit ไว้ด้วย (เช่นตัวกรองหน้าประชุม) จะส่งฟอร์มเองทันทีที่
 * ช่องวันเริ่มและวันสิ้นสุดถูกกรอกครบทั้งคู่ ไม่ต้องรอปุ่ม "แสดงผล" — รายงานโปรเจกต์ไม่ติด
 * attribute นี้ จึงยังทำงานแบบเดิม (กรอกแล้วกดปุ่มเอง)
 *
 * ผูกได้ครั้งเดียวต่อฟอร์ม (data-period-ready) เรียกซ้ำจึงไม่ซ้อน listener
 */
export const CUSTOM_PERIOD = 'custom';

function submitForm(form) {
    if (typeof form.requestSubmit === 'function') form.requestSubmit();
    else form.submit();
}

/**
 * @param {ParentNode} root
 * @param {{formatDate?: (value: string) => string}} options  ตัวแปลงค่า Y-m-d เป็นป้ายวันที่ของระบบ
 */
export function initPeriodRange(root = document, {formatDate} = {}) {
    root.querySelectorAll('select[data-period-select]').forEach((select) => {
        const form = select.form;
        if (! form || form.dataset.periodReady === '1') return;
        form.dataset.periodReady = '1';

        const range = form.querySelector('[data-period-range]');
        const inputs = [...form.querySelectorAll('[data-period-input]')];
        const autoSubmitWhenComplete = form.hasAttribute('data-period-auto-submit');

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

        // "กำหนดช่วงวันที่เอง" ไม่มี change เดียวที่บอกว่า "กรอกครบแล้ว" เหมือน select ช่องอื่น
        // จึงต้องเช็กทุกครั้งที่ช่องวันที่เปลี่ยนว่าครบทั้งคู่หรือยังก่อนค่อยส่งฟอร์มเอง
        if (autoSubmitWhenComplete && inputs.length) {
            inputs.forEach((input) => {
                input.addEventListener('change', () => {
                    if (select.value !== CUSTOM_PERIOD) return;
                    if (inputs.every((candidate) => candidate.value !== '')) submitForm(form);
                });
            });
        }

        sync();
    });
}
