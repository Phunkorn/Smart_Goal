/*
 * ประวัติการแก้ไขสำหรับ undo/redo
 *
 * เก็บเป็นภาพนิ่งของทั้งฉากในแต่ละก้าว ไม่ใช่รายการคำสั่งย้อนกลับ เพราะฉากเป็น
 * โครงสร้างที่ไม่เปลี่ยนแปลงในที่อยู่แล้ว ภาพนิ่งจึงเป็นเพียงการอ้างถึงอ็อบเจ็กต์
 * ไม่ได้คัดลอกข้อมูลจริง และการย้อนกลับไม่มีทางคำนวณผิดเหมือนคำสั่งผกผัน
 *
 * มีเพดานจำนวนก้าว เพราะกระดานที่มีคนวาดทั้งวันจะสะสมภาพนิ่งจนกินหน่วยความจำ
 */

export const createHistory = (initial, {limit = 50} = {}) => ({
    entries: [initial],
    index: 0,
    limit,
});

export const canUndo = (history) => history.index > 0;

export const canRedo = (history) => history.index < history.entries.length - 1;

export const current = (history) => history.entries[history.index];

/**
 * บันทึกสถานะใหม่เป็นก้าวถัดไป
 *
 * ทุกครั้งที่มีการแก้หลังจากย้อนกลับ ก้าวที่อยู่ข้างหน้าจะถูกตัดทิ้ง ซึ่งเป็น
 * พฤติกรรมมาตรฐานของ undo/redo ที่ผู้ใช้คุ้นเคยจากโปรแกรมอื่น
 */
export const push = (history, state) => {
    // ไม่บันทึกซ้ำเมื่อสถานะเป็นตัวเดิม เช่นคลิกเปล่าบนพื้นที่ว่างแล้วปล่อย
    // ไม่อย่างนั้นผู้ใช้จะต้องกด undo หลายครั้งกว่าจะเห็นอะไรเปลี่ยน
    if (current(history) === state) {
        return history;
    }

    const entries = [...history.entries.slice(0, history.index + 1), state];

    // ตัดก้าวเก่าสุดออกเมื่อเกินเพดาน แล้วเลื่อนตัวชี้ตามไปด้วย
    const trimmed = entries.length > history.limit
        ? entries.slice(entries.length - history.limit)
        : entries;

    return {...history, entries: trimmed, index: trimmed.length - 1};
};

export const undo = (history) =>
    (canUndo(history) ? {...history, index: history.index - 1} : history);

export const redo = (history) =>
    (canRedo(history) ? {...history, index: history.index + 1} : history);

/**
 * เริ่มประวัติใหม่จากสถานะที่กำหนด
 *
 * ใช้หลังโหลดเนื้อหาฉบับล่าสุดจากเซิร์ฟเวอร์เมื่อเกิดการชนเวอร์ชัน ประวัติเดิม
 * อ้างถึงฉากที่ไม่มีอยู่บนเซิร์ฟเวอร์แล้ว การกด undo ต่อจะพาผู้ใช้กลับไปหา
 * สถานะที่บันทึกไม่ได้อีกต่อไป
 */
export const reset = (history, state) => createHistory(state, {limit: history.limit});
