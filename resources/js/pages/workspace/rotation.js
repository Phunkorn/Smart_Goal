/*
 * มุมหมุนของชิ้นงาน - คณิตศาสตร์ของจุดและองศาล้วน ๆ ไม่แตะ DOM
 *
 * ชิ้นงานสองกลุ่มหมุนต่างกันโดยตั้งใจ
 *
 *  - ชิ้นที่อธิบายด้วยกรอบ (สี่เหลี่ยม วงกลม โน้ต ข้อความ รูปภาพ) เก็บมุมไว้ใน
 *    rotation เป็นองศา หมุนรอบจุดกึ่งกลางของกรอบ เพราะกรอบที่เอียงแปลงกลับ
 *    เป็นกรอบตั้งตรงไม่ได้โดยไม่เสียรูปร่าง
 *  - ชิ้นที่อธิบายด้วยจุด (ดินสอ เส้นตรง ลูกศร) หมุนพิกัดของจุดไปเลยโดยไม่เก็บมุม
 *    จุดที่หมุนแล้วก็ยังเป็นจุดธรรมดา การตรวจการชน กรอบ และการบันทึกของ
 *    ชิ้นกลุ่มนี้จึงไม่ต้องรู้จักการหมุนเลย (ดู rotateElement ใน geometry.js)
 *
 * องศาบวกคือหมุนตามเข็มนาฬิกาบนจอ ซึ่งตรงกับ rotate() ของทั้ง SVG และ CSS
 * เพราะแกน y ของทั้งสองชี้ลง ตัวเรนเดอร์จึงส่งค่านี้ต่อได้ตรง ๆ
 *
 * เอกสารเก่าที่ไม่มี rotation อ่านเป็น 0 และมุม 0 ถูกลบคีย์ทิ้ง เอกสารที่ไม่ได้
 * หมุนอะไรจึงมีหน้าตาเหมือนก่อนมีฟีเจอร์นี้ทุกประการ
 *
 * รายการชนิดต้องตรงกับ WorkspaceDesign::ROTATABLE_ELEMENT_TYPES ฝั่งเซิร์ฟเวอร์
 * (มีเทสต์สัญญาตรวจอยู่) ไม่งั้นมุมที่หมุนไว้จะถูกตัวกรองตัดทิ้งเงียบ ๆ
 */

export const ROTATABLE_BOX_TYPES = ['rect', 'ellipse', 'sticky', 'text', 'image'];

/** ระยะก้าวของมุมเมื่อกด Shift ค้างระหว่างหมุน */
export const ROTATION_SNAP_DEGREES = 15;

export const storesRotation = (type) => ROTATABLE_BOX_TYPES.includes(type);

/** บีบมุมให้อยู่ในช่วง [0, 360) ปัดสองตำแหน่งเท่ากับที่เซิร์ฟเวอร์เก็บ */
export const normalizeDegrees = (degrees) => {
    if (! Number.isFinite(degrees)) {
        return 0;
    }

    const rounded = Math.round((((degrees % 360) + 360) % 360) * 100) / 100;

    // 359.999 ปัดแล้วกลายเป็น 360 ซึ่งคือมุมเดียวกับ 0
    return rounded >= 360 ? 0 : rounded;
};

export const rotationOf = (element) =>
    (storesRotation(element.type) ? normalizeDegrees(Number(element.rotation) || 0) : 0);

/** คืนชิ้นงานที่มีมุมนี้ มุม 0 ลบคีย์ทิ้งแทนการเก็บเลขศูนย์ */
export const withRotation = (element, degrees) => {
    const {rotation: _previous, ...rest} = element;
    const rotation = normalizeDegrees(degrees);

    return rotation === 0 ? rest : {...rest, rotation};
};

export const rotatePoint = (point, center, degrees) => {
    if (! degrees) {
        return point;
    }

    const radians = (degrees * Math.PI) / 180;
    const cos = Math.cos(radians);
    const sin = Math.sin(radians);
    const dx = point.x - center.x;
    const dy = point.y - center.y;

    return {
        x: center.x + dx * cos - dy * sin,
        y: center.y + dx * sin + dy * cos,
    };
};

/** มุมของจุดเมื่อมองจากจุดศูนย์กลาง (องศา) ใช้วัดว่าลากมือจับหมุนไปเท่าไร */
export const angleAround = (center, point) =>
    (Math.atan2(point.y - center.y, point.x - center.x) * 180) / Math.PI;

export const snapDegrees = (degrees, step = ROTATION_SNAP_DEGREES) =>
    Math.round(degrees / step) * step;
