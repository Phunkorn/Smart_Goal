/**
 * ป้ายเวลากำหนดส่งฝั่งหน้าจอ — คู่กับ App\Support\TodayWorkspace::dueTimeLabel()
 *
 * ทุกมุมมองของ Task Workspace (บอร์ด ตาราง ปฏิทิน โมดัล) วาดป้ายนี้ใหม่เองหลังบันทึก
 * โดยไม่โหลดหน้า ถ้าแต่ละไฟล์เขียนเงื่อนไขของตัวเอง มุมมองหนึ่งจะขึ้น "23:59 น."
 * ขณะที่อีกมุมมองบอกว่า "ไม่ระบุเวลา" สำหรับงานใบเดียวกัน
 *
 * "ไม่ระบุเวลา" ตัดสินจากธงเจตนา (data-due-time-unset) ไม่ใช่จากเวลาที่เก็บไว้
 * เพราะ job_due_at ของงานที่ไม่ระบุเวลายังถือ 23:59 น. ไว้ให้วงจรสถานะล่าช้าใช้
 */

/** ข้อความแทนเวลาที่ผู้ใช้เลือกไม่ระบุ — ต้องตรงกับ TodayWorkspace::UNSET_DUE_TIME_LABEL */
export const UNSET_DUE_TIME_LABEL = 'ไม่ระบุเวลา';

/**
 * ธงเจตนาเดินทางมาหลายรูป: '1' จาก dataset, true จาก JSON ของ server
 * และ 'true' เมื่อถูกเขียนลง dataset ผ่าน String(boolean) — รับทุกรูปที่เกิดขึ้นจริง
 */
export const isDueTimeUnset = (value) => value === true || value === 1 || value === '1' || value === 'true';

/**
 * ข้อความของงานที่ไม่มีวันครบกำหนดเลย — ต้องตรงกับ TodayWorkspace::NO_DUE_LABEL
 *
 * คนละเรื่องกับ UNSET_DUE_TIME_LABEL ซึ่งยังมีวันส่งอยู่ งานที่ไม่มีกำหนดส่งคือ data-due ว่าง
 * (job_due_at เป็น null) ไม่มีธงแยกที่แถว ส่วนช่องกรอกถือ data-due-unset ไว้ให้ปฏิทินอ่าน
 */
export const NO_DUE_LABEL = 'ไม่มีกำหนดส่ง';

/** ป้ายเวลาล้วน เช่น "17:00 น." — ใช้ในคอลัมน์เวลาของบอร์ดและช่องของปฏิทิน */
export const dueClockLabel = (clock, unset, empty = '-') => {
    if (isDueTimeUnset(unset)) return UNSET_DUE_TIME_LABEL;

    return clock ? `${clock} น.` : empty;
};

/** ป้ายเต็มประโยคของเซลล์กำหนดส่งในตาราง ซึ่งอ่านคู่กับช่วงวันบรรทัดบน */
export const dueWithinLabel = (clock, unset, hasDue = true) => {
    // บรรทัดบนบอกแล้วว่า "ไม่มีกำหนดส่ง" บรรทัดเวลาจึงเว้นว่าง ไม่ใช่บอกว่าไม่ระบุเวลา
    if (!hasDue) return '';

    return isDueTimeUnset(unset) || !clock
        ? 'ไม่ระบุเวลากำหนดส่ง'
        : `ส่งภายใน ${clock} น.`;
};
