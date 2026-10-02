import test from 'node:test';
import assert from 'node:assert/strict';
import {
    boundsOf,
    constrainToAngle,
    constrainToSquare,
    hitTest,
    pickTopmost,
    rotateElement,
} from '../../resources/js/pages/workspace/geometry.js';
import {
    normalizeDegrees,
    rotatePoint,
    rotationOf,
    snapDegrees,
    withRotation,
} from '../../resources/js/pages/workspace/rotation.js';
import {
    fitElementToFrame,
    frameHandlePositions,
    frameOf,
    frameTargetAt,
    resizeFrame,
    rotationHandlePosition,
} from '../../resources/js/pages/workspace/selection-frame.js';

/*
 * การหมุนและการล็อกมุม - ตรรกะบริสุทธิ์ที่ตัวเรนเดอร์ การตรวจการชน และมือจับ
 * ใช้ร่วมกัน ถ้าชุดนี้ผิด สิ่งที่ตาเห็นกับสิ่งที่คลิกโดนจะไม่ตรงกัน
 */

const near = (actual, expected, message) =>
    assert.ok(Math.abs(actual - expected) < 1e-6, `${message ?? ''} ได้ ${actual} คาดว่า ${expected}`);

const nearPoint = (actual, expected) => {
    near(actual.x, expected.x, 'x');
    near(actual.y, expected.y, 'y');
};

const rect = (overrides = {}) => ({
    id: 'r1', type: 'rect', z: 1, x: 100, y: 100, w: 100, h: 100,
    stroke: '#1f2937', strokeWidth: 2, fill: '#fde68a', ...overrides,
});

/* ── มุมและจุด ─────────────────────────────────────────────── */

test('มุมถูกบีบให้อยู่ในช่วง 0 ถึงน้อยกว่า 360 องศา', () => {
    assert.equal(normalizeDegrees(-90), 270);
    assert.equal(normalizeDegrees(450), 90);
    assert.equal(normalizeDegrees(360), 0);
    assert.equal(normalizeDegrees(359.999), 0, 'ปัดแล้วเป็น 360 ต้องกลายเป็น 0');
    assert.equal(normalizeDegrees(Number.NaN), 0);
});

test('เอกสารเก่าที่ไม่มี rotation อ่านเป็นมุม 0', () => {
    assert.equal(rotationOf(rect()), 0);
    assert.equal(rotationOf(rect({rotation: '30'})), 30, 'ค่าจาก JSON ที่เป็นสตริงตัวเลขยังอ่านได้');
});

test('ดินสอ เส้นตรง และลูกศรไม่มีมุมเก็บไว้ แม้จะมีคีย์ rotation ติดมา', () => {
    assert.equal(rotationOf({type: 'line', rotation: 45}), 0);
    assert.equal(rotationOf({type: 'pen', rotation: 45}), 0);
});

test('มุม 0 ลบคีย์ทิ้ง เอกสารที่ไม่ได้หมุนจึงเหมือนก่อนมีฟีเจอร์นี้', () => {
    assert.equal('rotation' in withRotation(rect({rotation: 30}), 360), false);
    assert.equal(withRotation(rect(), 45).rotation, 45);
});

test('หมุนจุด 90 องศาตามเข็มนาฬิกาบนจอ (แกน y ชี้ลง)', () => {
    nearPoint(rotatePoint({x: 10, y: 0}, {x: 0, y: 0}, 90), {x: 0, y: 10});
});

test('Shift ล็อกมุมหมุนทีละ 15 องศา', () => {
    assert.equal(snapDegrees(52), 45);
    assert.equal(snapDegrees(53), 60);
});

/* ── การหมุนชิ้นงาน ───────────────────────────────────────── */

test('หมุนสี่เหลี่ยมรอบจุดกึ่งกลางตัวเอง กรอบก่อนหมุนอยู่ที่เดิมแต่มีมุมเพิ่ม', () => {
    const rotated = rotateElement(rect(), {x: 150, y: 150}, 30);

    near(rotated.x, 100);
    near(rotated.y, 100);
    assert.equal(rotated.w, 100);
    assert.equal(rotated.rotation, 30);
});

