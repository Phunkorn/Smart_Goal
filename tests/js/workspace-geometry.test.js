import test from 'node:test';
import assert from 'node:assert/strict';
import {
    HANDLES,
    boundsFromPoints,
    boundsOf,
    distanceToSegment,
    handleAtPoint,
    handlePositions,
    hitTest,
    normalizeBounds,
    pickAll,
    pickTopmost,
    pickWithin,
    resizeBounds,
    scaleElementToBounds,
    translateElement,
    unionBounds,
} from '../../resources/js/pages/workspace/geometry.js';

/*
 * เรขาคณิตของชิ้นงานบนกระดาน
 *
 * โมดูลนี้เป็นเหตุผลที่เลือกไม่ใช้ elementFromPoint หรือ getBBox ของเบราว์เซอร์
 * การชนคำนวณจากตัวแบบล้วน ๆ โค้ดเส้นทางเดียวกันจึงทำงานทั้งในเบราว์เซอร์จริง
 * และในเทสต์ชุดนี้ ไม่มีสาขาไหนที่ไม่เคยถูกทดสอบ
 */

const rect = (overrides = {}) => ({
    id: 'r1', type: 'rect', z: 1, x: 0, y: 0, w: 100, h: 50,
    stroke: '#1f2937', strokeWidth: 2, fill: 'none', ...overrides,
});

const stroke = (points, overrides = {}) => ({
    id: 's1', type: 'pen', z: 1, stroke: '#1f2937', strokeWidth: 4, points, ...overrides,
});

test('กรอบของเส้นดินสอครอบคลุมความหนาของเส้นด้วย', () => {
    const box = boundsOf(stroke([[10, 10], [30, 20]], {strokeWidth: 4}));

    assert.deepEqual(box, {x: 8, y: 8, w: 24, h: 14});
});

test('กรอบของรูปทรงที่ขนาดติดลบถูกทำให้เป็นบวก', () => {
    assert.deepEqual(boundsOf(rect({x: 100, y: 80, w: -60, h: -30})), {x: 40, y: 50, w: 60, h: 30});
});

test('กรอบรวมของหลายชิ้น และคืน null เมื่อไม่มีชิ้นงาน', () => {
    const box = unionBounds([rect(), rect({id: 'r2', x: 200, y: 100, w: 50, h: 50})]);

    assert.deepEqual(box, {x: 0, y: 0, w: 250, h: 150});
    assert.equal(unionBounds([]), null);
});

test('ระยะจากจุดถึงส่วนของเส้นตรง รวมกรณีเส้นยาวศูนย์', () => {
    assert.equal(distanceToSegment({x: 5, y: 3}, {x: 0, y: 0}, {x: 10, y: 0}), 3);

    // ปลายเส้นทั้งสองอยู่จุดเดียวกัน ต้องไม่หารด้วยศูนย์แล้วได้ NaN
    // ซึ่งจะทำให้การเปรียบเทียบเป็นเท็จเสมอ แล้วคลิกเส้นจุดเดียวไม่โดนเลย
    assert.equal(distanceToSegment({x: 3, y: 4}, {x: 0, y: 0}, {x: 0, y: 0}), 5);
});

test('คลิกโดนเส้นดินสอตามความหนาและระยะผ่อนผัน', () => {
    const line = stroke([[0, 0], [100, 0]], {strokeWidth: 4});

    assert.equal(hitTest(line, {x: 50, y: 1}), true, 'ในความหนาของเส้น');
    assert.equal(hitTest(line, {x: 50, y: 5}), false, 'นอกเส้นโดยไม่มีระยะผ่อนผัน');
    assert.equal(hitTest(line, {x: 50, y: 5}, 4), true, 'นอกเส้นแต่อยู่ในระยะผ่อนผัน');
});

test('เส้นดินสอที่มีจุดเดียวยังคลิกโดน', () => {
    assert.equal(hitTest(stroke([[10, 10]], {strokeWidth: 8}), {x: 12, y: 12}), true);
});

