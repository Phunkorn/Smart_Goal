/*
 * เรขาคณิตของชิ้นงานบนกระดาน — กรอบ การชน และการย่อขยาย
 *
 * ทั้งหมดเป็นฟังก์ชันบริสุทธิ์ที่คำนวณจากตัวแบบข้อมูลโดยตรง ไม่พึ่ง DOM
 *
 * ตั้งใจไม่ใช้ elementFromPoint, getBBox หรือ isPointInStroke ของเบราว์เซอร์
 * ด้วยเหตุผลสองข้อ (1) jsdom ที่ใช้ทดสอบไม่มีทั้งสามอย่างและไม่คำนวณ layout
 * เลย ถ้าพึ่งมันจะเหลือโค้ดเส้นทางที่ไม่เคยถูกทดสอบ (2) การคำนวณจากตัวแบบทำให้
 * ผลลัพธ์เหมือนกันทุกเบราว์เซอร์ ไม่ขึ้นกับว่าเรนเดอร์ไปแล้วหรือยัง
 *
 * ชิ้นที่หมุนอยู่ถูกตรวจการชนโดยหมุนจุดที่คลิกกลับเข้าไปในพิกัดของชิ้นงานก่อน
 * สิ่งที่ตาเห็นกับสิ่งที่คลิกโดนจึงเป็นรูปร่างเดียวกันเสมอ
 */

import {rotatePoint, rotationOf, withRotation} from './rotation.js';

/**
 * กรอบของชิ้นงานก่อนหมุน (ยังไม่รวม rotation) ใช้กับชิ้นที่อธิบายด้วยกรอบ
 *
 * เส้นตรงกับลูกศรเก็บ w และ h ติดลบได้เพราะต้องจำทิศทาง และระหว่างลากสร้าง
 * ค่าชั่วคราวก็ติดลบได้ จึงทำให้กรอบเป็นบวกเสมอที่นี่
 */
export const localBoxOf = (element) => ({
    x: element.w < 0 ? element.x + element.w : element.x,
    y: element.h < 0 ? element.y + element.h : element.y,
    w: Math.abs(element.w),
    h: Math.abs(element.h),
});

export const centerOf = (box) => ({x: box.x + box.w / 2, y: box.y + box.h / 2});

/** มุมทั้งสี่ของกรอบหลังหมุนรอบจุดกึ่งกลางของตัวเอง */
export const cornersOf = (box, rotation = 0) => {
    const center = centerOf(box);

    return [
        {x: box.x, y: box.y},
        {x: box.x + box.w, y: box.y},
        {x: box.x + box.w, y: box.y + box.h},
        {x: box.x, y: box.y + box.h},
    ].map((corner) => rotatePoint(corner, center, rotation));
};

/** กรอบสี่เหลี่ยมตั้งตรงที่ล้อมชิ้นงานหนึ่งชิ้น (รวมผลของการหมุนแล้ว) */
export const boundsOf = (element) => {
    if (element.type === 'pen') {
        return strokeBounds(element);
    }

    const box = localBoxOf(element);
    const rotation = rotationOf(element);

    return rotation ? boundsOfPoints(cornersOf(box, rotation)) : box;
};

const boundsOfPoints = (points) => {
    const xs = points.map((point) => point.x);
    const ys = points.map((point) => point.y);
    const minX = Math.min(...xs);
    const minY = Math.min(...ys);

    return {x: minX, y: minY, w: Math.max(...xs) - minX, h: Math.max(...ys) - minY};
};

const strokeBounds = (element) => {
    const half = (element.strokeWidth || 1) / 2;
    let minX = Infinity;
    let minY = Infinity;
    let maxX = -Infinity;
    let maxY = -Infinity;

    element.points.forEach(([x, y]) => {
        minX = Math.min(minX, x);
        minY = Math.min(minY, y);
        maxX = Math.max(maxX, x);
        maxY = Math.max(maxY, y);
    });

    return {
        x: minX - half,
        y: minY - half,
        w: maxX - minX + half * 2,
        h: maxY - minY + half * 2,
    };
};