test('หมุนกลุ่มรอบจุดกลางของกลุ่ม ชิ้นงานโคจรไปตามวง ไม่ใช่หมุนอยู่กับที่', () => {
    const rotated = rotateElement(rect({x: 0, y: 0, w: 20, h: 20}), {x: 110, y: 10}, 180);

    // จุดกลางเดิม (10,10) หมุน 180 รอบ (110,10) ไปอยู่ที่ (210,10)
    near(rotated.x + rotated.w / 2, 210);
    near(rotated.y + rotated.h / 2, 10);
    assert.equal(rotated.rotation, 180);
});

test('เส้นตรงหมุนที่จุดปลายโดยไม่เก็บมุม', () => {
    const line = {id: 'l', type: 'line', x: 0, y: 0, w: 100, h: 0, stroke: '#000000', strokeWidth: 2};
    const rotated = rotateElement(line, {x: 50, y: 0}, 90);

    nearPoint({x: rotated.x, y: rotated.y}, {x: 50, y: -50});
    nearPoint({x: rotated.w, y: rotated.h}, {x: 0, y: 100});
    assert.equal('rotation' in rotated, false);
});

test('เส้นดินสอหมุนทุกจุด', () => {
    const pen = {id: 'p', type: 'pen', points: [[0, 0], [10, 0]], stroke: '#000000', strokeWidth: 2};
    const rotated = rotateElement(pen, {x: 0, y: 0}, 90);

    nearPoint({x: rotated.points[1][0], y: rotated.points[1][1]}, {x: 0, y: 10});
});

test('การหมุนไม่แก้ชิ้นเดิม', () => {
    const original = rect();
    rotateElement(original, {x: 150, y: 150}, 45);

    assert.equal('rotation' in original, false);
});

/* ── กรอบและการชนของชิ้นที่หมุนอยู่ ─────────────────────────── */

test('กรอบตั้งตรงของสี่เหลี่ยมที่หมุน 45 องศาครอบมุมที่ยื่นออกไป', () => {
    const box = boundsOf(rect({rotation: 45}));
    const half = 50 * Math.SQRT2;

    near(box.x, 150 - half);
    near(box.w, half * 2);
});

/*
 * นี่คือข้อที่ห้ามพลาดที่สุด: ห้ามหมุนแค่ภาพที่เห็นแต่ตำแหน่งคลิกยังอยู่ที่เดิม
 */
test('คลิกโดนมุมที่ยื่นออกไปหลังหมุน และไม่โดนมุมเดิมที่ว่างไปแล้ว', () => {
    const diamond = rect({rotation: 45});

    assert.equal(hitTest(diamond, {x: 150, y: 85}), true, 'ยอดข้าวหลามตัดอยู่เหนือกรอบเดิม');
    assert.equal(hitTest(diamond, {x: 104, y: 104}), false, 'มุมของกรอบเดิมว่างไปแล้วหลังหมุน');
});

test('สี่เหลี่ยมที่ไม่เติมสีและหมุนอยู่ คลิกโดนเฉพาะเส้นขอบที่เอียง', () => {
    const outline = rect({rotation: 45, fill: 'none'});

    assert.equal(hitTest(outline, {x: 150, y: 150}), false, 'กลางรูปว่าง');
    assert.equal(hitTest(outline, {x: 150, y: 150 - 50 * Math.SQRT2 + 1}, 2), true, 'ใกล้ยอดคือเส้นขอบ');
});

test('วงรีที่หมุนตรวจการชนตามแนวที่เอียง', () => {
    const ellipse = rect({type: 'ellipse', x: 0, y: 40, w: 100, h: 20, rotation: 90});

    assert.equal(hitTest(ellipse, {x: 50, y: 5}), true, 'หมุน 90 แล้วแกนยาวชี้ขึ้นลง');
    assert.equal(hitTest(ellipse, {x: 5, y: 50}), false, 'ปลายแกนยาวเดิมว่างไปแล้ว');
});

test('pickTopmost เลือกชิ้นที่หมุนอยู่จากจุดที่เห็นจริง', () => {
    assert.equal(pickTopmost([rect({rotation: 45})], {x: 150, y: 85})?.id, 'r1');
});

/* ── ล็อกมุมของเส้นตรงด้วย Shift ────────────────────────────── */

