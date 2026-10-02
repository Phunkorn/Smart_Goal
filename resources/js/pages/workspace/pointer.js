/*
 * รับเหตุการณ์จากตัวชี้ (เมาส์ ปากกา นิ้ว) แล้วแปลงเป็นเหตุการณ์ระดับสูง
 *
 * หน้าที่ของโมดูลนี้มีสามอย่าง
 * 1. แปลงพิกัดหน้าจอเป็นพิกัดของ stage แล้วส่งต่อทั้งพิกัดหน้าจอและพิกัดโลก
 * 2. จัดการหลายนิ้วพร้อมกัน เพื่อทำการเลื่อนสองนิ้วและการหุบนิ้วซูม
 * 3. รวมล้อเมาส์เข้ามาเป็นการซูมและการเลื่อนด้วย
 *
 * ไม่ตัดสินใจอะไรเกี่ยวกับเนื้อหากระดานเลย แค่บอกว่า "มีคนกดที่ตรงนี้"
 *
 * jsdom ไม่มี PointerEvent และโยน error เมื่อเรียก setPointerCapture โค้ดจึงต้อง
 * เรียกแบบเผื่อไม่มี (optional call) และเทสต์ใช้ตัวช่วยใน tests/js/helpers/pointer.js
 * สร้างเหตุการณ์ขึ้นมาจาก MouseEvent แทน
 */

import {screenToWorld, zoomAt} from './camera.js';
import {isEditableNode} from './keyboard.js';

/** ระยะที่ล้อเมาส์หนึ่งหน่วยแปลงเป็นตัวคูณการซูม */
const WHEEL_ZOOM_STEP = 0.0015;

