/*
 * กล้องของผืนผ้าใบ — แปลงพิกัดระหว่างหน้าจอกับพิกัดโลก และคุมการเลื่อน/ซูม
 *
 * โมดูลนี้ไม่แตะ DOM เลย ทั้งชั้น SVG และชั้น overlay ของกระดาษโน้ตอ่านค่าจาก
 * กล้องตัวเดียวกัน สองชั้นจึงเลื่อนและซูมพร้อมกันเสมอ ไม่มีทางเคลื่อนออกจากกัน
 *
 * กล้องเป็นค่าที่ไม่เปลี่ยนแปลงในที่ (immutable) ทุกฟังก์ชันคืนกล้องตัวใหม่
 * เพื่อให้เปรียบเทียบสถานะก่อน/หลังได้ง่ายและทดสอบได้ตรงไปตรงมา
 */

export const createCamera = ({x = 0, y = 0, scale = 1} = {}) => ({x, y, scale});

/**
 * บีบระดับการซูมให้อยู่ในช่วงที่ใช้งานได้จริง
 *
 * ซูมออกไกลเกินไปทำให้ชิ้นงานเล็กจนคลิกไม่โดน ส่วนซูมเข้ามากเกินไปทำให้
 * ผู้ใช้หลงทางบนผืนผ้าใบที่ไม่มีจุดอ้างอิง
 */
export const clampScale = (scale, {minScale, maxScale}) =>
    Math.min(maxScale, Math.max(minScale, scale));

/** พิกัดหน้าจอ (เทียบกับมุมซ้ายบนของ stage) → พิกัดโลก */
export const screenToWorld = (camera, point) => ({
    x: (point.x - camera.x) / camera.scale,
    y: (point.y - camera.y) / camera.scale,
});

/** พิกัดโลก → พิกัดหน้าจอ */
export const worldToScreen = (camera, point) => ({
    x: point.x * camera.scale + camera.x,
    y: point.y * camera.scale + camera.y,
});

/** เลื่อนกล้องตามระยะที่ลากบนหน้าจอ */
export const panBy = (camera, dx, dy) => ({...camera, x: camera.x + dx, y: camera.y + dy});

/**
 * ซูมโดยตรึงจุดอ้างอิงบนหน้าจอไว้กับที่
 *
 * นี่คือพฤติกรรมที่ทำให้การซูมด้วยล้อเมาส์และการหุบนิ้วรู้สึกถูกต้อง จุดที่อยู่
 * ใต้เคอร์เซอร์หรือกึ่งกลางระหว่างสองนิ้วต้องไม่ขยับ ถ้าไม่ตรึง ภาพจะไหลหนี
 * ทุกครั้งที่ซูมและผู้ใช้ต้องคอยลากกลับ
 */
export const zoomAt = (camera, screenPoint, factor, limits) => {
    const scale = clampScale(camera.scale * factor, limits);

    // ถ้าชนเพดานแล้ว อย่าขยับตำแหน่ง ไม่งั้นภาพจะเลื่อนทั้งที่ระดับซูมเท่าเดิม
    if (scale === camera.scale) {
        return camera;
    }

    const world = screenToWorld(camera, screenPoint);

    return {
        x: screenPoint.x - world.x * scale,
        y: screenPoint.y - world.y * scale,
        scale,
    };
};

/** ตั้งระดับซูมโดยตรึงจุดกึ่งกลางของพื้นที่แสดงผล (ปุ่มซูมเข้า/ออกบนแถบเครื่องมือ) */
export const zoomToCenter = (camera, viewport, factor, limits) =>
    zoomAt(camera, {x: viewport.width / 2, y: viewport.height / 2}, factor, limits);

/**
 * จัดกล้องให้เห็นกรอบที่กำหนดพอดีจอ พร้อมระยะขอบ
 *
 * คืนกล้องเดิมเมื่อกรอบไม่มีขนาด เช่นกระดานเปล่า เพราะการหารด้วยศูนย์จะทำให้
 * ระดับซูมกลายเป็น Infinity แล้วผืนผ้าใบหายไปทั้งหน้า
 */
export const fitToBounds = (camera, bounds, viewport, limits, padding = 48) => {
    if (! bounds || bounds.w <= 0 || bounds.h <= 0) {
        return camera;
    }

    const usableWidth = Math.max(1, viewport.width - padding * 2);
    const usableHeight = Math.max(1, viewport.height - padding * 2);
    const scale = clampScale(Math.min(usableWidth / bounds.w, usableHeight / bounds.h), limits);

    return {
        x: viewport.width / 2 - (bounds.x + bounds.w / 2) * scale,
        y: viewport.height / 2 - (bounds.y + bounds.h / 2) * scale,
        scale,
    };
};

/** ค่า transform สำหรับกลุ่ม <g> ของชั้น SVG */
export const svgTransform = (camera) =>
    `translate(${camera.x} ${camera.y}) scale(${camera.scale})`;

/** ค่า transform ของ CSS สำหรับชั้น overlay ที่เป็น HTML */
export const cssTransform = (camera) =>
    `translate(${camera.x}px, ${camera.y}px) scale(${camera.scale})`;