test('Shift ล็อกเส้นเกือบแนวนอนให้เป็นแนวนอนพอดี', () => {
    assert.deepEqual(constrainToAngle({x: 0, y: 0}, {x: 100, y: 12}), {x: 100, y: 0});
});

test('Shift ล็อกเส้นเกือบแนวตั้งให้เป็นแนวตั้งพอดี', () => {
    assert.deepEqual(constrainToAngle({x: 0, y: 0}, {x: -9, y: -120}), {x: 0, y: -120});
});

test('Shift ล็อกแนวทแยง 45 องศาได้ครบสี่ทิศ และ w กับ h เท่ากันพอดี', () => {
    [[90, 100], [-90, 100], [-100, -90], [100, -90]].forEach(([x, y]) => {
        const end = constrainToAngle({x: 0, y: 0}, {x, y});

        assert.equal(Math.abs(end.x), Math.abs(end.y), `ทิศ (${x}, ${y})`);
        assert.equal(Math.sign(end.x), Math.sign(x));
        assert.equal(Math.sign(end.y), Math.sign(y));
    });
});

test('Shift ที่ยังไม่ได้ลากไปไหนไม่ทำให้เกิด NaN', () => {
    assert.deepEqual(constrainToAngle({x: 5, y: 5}, {x: 5, y: 5}), {x: 5, y: 5});
});

/* ── กรอบการเลือกที่เอียงตามชิ้นงาน ────────────────────────── */

test('เลือกชิ้นที่หมุนอยู่ชิ้นเดียว กรอบเอียงตามชิ้นนั้นพอดี', () => {
    assert.deepEqual(frameOf([rect({rotation: 30})]), {x: 100, y: 100, w: 100, h: 100, rotation: 30});
});

test('เลือกหลายชิ้น กรอบตั้งตรงล้อมทุกชิ้น', () => {
    const frame = frameOf([rect({rotation: 30}), rect({id: 'r2', x: 400})]);

    assert.equal(frame.rotation, 0);
    assert.equal(frame.x + frame.w, 500);
});

test('ไม่ได้เลือกอะไร ไม่มีกรอบ', () => {
    assert.equal(frameOf([]), null);
});

test('มือจับหมุนอยู่เหนือกึ่งกลางขอบบน และเอียงตามกรอบ', () => {
    const upright = {x: 100, y: 100, w: 100, h: 100, rotation: 0};

    nearPoint(rotationHandlePosition(upright, 28), {x: 150, y: 72});
    nearPoint(rotationHandlePosition({...upright, rotation: 90}, 28), {x: 228, y: 150});
});

test('กดที่มือจับหมุนได้ rotate กดที่มุมได้ชื่อมือจับ แม้กรอบจะเอียงอยู่', () => {
    const frame = {x: 100, y: 100, w: 100, h: 100, rotation: 90};
    const options = {handleRadius: 7, rotationOffset: 28};

    assert.equal(frameTargetAt(frame, {x: 228, y: 150}, options), 'rotate');

    // มุม nw ของกรอบที่หมุน 90 องศาไปอยู่ที่มุมขวาบนของจอ
    const corner = frameHandlePositions(frame).nw;
    nearPoint(corner, {x: 200, y: 100});
    assert.equal(frameTargetAt(frame, corner, options), 'nw');
    assert.equal(frameTargetAt(frame, {x: 150, y: 150}, options), null);
});

test('ย่อขยายชิ้นที่เอียงอยู่ มุมตรงข้ามต้องอยู่กับที่บนจอ', () => {
    const frame = {x: 100, y: 100, w: 100, h: 100, rotation: 90};
    const anchorBefore = frameHandlePositions(frame).nw;
    const seBefore = frameHandlePositions(frame).se;

    // ลากมุม se ออกไปตามแนวของกรอบ (หมุน 90 แล้ว "ขวา" ของกรอบคือ "ลง" บนจอ)
    const resized = resizeFrame(frame, 'se', seBefore, {x: seBefore.x - 20, y: seBefore.y + 40});

    assert.equal(resized.rotation, 90);
    near(resized.w, 140);
    near(resized.h, 120);
    nearPoint(frameHandlePositions(resized).nw, anchorBefore);
});

