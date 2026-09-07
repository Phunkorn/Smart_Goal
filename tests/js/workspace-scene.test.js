import test from 'node:test';
import assert from 'node:assert/strict';
import {
    addElement,
    bringToFront,
    createScene,
    deserialize,
    elementCount,
    findById,
    isSameContent,
    nextZ,
    removeElements,
    replaceElements,
    sendToBack,
    sequentialIdFactory,
    serialize,
    updateElement,
} from '../../resources/js/pages/workspace/scene.js';
import {createHistory, canRedo, canUndo, current, push, redo, reset, undo}
    from '../../resources/js/pages/workspace/history.js';

/*
 * ตัวแบบข้อมูลของกระดานและประวัติ undo/redo
 *
 * ฉากเป็นค่าที่ไม่เปลี่ยนแปลงในที่ ประวัติจึงเก็บเป็นการอ้างถึงฉากแต่ละรุ่นได้ตรง ๆ
 * เทสต์ชุดนี้ยืนยันทั้งสองสมบัติ เพราะถ้าฉากถูกแก้ในที่เมื่อไร undo จะพากลับไป
 * หาสถานะที่ถูกแก้ไปแล้วโดยไม่มีใครรู้
 */

const el = (id, overrides = {}) => ({id, type: 'rect', z: 1, x: 0, y: 0, w: 10, h: 10, ...overrides});

test('อ่านเอกสารที่ไม่สมบูรณ์ได้โดยไม่ล้ม', () => {
    assert.deepEqual(deserialize(null).elements, []);
    assert.deepEqual(deserialize({}).elements, []);
    assert.deepEqual(deserialize({elements: 'ไม่ใช่อาร์เรย์'}).elements, []);
    assert.equal(deserialize({elements: [el('a')]}).elements.length, 1);
});

test('แปลงเป็นเอกสารแล้วอ่านกลับได้ค่าเดิม', () => {
    const scene = createScene([el('a'), el('b')]);
    const roundTrip = deserialize(serialize(scene));

    assert.deepEqual(roundTrip.elements, scene.elements);
    assert.equal(serialize(scene).schema, 1);
});

test('การเพิ่มชิ้นงานคืนฉากใหม่โดยไม่แก้ของเดิม', () => {
    const before = createScene([el('a')]);
    const after = addElement(before, el('b'));

    assert.equal(before.elements.length, 1, 'ฉากเดิมต้องไม่ถูกแก้');
    assert.equal(after.elements.length, 2);
    assert.notEqual(before, after);
});

test('ชิ้นใหม่อยู่ท้ายรายการเสมอ เพราะลำดับคือลำดับการซ้อนทับ', () => {
    const scene = addElement(createScene([el('a')]), el('b'));

    assert.deepEqual(scene.elements.map((e) => e.id), ['a', 'b']);
});

test('ค่าลำดับชั้นถัดไปมากกว่าค่าสูงสุดที่มีอยู่', () => {
    assert.equal(nextZ(createScene([])), 1);
    assert.equal(nextZ(createScene([el('a', {z: 3}), el('b', {z: 7})])), 8);
});

test('แก้ไขชิ้นงานทีละชิ้นและหลายชิ้นพร้อมกัน', () => {
    const scene = createScene([el('a'), el('b')]);

    assert.equal(updateElement(scene, 'a', {x: 99}).elements[0].x, 99);
    assert.equal(updateElement(scene, 'a', {x: 99}).elements[1].x, 0, 'ชิ้นอื่นต้องไม่ถูกแตะ');

    const replaced = replaceElements(scene, [el('b', {x: 50})]);

    assert.equal(replaced.elements[0].x, 0);
    assert.equal(replaced.elements[1].x, 50);
});

test('ลบหลายชิ้นพร้อมกัน', () => {
    const scene = removeElements(createScene([el('a'), el('b'), el('c')]), ['a', 'c']);

    assert.deepEqual(scene.elements.map((e) => e.id), ['b']);
});

/*
 * ลำดับในอาร์เรย์คือสิ่งที่ตัวเรนเดอร์ใช้ ส่วน z คือสิ่งที่ถูกบันทึกลงเซิร์ฟเวอร์
 * สองอย่างนี้ต้องไม่ขัดกัน ไม่งั้นการโหลดกระดานกลับมาจะได้ลำดับซ้อนทับคนละแบบ
 */
