/*
 * กรอบการเลือกและมือจับทั้งแปด
 *
 * อยู่ในชั้นของตัวเองที่ไม่ถูกกล้องย่อขยาย จึงต้องแปลงพิกัดโลกเป็นพิกัดหน้าจอเอง
 * เหตุผลคือมือจับต้องมีขนาดคงที่บนหน้าจอเสมอ ถ้าปล่อยให้อยู่ในชั้นที่ถูกซูม
 * มือจับจะเล็กจนแตะไม่โดนเมื่อซูมออก และใหญ่จนบังงานเมื่อซูมเข้า
 *
 * ตำแหน่งของมือจับคำนวณจาก geometry.js ตัวเดียวกับที่ตรวจการคลิก สิ่งที่ตาเห็น
 * กับสิ่งที่แตะโดนจึงตรงกันเสมอ
 */

import {worldToScreen} from './camera.js';
import {HANDLES, handlePositions} from './geometry.js';

const SVG_NS = 'http://www.w3.org/2000/svg';

/** ขนาดมือจับบนหน้าจอ (พิกเซล) ต้องตรงกับ HANDLE_RADIUS_PX ใน tools/select.js */
const HANDLE_SIZE = 10;

/**
 * วาดกรอบและมือจับ หรือเก็บทั้งหมดเมื่อไม่มีอะไรถูกเลือก
 *
 * @param {Element} layer ชั้น <g> ที่ไม่ถูก transform ของกล้อง
 * @param {?{x:number,y:number,w:number,h:number}} bounds กรอบในพิกัดโลก
 */
export const renderSelection = (layer, bounds, camera, {editable = true} = {}) => {
    const doc = layer.ownerDocument;

    if (! bounds) {
        layer.replaceChildren();

        return;
    }

    const topLeft = worldToScreen(camera, {x: bounds.x, y: bounds.y});
    const bottomRight = worldToScreen(camera, {x: bounds.x + bounds.w, y: bounds.y + bounds.h});

    const frame = ensure(layer, doc, 'rect', 'frame');
    frame.setAttribute('class', 'wsb-selection__frame');
    frame.setAttribute('x', String(topLeft.x));
    frame.setAttribute('y', String(topLeft.y));
    frame.setAttribute('width', String(Math.max(0, bottomRight.x - topLeft.x)));
    frame.setAttribute('height', String(Math.max(0, bottomRight.y - topLeft.y)));

    // ผู้ที่ดูอย่างเดียวเห็นกรอบได้ (มีประโยชน์เวลาชี้ให้เพื่อนดู) แต่ไม่มีมือจับ
    // เพราะย่อขยายไม่ได้ การแสดงมือจับที่กดแล้วไม่เกิดอะไรทำให้สับสนกว่าไม่มี
    if (! editable) {
        HANDLES.forEach((name) => layer.querySelector(`[data-handle="${name}"]`)?.remove());

        return;
    }

    const positions = handlePositions(bounds);

    HANDLES.forEach((name) => {
        const handle = ensure(layer, doc, 'rect', name, 'data-handle');
        const point = worldToScreen(camera, positions[name]);

        handle.setAttribute('class', 'wsb-selection__handle');
        handle.setAttribute('x', String(point.x - HANDLE_SIZE / 2));
        handle.setAttribute('y', String(point.y - HANDLE_SIZE / 2));
        handle.setAttribute('width', String(HANDLE_SIZE));
        handle.setAttribute('height', String(HANDLE_SIZE));
    });
};

/**
 * หาโหนดเดิมหรือสร้างใหม่
 *
 * ใช้ซ้ำแทนการสร้างใหม่ทุกเฟรม เพราะฟังก์ชันนี้ถูกเรียกทุกครั้งที่เมาส์ขยับ
 * ระหว่างลากย่อขยาย
 */
const ensure = (layer, doc, tag, key, attribute = 'data-part') => {
    const selector = `[${attribute}="${key}"]`;
    let node = layer.querySelector(selector);

    if (! node) {
        node = doc.createElementNS(SVG_NS, tag);
        node.setAttribute(attribute, key);
        layer.append(node);
    }

    return node;
};
