import test from 'node:test';
import assert from 'node:assert/strict';
import {isReadOnlyTool, toolFor} from '../../resources/js/pages/workspace/tools/index.js';
import {
    addElement,
    createScene,
    nextZ,
    removeElements,
    replaceElements,
} from '../../resources/js/pages/workspace/scene.js';
import {simplifyPoints, pointsToPathData} from '../../resources/js/pages/workspace/simplify.js';

/*
 * เครื่องมือวาด
 *
 * เครื่องมือทุกตัวเป็นฟังก์ชันบริสุทธิ์ที่รับ context แล้วคืน patch จึงเรียก
 * ทดสอบตรง ๆ ได้โดยไม่ต้องมี DOM ทั้งชุด นี่คือเหตุผลที่แยกชั้นการวาดออกจาก
 * ชั้นการตัดสินใจตั้งแต่แรก
 */

let counter = 0;
const idFactory = () => `id-${++counter}`;

const contextFor = (overrides = {}) => ({
    point: {x: 0, y: 0},
    scene: createScene([]),
    selection: [],
    camera: {x: 0, y: 0, scale: 1},
    style: {stroke: '#dc2626', strokeWidth: 4},
    draft: null,
    additive: false,
    idFactory,
    addElement: (scene, element) => addElement(scene, {...element, z: nextZ(scene)}),
    replaceElements,
    removeElements,
    ...overrides,
});

const rect = (id, box) => ({
    id, type: 'rect', z: 1, ...box, stroke: '#1f2937', strokeWidth: 2, fill: '#fde68a',
});

/* ── ดินสอ ───────────────────────────────────────────────────── */

test('ดินสอสะสมจุดใน draft ไม่ใช่ใส่ลงฉากทีละจุด', () => {
    const pen = toolFor('pen');
    let context = contextFor({point: {x: 0, y: 0}});

    const down = pen.onPointerDown(context);
    assert.equal(down.scene, undefined, 'ฉากต้องยังไม่เปลี่ยนตอนกดลง');
    assert.deepEqual(down.draft.points, [[0, 0]]);

    const move = pen.onPointerMove(contextFor({point: {x: 10, y: 5}, draft: down.draft}));
    assert.equal(move.scene, undefined, 'ระหว่างลากยังไม่แตะฉาก');
    assert.equal(move.preview.type, 'pen', 'แต่ต้องมีตัวอย่างให้เห็น');
    assert.equal(move.draft.points.length, 2);
});

test('ดินสอใส่เส้นลงฉากตอนปล่อยนิ้ว พร้อมสีและความหนาที่เลือกไว้', () => {
    const pen = toolFor('pen');
    const draft = pen.onPointerDown(contextFor()).draft;

    const result = pen.onPointerUp(contextFor({draft, point: {x: 10, y: 10}}));

    assert.equal(result.scene.elements.length, 1);
    assert.equal(result.scene.elements[0].type, 'pen');
    assert.equal(result.scene.elements[0].stroke, '#dc2626');
    assert.equal(result.scene.elements[0].strokeWidth, 4);
    assert.equal(result.commit, true, 'ต้องบันทึกเป็นก้าว undo หนึ่งก้าว');
    assert.equal(result.draft, null);
});

test('ดินสอข้ามจุดที่ซ้ำกับจุดก่อนหน้าพอดี', () => {
    const pen = toolFor('pen');
    const draft = pen.onPointerDown(contextFor({point: {x: 5, y: 5}})).draft;

    assert.equal(pen.onPointerMove(contextFor({point: {x: 5, y: 5}, draft})), undefined);
});

/* ── รูปทรง ──────────────────────────────────────────────────── */

