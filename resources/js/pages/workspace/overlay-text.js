/*
 * ชั้นข้อความ - กระดาษโน้ตและกล่องข้อความ เรนเดอร์เป็น HTML ไม่ใช่ SVG
 *
 * ทำไมต้องเป็น HTML
 * ---------------------------------------------------------------
 * ภาษาไทยไม่มีเว้นวรรคระหว่างคำ การตัดบรรทัดจึงต้องอาศัยตัวตัดคำของเบราว์เซอร์
 * ทั้ง <canvas> และ <text> ของ SVG ไม่มีการตัดบรรทัดให้เลย ถ้าใช้อย่างใดอย่างหนึ่ง
 * ต้องเขียนตัวตัดบรรทัดของภาษาไทยเอง พร้อมจัดการสระบนล่างและวรรณยุกต์ ส่วน
 * contenteditable ของ HTML ให้การตัดคำ การพิมพ์ผ่าน IME เคอร์เซอร์ และการเลือก
 * ข้อความมาครบโดยไม่ต้องเขียนอะไรเพิ่ม
 *
 * ชั้นนี้ใช้ค่ากล้องเดียวกับชั้น SVG ผ่าน CSS transform สองชั้นจึงเลื่อนและซูม
 * พร้อมกันเสมอ
 *
 * ห้ามใช้ innerHTML เด็ดขาด ข้อความบนกระดานเป็นข้อความอิสระที่เพื่อนร่วมแผนก
 * คนไหนก็พิมพ์เข้ามาได้ ต้องเขียนผ่าน textContent เท่านั้น
 */

import {cssTransform} from './camera.js';
import {boundsOf} from './geometry.js';

/** ชนิดของชิ้นงานที่อยู่ในชั้น HTML แทนที่จะเป็น SVG */
export const OVERLAY_TYPES = ['sticky', 'text'];

export const isOverlayType = (type) => OVERLAY_TYPES.includes(type);

/** ปรับ transform ของชั้น overlay ให้ตรงกับกล้อง */
export const applyOverlayCamera = (layer, camera) => {
    layer.style.transform = cssTransform(camera);
};

/**
 * ทำให้ลูกของ overlay ตรงกับรายการชิ้นงาน
 *
 * ใช้การจับคู่ด้วยรหัสเช่นเดียวกับตัวเรนเดอร์ SVG และด้วยเหตุผลที่หนักกว่าเดิม
 * ถ้าสร้างโหนดใหม่ทุกรอบ กล่องที่ผู้ใช้กำลังพิมพ์อยู่จะเสียโฟกัสทุกครั้งที่
 * มีอะไรเปลี่ยนบนกระดาน
 */
export const renderOverlay = (layer, elements, {doc = layer.ownerDocument, editable = true, editingId = null} = {}) => {
    const existing = new Map();

    Array.from(layer.children).forEach((node) => {
        existing.set(node.dataset.elId, node);
    });

    elements.forEach((element, index) => {
        let node = existing.get(element.id);

        if (! node || node.dataset.elType !== element.type) {
            node?.remove();
            node = createNode(doc, element);
        }

        // แก้ไขได้ทีละกล่องเดียว และเฉพาะกล่องที่ผู้ใช้ดับเบิลคลิกเปิดขึ้นมา
        //
        // ถ้าเปิดให้แก้ได้ตลอดเวลา กล่องจะดูดการคลิกไปหมด แล้วผู้ใช้จะลากย้าย
        // หรือเลือกกระดาษโน้ตไม่ได้เลย เพราะเหตุการณ์ไม่เคยไปถึงผืนผ้าใบข้างใต้
        // ตอนไม่ได้แก้ไข กล่องถูกตั้ง pointer-events: none ใน CSS การคลิกจึงทะลุ
        // ลงไปให้ระบบตรวจการชนจากตัวแบบข้อมูลตามปกติ
        setEditingState(node, editable && element.id === editingId);

        applyStyles(node, element);

        // ไม่เขียนทับข้อความขณะที่ผู้ใช้กำลังพิมพ์อยู่ในกล่องนั้น มิฉะนั้นเคอร์เซอร์
        // จะกระโดดกลับไปต้นข้อความทุกครั้งที่มีการเรนเดอร์ใหม่
        if (doc.activeElement !== node && node.textContent !== element.text) {
            node.textContent = element.text ?? '';
        }

        existing.delete(element.id);

        const atIndex = layer.children[index];

        if (atIndex !== node) {
            layer.insertBefore(node, atIndex || null);
        }
    });

    existing.forEach((node) => node.remove());
};

const createNode = (doc, element) => {
    const node = doc.createElement('div');

    node.dataset.elId = element.id;
    node.dataset.elType = element.type;
    node.className = element.type === 'sticky' ? 'wsb-note' : 'wsb-textbox';
    node.setAttribute('role', 'textbox');
    node.setAttribute('aria-multiline', 'true');
    node.setAttribute(
        'aria-label',
        element.type === 'sticky' ? 'กระดาษโน้ต' : 'กล่องข้อความ'
    );

    return node;
};