/** กรอบรวมของหลายชิ้น คืน null เมื่อไม่มีชิ้นงานเลย */
export const unionBounds = (elements) => {
    if (! elements.length) {
        return null;
    }

    let minX = Infinity;
    let minY = Infinity;
    let maxX = -Infinity;
    let maxY = -Infinity;

    elements.forEach((element) => {
        const box = boundsOf(element);
        minX = Math.min(minX, box.x);
        minY = Math.min(minY, box.y);
        maxX = Math.max(maxX, box.x + box.w);
        maxY = Math.max(maxY, box.y + box.h);
    });

    return {x: minX, y: minY, w: maxX - minX, h: maxY - minY};
};

export const boundsContain = (bounds, point) =>
    point.x >= bounds.x
    && point.x <= bounds.x + bounds.w
    && point.y >= bounds.y
    && point.y <= bounds.y + bounds.h;

/** กรอบ a อยู่ในกรอบ b ทั้งหมดหรือไม่ (ใช้กับการลากเลือกเป็นกรอบ) */
export const boundsWithin = (inner, outer) =>
    inner.x >= outer.x
    && inner.y >= outer.y
    && inner.x + inner.w <= outer.x + outer.w
    && inner.y + inner.h <= outer.y + outer.h;

/** ระยะจากจุดถึงส่วนของเส้นตรง */
export const distanceToSegment = (point, a, b) => {
    const dx = b.x - a.x;
    const dy = b.y - a.y;
    const lengthSquared = dx * dx + dy * dy;

    // ส่วนของเส้นที่ยาวเป็นศูนย์ (จุดเดียว) ต้องวัดระยะถึงจุดนั้นตรง ๆ
    // ไม่งั้นจะหารด้วยศูนย์แล้วได้ NaN ซึ่งทำให้การคลิกโดนทุกที่
    if (lengthSquared === 0) {
        return Math.hypot(point.x - a.x, point.y - a.y);
    }

    const t = Math.max(0, Math.min(1, ((point.x - a.x) * dx + (point.y - a.y) * dy) / lengthSquared));

    return Math.hypot(point.x - (a.x + t * dx), point.y - (a.y + t * dy));
};

/**
 * ระยะผ่อนผันของการคลิกโดน หน่วยพิกเซลบนหน้าจอ
 *
 * อยู่ที่นี่เพราะทุกทางที่ "ชี้ไปที่ชิ้นงาน" ต้องผ่อนผันเท่ากัน ทั้งการคลิกเลือก
 * การดับเบิลคลิกเปิดกล่องข้อความ และการคลิกขวา ถ้าแต่ละทางถือค่าของตัวเอง
 * (หรือไม่ผ่อนผันเลย) ผู้ใช้จะเจอว่าคลิกซ้ายโดนเส้นแต่คลิกขวาที่จุดเดียวกัน
 * กลับไม่โดน ซึ่งอธิบายไม่ได้เลยจากมุมของคนใช้
 */
export const HIT_TOLERANCE_PX = 6;

/**
 * จุดนี้อยู่บนชิ้นงานหรือไม่
 *
 * tolerance เป็นระยะผ่อนผันในหน่วยพิกัดโลก ผู้เรียกต้องหารด้วยระดับซูมมาก่อน
 * เพื่อให้ "แตะพลาดได้กี่พิกเซลบนหน้าจอ" คงที่ไม่ว่าจะซูมเข้าหรือออก
 */
export const hitTest = (element, worldPoint, tolerance = 0) => {
    const point = toLocalPoint(element, worldPoint);

    switch (element.type) {
        case 'pen':
            return hitStroke(element, point, tolerance);
        case 'line':
        case 'arrow':
            return distanceToSegment(
                point,
                {x: element.x, y: element.y},
                {x: element.x + element.w, y: element.y + element.h}
            ) <= (element.strokeWidth || 1) / 2 + tolerance;
        case 'ellipse':
            return hitEllipse(element, point, tolerance);
        case 'rect':
            return hitRect(element, point, tolerance);
        default:
            // กระดาษโน้ต ข้อความ และรูปภาพเป็นพื้นทึบ คลิกที่ไหนก็โดน
            return boundsContain(inflate(localBoxOf(element), tolerance), point);
    }
};

