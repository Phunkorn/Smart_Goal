/*
 * เครื่องมือเลือก - เลือก ย้าย ย่อขยาย หมุน และลากกรอบเลือกหลายชิ้น
 *
 * เป็นเครื่องมือที่ซับซ้อนที่สุด เพราะการกดลงหนึ่งครั้งอาจหมายถึงหลายอย่าง
 * ขึ้นกับว่ากดตรงไหน จึงตัดสินโหมดตั้งแต่ตอน pointerdown แล้วเก็บไว้ใน draft
 * ไม่ตัดสินใหม่ทุกครั้งที่ขยับ ซึ่งจะทำให้โหมดสลับไปมาระหว่างลาก
 *
 * ลำดับการตัดสินสำคัญ: มือจับก่อน แล้วค่อยชิ้นงาน แล้วค่อยที่ว่าง เพราะมือจับ
 * วางอยู่บนขอบของกรอบซึ่งทับกับตัวชิ้นงานพอดี ถ้าเช็คชิ้นงานก่อนจะย่อขยายไม่ได้เลย
 *
 * ผู้ที่ดูอย่างเดียวใช้เครื่องมือนี้ได้เพื่อชี้ชิ้นงานให้เพื่อนดู แต่ย้าย ย่อขยาย
 * หรือหมุนไม่ได้ เพราะการเปลี่ยนที่เกิดเฉพาะบนจอของเขาเองจะทำให้เข้าใจผิดว่า
 * แก้กระดานได้ ทั้งที่ไม่มีทางบันทึก
 */

import {
    HIT_TOLERANCE_PX,
    boundsFromPoints,
    pickTopmost,
    pickWithin,
    rotateElement,
    translateElement,
} from '../geometry.js';
import {angleAround, normalizeDegrees, snapDegrees} from '../rotation.js';
import {
    ROTATION_HANDLE_OFFSET_PX,
    fitElementToFrame,
    frameCenter,
    frameOf,
    frameTargetAt,
    resizeFrame,
} from '../selection-frame.js';

/** ครึ่งหนึ่งของขนาดมือจับ หน่วยพิกเซลบนหน้าจอ */
const HANDLE_RADIUS_PX = 7;

/**
 * รัศมีที่กดโดนมือจับหมุน กว้างกว่าวงกลมที่วาด (6px) โดยตั้งใจ มือจับนี้ลอยอยู่
 * นอกกรอบโดยไม่มีอะไรทับ จึงให้พื้นที่กดเผื่อได้โดยไม่ไปแย่งการคลิกของใคร
 */
const ROTATION_HANDLE_RADIUS_PX = 11;

/** ขนาดต่ำสุดของกรอบหลังย่อ กันไม่ให้ชิ้นงานหดจนคลิกกลับมาไม่ได้ */
const MIN_SIZE = 4;

export const selectTool = {
    name: 'select',
    cursor: 'default',

    onPointerDown({point, scene, selection, camera, additive, canEdit = true}) {
        const selected = scene.elements.filter((element) => selection.includes(element.id));
        const frame = frameOf(selected);

        // 1) มือจับของกรอบที่เลือกอยู่ (หมุนหรือย่อขยาย)
        if (frame && canEdit) {
            const target = frameTargetAt(frame, point, {
                handleRadius: HANDLE_RADIUS_PX / camera.scale,
                rotationRadius: ROTATION_HANDLE_RADIUS_PX / camera.scale,
                rotationOffset: ROTATION_HANDLE_OFFSET_PX / camera.scale,
            });

            if (target === 'rotate') {
                const pivot = frameCenter(frame);

                return {
                    draft: {
                        mode: 'rotate',
                        pivot,
                        startAngle: angleAround(pivot, point),
                        startFrame: frame,
                        startElements: selected,
                        liveFrame: frame,
                        moved: false,
                    },
                };
            }

            if (target) {
                return {
                    draft: {
                        mode: 'resize',
                        handle: target,
                        origin: point,
                        startFrame: frame,
                        startElements: selected,
                    },
                };
            }
        }

        // 2) ชิ้นงานที่อยู่ใต้เคอร์เซอร์
        const hit = pickTopmost(scene.elements, point, HIT_TOLERANCE_PX / camera.scale);

        if (hit) {
            const nextSelection = nextSelectionFor(selection, hit.id, additive);

            if (! canEdit) {
                return {selection: nextSelection};
            }

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

    onPointerMove({point, scene, draft, constrain, replaceElements}) {
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

        if (draft.mode === 'rotate') {
            return rotateSelection(draft, point, constrain, scene, replaceElements);
        }

        const target = resizeFrame(draft.startFrame, draft.handle, draft.origin, point, MIN_SIZE);
        const resized = draft.startElements.map(
            (element) => fitElementToFrame(element, draft.startFrame, target)
        );

        return {scene: replaceElements(scene, resized), draft: {...draft, moved: true}};
    },

    onPointerUp({draft}) {
        // กรอบเลือกไม่ได้เปลี่ยนเนื้อหา จึงไม่กินก้าว undo และการคลิกเลือกเฉย ๆ
        // ก็เช่นกัน มีเฉพาะการย้าย ย่อขยาย หรือหมุนที่เกิดขึ้นจริงเท่านั้นที่บันทึก
        return {draft: null, preview: null, commit: Boolean(draft?.moved)};
    },
};

/**
 * หมุนชิ้นที่เลือกตามมุมที่ลากมือจับไปรอบจุดกึ่งกลางของกรอบ
 *
 * หมุนจากสถานะตอนเริ่มลากเสมอ ไม่ใช่หมุนต่อจากเฟรมก่อน ความคลาดเคลื่อนจาก
 * การปัดเศษจึงไม่สะสมไม่ว่าจะลากวนกี่รอบ
 *
 * Shift ล็อกมุมสุดท้ายของกรอบไว้ทีละ 15 องศา (ไม่ใช่ล็อกระยะที่หมุนเพิ่ม)
 * ชิ้นที่เอียงอยู่ 7 องศาจึงยังกลับมาตั้งตรงพอดีได้
 */
const rotateSelection = (draft, point, constrain, scene, replaceElements) => {
    const startRotation = draft.startFrame.rotation;
    let delta = angleAround(draft.pivot, point) - draft.startAngle;

    if (constrain) {
        delta = snapDegrees(startRotation + delta) - startRotation;
    }

    const rotated = draft.startElements.map((element) => rotateElement(element, draft.pivot, delta));

    return {
        scene: replaceElements(scene, rotated),
        draft: {
            ...draft,
            // กรอบหมุนตามไปด้วยระหว่างลาก ไม่ใช่คำนวณใหม่จากชิ้นงาน ไม่งั้นกรอบ
            // ของกลุ่มหรือเส้นดินสอจะยืดหดทุกเฟรมจนผู้ใช้มองไม่ออกว่าหมุนไปเท่าไร
            liveFrame: {...draft.startFrame, rotation: normalizeDegrees(startRotation + delta)},
            moved: normalizeDegrees(delta) !== 0,
        },
    };
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
