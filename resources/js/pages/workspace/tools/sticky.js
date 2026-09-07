/*
 * กระดาษโน้ตและกล่องข้อความ
 *
 * ต่างจากรูปทรงตรงที่ "แตะครั้งเดียวก็สร้างได้" ด้วยขนาดตั้งต้น ไม่ต้องลากกรอบ
 * เพราะการระดมสมองคือการแปะโน้ตรัว ๆ การบังคับให้ลากกรอบทุกครั้งจะทำให้ช้าลง
 * โดยไม่ได้อะไร ผู้ใช้ที่อยากได้ขนาดเฉพาะยังลากได้เหมือนเดิม
 *
 * ทั้งสองใช้ตัวสร้างเดียวกันเพราะท่าทางเหมือนกันทุกประการ ต่างกันแค่ค่าตั้งต้น
 */

import {boundsFromPoints} from '../geometry.js';

/**
 * ขนาดกล่องตั้งต้นเมื่อแตะครั้งเดียวโดยไม่ลาก (หน่วยพิกัดโลก)
 *
 * ขนาดตัวอักษรไม่อยู่ที่นี่ เพราะเป็นค่าที่ผู้ใช้เลือกจากแถบเครื่องมือได้
 * และต้องมีผลกับกล่องใบถัดไปทันที ค่าตั้งต้นมาจาก WorkspaceDesign
 */
const DEFAULTS = {
    sticky: {w: 180, h: 180},
    text: {w: 240, h: 60},
};

/** ระยะที่ถือว่าเป็นการลาก ไม่ใช่การแตะ (พิกเซลบนหน้าจอ) */
const DRAG_THRESHOLD_PX = 6;

export const textElementTool = (type) => ({
    name: type,
    cursor: 'text',

    onPointerDown({point}) {
        return {draft: {origin: point}, selection: []};
    },

    onPointerMove({point, draft}) {
        if (! draft) {
            return undefined;
        }

        const box = boundsFromPoints(draft.origin, point);

        return {
            preview: {
                id: 'preview',
                type: 'rect',
                z: 0,
                ...box,
                stroke: '#94a3b8',
                strokeWidth: 1,
                fill: 'none',
            },
        };
    },

    onPointerUp({point, draft, camera, scene, style, idFactory, addElement}) {
        if (! draft) {
            return {draft: null};
        }

        const defaults = DEFAULTS[type];
        const dragged = boundsFromPoints(draft.origin, point);
        const threshold = DRAG_THRESHOLD_PX / camera.scale;
        const useDrag = dragged.w > threshold && dragged.h > threshold;

        const box = useDrag
            ? dragged
            : {x: draft.origin.x, y: draft.origin.y, w: defaults.w, h: defaults.h};

        const element = {
            id: idFactory(),
            type,
            z: 0,
            ...box,
            text: '',
            fontSize: style.fontSize,
            ...(type === 'sticky'
                ? {fill: style.stickyColor || '#fde68a'}
                : {color: style.stroke}),
        };

        return {
            scene: addElement(scene, element),
            selection: [element.id],
            draft: null,
            preview: null,
            commit: true,
            // ให้ index.js ย้ายโฟกัสไปที่กล่องใหม่ ผู้ใช้จะได้พิมพ์ต่อได้ทันที
            // โดยไม่ต้องคลิกซ้ำ ซึ่งเป็นจังหวะที่สำคัญมากตอนระดมสมอง
            focusElement: element.id,
        };
    },
});

export const stickyTool = textElementTool('sticky');
export const textTool = textElementTool('text');
