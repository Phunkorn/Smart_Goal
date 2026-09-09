/*
 * การจัดรูปแบบและคำนวณเวลาของบันทึกงานประจำวัน
 *
 * โมดูลนี้เป็นฟังก์ชันบริสุทธิ์ทั้งหมด ไม่แตะ DOM และไม่อ่านนาฬิกาของระบบเอง
 * (ผู้เรียกส่งเวลาปัจจุบันเข้ามา) เพื่อให้ทดสอบได้แบบกำหนดผลลัพธ์ได้แน่นอน
 *
 * หมายเหตุ: การคำนวณจำนวนนาทีที่ "ถูกบันทึกจริง" เป็นหน้าที่ของเซิร์ฟเวอร์เสมอ
 * สิ่งที่คำนวณที่นี่มีไว้แสดงผลระหว่างที่ตัวจับเวลากำลังเดินเท่านั้น
 */

/** แปลงนาทีเป็นข้อความไทย ให้ตรงกับ WorkLogDesign::durationLabel() ฝั่ง PHP */
export const durationLabel = (minutes) => {
    if (minutes === null || minutes === undefined || Number.isNaN(Number(minutes))) {
        return 'ไม่ระบุเวลา';
    }

    const total = Math.trunc(Number(minutes));

    if (total <= 0) return '0 น.';

    const hours = Math.floor(total / 60);
    const rest = total % 60;

    if (hours === 0) return `${rest} น.`;
    if (rest === 0) return `${hours} ชม.`;

    return `${hours} ชม. ${rest} น.`;
};

/** "09:30" → {hours: 9, minutes: 30} คืน null เมื่อรูปแบบไม่ถูกต้อง */
export const parseClock = (value) => {
    const match = /^([01]\d|2[0-3]):([0-5]\d)$/.exec(String(value ?? '').trim());

    if (! match) return null;

    return {hours: Number(match[1]), minutes: Number(match[2])};
};

/**
 * จำนวนนาทีระหว่างเวลานาฬิกาสองค่าในหนึ่งวันทำงาน
 *
 * งานข้ามคืน (22:30 ถึง 01:15) ถูกตีความว่าเวลาสิ้นสุดอยู่วันถัดไป ไม่ใช่ค่าติดลบ
 * เพราะเป็นกรณีที่เกิดขึ้นจริงกับงานเวรและงานนอกสถานที่
 */
export const minutesBetween = (start, end) => {
    const from = parseClock(start);
    const to = parseClock(end);

    if (! from || ! to) return null;

    const fromMinutes = (from.hours * 60) + from.minutes;
    const toMinutes = (to.hours * 60) + to.minutes;
    const diff = toMinutes - fromMinutes;

    return diff > 0 ? diff : diff + (24 * 60);
};
