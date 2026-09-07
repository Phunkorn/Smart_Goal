/*
 * เรขาคณิตของชิ้นงานบนกระดาน — กรอบ การชน และการย่อขยาย
 *
 * ทั้งหมดเป็นฟังก์ชันบริสุทธิ์ที่คำนวณจากตัวแบบข้อมูลโดยตรง ไม่พึ่ง DOM
 *
 * ตั้งใจไม่ใช้ elementFromPoint, getBBox หรือ isPointInStroke ของเบราว์เซอร์
 * ด้วยเหตุผลสองข้อ (1) jsdom ที่ใช้ทดสอบไม่มีทั้งสามอย่างและไม่คำนวณ layout
 * เลย ถ้าพึ่งมันจะเหลือโค้ดเส้นทางที่ไม่เคยถูกทดสอบ (2) การคำนวณจากตัวแบบทำให้
 * ผลลัพธ์เหมือนกันทุกเบราว์เซอร์ ไม่ขึ้นกับว่าเรนเดอร์ไปแล้วหรือยัง
 */

/** กรอบสี่เหลี่ยมที่ล้อมชิ้นงานหนึ่งชิ้น */
export const boundsOf = (element) => {
    if (element.type === 'pen') {
        return strokeBounds(element);
    }

    // ขนาดติดลบไม่ควรมี (ตัวกรองฝั่งเซิร์ฟเวอร์บีบเป็นศูนย์แล้ว) แต่ระหว่างลากสร้าง
    // ฝั่งหน้าจอยังเป็นค่าชั่วคราวได้ จึงทำให้กรอบเป็นบวกเสมอที่นี่
    const x = element.w < 0 ? element.x + element.w : element.x;
    const y = element.h < 0 ? element.y + element.h : element.y;

    return {x, y, w: Math.abs(element.w), h: Math.abs(element.h)};
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
 * จุดนี้อยู่บนชิ้นงานหรือไม่
 *
 * tolerance เป็นระยะผ่อนผันในหน่วยพิกัดโลก ผู้เรียกต้องหารด้วยระดับซูมมาก่อน
 * เพื่อให้ "แตะพลาดได้กี่พิกเซลบนหน้าจอ" คงที่ไม่ว่าจะซูมเข้าหรือออก
 */
export const hitTest = (element, point, tolerance = 0) => {
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
            return boundsContain(inflate(boundsOf(element), tolerance), point);
    }
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
    const box = boundsOf(element);

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
    const box = boundsOf(element);
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
