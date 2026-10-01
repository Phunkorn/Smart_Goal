import test from 'node:test';
import assert from 'node:assert/strict';
import {
    PASTE_OFFSET,
    pasteSourceFor,
    copyElements,
    duplicateElements,
    pasteFromClipboard,
} from '../../resources/js/pages/workspace/clipboard.js';
import {createScene} from '../../resources/js/pages/workspace/scene.js';

/*
 * คัดลอก วาง และทำสำเนา
 *
 * ข้อที่สำคัญที่สุดคือรหัสของสำเนาต้องไม่ซ้ำกับชิ้นใดในฉาก เพราะเซิร์ฟเวอร์
 * ปฏิเสธทั้งเอกสารเมื่อเจอรหัสซ้ำ ผู้ใช้จะเสียการบันทึกทั้งกระดาน
 */

const rect = (id, x = 0, z = 1) => ({
    id, type: 'rect', z, x, y: 0, w: 50, h: 50, stroke: '#1f2937', strokeWidth: 2, fill: 'none',
});

const pen = (id) => ({
    id, type: 'pen', z: 3, stroke: '#1f2937', strokeWidth: 4, points: [[0, 0], [10, 10]],
});

const counter = (prefix = 'copy') => {
    let next = 0;

    return () => `${prefix}-${++next}`;
};

test('คัดลอกเก็บชิ้นที่เลือกตามลำดับในฉาก ไม่ใช่ตามลำดับที่คลิก', () => {
    const scene = createScene([rect('a'), rect('b', 100), rect('c', 200)]);
    const clipboard = copyElements(scene, ['c', 'a']);

    assert.deepEqual(clipboard.elements.map((element) => element.id), ['a', 'c']);
    assert.equal(clipboard.pasteCount, 0);
});

test('ไม่ได้เลือกอะไร คัดลอกแล้วไม่มีอะไรในคลิปบอร์ด', () => {
    assert.equal(copyElements(createScene([rect('a')]), []), null);
});

test('วางได้สำเนาที่มีรหัสใหม่ เลื่อนออกจากต้นฉบับ และถูกเลือกไว้', () => {
    const scene = createScene([rect('a'), pen('p')]);
    const clipboard = copyElements(scene, ['a', 'p']);
    const pasted = pasteFromClipboard(scene, clipboard, counter());

    assert.equal(pasted.scene.elements.length, 4);
    assert.deepEqual(pasted.selection, ['copy-1', 'copy-2']);

    const [rectCopy, penCopy] = pasted.scene.elements.slice(2);
    assert.equal(rectCopy.x, PASTE_OFFSET);
    assert.equal(rectCopy.y, PASTE_OFFSET);
    assert.deepEqual(penCopy.points, [[PASTE_OFFSET, PASTE_OFFSET], [10 + PASTE_OFFSET, 10 + PASTE_OFFSET]]);
});

test('วางซ้ำแต่ละครั้งเลื่อนออกไปอีกขั้น ไม่ซ้อนทับสำเนาก่อนหน้าพอดี', () => {
    const scene = createScene([rect('a')]);
    const ids = counter();
    const first = pasteFromClipboard(scene, copyElements(scene, ['a']), ids);
    const second = pasteFromClipboard(first.scene, first.clipboard, ids);

    assert.equal(second.scene.elements.at(-1).x, PASTE_OFFSET * 2);
    assert.equal(second.clipboard.pasteCount, 2);
});

test('รหัสใหม่ไม่ซ้ำกับชิ้นในฉาก แม้ตัวสร้างรหัสจะคืนรหัสที่มีอยู่แล้ว', () => {
    const scene = createScene([rect('dup-1'), rect('dup-2', 100)]);
    const colliding = counter('dup');
    const pasted = pasteFromClipboard(scene, copyElements(scene, ['dup-1', 'dup-2']), colliding);
    const ids = pasted.scene.elements.map((element) => element.id);

    assert.equal(new Set(ids).size, ids.length, `รหัสซ้ำ: ${ids.join(', ')}`);
});

test('สำเนาอยู่บนสุดของลำดับการซ้อน และคงลำดับบนล่างเดิมระหว่างกันเอง', () => {
    const scene = createScene([rect('a', 0, 1), rect('b', 100, 2), rect('c', 200, 5)]);
    const pasted = pasteFromClipboard(scene, copyElements(scene, ['a', 'b']), counter());
    const copies = pasted.scene.elements.slice(3);

    assert.deepEqual(copies.map((copy) => copy.z), [6, 7]);
});

test('ต้นฉบับไม่ถูกแตะเลย', () => {
    const original = rect('a');
    const scene = createScene([original]);

    pasteFromClipboard(scene, copyElements(scene, ['a']), counter());

    assert.equal(original.x, 0);
    assert.equal(scene.elements.length, 1);
});

test('มุมหมุนและคุณสมบัติอื่นติดไปกับสำเนา', () => {
    const scene = createScene([{...rect('a'), rotation: 30, fill: '#fde68a'}]);
    const [copy] = pasteFromClipboard(scene, copyElements(scene, ['a']), counter()).scene.elements.slice(1);

    assert.equal(copy.rotation, 30);
    assert.equal(copy.fill, '#fde68a');
});

test('ทำสำเนาชิ้นที่เลือกโดยไม่ต้องผ่านคลิปบอร์ด', () => {
    const scene = createScene([rect('a'), rect('b', 100)]);
    const duplicated = duplicateElements(scene, ['a', 'b'], counter());

    assert.equal(duplicated.scene.elements.length, 4);
    assert.deepEqual(duplicated.selection, ['copy-1', 'copy-2']);
    assert.equal(duplicated.scene.elements[3].x, 100 + PASTE_OFFSET);
});

test('ทำสำเนาโดยไม่ได้เลือกอะไรไม่เกิดอะไรขึ้น', () => {
    assert.equal(duplicateElements(createScene([rect('a')]), [], counter()), null);
});

/* ── ของที่คัดลอกล่าสุดต้องชนะ ─────────────────────────────────── */

test('เครื่องหมายในคลิปบอร์ดตรงกับชิ้นงานที่เก็บไว้ วางชิ้นงาน แม้จะมีรูปติดมาด้วย', () => {
    const clipboard = copyElements(createScene([rect('a')]), ['a'], 'tok-1');

    assert.equal(pasteSourceFor({marker: 'tok-1', imageCount: 1, clipboard}), 'board');
});

test('ไม่มีเครื่องหมาย แต่มีรูป แปลว่าไปแคปรูปมาทีหลัง วางรูป', () => {
    const clipboard = copyElements(createScene([rect('a')]), ['a'], 'tok-1');

    assert.equal(pasteSourceFor({imageCount: 1, clipboard}), 'images');
    assert.equal(pasteSourceFor({imageCount: 2, clipboard: null}), 'images');
});

test('เบราว์เซอร์ไม่ได้ส่งเครื่องหมายลงคลิปบอร์ด ถอยไปวางชิ้นงานที่เก็บไว้', () => {
    const clipboard = copyElements(createScene([rect('a')]), ['a'], 'tok-1');

    assert.equal(pasteSourceFor({clipboard}), 'board');
});

test('เครื่องหมายจากอีกแท็บวางข้ามไม่ได้ และไม่มีอะไรเลยก็ไม่วาง', () => {
    const clipboard = copyElements(createScene([rect('a')]), ['a'], 'tok-1');

    assert.equal(pasteSourceFor({marker: 'tok-other-tab', clipboard}), null);
    assert.equal(pasteSourceFor({}), null);
});
