/**
 * แหล่งข้อมูลไฟล์แนบร่วมของทุกมุมมองใน Task Workspace
 *
 * เดิมสามโมดูล (บอร์ด, โมดัลรายละเอียดงาน, ปฏิทิน) ต่างคน ต่าง JSON.parse
 * โหนด [data-attachment-data] เป็นของตัวเอง ผลคือเมื่อแนบหรือลบไฟล์จากโมดัล
 * สำเนาของอีกสองโมดูลยังเป็นค่าเดิม ปุ่มคลิปหนีบบนการ์ดบอร์ดจึงไม่ขยับ
 * จนกว่าจะรีโหลดหน้า ก่อนหน้านี้ปัญหานี้ถูกกลบด้วย location.reload() หลังอัปโหลด
 * ซึ่งทำให้โมดัลปิดหายไปเอง
 *
 * ตอนนี้ทุกโมดูลใช้อ็อบเจกต์เดียวกัน และเรียก publishTaskFiles() เมื่อรายการไฟล์เปลี่ยน
 */

// ผูกกับ document ไม่ใช่ตัวแปรระดับโมดูล หน้าจริงมี document เดียวผลจึงเหมือนกัน
// แต่ทำให้เทสต์แต่ละเคสที่สร้าง DOM ของตัวเองไม่ใช้ข้อมูลค้างจากเคสก่อน
const stores = new WeakMap();

/** อ็อบเจกต์เดียวที่ทุกโมดูลอ้างอิงร่วมกัน อย่าแทนที่ ให้แก้ในที่ */
export function attachmentStore(documentRef = globalThis.document) {
    if (!documentRef) return {};
    if (stores.has(documentRef)) return stores.get(documentRef);

    const node = documentRef.querySelector('[data-attachment-data]');
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
 * ปุ่มคลิปหนีบมีสามที่ (ตาราง, การ์ดบอร์ด, การ์ด kanban) และเขียนตัวเลขคนละแท็กกัน
 * จึงอัปเดตจากตัว attribute ที่ทุกที่ใช้ร่วมกันแทนที่จะไล่ตาม class ของแต่ละหน้า
 */
export function paintAttachmentCounters(taskId, count, documentRef = globalThis.document) {
    const id = String(taskId);
    const selector = `[data-open-attachments='${id}'], [data-board-open-attachments='${id}']`;

    documentRef?.querySelectorAll?.(selector).forEach((button) => {
        const counter = button.querySelector('b, strong, small');
        if (counter) counter.textContent = button.classList.contains('board-attachments') && count === 0 ? '-' : String(count);
        button.classList.toggle('has-files', count > 0);
        button.title = count > 0 ? `ไฟล์แนบ ${count} ไฟล์` : 'ยังไม่มีไฟล์แนบ';
    });
}

/**
 * เรียกหลังแนบหรือลบไฟล์สำเร็จ อัปเดตข้อมูลกลาง ตัวเลขบนปุ่ม แล้วแจ้งโมดูลอื่น
 */
export function publishTaskFiles(taskId, files, documentRef = globalThis.document) {
    const id = String(taskId);
    const list = Array.isArray(files) ? files : [];
    const data = attachmentStore(documentRef);

    if (data[id]) data[id].files = list;

    paintAttachmentCounters(id, list.length, documentRef);
    documentRef?.dispatchEvent?.(new CustomEvent('mytasks:attachments-changed', {detail: {id, files: list}}));

    return list;
}

/**
 * เพดานไฟล์แนบที่ server ประกาศไว้บนโหนดข้อมูลเดียวกัน
 *
 * เดิมเลข 5 ไฟล์และ 10 MB ถูกฮาร์ดโค้ดอยู่ใน JavaScript สามไฟล์ แยกจากกติกาฝั่ง PHP
 * เวลาปรับเพดานจึงต้องแก้ทั้งสองฝั่งให้ตรงกันเอง และเคยหลุดไม่ตรงกันมาแล้ว
 * ค่า fallback ที่นี่มีไว้กันหน้าพังเมื่อโหนดหาย ไม่ใช่ค่าที่ใช้งานจริง
 */
export function attachmentLimits(documentRef = globalThis.document) {
    const node = documentRef?.querySelector('[data-attachment-data]');
    const maxKilobytes = Number(node?.dataset.maxKilobytes) || 1048576;
    const maxMegabytes = Math.round(maxKilobytes / 1024);

    return {
        maxFiles: Number(node?.dataset.maxFiles) || 20,
        maxKilobytes,
        maxMegabytes,
        // ป้ายมาจาก server เพื่อให้เขียนหน่วยเหมือนกันทุกที่ ("1 GB" ไม่ใช่ "1024 MB")
        maxSizeLabel: node?.dataset.maxSizeLabel || `${maxMegabytes} MB`,
        extensions: (node?.dataset.extensions || '').split(',').filter(Boolean),
        typesLabel: node?.dataset.typesLabel || '',
    };
}
