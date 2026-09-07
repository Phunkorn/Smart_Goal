import test from 'node:test';
import assert from 'node:assert/strict';
import {
    clampScale,
    createCamera,
    cssTransform,
    fitToBounds,
    panBy,
    screenToWorld,
    svgTransform,
    worldToScreen,
    zoomAt,
} from '../../resources/js/pages/workspace/camera.js';

/*
 * กล้องของผืนผ้าใบ
 *
 * ไม่ต้องใช้ DOM เลย เพราะกล้องเป็นคณิตศาสตร์ล้วน ๆ ทั้งชั้น SVG และชั้น overlay
 * อ่านค่าจากกล้องตัวเดียวกัน เทสต์ชุดนี้จึงคุ้มครองการทำงานของทั้งสองชั้นพร้อมกัน
 */

const LIMITS = {minScale: 0.1, maxScale: 4};

test('แปลงพิกัดหน้าจอไปโลกแล้วกลับ ต้องได้ค่าเดิม', () => {
    const camera = {x: 120, y: -40, scale: 1.75};
    const screen = {x: 321, y: 654};

    const roundTrip = worldToScreen(camera, screenToWorld(camera, screen));

    assert.ok(Math.abs(roundTrip.x - screen.x) < 1e-9);
    assert.ok(Math.abs(roundTrip.y - screen.y) < 1e-9);
});

test('กล้องที่ยังไม่ขยับ พิกัดหน้าจอเท่ากับพิกัดโลก', () => {
    const camera = createCamera();

    assert.deepEqual(screenToWorld(camera, {x: 10, y: 20}), {x: 10, y: 20});
});

test('การเลื่อนบวกระยะเข้ากับตำแหน่งกล้อง โดยไม่แตะระดับซูม', () => {
    const moved = panBy({x: 10, y: 10, scale: 2}, 5, -3);

    assert.deepEqual(moved, {x: 15, y: 7, scale: 2});
});

/*
 * นี่คือพฤติกรรมที่ทำให้การซูมรู้สึกถูกต้อง จุดใต้เคอร์เซอร์ต้องไม่ขยับ
 * ถ้าพังเมื่อไร ภาพจะไหลหนีทุกครั้งที่ผู้ใช้หมุนล้อ
 */
test('การซูมตรึงจุดอ้างอิงไว้กับที่', () => {
    const camera = {x: 30, y: -15, scale: 1};
    const anchor = {x: 400, y: 300};
    const before = screenToWorld(camera, anchor);

    const zoomed = zoomAt(camera, anchor, 1.6, LIMITS);
    const after = screenToWorld(zoomed, anchor);

    assert.ok(Math.abs(after.x - before.x) < 1e-9, 'พิกัดโลกใต้จุดอ้างอิงต้องไม่เปลี่ยน');
    assert.ok(Math.abs(after.y - before.y) < 1e-9);
    assert.equal(zoomed.scale, 1.6);
});

test('ระดับซูมถูกบีบให้อยู่ในช่วงที่กำหนด', () => {
    assert.equal(clampScale(100, LIMITS), 4);
    assert.equal(clampScale(0.001, LIMITS), 0.1);
    assert.equal(clampScale(2, LIMITS), 2);
});

/*
 * เมื่อชนเพดานซูมแล้ว ต้องไม่ขยับตำแหน่งกล้องเลย
 * ไม่งั้นการหมุนล้อค้างไว้จะทำให้ภาพไหลทั้งที่ระดับซูมเท่าเดิม
 */
test('การซูมที่ชนเพดานแล้วคืนกล้องตัวเดิมโดยไม่เลื่อน', () => {
    const camera = {x: 10, y: 20, scale: 4};

    assert.equal(zoomAt(camera, {x: 100, y: 100}, 2, LIMITS), camera);
});

test('จัดให้พอดีจอวางกรอบไว้กลางพื้นที่แสดงผล', () => {
    const bounds = {x: 100, y: 200, w: 400, h: 200};
    const viewport = {width: 800, height: 600};

    const camera = fitToBounds(createCamera(), bounds, viewport, LIMITS, 0);
    const center = worldToScreen(camera, {x: bounds.x + bounds.w / 2, y: bounds.y + bounds.h / 2});

    assert.ok(Math.abs(center.x - 400) < 1e-9);
    assert.ok(Math.abs(center.y - 300) < 1e-9);
    assert.equal(camera.scale, 2, 'ต้องเลือกด้านที่จำกัดกว่า (800/400 = 2 เทียบกับ 600/200 = 3)');
});

/*
 * กระดานเปล่าไม่มีกรอบให้จัด การหารด้วยศูนย์จะทำให้ scale เป็น Infinity
 * แล้วผืนผ้าใบหายไปทั้งหน้า
 */
test('จัดให้พอดีจอบนกระดานเปล่าคืนกล้องเดิม', () => {
    const camera = createCamera({x: 5, y: 5, scale: 1.5});

    assert.equal(fitToBounds(camera, null, {width: 800, height: 600}, LIMITS), camera);
    assert.equal(fitToBounds(camera, {x: 0, y: 0, w: 0, h: 0}, {width: 800, height: 600}, LIMITS), camera);
});

test('ค่า transform ของ SVG และ CSS สื่อถึงกล้องตัวเดียวกัน', () => {
    const camera = {x: 12, y: -8, scale: 1.25};

    assert.equal(svgTransform(camera), 'translate(12 -8) scale(1.25)');
    assert.equal(cssTransform(camera), 'translate(12px, -8px) scale(1.25)');
});
