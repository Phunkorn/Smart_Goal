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
 * แต่ละบรรทัด (คั่นด้วย \n ใน element.text) เรนเดอร์เป็น <div> ลูกของตัวเองหนึ่ง
 * ใบเสมอ ไม่ใช่ข้อความก้อนเดียว เพราะ CSS text-align จัดได้ทีละบล็อกเท่านั้น
 * จะให้แต่ละบรรทัดจัดคนละแบบ (เช่นบรรทัดแรกกึ่งกลาง บรรทัดถัดมาชิดขวา) ได้จริง
 * ต้องแยกบล็อกต่อบรรทัดเสมอ กล่องจึงใช้ contenteditable="true" (ไม่ใช่
 * "plaintext-only" เหมือนก่อนหน้านี้) เพื่อให้เบราว์เซอร์สร้าง <div> ใหม่เองตาม
 * ธรรมชาติทุกครั้งที่ผู้ใช้กด Enter
 *
 * การเปิดโหมด true แทน plaintext-only แปลว่าเบราว์เซอร์ยอมรับการวางหรือลาก
 * เนื้อหาที่มีรูปแบบ (ตัวหนา สี ลิงก์ ฯลฯ) เข้ามาได้ตามธรรมชาติ ตัวจัดการ paste
 * และ drop ด้านล่างจึงต้องเป็นด่านเดียวที่ปฏิเสธสิ่งเหล่านั้นแทน plaintext-only
 * และไม่ว่า DOM จะมีอะไรปนมาระหว่างพิมพ์ก็ตาม ตอน commit (readEditableContent)
 * จะอ่านออกมาเฉพาะตัวอักษร (textContent ของแต่ละบล็อก) กับ text-align ของบล็อก
 * เท่านั้น ไม่มีทางที่แท็กหรือสไตล์อื่นจะหลุดรอดไปถึงข้อมูลที่บันทึกได้
 *
 * ห้ามใช้ innerHTML เด็ดขาด ข้อความบนกระดานเป็นข้อความอิสระที่เพื่อนร่วมแผนก
 * คนไหนก็พิมพ์เข้ามาได้ ต้องเขียนผ่าน textContent/createElement เท่านั้น
 */

