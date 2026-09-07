/*
 * เครื่องมือเลือก - เลือก ย้าย ย่อขยาย และลากกรอบเลือกหลายชิ้น
 *
 * เป็นเครื่องมือที่ซับซ้อนที่สุด เพราะการกดลงหนึ่งครั้งอาจหมายถึงสามอย่าง
 * ขึ้นกับว่ากดตรงไหน จึงตัดสินโหมดตั้งแต่ตอน pointerdown แล้วเก็บไว้ใน draft
 * ไม่ตัดสินใหม่ทุกครั้งที่ขยับ ซึ่งจะทำให้โหมดสลับไปมาระหว่างลาก
 *
 * ลำดับการตัดสินสำคัญ: มือจับก่อน แล้วค่อยชิ้นงาน แล้วค่อยที่ว่าง เพราะมือจับ
 * วางอยู่บนขอบของกรอบซึ่งทับกับตัวชิ้นงานพอดี ถ้าเช็คชิ้นงานก่อนจะย่อขยายไม่ได้เลย
 */

import {
    boundsOf,
    boundsFromPoints,
    handleAtPoint,
    pickTopmost,
    pickWithin,
    resizeBounds,
    scaleElementToBounds,
    translateElement,
    unionBounds,
} from '../geometry.js';

/** ระยะผ่อนผันของการคลิกโดน หน่วยพิกเซลบนหน้าจอ */
const HIT_TOLERANCE_PX = 6;

/** ครึ่งหนึ่งของขนาดมือจับ หน่วยพิกเซลบนหน้าจอ */
const HANDLE_RADIUS_PX = 7;

/** ขนาดต่ำสุดของกรอบหลังย่อ กันไม่ให้ชิ้นงานหดจนคลิกกลับมาไม่ได้ */
const MIN_SIZE = 4;

export const selectTool = {
    name: 'select',
    cursor: 'default',

    onPointerDown({point, scene, selection, camera, additive}) {
        const selected = scene.elements.filter((element) => selection.includes(element.id));
        const bounds = unionBounds(selected);

        // 1) มือจับของกรอบที่เลือกอยู่
        if (bounds) {
            const handle = handleAtPoint(bounds, point, HANDLE_RADIUS_PX / camera.scale);

            if (handle) {
                return {
                    draft: {
                        mode: 'resize',
                        handle,
                        origin: point,
                        startBounds: bounds,
                        startElements: selected,
                    },
                };
            }
        }

        // 2) ชิ้นงานที่อยู่ใต้เคอร์เซอร์
        const hit = pickTopmost(scene.elements, point, HIT_TOLERANCE_PX / camera.scale);

        if (hit) {
            const nextSelection = nextSelectionFor(selection, hit.id, additive);
            const moving = scene.elements.filter((element) => nextSelection.includes(element.id));

            return {
                selection: nextSelection,
                draft: {mode: 'move', origin: point, startElements: moving, moved: false},
            };
        }

        // 3) ที่ว่าง - เริ่มลากกรอบเลือก
        return {
            selection: additive ? selection : [],
            draft: {mode: 'marquee', origin: point, base: additive ? selection : []},
        };
    },

    onPointerMove({point, scene, draft, replaceElements}) {
        if (! draft) {
            return undefined;
        }

        if (draft.mode === 'marquee') {
            const box = boundsFromPoints(draft.origin, point);
            const inside = pickWithin(scene.elements, box).map((element) => element.id);

            return {
                selection: [...new Set([...draft.base, ...inside])],
                preview: {id: 'marquee', type: 'marquee', ...box},
            };
        }

        if (draft.mode === 'move') {
            const dx = point.x - draft.origin.x;
            const dy = point.y - draft.origin.y;
            const moved = draft.startElements.map((element) => translateElement(element, dx, dy));

            return {
                scene: replaceElements(scene, moved),
                draft: {...draft, moved: dx !== 0 || dy !== 0},
            };
        }

        const target = clampSize(resizeBounds(
            draft.startBounds,
            draft.handle,
            point.x - draft.origin.x,
            point.y - draft.origin.y
        ));

        const resized = draft.startElements.map(
            (element) => scaleElementToBounds(element, draft.startBounds, target)
        );

        return {scene: replaceElements(scene, resized), draft: {...draft, moved: true}};
    },

    onPointerUp({draft}) {
        // กรอบเลือกไม่ได้เปลี่ยนเนื้อหา จึงไม่กินก้าว undo และการคลิกเลือกเฉย ๆ
        // ก็เช่นกัน มีเฉพาะการย้ายหรือย่อขยายที่เกิดขึ้นจริงเท่านั้นที่บันทึก
        return {draft: null, preview: null, commit: Boolean(draft?.moved)};
    },
};

/**
 * รายการที่เลือกหลังคลิกโดนชิ้นงานหนึ่งชิ้น
 *
 * additive คือกดปุ่ม Shift ค้าง ซึ่งสลับสถานะของชิ้นนั้นเข้า/ออกจากกลุ่ม
 * การคลิกธรรมดาบนชิ้นที่เลือกอยู่แล้วต้องไม่ล้างกลุ่มทิ้ง ไม่งั้นการลากกลุ่ม
 * จะเป็นไปไม่ได้ เพราะการกดลงเพื่อเริ่มลากจะเหลือชิ้นเดียวเสมอ
 */
const nextSelectionFor = (selection, id, additive) => {
    if (additive) {
        return selection.includes(id)
            ? selection.filter((current) => current !== id)
            : [...selection, id];
    }

    return selection.includes(id) ? selection : [id];
};

const clampSize = (bounds) => ({
    ...bounds,
    w: Math.max(MIN_SIZE, bounds.w),
    h: Math.max(MIN_SIZE, bounds.h),
});

/** กรอบรวมของชิ้นที่เลือก ใช้โดยชั้นแสดงมือจับ */
export const selectionBounds = (scene, selection) =>
    unionBounds(scene.elements.filter((element) => selection.includes(element.id)));

export {boundsOf};