test('สี่เหลี่ยมถูกสร้างจากการลากและเลือกให้อัตโนมัติ', () => {
    const tool = toolFor('rect');
    const draft = tool.onPointerDown(contextFor({point: {x: 10, y: 10}})).draft;

    const result = tool.onPointerUp(contextFor({draft, point: {x: 110, y: 60}}));
    const shape = result.scene.elements[0];

    assert.deepEqual({x: shape.x, y: shape.y, w: shape.w, h: shape.h}, {x: 10, y: 10, w: 100, h: 50});
    assert.deepEqual(result.selection, [shape.id], 'รูปที่เพิ่งสร้างควรถูกเลือกไว้ให้ปรับต่อได้ทันที');
});

test('สี่เหลี่ยมที่ลากย้อนกลับได้กรอบที่เป็นบวก', () => {
    const tool = toolFor('rect');
    const draft = tool.onPointerDown(contextFor({point: {x: 110, y: 60}})).draft;
    const shape = tool.onPointerUp(contextFor({draft, point: {x: 10, y: 10}})).scene.elements[0];

    assert.deepEqual({x: shape.x, y: shape.y, w: shape.w, h: shape.h}, {x: 10, y: 10, w: 100, h: 50});
});

/*
 * ลูกศรต้องจำทิศทางจริง (w และ h ติดลบได้) เพราะหัวลูกศรต้องรู้ว่าปลายอยู่ข้างไหน
 * ถ้าทำให้เป็นบวกเหมือนสี่เหลี่ยม ลูกศรที่ลากขึ้นซ้ายจะกลับหัว
 */
test('ลูกศรและเส้นตรงเก็บทิศทางตามที่ลากจริง', () => {
    const tool = toolFor('arrow');
    const draft = tool.onPointerDown(contextFor({point: {x: 100, y: 100}})).draft;
    const shape = tool.onPointerUp(contextFor({draft, point: {x: 20, y: 40}})).scene.elements[0];

    assert.deepEqual({x: shape.x, y: shape.y, w: shape.w, h: shape.h}, {x: 100, y: 100, w: -80, h: -60});
});

test('การคลิกเปล่าโดยไม่ลากไม่ทิ้งรูปทรงขนาดศูนย์ไว้', () => {
    const tool = toolFor('rect');
    const draft = tool.onPointerDown(contextFor({point: {x: 10, y: 10}})).draft;

    const result = tool.onPointerUp(contextFor({draft, point: {x: 11, y: 11}}));

    assert.equal(result.scene, undefined);
    assert.equal(result.commit, undefined);
});

/* ── ยางลบ ───────────────────────────────────────────────────── */

test('ยางลบลบทั้งชิ้นที่ลากผ่าน', () => {
    const eraser = toolFor('eraser');
    const scene = createScene([rect('a', {x: 0, y: 0, w: 50, h: 50})]);

    const result = eraser.onPointerDown(contextFor({scene, point: {x: 25, y: 25}}));

    assert.equal(result.scene.elements.length, 0);
    assert.deepEqual(result.draft.erased, ['a']);
});

test('การลากยางลบผ่านหลายชิ้นนับเป็นก้าว undo เดียว', () => {
    const eraser = toolFor('eraser');
    const scene = createScene([
        rect('a', {x: 0, y: 0, w: 20, h: 20}),
        rect('b', {x: 60, y: 0, w: 20, h: 20}),
    ]);

    const first = eraser.onPointerDown(contextFor({scene, point: {x: 10, y: 10}}));
    const second = eraser.onPointerMove(contextFor({
        scene: first.scene,
        point: {x: 70, y: 10},
        draft: first.draft,
    }));

    assert.equal(second.scene.elements.length, 0);

    const up = eraser.onPointerUp(contextFor({draft: second.draft}));
    assert.equal(up.commit, true);
});

test('ยางลบที่ลากผ่านที่ว่างไม่กินก้าว undo', () => {
    const eraser = toolFor('eraser');
    const first = eraser.onPointerDown(contextFor({point: {x: 500, y: 500}}));

    assert.equal(eraser.onPointerUp(contextFor({draft: first.draft})).commit, false);
});

/* ── เครื่องมือเลือก ─────────────────────────────────────────── */

