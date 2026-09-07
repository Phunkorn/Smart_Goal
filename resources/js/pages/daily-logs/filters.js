/*
 * สถานะของตัวกรองประเภทงานในไทม์ไลน์
 *
 * เป็นฟังก์ชันบริสุทธิ์ทั้งหมด การตัดสินใจว่า "แถวไหนควรแสดง" จึงทดสอบได้โดย
 * ไม่ต้องมี DOM ส่วนการซ่อน/แสดงจริงเป็นหน้าที่ของ timeline.js
 *
 * ค่าที่ยอมรับได้ต้องตรงกับคีย์ใน App\Support\WorkLogDesign::KINDS ฝั่งเซิร์ฟเวอร์
 * ซึ่งเป็นผู้ถือกติกาจริง รายการที่นี่มีไว้กันค่าที่พิมพ์ผิดเท่านั้น
 */
export const ALL_KINDS = 'all';

export const normalizeKind = (kind, allowed = []) => {
    const value = String(kind ?? '').trim();

    if (value === '' || value === ALL_KINDS) return ALL_KINDS;

    return allowed.includes(value) ? value : ALL_KINDS;
};

/**
 * แถวนี้ควรแสดงภายใต้ตัวกรองปัจจุบันหรือไม่
 *
 * ตั้งใจไม่เรียก normalizeKind() ที่นี่ เพราะรายการที่อนุญาตของ normalizeKind
 * คือ "ประเภทที่ระบบรู้จัก" ไม่ใช่ "ประเภทของแถวที่กำลังเทียบ" การส่งประเภท
 * ของแถวเดียวเข้าไปเป็นรายการอนุญาต จะทำให้ตัวกรองที่ไม่ตรงถูกปัดเป็น "ทั้งหมด"
 * แล้วแสดงทุกแถวออกมา
 */
export const matchesFilter = (activeKind, rowKind) => {
    const active = String(activeKind ?? '').trim();

    if (active === '' || active === ALL_KINDS) return true;

    return active === String(rowKind ?? '');
};

/**
 * นับจำนวนแถวที่จะเหลืออยู่หลังกรอง
 * ใช้ตัดสินว่าต้องแสดงข้อความ "ไม่มีรายการตรงกับตัวกรอง" หรือไม่
 */
export const visibleCount = (activeKind, rowKinds = []) => rowKinds
    .filter((kind) => matchesFilter(activeKind, kind))
    .length;