export const initPointer = (stage, {
    onDown,
    onMove,
    onUp,
    onZoom,
    onPan,
    getCamera,
    claimsEditableTarget = () => false,
}) => {
    // นิ้วที่กำลังแตะอยู่ ณ ขณะนั้น เก็บเป็น Map เพราะต้องรู้ว่าแต่ละนิ้วอยู่ที่ไหน
    // ตอนคำนวณระยะห่างสำหรับการหุบนิ้ว
    const active = new Map();
    let pinch = null;

    // เหตุการณ์ตัวชี้ล่าสุดของท่าลาก ใช้ส่งการขยับซ้ำเมื่อผู้ใช้กดหรือปล่อย Shift
    // ขณะที่เมาส์นิ่งอยู่ (ดู handleShiftChange)
    let lastPointerEvent = null;

    const stagePoint = (event) => {
        const rect = stage.getBoundingClientRect();

        return {x: event.clientX - rect.left, y: event.clientY - rect.top};
    };

    /*
     * Shift ถูกส่งออกไปสองชื่อตามเจตนา ไม่ใช่ตามปุ่ม
     *   additive  - ตอนคลิก: เพิ่ม/ถอนชิ้นนั้นออกจากกลุ่มที่เลือก
     *   constrain - ตอนลาก: ล็อกมุมของเส้น/การหมุน ล็อกสี่เหลี่ยม-วงรีให้เป็นจัตุรัส-วงกลม
     *               และล็อกสัดส่วนเดิมไว้ขณะย่อขยาย
     * เครื่องมือแต่ละตัวอ่านเฉพาะชื่อที่ตรงกับสิ่งที่มันทำ ทั้งสองจึงไม่ชนกัน
     */
    const contextFor = (event, shiftKey = event.shiftKey === true) => {
        const screenPoint = stagePoint(event);

        return {
            screenPoint,
            point: screenToWorld(getCamera(), screenPoint),
            additive: shiftKey,
            constrain: shiftKey,
            pointerId: event.pointerId,
        };
    };

    const handleDown = (event) => {
        // ปุ่มขวาและปุ่มกลางไม่ควรเริ่มการวาด ปล่อยให้เมนูของเบราว์เซอร์ทำงานไป
        if (event.button !== undefined && event.button !== 0) {
            return;
        }

        // กดลงในกล่องข้อความที่กำลังแก้ไขอยู่ (contenteditable) ต้องปล่อยให้
        // เบราว์เซอร์ลากเลือกข้อความเองทั้งหมด ไม่ใช่ตามด้วย setPointerCapture
        // ของเรา เพราะการจับพอยน์เตอร์ไว้ที่ stage จะแย่งพอยน์เตอร์ไปจากกล่อง
        // กลางท่าลาก ทำให้ลากเลือกข้อความไม่ได้ และเครื่องมือเลือกจะเริ่มลาก
        // ย้ายทั้งกล่อง (หรือทั้งกลุ่มที่เลือกอยู่) แทนไปพร้อมกัน
        //
        // ยกเว้นเครื่องมือที่ไม่ได้ทำงานกับเนื้อหาในกล่องเลย (มือเลื่อนกระดาน)
        // ผู้เรียกเป็นคนตอบว่าเครื่องมือที่ถืออยู่ขอท่านี้ไหม โมดูลนี้จึงยังไม่
        // ต้องรู้จักชื่อเครื่องมือใด ๆ ถ้าไม่มีข้อยกเว้นนี้ กล่องที่เปิดโหมด
        // แก้ไขค้างอยู่หนึ่งใบจะกลายเป็นหลุมที่เลื่อนกระดานไม่ได้ และผู้ใช้ต้อง
        // ไปคลิกที่อื่นให้กล่องปิดก่อนทุกครั้ง
        if (isEditableNode(event.target) && ! claimsEditableTarget()) {
            return;
        }

        active.set(event.pointerId, stagePoint(event));

        if (active.size === 2) {
            // นิ้วที่สองแตะลงมา ยกเลิกท่าทางที่นิ้วแรกเริ่มไว้ แล้วเปลี่ยนเป็น
            // การเลื่อน/ซูมสองนิ้วแทน ถ้าไม่ยกเลิก จะได้เส้นดินสอค้างไว้หนึ่งเส้น
            // ทุกครั้งที่ผู้ใช้ตั้งใจจะซูม
            pinch = pinchStateFrom(active, getCamera());
            onUp?.({...contextFor(event), cancelled: true});

            return;
        }

        if (active.size > 2) {
            return;
        }

        stage.setPointerCapture?.(event.pointerId);
        lastPointerEvent = event;
        onDown?.(contextFor(event));
    };

    const handleMove = (event) => {
        if (! active.has(event.pointerId)) {
            return;
        }

        active.set(event.pointerId, stagePoint(event));

        if (pinch && active.size === 2) {
            applyPinch(pinch, active, {onZoom, onPan});

            return;
        }

        lastPointerEvent = event;
        onMove?.(contextFor(event));
    };

    /*
     * กดหรือปล่อย Shift กลางท่าลากโดยไม่ขยับเมาส์ ต้องเห็นเส้นล็อกหรือปลดทันที
     * ไม่ใช่รอจนกว่าจะขยับเมาส์อีกนิด ซึ่งทำให้ผู้ใช้คิดว่า Shift ไม่ทำงาน
     */
    const handleShiftChange = (event) => {
        if (event.key !== 'Shift' || pinch || active.size !== 1 || ! lastPointerEvent) {
            return;
        }

        onMove?.(contextFor(lastPointerEvent, event.type === 'keydown'));
    };

    const handleUp = (event) => {
        if (! active.has(event.pointerId)) {
            return;
        }

        active.delete(event.pointerId);
        stage.releasePointerCapture?.(event.pointerId);

        if (active.size === 0) {
            lastPointerEvent = null;
        }

        if (pinch) {
            // ยังไม่กลับไปวาดต่อจนกว่านิ้วจะยกหมด ไม่งั้นนิ้วที่เหลือค้างอยู่
            // จะกลายเป็นการเริ่มลากเส้นใหม่ทันทีที่ยกอีกนิ้ว
            if (active.size === 0) {
                pinch = null;
            }

            return;
        }

        onUp?.(contextFor(event));
    };

    const handleWheel = (event) => {
        event.preventDefault();

        const screenPoint = stagePoint(event);

        // Ctrl + ล้อ เป็นท่าหุบนิ้วบนแทร็กแพด เบราว์เซอร์ส่งมาเป็น wheel เสมอ
        if (event.ctrlKey || event.metaKey) {
            onZoom?.({screenPoint, factor: Math.exp(-event.deltaY * WHEEL_ZOOM_STEP * 4)});

            return;
        }

        // ล้อเปล่าเลื่อนกระดาน (แนวนอนเมื่อกด Shift) ซึ่งเป็นพฤติกรรมของ
        // โปรแกรมกระดานทั่วไป การให้ล้อเปล่าซูมจะทำให้เลื่อนดูงานยาว ๆ ไม่ได้
        onPan?.({dx: -event.deltaX, dy: -event.deltaY});
    };

    stage.addEventListener('pointerdown', handleDown);
    stage.addEventListener('pointermove', handleMove);
    stage.addEventListener('pointerup', handleUp);
    stage.addEventListener('pointercancel', handleUp);
    stage.addEventListener('pointerleave', handleUp);
    stage.addEventListener('wheel', handleWheel, {passive: false});
    stage.ownerDocument.addEventListener('keydown', handleShiftChange);
    stage.ownerDocument.addEventListener('keyup', handleShiftChange);

    return {
        destroy() {
            stage.ownerDocument.removeEventListener('keydown', handleShiftChange);
            stage.ownerDocument.removeEventListener('keyup', handleShiftChange);
            stage.removeEventListener('pointerdown', handleDown);
            stage.removeEventListener('pointermove', handleMove);
            stage.removeEventListener('pointerup', handleUp);
            stage.removeEventListener('pointercancel', handleUp);
            stage.removeEventListener('pointerleave', handleUp);
            stage.removeEventListener('wheel', handleWheel);
        },
    };
};

const pinchStateFrom = (active, camera) => {
    const [a, b] = Array.from(active.values());

    return {
        distance: Math.hypot(b.x - a.x, b.y - a.y),
        center: {x: (a.x + b.x) / 2, y: (a.y + b.y) / 2},
        camera,
    };
};

const applyPinch = (pinch, active, {onZoom, onPan}) => {
    const [a, b] = Array.from(active.values());
    const distance = Math.hypot(b.x - a.x, b.y - a.y);
    const center = {x: (a.x + b.x) / 2, y: (a.y + b.y) / 2};

    onPan?.({dx: center.x - pinch.center.x, dy: center.y - pinch.center.y});

    // นิ้วสองนิ้วที่ทับกันพอดีทำให้ระยะเป็นศูนย์ การหารจะได้ Infinity แล้วกล้องพัง
    if (pinch.distance > 0 && distance > 0) {
        onZoom?.({screenPoint: center, factor: distance / pinch.distance});
    }

    pinch.distance = distance;
    pinch.center = center;
};

/** ตัวช่วยสำหรับปุ่มซูมบนแถบเครื่องมือ ใช้ตรรกะเดียวกับการหุบนิ้ว */
export const zoomFromButton = (camera, viewport, factor, limits) =>
    zoomAt(camera, {x: viewport.width / 2, y: viewport.height / 2}, factor, limits);