test('คลิกที่ชิ้นงานเลือกชิ้นนั้น และคลิกที่ว่างล้างการเลือก', () => {
    const select = toolFor('select');
    const scene = createScene([rect('a', {x: 0, y: 0, w: 50, h: 50})]);

    assert.deepEqual(select.onPointerDown(contextFor({scene, point: {x: 25, y: 25}})).selection, ['a']);
    assert.deepEqual(
        select.onPointerDown(contextFor({scene, selection: ['a'], point: {x: 400, y: 400}})).selection,
        []
    );
});

/*
 * การกดลงบนชิ้นที่อยู่ในกลุ่มที่เลือกอยู่แล้วต้องไม่ล้างกลุ่มทิ้ง ไม่งั้นการลาก
 * กลุ่มจะเป็นไปไม่ได้เลย เพราะการกดเพื่อเริ่มลากจะเหลือชิ้นเดียวเสมอ
 */
test('กดลงบนชิ้นที่อยู่ในกลุ่มแล้วยังคงทั้งกลุ่มไว้', () => {
    const select = toolFor('select');
    const scene = createScene([
        rect('a', {x: 0, y: 0, w: 50, h: 50}),
        rect('b', {x: 60, y: 0, w: 50, h: 50}),
    ]);

    const result = select.onPointerDown(contextFor({scene, selection: ['a', 'b'], point: {x: 25, y: 25}}));

    assert.deepEqual(result.selection, ['a', 'b']);
    assert.equal(result.draft.mode, 'move');
    assert.equal(result.draft.startElements.length, 2, 'ต้องเตรียมย้ายทั้งกลุ่ม');
});

test('กด Shift ค้างสลับชิ้นเข้าออกจากกลุ่ม', () => {
    const select = toolFor('select');
    const scene = createScene([rect('a', {x: 0, y: 0, w: 50, h: 50})]);

    assert.deepEqual(
        select.onPointerDown(contextFor({scene, selection: ['b'], point: {x: 25, y: 25}, additive: true})).selection,
        ['b', 'a']
    );
    assert.deepEqual(
        select.onPointerDown(contextFor({scene, selection: ['a', 'b'], point: {x: 25, y: 25}, additive: true})).selection,
        ['b']
    );
});

test('การลากย้ายเลื่อนทุกชิ้นในกลุ่มเท่ากัน', () => {
    const select = toolFor('select');
    const scene = createScene([
        rect('a', {x: 0, y: 0, w: 50, h: 50}),
        rect('b', {x: 100, y: 0, w: 50, h: 50}),
    ]);

    const down = select.onPointerDown(contextFor({scene, selection: ['a', 'b'], point: {x: 25, y: 25}}));
    const moved = select.onPointerMove(contextFor({scene, draft: down.draft, point: {x: 45, y: 35}}));

    assert.equal(moved.scene.elements[0].x, 20);
    assert.equal(moved.scene.elements[1].x, 120);
    assert.equal(moved.scene.elements[0].y, 10);
});

/*
 * ลำดับการตัดสินสำคัญ: มือจับต้องมาก่อนตัวชิ้นงาน เพราะมือจับวางอยู่บนขอบของ
 * กรอบซึ่งทับกับตัวชิ้นงานพอดี ถ้าเช็คชิ้นงานก่อน จะย่อขยายไม่ได้เลย
 */
test('กดที่มือจับเริ่มโหมดย่อขยาย ไม่ใช่โหมดย้าย', () => {
    const select = toolFor('select');
    const scene = createScene([rect('a', {x: 0, y: 0, w: 100, h: 100})]);

    const result = select.onPointerDown(contextFor({scene, selection: ['a'], point: {x: 100, y: 100}}));

    assert.equal(result.draft.mode, 'resize');
    assert.equal(result.draft.handle, 'se');
});

test('การลากมือจับย่อขยายชิ้นงาน', () => {
    const select = toolFor('select');
    const scene = createScene([rect('a', {x: 0, y: 0, w: 100, h: 100})]);

    const down = select.onPointerDown(contextFor({scene, selection: ['a'], point: {x: 100, y: 100}}));
    const resized = select.onPointerMove(contextFor({scene, draft: down.draft, point: {x: 200, y: 150}}));

    assert.equal(resized.scene.elements[0].w, 200);
    assert.equal(resized.scene.elements[0].h, 150);
});