/*
 * รูปทรงที่ไม่ได้เติมสีต้องคลิกโดนเฉพาะเส้นขอบ ไม่งั้นสี่เหลี่ยมใหญ่ ๆ จะบัง
 * ทุกอย่างที่อยู่ข้างใต้จนเลือกไม่ได้ ซึ่งเป็นข้อผิดพลาดที่พบบ่อยในโปรแกรมวาด
 */
test('สี่เหลี่ยมที่ไม่เติมสีคลิกโดนเฉพาะเส้นขอบ', () => {
    const outline = rect({fill: 'none', strokeWidth: 2});

    assert.equal(hitTest(outline, {x: 0, y: 25}), true, 'บนขอบซ้าย');
    assert.equal(hitTest(outline, {x: 50, y: 25}), false, 'กลางรูปที่ยังโปร่ง');
});

test('สี่เหลี่ยมที่เติมสีคลิกโดนทั้งพื้นที่', () => {
    assert.equal(hitTest(rect({fill: '#fde68a'}), {x: 50, y: 25}), true);
});

test('วงกลมที่ไม่เติมสีคลิกโดนเฉพาะเส้นขอบ', () => {
    const ellipse = {...rect({type: 'ellipse', w: 100, h: 100}), fill: 'none', strokeWidth: 2};

    assert.equal(hitTest(ellipse, {x: 50, y: 0}), true, 'จุดบนสุดของวงกลม');
    assert.equal(hitTest(ellipse, {x: 50, y: 50}), false, 'จุดศูนย์กลางที่ยังโปร่ง');
    assert.equal(hitTest(ellipse, {x: 0, y: 0}), false, 'มุมกรอบที่อยู่นอกวงกลม');
});

test('กระดาษโน้ตและรูปภาพคลิกโดนทั้งพื้นที่', () => {
    const sticky = {id: 'n1', type: 'sticky', z: 1, x: 0, y: 0, w: 180, h: 180, text: '', fill: '#fde68a'};

    assert.equal(hitTest(sticky, {x: 90, y: 90}), true);
    assert.equal(hitTest(sticky, {x: 200, y: 90}), false);
});

test('เลือกชิ้นบนสุดเมื่อหลายชิ้นซ้อนกัน', () => {
    const below = rect({id: 'below', fill: '#fde68a'});
    const above = rect({id: 'above', fill: '#bfdbfe'});

    assert.equal(pickTopmost([below, above], {x: 50, y: 25}).id, 'above');
    assert.equal(pickTopmost([below, above], {x: 500, y: 500}), null);
});

test('ยางลบเก็บทุกชิ้นที่อยู่ใต้จุดเดียวกัน', () => {
    const a = rect({id: 'a', fill: '#fde68a'});
    const b = rect({id: 'b', fill: '#bfdbfe'});

    assert.deepEqual(pickAll([a, b], {x: 10, y: 10}).map((e) => e.id), ['a', 'b']);
});

test('กรอบเลือกเก็บเฉพาะชิ้นที่อยู่ในกรอบทั้งชิ้น', () => {
    const inside = rect({id: 'inside', x: 10, y: 10, w: 20, h: 20});
    const overlapping = rect({id: 'overlap', x: 90, y: 10, w: 40, h: 20});

    const picked = pickWithin([inside, overlapping], {x: 0, y: 0, w: 100, h: 100});

    assert.deepEqual(picked.map((e) => e.id), ['inside'], 'ชิ้นที่โผล่พ้นกรอบไม่ถูกเลือก');
});

test('กรอบจากสองมุมทำงานได้ทุกทิศทางการลาก', () => {
    assert.deepEqual(boundsFromPoints({x: 100, y: 80}, {x: 20, y: 10}), {x: 20, y: 10, w: 80, h: 70});
});