/** หมุนจุดที่คลิกกลับเข้าไปในพิกัดของชิ้นงานที่ยังไม่หมุน */
const toLocalPoint = (element, point) => {
    const rotation = rotationOf(element);

    return rotation ? rotatePoint(point, centerOf(localBoxOf(element)), -rotation) : point;
};

const hitStroke = (element, point, tolerance) => {
    const reach = (element.strokeWidth || 1) / 2 + tolerance;
    const points = element.points;

    if (points.length === 1) {
        return Math.hypot(point.x - points[0][0], point.y - points[0][1]) <= reach;
    }

    for (let index = 0; index < points.length - 1; index += 1) {
        const a = {x: points[index][0], y: points[index][1]};
        const b = {x: points[index + 1][0], y: points[index + 1][1]};

        if (distanceToSegment(point, a, b) <= reach) {
            return true;
        }
    }

    return false;
};

const hitRect = (element, point, tolerance) => {
    const box = localBoxOf(element);

    // รูปทรงที่ไม่ได้เติมสีต้องคลิกโดนเฉพาะที่เส้นขอบ ไม่งั้นสี่เหลี่ยมใหญ่ ๆ
    // จะบังทุกอย่างที่อยู่ข้างใต้จนเลือกไม่ได้
    if (element.fill && element.fill !== 'none') {
        return boundsContain(inflate(box, tolerance), point);
    }

    const reach = (element.strokeWidth || 1) / 2 + tolerance;

    return boundsContain(inflate(box, reach), point)
        && ! boundsContain(inflate(box, -reach), point);
};

const hitEllipse = (element, point, tolerance) => {
    const box = localBoxOf(element);
    const rx = box.w / 2;
    const ry = box.h / 2;

    if (rx <= 0 || ry <= 0) {
        return false;
    }

    const cx = box.x + rx;
    const cy = box.y + ry;
    const reach = (element.strokeWidth || 1) / 2 + tolerance;
    const outer = normalizedRadius(point, cx, cy, rx + reach, ry + reach);

    if (outer > 1) {
        return false;
    }

    if (element.fill && element.fill !== 'none') {
        return true;
    }

    const innerRx = Math.max(0, rx - reach);
    const innerRy = Math.max(0, ry - reach);

    if (innerRx === 0 || innerRy === 0) {
        return true;
    }

    return normalizedRadius(point, cx, cy, innerRx, innerRy) >= 1;
};

const normalizedRadius = (point, cx, cy, rx, ry) =>
    ((point.x - cx) / rx) ** 2 + ((point.y - cy) / ry) ** 2;

const inflate = (bounds, amount) => ({
    x: bounds.x - amount,
    y: bounds.y - amount,
    w: bounds.w + amount * 2,
    h: bounds.h + amount * 2,
});

/**
 * ชิ้นงานบนสุดที่อยู่ใต้จุดนี้
 *
 * ไล่จากท้ายรายการมาหน้า เพราะชิ้นที่วาดทีหลังอยู่บนสุดในลำดับการเรนเดอร์
 * ผู้ใช้คาดหวังว่าจะได้ชิ้นที่ตาเห็นอยู่ข้างบน ไม่ใช่ชิ้นที่ถูกบังอยู่ข้างใต้
 */
export const pickTopmost = (elements, point, tolerance = 0) => {
    for (let index = elements.length - 1; index >= 0; index -= 1) {
        if (hitTest(elements[index], point, tolerance)) {
            return elements[index];
        }
    }

    return null;
};

/** ทุกชิ้นที่อยู่ใต้จุดนี้ (ยางลบใช้ตอนลากผ่าน) */
export const pickAll = (elements, point, tolerance = 0) =>
    elements.filter((element) => hitTest(element, point, tolerance));