test('การย้ายไปบนสุดหรือล่างสุดปรับทั้งลำดับและค่า z ให้ตรงกัน', () => {
    const scene = createScene([el('a'), el('b'), el('c')]);

    const front = bringToFront(scene, ['a']);
    assert.deepEqual(front.elements.map((e) => e.id), ['b', 'c', 'a']);
    assert.deepEqual(front.elements.map((e) => e.z), [1, 2, 3]);

    const back = sendToBack(scene, ['c']);
    assert.deepEqual(back.elements.map((e) => e.id), ['c', 'a', 'b']);
    assert.deepEqual(back.elements.map((e) => e.z), [1, 2, 3]);
});

test('ค้นหาตามรหัส และนับจำนวนชิ้นงาน', () => {
    const scene = createScene([el('a'), el('b')]);

    assert.equal(findById(scene, 'b').id, 'b');
    assert.equal(findById(scene, 'zz'), null);
    assert.equal(elementCount(scene), 2);
});

test('เทียบเนื้อหาสองฉากได้แม้เป็นคนละอ็อบเจ็กต์', () => {
    assert.equal(isSameContent(createScene([el('a')]), createScene([el('a')])), true);
    assert.equal(isSameContent(createScene([el('a')]), createScene([el('b')])), false);
});

/*
 * ตัวสร้างรหัสถูกฉีดเข้ามาแทนการเรียก crypto.randomUUID โดยตรง เพราะ jsdom
 * ไม่ได้มีมันบน window เสมอไป และการฉีดทำให้เทสต์ได้รหัสที่คาดเดาได้
 */
test('ตัวสร้างรหัสให้ค่าที่ไม่ซ้ำกันและขึ้นต้นตามที่กำหนด', () => {
    const factory = sequentialIdFactory('note');
    const ids = [factory(), factory(), factory()];

    assert.equal(new Set(ids).size, 3);
    ids.forEach((id) => assert.match(id, /^note-\d+-/));
});

/* ── ประวัติ undo/redo ───────────────────────────────────────── */

test('ย้อนกลับและทำซ้ำเดินไปตามก้าวที่บันทึกไว้', () => {
    const a = createScene([el('a')]);
    const b = createScene([el('a'), el('b')]);

    let past = createHistory(a);
    past = push(past, b);

    assert.equal(current(past), b);
    assert.equal(canUndo(past), true);
    assert.equal(canRedo(past), false);

    past = undo(past);
    assert.equal(current(past), a);
    assert.equal(canRedo(past), true);

    past = redo(past);
    assert.equal(current(past), b);
});

test('ย้อนกลับที่ก้าวแรกและทำซ้ำที่ก้าวสุดท้ายไม่ทำอะไร', () => {
    const past = createHistory(createScene([]));

    assert.equal(undo(past), past);
    assert.equal(redo(past), past);
});

test('การแก้หลังย้อนกลับตัดก้าวที่อยู่ข้างหน้าทิ้ง', () => {
    const a = createScene([el('a')]);
    const b = createScene([el('b')]);
    const c = createScene([el('c')]);

    let past = push(push(createHistory(a), b), c);
    past = undo(past);
    past = push(past, createScene([el('d')]));

    assert.equal(canRedo(past), false, 'ก้าวที่ถูกตัดต้องทำซ้ำกลับมาไม่ได้');
    assert.equal(current(past).elements[0].id, 'd');
});

test('การบันทึกสถานะเดิมซ้ำไม่กินก้าวเพิ่ม', () => {
    const a = createScene([el('a')]);
    const past = push(createHistory(a), a);

    assert.equal(past.entries.length, 1);
});

test('ประวัติมีเพดานจำนวนก้าว และตัดก้าวเก่าสุดออก', () => {
    let past = createHistory(createScene([el('0')]), {limit: 3});

    for (let index = 1; index <= 5; index += 1) {
        past = push(past, createScene([el(String(index))]));
    }

    assert.equal(past.entries.length, 3);
    assert.equal(current(past).elements[0].id, '5');
    assert.equal(past.entries[0].elements[0].id, '3', 'ก้าวเก่าสุดถูกตัดออก');
});

/*
 * หลังโหลดเนื้อหาฉบับล่าสุดจากเซิร์ฟเวอร์เมื่อชนเวอร์ชัน ประวัติเดิมอ้างถึงฉาก
 * ที่ไม่มีอยู่บนเซิร์ฟเวอร์แล้ว การกด undo ต่อจะพาผู้ใช้กลับไปหาสถานะที่บันทึกไม่ได้
 */
test('การเริ่มประวัติใหม่ล้างทั้งก้าวที่ย้อนได้และก้าวที่ทำซ้ำได้', () => {
    const past = push(createHistory(createScene([el('a')])), createScene([el('b')]));
    const fresh = reset(past, createScene([el('server')]));

    assert.equal(canUndo(fresh), false);
    assert.equal(canRedo(fresh), false);
    assert.equal(current(fresh).elements[0].id, 'server');
});