test('มือจับทั้งแปดอยู่บนขอบและกึ่งกลางด้านของกรอบ', () => {
    const positions = handlePositions({x: 0, y: 0, w: 100, h: 60});

    assert.equal(HANDLES.length, 8);
    assert.deepEqual(positions.nw, {x: 0, y: 0});
    assert.deepEqual(positions.se, {x: 100, y: 60});
    assert.deepEqual(positions.n, {x: 50, y: 0});
    assert.deepEqual(positions.w, {x: 0, y: 30});
});

test('หามือจับที่อยู่ใต้จุด และคืน null เมื่อไม่โดน', () => {
    const bounds = {x: 0, y: 0, w: 100, h: 60};

    assert.equal(handleAtPoint(bounds, {x: 98, y: 58}, 6), 'se');
    assert.equal(handleAtPoint(bounds, {x: 50, y: 30}, 6), null);
});

test('การลากมือจับปรับกรอบตามด้านที่ลาก', () => {
    const bounds = {x: 0, y: 0, w: 100, h: 60};

    assert.deepEqual(resizeBounds(bounds, 'se', 20, 10), {x: 0, y: 0, w: 120, h: 70});
    assert.deepEqual(resizeBounds(bounds, 'nw', 20, 10), {x: 20, y: 10, w: 80, h: 50});
    assert.deepEqual(resizeBounds(bounds, 'e', 20, 999), {x: 0, y: 0, w: 120, h: 60}, 'มือจับด้านข้างไม่แตะความสูง');
});

test('การลากมือจับผ่านอีกด้านทำให้กรอบพลิกแล้วถูกทำให้เป็นบวก', () => {
    assert.deepEqual(resizeBounds({x: 0, y: 0, w: 100, h: 60}, 'e', -140, 0), {x: -40, y: 0, w: 40, h: 60});
    assert.deepEqual(normalizeBounds({x: 10, y: 10, w: -10, h: -10}), {x: 0, y: 0, w: 10, h: 10});
});

test('การเลื่อนชิ้นงานคืนชิ้นใหม่โดยไม่แก้ของเดิม', () => {
    const original = stroke([[0, 0], [10, 10]]);
    const moved = translateElement(original, 5, -5);

    assert.deepEqual(moved.points, [[5, -5], [15, 5]]);
    assert.deepEqual(original.points, [[0, 0], [10, 10]], 'ของเดิมต้องไม่ถูกแก้');
});

test('การย่อขยายกลุ่มรักษาตำแหน่งสัมพัทธ์ระหว่างชิ้น', () => {
    const from = {x: 0, y: 0, w: 100, h: 100};
    const to = {x: 0, y: 0, w: 200, h: 200};

    const scaled = scaleElementToBounds(rect({x: 50, y: 50, w: 10, h: 10}), from, to);

    assert.deepEqual(
        {x: scaled.x, y: scaled.y, w: scaled.w, h: scaled.h},
        {x: 100, y: 100, w: 20, h: 20}
    );
});

/*
 * เส้นตรงแนวนอนมีความสูงเป็นศูนย์ การหารด้วยศูนย์จะทำให้พิกัดกลายเป็น NaN
 * แล้วชิ้นงานหายไปจากหน้าจอโดยไม่มีอะไรบอก
 */
test('การย่อขยายกรอบที่แบนราบไม่ทำให้พิกัดกลายเป็น NaN', () => {
    const flat = {x: 0, y: 50, w: 100, h: 0};
    const scaled = scaleElementToBounds(
        {id: 'l1', type: 'line', z: 1, x: 0, y: 50, w: 100, h: 0, stroke: '#000', strokeWidth: 2},
        flat,
        {x: 0, y: 50, w: 200, h: 0}
    );

    assert.equal(Number.isNaN(scaled.y), false);
    assert.equal(scaled.w, 200);
    assert.equal(scaled.h, 0);
});