/** ทุกชิ้นที่อยู่ในกรอบที่ลากเลือก */
export const pickWithin = (elements, bounds) =>
    elements.filter((element) => boundsWithin(boundsOf(element), bounds));

/** ชื่อมือจับทั้งแปดของกรอบการเลือก เรียงตามเข็มนาฬิกาจากซ้ายบน */
export const HANDLES = ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'];

/** ตำแหน่งของมือจับทั้งแปดในพิกัดโลก */
export const handlePositions = (bounds) => {
    const midX = bounds.x + bounds.w / 2;
    const midY = bounds.y + bounds.h / 2;
    const right = bounds.x + bounds.w;
    const bottom = bounds.y + bounds.h;

    return {
        nw: {x: bounds.x, y: bounds.y},
        n: {x: midX, y: bounds.y},
        ne: {x: right, y: bounds.y},
        e: {x: right, y: midY},
        se: {x: right, y: bottom},
        s: {x: midX, y: bottom},
        sw: {x: bounds.x, y: bottom},
        w: {x: bounds.x, y: midY},
    };
};

/**
 * มือจับที่อยู่ใต้จุดนี้ คืน null เมื่อไม่โดนอันไหนเลย
 *
 * radius เป็นหน่วยพิกัดโลกเช่นเดียวกับ tolerance ของ hitTest
 */
export const handleAtPoint = (bounds, point, radius) => {
    const positions = handlePositions(bounds);

    return HANDLES.find((name) => {
        const position = positions[name];

        return Math.abs(point.x - position.x) <= radius && Math.abs(point.y - position.y) <= radius;
    }) || null;
};

/**
 * กรอบใหม่หลังลากมือจับ
 *
 * ปล่อยให้กรอบพลิกด้านได้ระหว่างลาก (ผู้ใช้ลากขอบขวาผ่านขอบซ้ายไป) แล้วค่อย
 * ทำให้เป็นบวกตอนจบ เพราะการล็อกไว้ที่ศูนย์ระหว่างลากทำให้ชิ้นงานค้างและ
 * ผู้ใช้รู้สึกว่าโปรแกรมหน่วง
 */
export const resizeBounds = (bounds, handle, dx, dy) => {
    let {x, y, w, h} = bounds;

    if (handle.includes('w')) {
        x += dx;
        w -= dx;
    }

    if (handle.includes('e')) {
        w += dx;
    }

    if (handle.includes('n')) {
        y += dy;
        h -= dy;
    }

    if (handle.includes('s')) {
        h += dy;
    }

    return normalizeBounds({x, y, w, h});
};

/** ทำให้กรอบมีความกว้างและสูงเป็นบวกเสมอ */
export const normalizeBounds = (bounds) => ({
    x: bounds.w < 0 ? bounds.x + bounds.w : bounds.x,
    y: bounds.h < 0 ? bounds.y + bounds.h : bounds.y,
    w: Math.abs(bounds.w),
    h: Math.abs(bounds.h),
});

/** กรอบจากสองมุมที่ลาก (ใช้ทั้งการสร้างรูปทรงและกรอบเลือก) */
export const boundsFromPoints = (a, b) => normalizeBounds({
    x: a.x,
    y: a.y,
    w: b.x - a.x,
    h: b.y - a.y,
});

/** เลื่อนชิ้นงานหนึ่งชิ้น คืนชิ้นใหม่ ไม่แก้ของเดิม */
export const translateElement = (element, dx, dy) => {
    if (element.type === 'pen') {
        return {...element, points: element.points.map(([x, y]) => [x + dx, y + dy])};
    }

    return {...element, x: element.x + dx, y: element.y + dy};
};

