/*
 * ตัวช่วยสร้างเหตุการณ์จากตัวชี้สำหรับเทสต์ใน jsdom
 *
 * jsdom ไม่มีคลาส PointerEvent และไม่มี setPointerCapture/releasePointerCapture
 * บน Element (เรียกแล้วโยน error) ตัวช่วยนี้จึงสร้างเหตุการณ์จาก MouseEvent
 * แล้วแปะ pointerId ลงไป ซึ่งเพียงพอกับสิ่งที่ pointer.js อ่านจริง
 *
 * jsdom ยังไม่คำนวณ layout ด้วย getBoundingClientRect จึงคืนศูนย์ทั้งหมด
 * ต้องใช้ stubStageRect() กำหนดกรอบเองเมื่อทดสอบการแปลงพิกัด
 */

const pointerEvent = (window, type, {x = 0, y = 0, pointerId = 1, button = 0, shiftKey = false, ctrlKey = false} = {}) => {
    const event = new window.MouseEvent(type, {
        bubbles: true,
        cancelable: true,
        clientX: x,
        clientY: y,
        button,
        shiftKey,
        ctrlKey,
    });

    // MouseEvent ไม่มี pointerId จึงต้องแปะเพิ่ม และต้องใช้ defineProperty
    // เพราะคุณสมบัติของ event เป็นแบบอ่านอย่างเดียว
    Object.defineProperty(event, 'pointerId', {value: pointerId});

    return event;
};

export const pointerDown = (target, options = {}) =>
    target.dispatchEvent(pointerEvent(target.ownerDocument.defaultView, 'pointerdown', options));

export const pointerMove = (target, options = {}) =>
    target.dispatchEvent(pointerEvent(target.ownerDocument.defaultView, 'pointermove', options));

export const pointerUp = (target, options = {}) =>
    target.dispatchEvent(pointerEvent(target.ownerDocument.defaultView, 'pointerup', options));

/** ลากจากจุดหนึ่งไปอีกจุดหนึ่งผ่านจุดกลางที่กำหนด */
export const drag = (target, from, to, {steps = 1, pointerId = 1, shiftKey = false} = {}) => {
    pointerDown(target, {...from, pointerId, shiftKey});

    for (let step = 1; step <= steps; step += 1) {
        pointerMove(target, {
            x: from.x + ((to.x - from.x) * step) / steps,
            y: from.y + ((to.y - from.y) * step) / steps,
            pointerId,
            shiftKey,
        });
    }

    pointerUp(target, {...to, pointerId, shiftKey});
};

/** เหตุการณ์ล้อเมาส์ (jsdom มี WheelEvent อยู่แล้ว) */
export const wheel = (target, {x = 0, y = 0, deltaX = 0, deltaY = 0, ctrlKey = false} = {}) =>
    target.dispatchEvent(new target.ownerDocument.defaultView.WheelEvent('wheel', {
        bubbles: true,
        cancelable: true,
        clientX: x,
        clientY: y,
        deltaX,
        deltaY,
        ctrlKey,
    }));

/**
 * กำหนดกรอบและขนาดของ stage เอง
 *
 * jsdom ไม่คำนวณ layout ทั้ง getBoundingClientRect และ clientWidth/clientHeight
 * จึงเป็นศูนย์เสมอ ถ้าไม่กำหนด การแปลงพิกัดหน้าจอเป็นพิกัดโลกจะทดสอบไม่ได้
 */
export const stubStageRect = (stage, {left = 0, top = 0, width = 800, height = 600} = {}) => {
    stage.getBoundingClientRect = () => ({
        left,
        top,
        right: left + width,
        bottom: top + height,
        width,
        height,
        x: left,
        y: top,
        toJSON: () => ({}),
    });

    Object.defineProperty(stage, 'clientWidth', {value: width, configurable: true});
    Object.defineProperty(stage, 'clientHeight', {value: height, configurable: true});

    // pointer.js เรียกสองเมธอดนี้แบบเผื่อไม่มี แต่ jsdom มี Element.prototype
    // ที่โยน error จึงต้องแทนที่ด้วยตัวเปล่า ไม่ใช่ปล่อยให้เรียกของจริง
    stage.setPointerCapture = () => {};
    stage.releasePointerCapture = () => {};
};