/**
 * เปิดหรือปิดการแก้ไขของกล่องหนึ่งกล่อง
 *
 * ตั้งค่าผ่าน setAttribute ไม่ใช่ผ่าน property node.contentEditable เพราะ jsdom
 * ที่ใช้ทดสอบไม่ได้ทำ property ตัวนั้นไว้ การกำหนดค่าจะกลายเป็นการแปะ property
 * เปล่า ๆ ที่ไม่สะท้อนลง attribute แล้วเทสต์จะตรวจสถานะจริงไม่ได้
 *
 * tabindex ถูกเพิ่มด้วยเพราะ jsdom ไม่ถือว่า contenteditable ทำให้โฟกัสได้
 * (เบราว์เซอร์จริงถือ) และเป็นผลดีกับผู้ใช้คีย์บอร์ดอยู่แล้ว
 */
const setEditingState = (node, editing) => {
    node.classList.toggle('is-editing', editing);

    if (! editing) {
        node.removeAttribute('contenteditable');
        node.removeAttribute('tabindex');

        return;
    }

    node.setAttribute('contenteditable', 'plaintext-only');
    node.setAttribute('tabindex', '0');

    // เบราว์เซอร์ที่ไม่รองรับ plaintext-only จะสะท้อนค่ากลับมาเป็นอย่างอื่น
    // ถอยไปใช้ true แล้วกันการวางแบบมีรูปแบบด้วยตัวจัดการ paste ด้านล่างแทน
    // (jsdom ไม่มี property นี้เลย ค่าจึงเป็น undefined และไม่เข้าเงื่อนไข)
    if (typeof node.contentEditable === 'string' && node.contentEditable !== 'plaintext-only') {
        node.setAttribute('contenteditable', 'true');
    }
};

const applyStyles = (node, element) => {
    const box = boundsOf(element);

    node.style.left = `${box.x}px`;
    node.style.top = `${box.y}px`;
    node.style.width = `${box.w}px`;
    node.style.height = `${box.h}px`;
    node.style.fontSize = `${element.fontSize || 16}px`;

    if (element.type === 'sticky') {
        node.style.background = element.fill || '#fde68a';
    } else {
        node.style.color = element.color || '#1f2937';
    }
};

/**
 * ผูกการแก้ไขข้อความให้ชั้น overlay
 *
 * ใช้ event delegation ที่ตัวชั้น กล่องที่ถูกสร้างใหม่จึงแก้ไขได้ทันทีโดยไม่ต้อง
 * ผูก listener ใหม่ทุกครั้ง ซึ่งเป็นบ่อเกิดของ listener ซ้อนกันหลายชั้น
 *
 * @param {Function} onCommit เรียกด้วย (id, text) เมื่อผู้ใช้พิมพ์เสร็จ
 */
export const initOverlayEditing = (layer, {onCommit, onFocus, maxLength = 2000}) => {
    if (! layer) {
        return null;
    }

    const commit = (node) => {
        if (node?.dataset?.elId) {
            onCommit?.(node.dataset.elId, node.textContent ?? '');
        }
    };

    layer.addEventListener('focusin', (event) => {
        onFocus?.(event.target.dataset.elId);
    });

    layer.addEventListener('blur', (event) => commit(event.target), true);

    layer.addEventListener('input', (event) => {
        const node = event.target;

        // ตัดที่เพดานเดียวกับฝั่งเซิร์ฟเวอร์ ผู้ใช้จะได้รู้ตัวตอนพิมพ์ ไม่ใช่ตอน
        // บันทึกแล้วเจอ 422 ที่อธิบายไม่ได้ว่าข้อความไหนยาวเกิน
        if ((node.textContent?.length ?? 0) > maxLength) {
            node.textContent = node.textContent.slice(0, maxLength);
        }
    });

    layer.addEventListener('keydown', (event) => {
        // Escape จบการพิมพ์ ส่วน Enter ขึ้นบรรทัดใหม่ตามปกติ เพราะโน้ตหลายบรรทัด
        // เป็นการใช้งานหลัก ไม่ใช่ข้อยกเว้น
        if (event.key === 'Escape') {
            event.stopPropagation();
            event.target.blur();
        }
    });

    // การวางต้องเป็นข้อความล้วนเสมอ ไม่งั้นการคัดลอกจากเว็บอื่นจะพา HTML
    // เข้ามาในกระดาน ซึ่งเป็นทั้งช่องโหว่และทำให้เค้าโครงพัง
    layer.addEventListener('paste', (event) => {
        event.preventDefault();

        const text = event.clipboardData?.getData('text/plain') ?? '';
        const selection = layer.ownerDocument.getSelection();

        if (! selection?.rangeCount) {
            return;
        }

        const range = selection.getRangeAt(0);
        range.deleteContents();
        range.insertNode(layer.ownerDocument.createTextNode(text));
        selection.collapseToEnd();

        commit(event.target);
    });

    return {
        /** ย้ายโฟกัสไปที่กล่องนั้นเพื่อให้พิมพ์ต่อได้ทันทีหลังสร้าง */
        focus(id) {
            layer.querySelector(`[data-el-id="${CSS.escape(id)}"]`)?.focus();
        },
    };
};