/**
 * ย่อขยายชิ้นงานตามการเปลี่ยนกรอบรวม
 *
 * ใช้อัตราส่วนของกรอบเก่าต่อกรอบใหม่ ทำให้เลือกหลายชิ้นแล้วย่อขยายพร้อมกันได้
 * โดยตำแหน่งสัมพัทธ์ระหว่างชิ้นยังคงเดิม
 *
 * ป้องกันการหารด้วยศูนย์เมื่อกรอบเดิมแบนราบ เช่นเส้นตรงแนวนอนที่ความสูงเป็นศูนย์
 */
export const scaleElementToBounds = (element, from, to) => {
    const scaleX = from.w === 0 ? 1 : to.w / from.w;
    const scaleY = from.h === 0 ? 1 : to.h / from.h;
    const mapX = (x) => to.x + (x - from.x) * scaleX;
    const mapY = (y) => to.y + (y - from.y) * scaleY;

    if (element.type === 'pen') {
        return {...element, points: element.points.map(([x, y]) => [mapX(x), mapY(y)])};
    }

    return {
        ...element,
        x: mapX(element.x),
        y: mapY(element.y),
        w: element.w * scaleX,
        h: element.h * scaleY,
    };
};

/**
 * หมุนชิ้นงานรอบจุดหมุนที่กำหนด คืนชิ้นใหม่ ไม่แก้ของเดิม
 *
 * จุดหมุนเป็นของกลุ่ม ไม่ใช่ของแต่ละชิ้น การเลือกหลายชิ้นแล้วหมุนจึงพาทุกชิ้น
 * วนรอบจุดกึ่งกลางของกลุ่มไปด้วยกัน ไม่ใช่ต่างคนต่างหมุนอยู่กับที่
 *
 * ชิ้นที่อธิบายด้วยจุดถูกหมุนที่ตัวพิกัด ส่วนชิ้นที่อธิบายด้วยกรอบถูกเลื่อนจุด
 * กึ่งกลางไปตามวงแล้วบวกมุมเพิ่ม (ดูเหตุผลที่ rotation.js)
 */
export const rotateElement = (element, pivot, degrees) => {
    const turn = (x, y) => rotatePoint({x, y}, pivot, degrees);

    if (element.type === 'pen') {
        return {
            ...element,
            points: element.points.map(([x, y]) => {
                const point = turn(x, y);

                return [point.x, point.y];
            }),
        };
    }

    if (element.type === 'line' || element.type === 'arrow') {
        const start = turn(element.x, element.y);
        const end = turn(element.x + element.w, element.y + element.h);

        return {...element, x: start.x, y: start.y, w: end.x - start.x, h: end.y - start.y};
    }

    const box = localBoxOf(element);
    const center = rotatePoint(centerOf(box), pivot, degrees);

    return withRotation(
        {...element, x: center.x - box.w / 2, y: center.y - box.h / 2, w: box.w, h: box.h},
        rotationOf(element) + degrees
    );
};

/**
 * จุดปลายที่ถูกล็อกให้กรอบเป็นจัตุรัส — Shift ขณะลากสร้างสี่เหลี่ยมหรือวงกลม
 *
 * ใช้ด้านที่ลากไปไกลกว่าเป็นตัวกำหนดขนาด แล้วคงทิศทางของแต่ละแกนไว้ การลากขึ้นซ้ายจึง
 * ยังได้สี่เหลี่ยมที่มุมซ้ายบนเหมือนเดิม ไม่ใช่กระโดดข้ามไปอยู่มุมตรงข้าม
 *
 * ต่างจาก constrainToAngle ที่ใช้กับเส้นและลูกศร ซึ่งฉายจุดลงบนแกนที่ใกล้ที่สุด
 * เส้นแนวนอนต้องอยู่แนวนอนจริง แต่สี่เหลี่ยมจัตุรัสต้องกว้างเท่าสูงเสมอ แม้เคอร์เซอร์
 * จะอยู่เยื้องมุมก็ตาม จึงเป็นคนละสูตรกับการฉายจุดลงเส้นทแยง
 */
