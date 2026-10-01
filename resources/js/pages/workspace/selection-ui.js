/*
 * กรอบการเลือก มือจับย่อขยายทั้งแปด และมือจับหมุน
 *
 * อยู่ในชั้นของตัวเองที่ไม่ถูกกล้องย่อขยาย จึงต้องแปลงพิกัดโลกเป็นพิกัดหน้าจอเอง
 * เหตุผลคือมือจับต้องมีขนาดคงที่บนหน้าจอเสมอ ถ้าปล่อยให้อยู่ในชั้นที่ถูกซูม
 * มือจับจะเล็กจนแตะไม่โดนเมื่อซูมออก และใหญ่จนบังงานเมื่อซูมเข้า
 *
 * ทุกส่วนถูกวางในพิกัดของกรอบก่อนหมุน แล้วหมุนทั้งชุดด้วย transform เดียวรอบ
 * จุดกึ่งกลางของกรอบ ซึ่งให้ตำแหน่งเดียวกับที่ selection-frame.js ใช้ตรวจการกด
 * (กล้องมีแค่เลื่อนกับซูมเท่ากันทุกแกน การหมุนบนจอจึงเท่ากับการหมุนในพิกัดโลก)
 * สิ่งที่ตาเห็นกับสิ่งที่แตะโดนจึงตรงกันเสมอ
 */

import {worldToScreen} from './camera.js';
import {HANDLES, handlePositions} from './geometry.js';
import {ROTATION_HANDLE_OFFSET_PX} from './selection-frame.js';

const SVG_NS = 'http://www.w3.org/2000/svg';

/** ขนาดมือจับบนหน้าจอ (พิกเซล) ต้องตรงกับ HANDLE_RADIUS_PX ใน tools/select.js */
const HANDLE_SIZE = 10;

/** รัศมีของมือจับหมุนบนหน้าจอ (พิกเซล) */
const ROTATION_HANDLE_RADIUS = 6;

/**
 * วาดกรอบและมือจับ หรือเก็บทั้งหมดเมื่อไม่มีอะไรถูกเลือก
 *
 * @param {Element} layer ชั้น <g> ที่ไม่ถูก transform ของกล้อง
 * @param {?{x:number,y:number,w:number,h:number,rotation?:number}} frame กรอบในพิกัดโลก
 */
export const renderSelection = (layer, frame, camera, {editable = true} = {}) => {
    const doc = layer.ownerDocument;

    if (! frame) {
        layer.replaceChildren();

        return;
    }

    const topLeft = worldToScreen(camera, {x: frame.x, y: frame.y});
    const width = Math.max(0, frame.w * camera.scale);
    const height = Math.max(0, frame.h * camera.scale);
    const rotation = frame.rotation || 0;
    const turn = rotation
        ? `rotate(${rotation} ${topLeft.x + width / 2} ${topLeft.y + height / 2})`
        : null;

    const box = ensure(layer, doc, 'rect', 'frame');
    box.setAttribute('class', 'wsb-selection__frame');
    box.setAttribute('x', String(topLeft.x));
    box.setAttribute('y', String(topLeft.y));
    box.setAttribute('width', String(width));
    box.setAttribute('height', String(height));
    setTurn(box, turn);

    // ผู้ที่ดูอย่างเดียวเห็นกรอบได้ (มีประโยชน์เวลาชี้ให้เพื่อนดู) แต่ไม่มีมือจับ
    // เพราะย่อขยายหรือหมุนไม่ได้ การแสดงมือจับที่กดแล้วไม่เกิดอะไรทำให้สับสนกว่าไม่มี
    if (! editable) {
        HANDLES.forEach((name) => layer.querySelector(`[data-handle="${name}"]`)?.remove());
        layer.querySelector('[data-part="rotate-stem"]')?.remove();
        layer.querySelector('[data-part="rotate-handle"]')?.remove();

        return;
    }

    renderRotationHandle(layer, doc, {x: topLeft.x + width / 2, y: topLeft.y}, turn);

    const positions = handlePositions(frame);

    HANDLES.forEach((name) => {
        const handle = ensure(layer, doc, 'rect', name, 'data-handle');
        const point = worldToScreen(camera, positions[name]);

        handle.setAttribute('class', 'wsb-selection__handle');
        handle.setAttribute('x', String(point.x - HANDLE_SIZE / 2));
        handle.setAttribute('y', String(point.y - HANDLE_SIZE / 2));
        handle.setAttribute('width', String(HANDLE_SIZE));
        handle.setAttribute('height', String(HANDLE_SIZE));
        setTurn(handle, turn);
    });
};

/**
 * มือจับหมุนเป็นวงกลมลอยเหนือกึ่งกลางขอบบน มีก้านต่อกับกรอบ
 *
 * ก้านบอกว่าวงกลมนี้เป็นของกรอบไหน และรูปทรงกลมต่างจากมือจับสี่เหลี่ยมชัดเจน
 * ผู้ใช้จึงไม่สับสนว่าอันไหนย่อขยาย อันไหนหมุน ซึ่งเป็นรูปแบบที่คุ้นจากโปรแกรมทั่วไป
 */
const renderRotationHandle = (layer, doc, topCenter, turn) => {
    const handleY = topCenter.y - ROTATION_HANDLE_OFFSET_PX;

    const stem = ensure(layer, doc, 'line', 'rotate-stem');
    stem.setAttribute('class', 'wsb-selection__stem');
    stem.setAttribute('x1', String(topCenter.x));
    stem.setAttribute('y1', String(topCenter.y));
    stem.setAttribute('x2', String(topCenter.x));
    stem.setAttribute('y2', String(handleY + ROTATION_HANDLE_RADIUS));
    setTurn(stem, turn);

    const knob = ensure(layer, doc, 'circle', 'rotate-handle');
    knob.setAttribute('class', 'wsb-selection__rotate');
    knob.setAttribute('cx', String(topCenter.x));
    knob.setAttribute('cy', String(handleY));
    knob.setAttribute('r', String(ROTATION_HANDLE_RADIUS));
    setTurn(knob, turn);
};

/** ตั้งหรือล้างการหมุน ต้องล้างด้วย เพราะโหนดถูกใช้ซ้ำข้ามการเรนเดอร์ */
const setTurn = (node, turn) => {
    if (turn) {
        node.setAttribute('transform', turn);
    } else {
        node.removeAttribute('transform');
    }
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
