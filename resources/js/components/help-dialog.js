/**
 * dialog คำอธิบายสั้น (เช่น "วิธีหาเลข AnyDesk" บนฟอร์ม /support) — ใช้ <dialog> ของเบราว์เซอร์
 *
 * ผู้เป็นเจ้าของสถานะเปิด/ปิดมีที่เดียว: <dialog>.showModal() / close()
 *   backdrop, กันโฟกัสออกนอก dialog และปิดด้วย Escape เป็นของเบราว์เซอร์
 *   ไฟล์นี้เพิ่ม: เปิดจากปุ่ม [data-help-dialog-open="id"], ปิดด้วยปุ่ม [data-help-dialog-close] หรือคลิกที่ backdrop
 *   และคืนโฟกัสให้ปุ่มที่เปิดเมื่อปิด (ทุกทาง รวม Escape — ฟังเหตุการณ์ close)
 *
 * listener ผูกครั้งเดียวต่อเอกสาร (event delegation) เรียกซ้ำได้ ไม่ผูกซ้ำ
 */
export function initHelpDialogs(root = document) {
    const doc = root.ownerDocument ?? root;
    const html = doc.documentElement;
    if (! html || html.dataset.helpDialogsReady === 'true') return false;
    html.dataset.helpDialogsReady = 'true';

    const openers = new WeakMap();

    doc.addEventListener('click', (event) => {
        const opener = event.target.closest?.('[data-help-dialog-open]');
        if (opener) {
            const dialog = doc.getElementById(opener.dataset.helpDialogOpen);
            // เบราว์เซอร์เก่าที่ไม่มี showModal — ไม่เปิดอะไร ฟอร์มยังกรอกได้ตามปกติ
            if (! dialog || typeof dialog.showModal !== 'function' || dialog.open) return;
            openers.set(dialog, opener);
            dialog.showModal();

            return;
        }

        const closer = event.target.closest?.('[data-help-dialog-close]');
        if (closer) {
            closer.closest('dialog')?.close();

            return;
        }

        // คลิกที่ backdrop: target คือตัว <dialog> เอง (เนื้อหาอยู่ในลูกของมัน)
        if (event.target.matches?.('dialog[data-help-dialog][open]')) event.target.close();
    });

    doc.addEventListener('close', (event) => {
        const dialog = event.target;
        if (! dialog.matches?.('dialog[data-help-dialog]')) return;
        openers.get(dialog)?.focus();
        openers.delete(dialog);
    }, true);

    return true;
}
