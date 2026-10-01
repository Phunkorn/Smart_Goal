/*
 * คัดลอก วาง และทำสำเนาชิ้นงาน - ตรรกะบริสุทธิ์ ไม่แตะ DOM
 *
 * คลิปบอร์ดเก็บไว้ในสถานะของหน้านี้ ไม่ใช่คลิปบอร์ดของระบบปฏิบัติการ เพราะ
 * ชิ้นงานรูปภาพอ้าง attachmentId ของกระดานใบนี้ การวางลงกระดานใบอื่นจะถูก
 * WorkspaceDocumentValidator ปฏิเสธทั้งเอกสาร ผู้ใช้จะเสียการบันทึกทั้งกระดาน
 * เพราะรูปเดียว การจำกัดไว้ในหน้าเดียวจึงปลอดภัยกว่า
 *
 * ชิ้นงานในฉากไม่เคยถูกแก้ในที่ (ดู scene.js) คลิปบอร์ดจึงอ้างอ็อบเจ็กต์เดิมได้
 * ตรง ๆ โดยไม่ต้องคัดลอกลึก การแก้ต้นฉบับทีหลังจะสร้างอ็อบเจ็กต์ใหม่เสมอ
 * ของในคลิปบอร์ดจึงไม่เปลี่ยนตาม
 */

import {translateElement} from './geometry.js';
import {createScene, findManyById, nextZ} from './scene.js';

/**
 * ระยะที่สำเนาเลื่อนออกจากต้นฉบับ (หน่วยพิกัดโลก)
 *
 * ถ้าวางทับที่เดิมพอดี ผู้ใช้จะไม่เห็นว่ามีอะไรเกิดขึ้น แล้วกดวางซ้ำจนได้
 * ชิ้นซ้อนกันหลายชั้นโดยไม่รู้ตัว
 */
export const PASTE_OFFSET = 24;

/**
 * ชนิดข้อมูลของเครื่องหมายที่ฝากไว้ในคลิปบอร์ดของระบบตอนคัดลอกชิ้นงาน
 *
 * ชิ้นงานจริงยังอยู่ในหน่วยความจำของหน้านี้ (เหตุผลอยู่บนหัวไฟล์) ส่วนคลิปบอร์ด
 * ของระบบรับรู้แค่ว่า "ครั้งล่าสุดคัดลอกชิ้นงานจากกระดาน" ตอนวางจึงตัดสินได้ว่า
 * ของล่าสุดที่ผู้ใช้คัดลอกคือชิ้นงานหรือรูปที่แคปมาจากที่อื่น
 *
 * ใช้ชนิดเฉพาะแทน text/plain เพื่อไม่ให้ข้อความแปลก ๆ ไปโผล่ตอนผู้ใช้วางในโปรแกรมอื่น
 */
export const CLIPBOARD_MIME = 'application/x-smart-goal-workspace';

/**
 * เก็บชิ้นที่เลือกไว้ในคลิปบอร์ด คืน null เมื่อไม่ได้เลือกอะไร
 *
 * pasteCount นับว่าวางไปแล้วกี่ครั้ง การวางแต่ละครั้งจึงเลื่อนออกไปอีกขั้น
 * ไม่ใช่ซ้อนทับสำเนาก่อนหน้าพอดี
 *
 * token คือเครื่องหมายที่ฝากไว้ในคลิปบอร์ดของระบบ (ดู CLIPBOARD_MIME)
 */
export const copyElements = (scene, ids, token = null) => {
    const elements = findManyById(scene, ids);

    return elements.length ? {elements, pasteCount: 0, token} : null;
};

/**
 * ตัดสินว่าการวางครั้งนี้ควรวางอะไร คืน 'board' 'images' หรือ null
 *
 * ของที่คัดลอกล่าสุดต้องชนะเสมอ เหมือนโปรแกรมทั่วไป
 *  - เครื่องหมายในคลิปบอร์ดตรงกับชิ้นงานที่เก็บไว้ = ล่าสุดคือชิ้นงานบนกระดาน
 *  - มีรูปในคลิปบอร์ด = ผู้ใช้ไปแคปหรือคัดลอกรูปมาทีหลัง
 *  - ไม่มีทั้งเครื่องหมายและรูป แต่มีชิ้นงานเก็บไว้ = เบราว์เซอร์ไม่ได้ส่งเครื่องหมาย
 *    ลงคลิปบอร์ดตอนคัดลอก จึงถอยไปวางชิ้นงานตามพฤติกรรมเดิม
 *  - เครื่องหมายไม่ตรง = คัดลอกมาจากอีกแท็บ วางข้ามไม่ได้ (รูปอ้างไฟล์ของกระดานนั้น)
 */
export const pasteSourceFor = ({marker = '', imageCount = 0, clipboard = null}) => {
    if (clipboard && marker && marker === clipboard.token) {
        return 'board';
    }

    if (imageCount > 0) {
        return 'images';
    }

    return clipboard && ! marker ? 'board' : null;
};

/** วางของในคลิปบอร์ดลงฉาก คืนฉากใหม่ รายการที่ควรเลือก และคลิปบอร์ดที่นับเพิ่มแล้ว */
export const pasteFromClipboard = (scene, clipboard, idFactory) => {
    const pasteCount = clipboard.pasteCount + 1;
    const pasted = insertCopies(scene, clipboard.elements, idFactory, PASTE_OFFSET * pasteCount);

    return {...pasted, clipboard: {...clipboard, pasteCount}};
};

/** ทำสำเนาชิ้นที่เลือกโดยไม่ผ่านคลิปบอร์ด คืน null เมื่อไม่ได้เลือกอะไร */
export const duplicateElements = (scene, ids, idFactory) => {
    const originals = findManyById(scene, ids);

    return originals.length ? insertCopies(scene, originals, idFactory, PASTE_OFFSET) : null;
};

/**
 * ต่อสำเนาไว้บนสุดของฉากในลำดับเดิม ชิ้นที่อยู่บนในต้นฉบับจึงยังอยู่บนในสำเนา
 *
 * รหัสใหม่ต้องไม่ซ้ำกับชิ้นใดในฉากเลย เพราะเซิร์ฟเวอร์ปฏิเสธทั้งเอกสารเมื่อเจอ
 * รหัสซ้ำ การขอรหัสใหม่จนกว่าจะไม่ชนจึงถูกกว่าการเสี่ยงให้การบันทึกล้มทั้งกระดาน
 */
const insertCopies = (scene, elements, idFactory, offset) => {
    const taken = new Set(scene.elements.map((element) => element.id));
    const baseZ = nextZ(scene);

    const copies = elements.map((element, index) => {
        let id = idFactory();

        while (taken.has(id)) {
            id = idFactory();
        }

        taken.add(id);

        return {...translateElement(element, offset, offset), id, z: baseZ + index};
    });

    return {
        scene: createScene([...scene.elements, ...copies]),
        selection: copies.map((copy) => copy.id),
    };
};