import {cssTransform} from './camera.js';
import {localBoxOf} from './geometry.js';
import {rotationOf} from './rotation.js';

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
        // จะกระโดดกลับไปต้นข้อความทุกครั้งที่มีการเรนเดอร์ใหม่ (draw() ถูกเรียก
        // ซ้ำทุกครั้งที่มีอะไรเปลี่ยนบนกระดาน แม้เรื่องนั้นไม่เกี่ยวกับกล่องนี้เลย)
        //
        // ลายเซ็นกันสร้างบรรทัดซ้ำโดยไม่จำเป็นเมื่อทั้งข้อความและการจัดบรรทัด
        // ไม่ได้เปลี่ยนจากรอบก่อน ไม่งั้นทุกกล่องบนกระดานจะถูกรื้อ DOM ใหม่ทุกครั้ง
        // ที่มีชิ้นงานอื่นขยับ ทั้งที่กล่องนั้นไม่ได้เปลี่ยนอะไรเลย
        const signature = `${element.text ?? ''}\u0000${JSON.stringify(element.lineAligns || {})}`;

        if (doc.activeElement !== node && node.dataset.linesSignature !== signature) {
            node.dataset.linesSignature = signature;
            renderLines(doc, node, element.text, element.lineAligns);
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
 *
 * ใช้ "true" ไม่ใช่ "plaintext-only" เพราะต้องพึ่งพฤติกรรมธรรมชาติของเบราว์เซอร์
 * ที่สร้าง <div> บล็อกใหม่ทุกครั้งที่กด Enter เพื่อให้จัดบรรทัดแยกกันได้ (ดู
 * หมายเหตุหัวไฟล์) ซึ่ง plaintext-only ปิดพฤติกรรมนี้ไว้
 */
const setEditingState = (node, editing) => {
    node.classList.toggle('is-editing', editing);

    if (! editing) {
        node.removeAttribute('contenteditable');
        node.removeAttribute('tabindex');

        return;
    }

    node.setAttribute('contenteditable', 'true');
    node.setAttribute('tabindex', '0');
};

const applyStyles = (node, element) => {
    const box = localBoxOf(element);
    const rotation = rotationOf(element);

    node.style.left = `${box.x}px`;
    node.style.top = `${box.y}px`;
    node.style.width = `${box.w}px`;
    node.style.height = `${box.h}px`;
    node.style.fontSize = `${element.fontSize || 16}px`;
    node.style.fontWeight = element.bold ? '700' : '400';
    node.style.fontStyle = element.italic ? 'italic' : 'normal';
    node.style.letterSpacing = element.letterSpacing ? `${element.letterSpacing}px` : 'normal';
    // transform-origin ปริยายของ HTML คือกึ่งกลางกล่อง ตรงกับจุดหมุนของ geometry.js
    node.style.transform = rotation ? `rotate(${rotation}deg)` : '';

    if (element.type === 'sticky') {
        node.style.background = element.fill || '#fde68a';
    } else {
        node.style.color = element.color || '#1f2937';
    }
};

/* ── การจัดบรรทัดต่อบรรทัด ────────────────────────────────────── */

/** ตัดข้อความเป็นบรรทัดตาม \n เหมือนที่ WorkspaceDocumentValidator ใช้นับลำดับ */
const linesOf = (text) => (text ?? '').split('\n');

/**
 * สร้างบล็อกต่อบรรทัดใหม่ทั้งหมดใต้ node แทนที่ของเดิม
 *
 * ใช้กับทั้งการแสดงผลตอนไม่ได้แก้ไข (ไม่ต้องห่วงเคอร์เซอร์ เพราะไม่มีใครโฟกัส
 * อยู่) และตอนเพิ่งเข้าสู่โหมดแก้ไข (ก่อนเรียก .focus() กล่องต้องมีบล็อกที่ตรง
 * กับข้อมูลที่บันทึกไว้แล้ว ผู้ใช้จึงพิมพ์ต่อในบรรทัดที่ถูกต้อง)
 *
 * บรรทัดว่างต้องมี <br> อยู่ข้างในเสมอ ไม่งั้นบล็อกจะยุบจนคลิกโดนไม่ได้ และ
 * เคอร์เซอร์ก็วางไม่ได้ในเบราว์เซอร์จริง
 */
const renderLines = (doc, node, text, lineAligns = {}) => {
    node.textContent = '';

    linesOf(text).forEach((line, index) => {
        const div = doc.createElement('div');
        const align = lineAligns?.[index] ?? lineAligns?.[String(index)];

        if (align && align !== 'left') {
            div.style.textAlign = align;
        }

        if (line === '') {
            div.appendChild(doc.createElement('br'));
        } else {
            div.textContent = line;
        }

        node.appendChild(div);
    });
};

/**
 * เดินไล่ลูกทั้งหมดแล้วต่อข้อความเข้าด้วยกัน โดยตีความ <br> เป็นการขึ้นบรรทัดใหม่
 * แบบอ่อน (Shift+Enter) ภายในย่อหน้าเดียวกัน
 *
 * อ่านผ่าน textContent ของแต่ละโหนดเท่านั้น (ไม่มีทางอ่าน tag หรือ attribute
 * อื่นออกมาปนได้) จึงยังคงกติกาเดิมของไฟล์นี้ที่ว่าเนื้อหาที่บันทึกมาจาก
 * ตัวอักษรล้วนเสมอ ไม่ว่า DOM ระหว่างพิมพ์จะมีอะไรปนมาจากการวาง/ลากก็ตาม
 */
const textWithBreaks = (node) => {
    let out = '';

    node.childNodes.forEach((child) => {
        if (child.nodeType === 3) {
            out += child.textContent ?? '';

            return;
        }

        if (child.nodeType !== 1) {
            return;
        }

        if (child.tagName === 'BR') {
            out += '\n';

            return;
        }

        out += textWithBreaks(child);
    });

    return out;
};

/** การจัดบรรทัดที่ตั้งไว้บนบล็อกนั้น (inline style เท่านั้น เราเป็นคนตั้งเองทั้งหมด) */
const alignValueOf = (el) => el?.style?.textAlign || 'left';

/**
 * อ่านข้อความและการจัดบรรทัดกลับจากกล่องที่กำลังแก้ไขอยู่ ตอน commit
 *
 * กล่องที่ยังไม่เคยขึ้นบรรทัดใหม่เลย (ยังไม่มีลูกที่เป็น element บล็อก) ถือเป็น
 * หนึ่งย่อหน้าเดียว อ่านทั้ง node ตรง ๆ ส่วนกล่องที่มีบล็อกแล้ว แต่ละบล็อกลูก
 * ระดับบนสุดคือหนึ่งบรรทัด (ย่อหน้า) เบราว์เซอร์สร้างบล็อกใหม่ให้เองทุกครั้งที่
 * กด Enter บนเนื้อหาที่จัดเป็นบล็อกอยู่แล้ว (ดูหมายเหตุหัวไฟล์)
 */
export const readEditableContent = (node) => {
    const blocks = Array.from(node.childNodes).filter((child) => child.nodeType === 1);

    if (! blocks.length) {
        return {text: textWithBreaks(node), lineAligns: {}};
    }

    const lines = [];
    const lineAligns = {};

    blocks.forEach((block, index) => {
        lines.push(textWithBreaks(block));

        const align = alignValueOf(block);

        if (align !== 'left') {
            lineAligns[index] = align;
        }
    });

    return {text: lines.join('\n'), lineAligns};
};

/** วางเคอร์เซอร์ไว้ท้ายเนื้อหา ใช้หลังตัดข้อความที่เกินเพดานความยาวทิ้ง */
const placeCaretAtEnd = (doc, node) => {
    const selection = doc.getSelection?.();

    if (! selection) {
        return;
    }

    const range = doc.createRange();
    range.selectNodeContents(node);
    range.collapse(false);
    selection.removeAllRanges();
    selection.addRange(range);
};

/**
 * บล็อกลูกระดับบนสุดของ node ที่ครอบ container อยู่ (เดินขึ้นจนกว่าจะเจอลูก
 * ระดับบนสุด) คืน null เมื่อ container คือตัว node เอง (ไม่ได้อยู่ในบล็อกไหน
 * เจาะจง เช่นกล่องว่างที่ยังไม่มีบล็อกเลย)
 */
const blockContaining = (node, container, blocks) => {
    if (! container || container === node) {
        return null;
    }

    let current = container;

    while (current && current.parentNode !== node) {
        current = current.parentNode;
    }

    return current && blocks.includes(current) ? current : null;
};

/**
 * การจัดบรรทัดของย่อหน้าที่เคอร์เซอร์อยู่ตอนนี้ ใช้ให้แถบเครื่องมือไฮไลต์ปุ่ม
 * ที่ตรงกับบรรทัดจริง ไม่ใช่ค่าที่ตั้งไว้ครั้งล่าสุด
 */
export const alignOfSelection = (doc, node) => {
    const selection = doc.getSelection?.();

    if (! selection?.rangeCount) {
        return 'left';
    }

    const range = selection.getRangeAt(0);

    if (! node.contains(range.startContainer)) {
        return 'left';
    }

    const blocks = Array.from(node.childNodes).filter((child) => child.nodeType === 1);

    if (! blocks.length) {
        return alignValueOf(node);
    }

    return alignValueOf(blockContaining(node, range.startContainer, blocks) ?? blocks[0]);
};

/**
 * ตั้งการจัดบรรทัดให้ทุกย่อหน้าที่ตัวเลือก (selection) ปัจจุบันแตะอยู่
 *
 * ทำเองแทนการพึ่ง document.execCommand('justify...') เพราะ execCommand เป็น
 * API ที่เลิกใช้แล้วและ jsdom (ที่ใช้ทดสอบไฟล์นี้) ไม่รองรับเลย ถ้าพึ่งมัน
 * พฤติกรรมของปุ่มจัดบรรทัดขณะแก้ไขจะไม่มีทางเขียนเทสต์คลุมได้จริง
 *
 * คืน false เมื่อไม่มีตัวเลือกอยู่ในกล่องนี้เลย (ปุ่มถูกกดตอนโฟกัสหลุดไปแล้ว)
 */
export const applyAlignToSelection = (doc, node, align) => {
    const selection = doc.getSelection?.();

    if (! selection?.rangeCount) {
        return false;
    }

    const range = selection.getRangeAt(0);

    if (! node.contains(range.commonAncestorContainer)) {
        return false;
    }

    const setAlign = (el) => {
        if (align === 'left') {
            el.style.removeProperty('text-align');
        } else {
            el.style.textAlign = align;
        }
    };

    const blocks = Array.from(node.childNodes).filter((child) => child.nodeType === 1);

    if (! blocks.length) {
        setAlign(node);

        return true;
    }

    const startBlock = blockContaining(node, range.startContainer, blocks);
    const endBlock = blockContaining(node, range.endContainer, blocks);
    const startIndex = startBlock ? blocks.indexOf(startBlock) : 0;
    const endIndex = endBlock ? blocks.indexOf(endBlock) : blocks.length - 1;
    const [from, to] = startIndex <= endIndex ? [startIndex, endIndex] : [endIndex, startIndex];

    for (let i = from; i <= to; i += 1) {
        setAlign(blocks[i]);
    }

    return true;
};

/**
 * ค่าการจัดบรรทัดของทุกบรรทัดในข้อความนี้ ใช้ตอนกดปุ่มจัดบรรทัดขณะที่ "เลือก"
 * กล่องทั้งใบอยู่ (ไม่ได้เปิดแก้ไข) ซึ่งควรมีผลกับทุกบรรทัดเหมือนเดิมทั้งกล่อง
 */
export const uniformLineAligns = (text, align) => {
    if (align === 'left') {
        return {};
    }

    return Object.fromEntries(linesOf(text).map((_, index) => [index, align]));
};

/** กล่องที่เหตุการณ์นี้เกิดขึ้นข้างใน คืน null เมื่อไม่ได้เกิดในกล่องไหนเลย */
const boxOf = (node) => (node?.nodeType === 1 ? node.closest('[data-el-id]') : null);

/**
 * ผูกการแก้ไขข้อความให้ชั้นข้อความ
 *
 * ผูกที่กล่องที่ครอบชั้นทั้งหมด (ไม่ใช่ที่ชั้นใดชั้นหนึ่ง) เพราะชั้นข้อความมีได้
 * หลายชั้นและถูกสร้างใหม่ตามลำดับของเอกสาร (ดู layers.js) การผูกที่ตัวครอบ
 * ครั้งเดียวจึงครอบคลุมกล่องที่เกิดใหม่ทั้งหมดโดยไม่ต้องผูก listener ซ้ำ ซึ่ง
 * เป็นบ่อเกิดของ listener ซ้อนกันหลายชั้น
 *
 * @param {Function} onCommit เรียกด้วย (id, text, lineAligns) เมื่อผู้ใช้พิมพ์เสร็จ
 */
export const initOverlayEditing = (layer, {onCommit, onFocus, maxLength = 2000}) => {
    if (! layer) {
        return null;
    }

    const doc = layer.ownerDocument;

    const commit = (node) => {
        if (node?.dataset?.elId) {
            const {text, lineAligns} = readEditableContent(node);
            onCommit?.(node.dataset.elId, text, lineAligns);
        }
    };

    layer.addEventListener('focusin', (event) => {
        onFocus?.(boxOf(event.target)?.dataset.elId);
    });

    layer.addEventListener('blur', (event) => commit(boxOf(event.target)), true);

    layer.addEventListener('input', (event) => {
        const node = boxOf(event.target);

        if (! node) {
            return;
        }

        const {text, lineAligns} = readEditableContent(node);

        // ตัดที่เพดานเดียวกับฝั่งเซิร์ฟเวอร์ ผู้ใช้จะได้รู้ตัวตอนพิมพ์ ไม่ใช่ตอน
        // บันทึกแล้วเจอ 422 ที่อธิบายไม่ได้ว่าข้อความไหนยาวเกิน
        if (text.length > maxLength) {
            const truncated = text.slice(0, maxLength);
            const keptLines = truncated.split('\n').length;
            const trimmedAligns = Object.fromEntries(
                Object.entries(lineAligns).filter(([index]) => Number(index) < keptLines)
            );

            renderLines(doc, node, truncated, trimmedAligns);
            placeCaretAtEnd(doc, node);
        }
    });

    layer.addEventListener('keydown', (event) => {
        // Escape จบการพิมพ์ ส่วน Enter ขึ้นบรรทัดใหม่ตามปกติ เพราะโน้ตหลายบรรทัด
        // เป็นการใช้งานหลัก ไม่ใช่ข้อยกเว้น
        if (event.key === 'Escape') {
            event.stopPropagation();
            boxOf(event.target)?.blur();
        }
    });

    // การวางต้องเป็นข้อความล้วนเสมอ ไม่งั้นการคัดลอกจากเว็บอื่นจะพา HTML
    // เข้ามาในกระดาน ซึ่งเป็นทั้งช่องโหว่และทำให้เค้าโครงพัง กล่องเปิด
    // contenteditable="true" (ไม่ใช่ plaintext-only) เพื่อให้ Enter สร้างบล็อก
    // ต่อบรรทัดได้เอง ตัวจัดการนี้จึงเป็นด่านเดียวที่กันรูปแบบจากการวาง ไม่ใช่
    // ด่านสำรองเหมือนก่อนหน้านี้อีกต่อไป
    //
    // ตัดข้อความที่วางเป็น \n แล้วแทรกเป็นตัวอักษรคั่นด้วย <br> (บรรทัดอ่อนภายใน
    // ย่อหน้าเดียวกัน) ไม่สร้างบล็อกใหม่ เพราะการวางไม่ควรเปลี่ยนขอบเขตย่อหน้า
    // ที่ผู้ใช้ตั้งการจัดบรรทัดไว้อยู่แล้ว
    //
    // ไม่ commit ตรงนี้ การ commit ปิดโหมดแก้ไข ผู้ใช้ที่วางข้อความแล้วจะพิมพ์ต่อ
    // ไม่ได้ ข้อความที่วางถูกบันทึกตอน blur เหมือนข้อความที่พิมพ์ ส่วน input ที่ยิง
    // ตามมาทำให้เพดานความยาวด้านบนมีผลกับข้อความที่วางด้วย
    layer.addEventListener('paste', (event) => {
        event.preventDefault();

        const text = event.clipboardData?.getData('text/plain') ?? '';
        const selection = doc.getSelection();

        if (! selection?.rangeCount) {
            return;
        }

        const range = selection.getRangeAt(0);
        range.deleteContents();

        const fragment = doc.createDocumentFragment();
        const parts = text.split('\n');

        parts.forEach((part, index) => {
            fragment.appendChild(doc.createTextNode(part));

            if (index < parts.length - 1) {
                fragment.appendChild(doc.createElement('br'));
            }
        });

        range.insertNode(fragment);
        selection.collapseToEnd();

        boxOf(event.target)?.dispatchEvent(new doc.defaultView.Event('input', {bubbles: true}));
    });

    // การลากวางเนื้อหาที่มีรูปแบบเข้ามาเป็นอีกช่องทางเดียวกับการวางที่ plaintext-only
    // เคยกันไว้ก่อนหน้านี้ contenteditable="true" ไม่ได้กันให้ ปฏิเสธไปเฉย ๆ แทน
    // การพยายามรองรับ เพราะเป็นการใช้งานที่พบน้อยมากบนกระดานนี้ ผู้ใช้วางด้วย
    // Ctrl+V ได้อยู่แล้วซึ่งผ่านตัวจัดการ paste ด้านบน
    layer.addEventListener('drop', (event) => event.preventDefault());

    return {
        /** ย้ายโฟกัสไปที่กล่องนั้นเพื่อให้พิมพ์ต่อได้ทันทีหลังสร้าง */
        focus(id) {
            layer.querySelector(`[data-el-id="${CSS.escape(id)}"]`)?.focus();
        },
    };
};
