/*
 * ต่อสายคัดลอก/วางกับคลิปบอร์ดของระบบ - จุดเดียวของ Ctrl+C / Ctrl+V บนกระดาน
 *
 * ฟังเหตุการณ์ copy และ paste ของเบราว์เซอร์ที่ระดับเอกสาร ไม่ใช่ keydown เพราะ
 *  - รูปที่แคปหน้าจอหรือคัดลอกจากเว็บอื่นมาถึงได้ทางเหตุการณ์ paste เท่านั้น
 *    การยกเลิก keydown ของ Ctrl+V จะทำให้เบราว์เซอร์ไม่ยิง paste เลย
 *  - เบราว์เซอร์ยิง paste ไปที่ตัวที่มีโฟกัส ซึ่งอาจเป็นปุ่มบนแถบเครื่องมือ
 *    การฟังที่ผืนผ้าใบจึงพลาดทุกครั้งที่ผู้ใช้เพิ่งกดปุ่มเครื่องมือ (พบในเบราว์เซอร์จริง)
 *
 * การตัดสินว่าจะวางอะไรอยู่ใน clipboard.js (pasteSourceFor) ส่วนการทำจริงอยู่ที่
 * index.js ไฟล์นี้แค่อ่าน/เขียนคลิปบอร์ดและปล่อยผ่านเมื่อผู้ใช้กำลังพิมพ์
 */

import {CLIPBOARD_MIME, pasteSourceFor} from './clipboard.js';
import {isTypingContext} from './keyboard.js';

/** ไฟล์รูปในคลิปบอร์ด (ชนิดที่รับได้จริงตรวจซ้ำที่เซิร์ฟเวอร์พร้อมข้อความภาษาไทย) */
export const imageFilesFrom = (clipboardData) =>
    Array.from(clipboardData?.files || []).filter((file) => String(file?.type || '').startsWith('image/'));

/**
 * ผู้ใช้ลากคลุมข้อความบนหน้าไว้ (เช่นชื่อกระดาน) ต้องปล่อยให้คัดลอกข้อความนั้นตามปกติ
 * การคลิกผืนผ้าใบทำให้เกิดการเลือกแบบว่างเปล่า ซึ่งไม่นับ
 */
const hasPageTextSelection = (doc) => {
    const selection = doc.getSelection?.();

    return Boolean(selection && ! selection.isCollapsed && selection.toString().trim() !== '');
};

/**
 * @param {object} handlers
 *   canEdit            วางได้หรือไม่ (ผู้ที่ดูอย่างเดียวคัดลอกได้แต่วางไม่ได้)
 *   onCopy()           -> token | null  เก็บชิ้นที่เลือกไว้ คืนเครื่องหมาย หรือ null ถ้าไม่ได้เลือกอะไร
 *   getClipboard()     -> ชิ้นงานที่เก็บไว้ล่าสุด
 *   onPasteBoard()     วางชิ้นงานที่เก็บไว้
 *   onPasteImages(files) อัปโหลดแล้ววางรูป
 */
export const initClipboardEvents = (doc, {canEdit = false, onCopy, getClipboard, onPasteBoard, onPasteImages}) => {
    const handleCopy = (event) => {
        if (isTypingContext(doc, event) || hasPageTextSelection(doc)) {
            return;
        }

        const token = onCopy?.();

        if (! token) {
            return;
        }

        // ต้องยกเลิกพฤติกรรมเดิม ไม่งั้นเบราว์เซอร์จะเขียนทับด้วยการเลือกว่าง ๆ
        event.clipboardData?.setData(CLIPBOARD_MIME, token);
        event.preventDefault();
    };

    const handlePaste = (event) => {
        if (! canEdit || isTypingContext(doc, event)) {
            return;
        }

        const images = imageFilesFrom(event.clipboardData);
        const source = pasteSourceFor({
            marker: event.clipboardData?.getData?.(CLIPBOARD_MIME) || '',
            imageCount: images.length,
            clipboard: getClipboard?.(),
        });

        if (! source) {
            return;
        }

        event.preventDefault();

        if (source === 'images') {
            onPasteImages?.(images);
        } else {
            onPasteBoard?.();
        }
    };

    doc.addEventListener('copy', handleCopy);
    doc.addEventListener('paste', handlePaste);

    return {
        destroy() {
            doc.removeEventListener('copy', handleCopy);
            doc.removeEventListener('paste', handlePaste);
        },
    };
};
