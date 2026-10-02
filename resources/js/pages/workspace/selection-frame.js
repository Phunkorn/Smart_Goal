/*
 * กรอบการเลือก - กรอบที่ล้อมชิ้นที่เลือก มือจับย่อขยายทั้งแปด และมือจับหมุน
 *
 * กรอบมีรูปร่าง {x, y, w, h, rotation} แบบเดียวกับชิ้นงาน คือกรอบก่อนหมุน
 * บวกมุมที่หมุนรอบจุดกึ่งกลาง ปกติกรอบตั้งตรง (rotation 0) และล้อมทุกชิ้นที่เลือก
 * ยกเว้นตอนเลือกชิ้นที่หมุนอยู่เพียงชิ้นเดียว กรอบจะเอียงตามชิ้นนั้นพอดี
 * มือจับจึงอยู่บนมุมจริงของชิ้นงาน ไม่ใช่มุมของกรอบตั้งตรงที่ล้อมมันอยู่
 *
 * ทั้ง selection-ui.js (วาด) และ tools/select.js (ตรวจว่ากดโดนอะไร) อ่านตำแหน่ง
 * จากโมดูลนี้ สิ่งที่ตาเห็นกับสิ่งที่กดโดนจึงตรงกันเสมอไม่ว่าจะหมุนไปเท่าไร
 *
 * เป็นฟังก์ชันบริสุทธิ์ทั้งหมด ไม่แตะ DOM
 */

import {
    HANDLES,
    centerOf,
    handleAtPoint,
    handlePositions,
    localBoxOf,
    lockBoundsAspect,
    resizeBounds,
    scaleElementToBounds,
    unionBounds,
} from './geometry.js';
import {rotatePoint, rotationOf} from './rotation.js';

/** ระยะจากขอบบนของกรอบถึงมือจับหมุน หน่วยพิกเซลบนหน้าจอ */
export const ROTATION_HANDLE_OFFSET_PX = 28;

/** กรอบของชิ้นที่เลือก คืน null เมื่อไม่ได้เลือกอะไร */
export const frameOf = (elements) => {
    if (! elements.length) {
        return null;
    }

    if (elements.length === 1 && rotationOf(elements[0]) !== 0) {
        return {...localBoxOf(elements[0]), rotation: rotationOf(elements[0])};
    }

    return {...unionBounds(elements), rotation: 0};
};

export const frameCenter = (frame) => centerOf(frame);

/** จุดในพิกัดโลก -> จุดในพิกัดของกรอบก่อนหมุน */
const toFrameSpace = (frame, point) => rotatePoint(point, frameCenter(frame), -(frame.rotation || 0));

/** จุดในพิกัดของกรอบก่อนหมุน -> จุดในพิกัดโลก */
const fromFrameSpace = (frame, point) => rotatePoint(point, frameCenter(frame), frame.rotation || 0);

/** ตำแหน่งมือจับทั้งแปดในพิกัดโลก หลังหมุนตามกรอบแล้ว */
export const frameHandlePositions = (frame) => {
    const positions = handlePositions(frame);

    return Object.fromEntries(HANDLES.map((name) => [name, fromFrameSpace(frame, positions[name])]));
};

/**
 * ตำแหน่งมือจับหมุน อยู่เหนือกึ่งกลางขอบบนของกรอบ และเอียงไปตามกรอบ
 *
 * @param {number} offset ระยะห่างจากขอบบน หน่วยพิกัดโลก (ผู้เรียกหารด้วยระดับซูมมาแล้ว)
 */
export const rotationHandlePosition = (frame, offset) =>
    fromFrameSpace(frame, {x: frame.x + frame.w / 2, y: frame.y - offset});

/**
 * ส่วนของกรอบที่อยู่ใต้จุดนี้ คืน 'rotate' ชื่อมือจับย่อขยาย หรือ null
 *
 * @param {object} options handleRadius, rotationRadius และ rotationOffset หน่วยพิกัดโลก
 */
export const frameTargetAt = (frame, point, {handleRadius, rotationRadius = handleRadius, rotationOffset}) => {
    const rotateHandle = rotationHandlePosition(frame, rotationOffset);

    if (Math.hypot(point.x - rotateHandle.x, point.y - rotateHandle.y) <= rotationRadius) {
        return 'rotate';
    }

    return handleAtPoint(frame, toFrameSpace(frame, point), handleRadius);
};

/**
 * กรอบใหม่หลังลากมือจับย่อขยายจาก origin ไปถึง point
 *
 * lockAspect (มาจากการกด Shift ค้าง) รักษาสัดส่วนเดิมของกรอบไว้ สี่เหลี่ยมจัตุรัส
 * กับวงกลมจึงย่อขยายแล้วยังคงรูป ดู lockBoundsAspect ใน geometry.js
 *
 * ระยะที่ลากถูกหมุนกลับเข้าไปในพิกัดของกรอบก่อน การลากมุมขวาล่างของโน้ตที่
 * เอียงอยู่จึงขยายโน้ตไปตามแนวของมันเอง และมุมตรงข้ามอยู่กับที่บนจอ ไม่ไหลหนี
 */
export const resizeFrame = (frame, handle, origin, point, minSize = 0, {lockAspect = false} = {}) => {
    const from = toFrameSpace(frame, origin);
    const to = toFrameSpace(frame, point);
    const resized = resizeBounds(frame, handle, to.x - from.x, to.y - from.y);

    /*
     * ล็อกสัดส่วนก่อนบีบด้วยขนาดต่ำสุด ไม่ใช่หลัง
     *
     * ถ้าบีบขนาดต่ำสุดทีหลัง ด้านที่ถูกบีบจะหลุดออกจากสัดส่วนทันที รูปที่ย่อจน
     * เกือบสุดแล้วกด Shift ค้างอยู่จึงจะค่อย ๆ ผิดสัดส่วนไปทีละนิด
     */
    const shaped = lockAspect
        ? lockBoundsAspect(resized, handle, frame.h === 0 ? 0 : frame.w / frame.h)
        : resized;
    const box = {...shaped, w: Math.max(minSize, shaped.w), h: Math.max(minSize, shaped.h)};

    if (! frame.rotation) {
        return {...box, rotation: 0};
    }

    // กรอบใหม่ถูกคำนวณในพิกัดของกรอบเดิม ต้องย้ายจุดกึ่งกลางกลับออกมาในพิกัดโลก
    const center = fromFrameSpace(frame, centerOf(box));

    return {x: center.x - box.w / 2, y: center.y - box.h / 2, w: box.w, h: box.h, rotation: frame.rotation};
};

/**
 * ย่อขยายชิ้นงานให้ตามกรอบใหม่
 *
 * กรอบตั้งตรงใช้การย่อขยายตามสัดส่วนแบบเดิม ซึ่งรักษาตำแหน่งสัมพัทธ์ของทุกชิ้น
 * ในกลุ่ม ส่วนกรอบที่เอียงมีได้เฉพาะตอนเลือกชิ้นเดียว (ดู frameOf) กรอบนั้นจึง
 * คือตัวชิ้นงานเอง ชิ้นงานรับขนาดและตำแหน่งของกรอบไปตรง ๆ
 */
export const fitElementToFrame = (element, from, to) => {
    if (! from.rotation) {
        return scaleElementToBounds(element, from, to);
    }

    return {...element, x: to.x, y: to.y, w: to.w, h: to.h};
};
