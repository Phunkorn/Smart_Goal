/*
 * ไอคอนเครื่องมือที่ลอยตามเมาส์บนผืนผ้าใบ
 *
 * ทำไมไม่ใช้ cursor ของ CSS
 * ---------------------------------------------------------------
 * ดินสอกับยางลบเป็นสองเครื่องมือที่ระบบปฏิบัติการไม่มีเคอร์เซอร์ตรงความหมายให้
 * เคยแก้ด้วยการวาดรูปฝังเป็นเคอร์เซอร์ของ CSS แล้วออกมาเพี้ยนทุกครั้งที่ลองบน
 * จอจริง เพราะรูปที่ฝังต้องมีขอบขาวล้อมไว้ไม่ให้จมหายไปกับเส้นสีเข้ม แล้วขอบนั้น
 * ก็ไปล้อมทั้งเส้นนอกและเส้นในของไอคอนจนกลายเป็นรูปซ้อนรูป
 *
 * ตัวนี้แสดง "ไอคอนตัวเดียวกับที่อยู่บนแถบเครื่องมือ" เป็นชิปเล็ก ๆ ลอยข้างเมาส์
 * แทน เรนเดอร์ด้วยฟอนต์ไอคอนตามปกติ จึงออกมาเหมือนบนแถบเป๊ะ ไม่มีทางเพี้ยน และ
 * เคอร์เซอร์กากบาทของระบบยังอยู่ ผู้ใช้จึงยังเล็งจุดที่จะวาดได้แม่นเหมือนเดิม
 *
 * ชิปถูกวางเยื้องลงขวาเล็กน้อยด้วย CSS ไม่ทับจุดที่กำลังเล็งอยู่
 *
 * ไฟล์นี้ไม่รู้จักชื่อไอคอนหรือชื่อเครื่องมือใด ๆ เลย ทั้งสองอย่างถูกส่งเข้ามา
 * จาก WorkspaceDesign ผ่าน JSON island ตามกติกาของหน้านี้
 */

/**
 * @param {HTMLElement} stage ผืนผ้าใบ (ตัวที่รับเหตุการณ์จากตัวชี้)
 * @param {HTMLElement} badge ชิปที่จะเลื่อนตามเมาส์
 * @param {object} options
 *   icons  แผนที่ ชื่อเครื่องมือ -> ชื่อคลาสไอคอนของมัน (มาจาก WorkspaceDesign)
 *   tools  เครื่องมือที่ต้องแสดงชิป เครื่องมืออื่นซ่อนไว้
 */
export const initToolCursor = (stage, badge, {icons = {}, tools = []} = {}) => {
    const icon = badge?.querySelector('[data-workspace-tool-cursor-icon]');

    if (! stage || ! badge || ! icon) {
        return null;
    }

    // เครื่องมือที่ต้องแสดงตอนนี้ (null = ไม่ต้องแสดง) และเมาส์อยู่บนกระดานหรือยัง
    let current = null;
    let inside = false;

    const sync = () => {
        badge.hidden = ! (current && inside);
    };

    const place = (event) => {
        const rect = stage.getBoundingClientRect();

        badge.style.transform = `translate(${event.clientX - rect.left}px, ${event.clientY - rect.top}px)`;
    };

    stage.addEventListener('pointermove', (event) => {
        // นิ้วไม่มีเคอร์เซอร์ให้ตามอยู่แล้ว ชิปที่วิ่งตามนิ้วมีแต่จะบังงาน
        if (event.pointerType === 'touch') {
            inside = false;
            sync();

            return;
        }

        inside = true;
        place(event);
        sync();
    });

    const leave = () => {
        inside = false;
        sync();
    };

    stage.addEventListener('pointerleave', leave);
    stage.addEventListener('pointercancel', leave);

    return {
        /** เรียกทุกครั้งที่เครื่องมือเปลี่ยน (จาก draw() ใน index.js) */
        update(tool) {
            const next = tools.includes(tool) ? tool : null;

            if (next === current) {
                return;
            }

            current = next;

            if (next) {
                icon.setAttribute('class', `bi ${icons[next] ?? ''} wsb-tool-cursor__icon`);
            }

            /*
             * ซ่อนเคอร์เซอร์ของระบบด้วย inline style ไม่ใช่ปล่อยให้เป็นหน้าที่ของ
             * ไฟล์ CSS
             *
             * เรื่องนี้กับการวาดไอคอนเป็นฟีเจอร์เดียวกัน ("อะไรอยู่ใต้เมาส์ตอนถือ
             * ดินสอ") จึงต้องอยู่ที่เดียวกันและเปลี่ยนพร้อมกันเสมอ ที่ผ่านมาแยกไป
             * อยู่คนละไฟล์ ผู้ใช้จึงเจอสถานะที่ไอคอนขึ้นแล้วแต่เคอร์เซอร์ยังอยู่
             * ซึ่งเป็นไปไม่ได้เลยถ้าสองอย่างนี้ถูกตั้งจากบรรทัดเดียวกัน
             *
             * inline style ยังชนะทุกกฎในสไตล์ชีต จึงไม่ต้องไปลุ้นเรื่องลำดับหรือ
             * ความจำเพาะของตัวเลือกอีก และคืนค่าว่างเมื่อเลิกถือเครื่องมือกลุ่มนี้
             * เพื่อให้เคอร์เซอร์ตามเครื่องมือที่ CSS กำหนดไว้กลับมาทำงานตามเดิม
             */
            stage.style.cursor = next ? 'none' : '';

            sync();
        },
    };
};