test('ย่อขยายกรอบตั้งตรงให้ผลเหมือนเดิมทุกประการ', () => {
    const frame = {x: 0, y: 0, w: 100, h: 50, rotation: 0};

    assert.deepEqual(
        resizeFrame(frame, 'se', {x: 100, y: 50}, {x: 150, y: 70}, 4),
        {x: 0, y: 0, w: 150, h: 70, rotation: 0}
    );
});

/*
 * Shift ระหว่างย่อขยาย — คงสัดส่วนเดิมไว้ สี่เหลี่ยมจัตุรัสจึงไม่เสียทรง
 *
 * มุมจับใช้ด้านที่เปลี่ยนไปมากกว่าเป็นตัวนำ ส่วนขอบด้านใช้แกนของมันเอง แล้วกึ่งกลาง
 * อีกแกนหนึ่งไว้กับที่ รูปจึงโตออกสองข้างเท่ากัน ไม่เอียงไปข้างใดข้างหนึ่ง
 */
test('ลากมุมพร้อม Shift กรอบคงสัดส่วนเดิมและมุมตรงข้ามอยู่กับที่', () => {
    const frame = {x: 0, y: 0, w: 100, h: 50, rotation: 0};

    const resized = resizeFrame(frame, 'se', {x: 100, y: 50}, {x: 200, y: 60}, 4, {lockAspect: true});

    assert.deepEqual(resized, {x: 0, y: 0, w: 200, h: 100, rotation: 0});
});

test('ลากมุม nw พร้อม Shift มุม se ต้องอยู่กับที่', () => {
    const frame = {x: 100, y: 100, w: 100, h: 50, rotation: 0};

    const resized = resizeFrame(frame, 'nw', {x: 100, y: 100}, {x: 60, y: 90}, 4, {lockAspect: true});

    assert.equal(resized.w / resized.h, 2, 'สัดส่วนต้องเท่าเดิม');
    assert.equal(resized.x + resized.w, 200, 'ขอบขวาต้องไม่ขยับ');
    assert.equal(resized.y + resized.h, 150, 'ขอบล่างต้องไม่ขยับ');
});

/* มือจับกลางขอบ: ด้านที่ไม่ได้ลากต้องโตออกสองข้างเท่ากัน รูปจึงไม่เอียงออกข้าง */
test('ลากขอบขวาพร้อม Shift ความสูงโตตามสัดส่วนโดยกึ่งกลางแนวตั้งอยู่ที่เดิม', () => {
    const frame = {x: 0, y: 0, w: 100, h: 50, rotation: 0};

    const resized = resizeFrame(frame, 'e', {x: 100, y: 25}, {x: 200, y: 25}, 4, {lockAspect: true});

    assert.deepEqual(resized, {x: 0, y: -25, w: 200, h: 100, rotation: 0});
});

test('ไม่กด Shift การย่อขยายยังอิสระสองแกนเหมือนเดิม', () => {
    const frame = {x: 0, y: 0, w: 100, h: 50, rotation: 0};

    assert.deepEqual(
        resizeFrame(frame, 'se', {x: 100, y: 50}, {x: 200, y: 60}, 4),
        {x: 0, y: 0, w: 200, h: 60, rotation: 0}
    );
});

test('จุดปลายที่ถูกล็อกเป็นจัตุรัสใช้ด้านที่ยาวกว่า และคงทิศทางที่ลาก', () => {
    assert.deepEqual(constrainToSquare({x: 0, y: 0}, {x: 100, y: 40}), {x: 100, y: 100});
    assert.deepEqual(constrainToSquare({x: 0, y: 0}, {x: 30, y: -90}), {x: 90, y: -90});
    assert.deepEqual(constrainToSquare({x: 10, y: 10}, {x: -40, y: 30}), {x: -40, y: 60});
    assert.deepEqual(constrainToSquare({x: 5, y: 5}, {x: 5, y: 5}), {x: 5, y: 5});
});

test('ชิ้นที่เอียงรับขนาดของกรอบใหม่ไปตรง ๆ และคงมุมไว้', () => {
    const element = rect({rotation: 90});
    const from = frameOf([element]);
    const to = resizeFrame(from, 'e', {x: 150, y: 200}, {x: 150, y: 230});
    const resized = fitElementToFrame(element, from, to);

    assert.equal(resized.rotation, 90);
    near(resized.w, 130);
});
