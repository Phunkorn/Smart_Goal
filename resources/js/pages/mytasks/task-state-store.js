/**
 * แหล่งข้อมูล "สถานะและสิทธิ์ของงาน" ร่วมของทุกมุมมองใน Task Workspace
 *
 * เดิมห้าโมดูล (บอร์ด, การ์ด kanban, แถวตาราง, โมดัลรายละเอียดงาน, ไทม์ไลน์) ต่างคน
 * ต่าง JSON.parse โหนด [data-task-management-data] เป็นของตัวเอง ผลคือเมื่อเปิดงาน
 * อีกครั้งจากโมดัล สำเนาของบอร์ดและ kanban ยังถือ capabilities ชุดเก่าที่ is_final เป็น true
 * แถวนั้นจึงยังซ่อนตัวเลือกสถานะปกติและโชว์ปุ่ม "เปิดงานอีกครั้ง" ค้างไว้จนผู้ใช้กด Ctrl+F5
 *
 * ปัญหาเดียวกันเคยเกิดกับไฟล์แนบและถูกแก้ด้วยวิธีนี้มาแล้ว (ดู attachment-store.js)
 * ตอนนี้ทุกโมดูลอ้างอิงอ็อบเจกต์เดียวกัน และเขียนผ่าน publishTaskState() ที่เดียว
 */

// ผูกกับ document ไม่ใช่ตัวแปรระดับโมดูล หน้าจริงมี document เดียวผลจึงเหมือนกัน
// แต่ทำให้เทสต์แต่ละเคสที่สร้าง DOM ของตัวเองไม่ใช้ข้อมูลค้างจากเคสก่อน
const stores = new WeakMap();

/** อ็อบเจกต์เดียวที่ทุกโมดูลอ้างอิงร่วมกัน อย่าแทนที่ ให้แก้ในที่ */
export function taskStateStore(documentRef = globalThis.document) {
    if (!documentRef) return {};
    if (stores.has(documentRef)) return stores.get(documentRef);

    const node = documentRef.querySelector('[data-task-management-data]');
    let parsed;
    try {
        parsed = node ? JSON.parse(node.textContent || '{}') : {};
    } catch {
        parsed = {};
    }

    stores.set(documentRef, parsed);

    return parsed;
}

/**
 * capabilities ที่ server ประกาศไว้สำหรับงานใบนั้น
 *
 * คืนอ็อบเจกต์ว่างเมื่อไม่รู้จักงานใบนี้ ผู้เรียกทุกรายอ่านค่าแบบ optional อยู่แล้ว
 * จึงตกไปเป็น "ทำอะไรไม่ได้" ซึ่งปลอดภัยกว่าการเดาว่าทำได้
 */
export function taskCapabilities(taskId, documentRef = globalThis.document) {
    return taskStateStore(documentRef)[String(taskId)]?.transitions || {};
}