export const constrainToSquare = (origin, point) => {
    const dx = point.x - origin.x;
    const dy = point.y - origin.y;
    const size = Math.max(Math.abs(dx), Math.abs(dy));

    return {
        x: origin.x + (dx < 0 ? -size : size),
        y: origin.y + (dy < 0 ? -size : size),
    };
};

/**
 * บีบกรอบให้กลับไปมีสัดส่วนเดิม — Shift ขณะย่อขยายชิ้นที่เลือกไว้
 *
 * มือจับตรงข้ามใช้ด้านที่เปลี่ยนไปมากกว่าเป็นตัวนำ ส่วนมือจับกลางขอบใช้แกนของมันเอง
 * เป็นตัวนำแล้วให้อีกแกนไหลตาม เหมือน Canva และ Figma
 *
 * มุมหรือขอบด้านตรงข้ามกับมือจับต้องอยู่กับที่เสมอ ส่วนแกนที่ไม่ได้ถูกลากโตออกสองข้าง
 * เท่ากัน รูปจึงโตออกจากกึ่งกลางของด้านนั้น ไม่เอียงไปข้างใดข้างหนึ่ง
 *
 * @param {number} ratio สัดส่วน w/h เดิมที่ต้องรักษาไว้
 */
export const lockBoundsAspect = (bounds, handle, ratio) => {
    if (! Number.isFinite(ratio) || ratio <= 0) {
        return bounds;
    }

    const horizontal = handle.includes('w') || handle.includes('e');
    const vertical = handle.includes('n') || handle.includes('s');
    let {w, h} = bounds;

    if (horizontal && vertical) {
        if (w / ratio >= h) {
            h = w / ratio;
        } else {
            w = h * ratio;
        }
    } else if (horizontal) {
        h = w / ratio;
    } else if (vertical) {
        w = h * ratio;
    }

    return {
        x: handle.includes('w') ? bounds.x + bounds.w - w : (horizontal ? bounds.x : bounds.x + (bounds.w - w) / 2),
        y: handle.includes('n') ? bounds.y + bounds.h - h : (vertical ? bounds.y : bounds.y + (bounds.h - h) / 2),
        w,
        h,
    };
};

/*
 * ทิศทั้งแปดที่ Shift ล็อกเส้นตรงไว้ เรียงตามมุมทีละ 45 องศา
 *
 * ใช้เวกเตอร์จำนวนเต็มแทน cos/sin เพราะ Math.cos(π/4) กับ Math.sin(π/4) ต่างกัน
 * ที่หลักสุดท้าย เส้นทแยงที่ได้จะเอียง 44.99999 องศา แล้ว w กับ h ไม่เท่ากันพอดี
 */
const OCTANT_DIRECTIONS = [[1, 0], [1, 1], [0, 1], [-1, 1], [-1, 0], [-1, -1], [0, -1], [1, -1]];

/**
 * จุดปลายของเส้นที่ถูกล็อกให้อยู่ในแนวนอน แนวตั้ง หรือแนวทแยง 45 องศา
 *
 * ใช้การฉายจุดของเคอร์เซอร์ลงบนทิศที่ใกล้ที่สุด ไม่ใช่รักษาความยาวเดิม
 * ปลายเส้นแนวนอนจึงอยู่ใต้เคอร์เซอร์พอดีในแกน x ซึ่งรู้สึกเป็นธรรมชาติกว่า
 * การให้ปลายเส้นเลยเคอร์เซอร์ออกไปเมื่อเคอร์เซอร์ไม่ได้อยู่บนแกนพอดี
 */
export const constrainToAngle = (origin, point) => {
    const dx = point.x - origin.x;
    const dy = point.y - origin.y;

    if (dx === 0 && dy === 0) {
        return point;
    }

    const octant = Math.round(Math.atan2(dy, dx) / (Math.PI / 4));
    const [ux, uy] = OCTANT_DIRECTIONS[(octant + 8) % 8];
    const distance = (dx * ux + dy * uy) / (ux * ux + uy * uy);

    return {x: origin.x + distance * ux, y: origin.y + distance * uy};
};