test('กรอบเลือกที่ลากบนที่ว่างเก็บชิ้นที่อยู่ในกรอบทั้งชิ้น', () => {
    const select = toolFor('select');
    const scene = createScene([
        rect('inside', {x: 10, y: 10, w: 20, h: 20}),
        rect('outside', {x: 300, y: 300, w: 20, h: 20}),
    ]);

    const down = select.onPointerDown(contextFor({scene, point: {x: 0, y: 0}}));
    const dragging = select.onPointerMove(contextFor({scene, draft: down.draft, point: {x: 100, y: 100}}));

    assert.deepEqual(dragging.selection, ['inside']);
    assert.equal(dragging.preview.type, 'marquee');
    assert.equal(select.onPointerUp(contextFor({draft: dragging.draft})).commit, false,
        'การเลือกไม่เปลี่ยนเนื้อหา จึงไม่กินก้าว undo');
});

test('การคลิกเลือกเฉย ๆ โดยไม่ลากไม่กินก้าว undo', () => {
    const select = toolFor('select');
    const scene = createScene([rect('a', {x: 0, y: 0, w: 50, h: 50})]);

    const down = select.onPointerDown(contextFor({scene, point: {x: 25, y: 25}}));

    assert.equal(select.onPointerUp(contextFor({draft: down.draft})).commit, false);
});

/* ── เครื่องมือกับสิทธิ์ และการลดจุด ─────────────────────────── */

test('เครื่องมือที่ไม่แก้เนื้อหาถูกระบุไว้ชัดเจน', () => {
    assert.equal(isReadOnlyTool('hand'), true);
    assert.equal(isReadOnlyTool('select'), true);
    assert.equal(isReadOnlyTool('pen'), false);
    assert.equal(isReadOnlyTool('eraser'), false);
});

test('ชื่อเครื่องมือที่ไม่รู้จักถอยไปใช้เครื่องมือเลือก', () => {
    assert.equal(toolFor('ไม่มีอยู่จริง').name, 'select');
});

test('การลดจุดตัดจุดที่อยู่บนเส้นตรงเดียวกันทิ้ง', () => {
    const straight = [[0, 0], [10, 0], [20, 0], [30, 0]];

    assert.deepEqual(simplifyPoints(straight, 1), [[0, 0], [30, 0]]);
});

test('การลดจุดเก็บจุดที่ทำให้รูปร่างเปลี่ยนจริงไว้', () => {
    const corner = [[0, 0], [10, 0], [10, 40], [10, 80]];
    const reduced = simplifyPoints(corner, 1);

    assert.deepEqual(reduced, [[0, 0], [10, 0], [10, 80]]);
});

test('การลดจุดไม่แตะเส้นที่มีสองจุดหรือน้อยกว่า', () => {
    assert.deepEqual(simplifyPoints([[0, 0]], 1), [[0, 0]]);
    assert.deepEqual(simplifyPoints([[0, 0], [5, 5]], 1), [[0, 0], [5, 5]]);
    assert.deepEqual(simplifyPoints(null, 1), []);
});

/*
 * เส้นที่มีจุดเดียวต้องได้คำสั่งวาดที่ยาวศูนย์ ซึ่งเมื่อคู่กับ stroke-linecap
 * แบบ round จะแสดงเป็นจุดกลม ตรงกับที่ผู้ใช้คาดหวังเมื่อแตะครั้งเดียวแล้วปล่อย
 */
test('แปลงจุดเป็นคำสั่งวาดของ SVG', () => {
    assert.equal(pointsToPathData([[0, 0], [10, 5]]), 'M 0 0 L 10 5');
    assert.equal(pointsToPathData([[3, 4]]), 'M 3 4 L 3 4');
    assert.equal(pointsToPathData([]), '');
});
