/*
 * ลดจำนวนจุดของเส้นดินสอ และแปลงเป็นคำสั่งวาดของ SVG
 *
 * ทำไมต้องลดจุด
 * ---------------------------------------------------------------
 * เหตุการณ์ pointermove ยิงถี่มาก การลากเส้นเดียวยาว ๆ ได้จุดหลายร้อยถึงหลักพัน
 * ถ้าเก็บทุกจุด (1) เอกสารจะโตเร็วจนชนเพดานขนาด (2) การเรนเดอร์และการชนช้าลง
 * และ (3) ทุกจุดถูกส่งขึ้นเซิร์ฟเวอร์ทุกครั้งที่บันทึกอัตโนมัติ
 *
 * ใช้อัลกอริทึม Ramer-Douglas-Peucker ซึ่งเก็บจุดที่ทำให้รูปร่างเปลี่ยนไปจริง
 * และตัดจุดที่อยู่เกือบเป็นเส้นตรงระหว่างเพื่อนบ้านทิ้ง ตาคนมองไม่ออกว่าต่าง
 */

import {distanceToSegment} from './geometry.js';

/**
 * ลดจุดด้วย RDP
 *
 * epsilon เป็นระยะในหน่วยพิกัดโลก ผู้เรียกควรหารด้วยระดับซูมมาก่อน เพื่อให้
 * ความละเอียดที่เก็บไว้สอดคล้องกับสิ่งที่ผู้ใช้เห็นตอนวาด ไม่ใช่หยาบเมื่อซูมเข้า
 *
 * @param {Array<[number, number]>} points
 * @returns {Array<[number, number]>}
 */
export const simplifyPoints = (points, epsilon = 1) => {
    if (! Array.isArray(points) || points.length <= 2 || epsilon <= 0) {
        return Array.isArray(points) ? [...points] : [];
    }

    return [...reduce(points, epsilon)];
};

const reduce = (points, epsilon) => {
    const first = {x: points[0][0], y: points[0][1]};
    const last = {x: points[points.length - 1][0], y: points[points.length - 1][1]};

    let worstIndex = 0;
    let worstDistance = 0;

    for (let index = 1; index < points.length - 1; index += 1) {
        const distance = distanceToSegment({x: points[index][0], y: points[index][1]}, first, last);

        if (distance > worstDistance) {
            worstDistance = distance;
            worstIndex = index;
        }
    }

    if (worstDistance <= epsilon) {
        return [points[0], points[points.length - 1]];
    }

    const left = reduce(points.slice(0, worstIndex + 1), epsilon);
    const right = reduce(points.slice(worstIndex), epsilon);

    // จุดที่ worstIndex อยู่ในผลลัพธ์ของทั้งสองฝั่ง ต้องตัดออกหนึ่งชุด
    return [...left.slice(0, -1), ...right];
};

/**
 * แปลงจุดเป็นค่า d ของ <path>
 *
 * ใช้เส้นตรงเชื่อมจุด ไม่ใช่เส้นโค้ง เพราะการชนใน geometry.js คำนวณจากส่วนของ
 * เส้นตรงระหว่างจุดเช่นกัน ถ้าวาดเป็นเส้นโค้งแต่คำนวณการชนเป็นเส้นตรง สิ่งที่
 * ตาเห็นกับสิ่งที่คลิกโดนจะไม่ตรงกันตรงช่วงที่โค้งมาก
 *
 * เส้นที่มีจุดเดียวถูกวาดเป็นเส้นยาวศูนย์ ซึ่งเมื่อคู่กับ stroke-linecap แบบ
 * round จะแสดงเป็นจุดกลม ตรงกับที่ผู้ใช้คาดหวังเมื่อแตะครั้งเดียวแล้วปล่อย
 */
export const pointsToPathData = (points) => {
    if (! points || points.length === 0) {
        return '';
    }

    const [first, ...rest] = points;
    const head = `M ${round(first[0])} ${round(first[1])}`;

    if (rest.length === 0) {
        return `${head} L ${round(first[0])} ${round(first[1])}`;
    }

    return rest.reduce((data, [x, y]) => `${data} L ${round(x)} ${round(y)}`, head);
};

const round = (value) => Math.round(value * 100) / 100;
