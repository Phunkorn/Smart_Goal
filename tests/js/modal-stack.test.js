import test from 'node:test';
import assert from 'node:assert/strict';
import {mountDom, pressKey} from './helpers/dom.js';
import {
    createModalStack,
    layerZIndex,
    popModal,
    pushModal,
    shouldLockBody,
    shouldPaintBackdrop,
    topOf,
} from '../../resources/js/components/modal-stack.js';

/* ---------- pure state ---------- */

test('push และ pop ไม่ทำให้เกิดรายการซ้ำ', () => {
    let stack = pushModal([], 'a');
    stack = pushModal(stack, 'b');
    stack = pushModal(stack, 'a');

    assert.deepEqual(stack, ['a', 'b']);
    assert.deepEqual(popModal(stack, 'a'), ['b']);
    assert.equal(topOf(stack), 'b');
    assert.equal(topOf([]), null);
});

test('body ถูกล็อกตราบใดที่ยังมี modal เปิดอยู่', () => {
    assert.equal(shouldLockBody([]), false);
    assert.equal(shouldLockBody(['a']), true);
    assert.equal(shouldLockBody(['a', 'b']), true);
});

test('วาด backdrop เฉพาะชั้นบนสุด', () => {
    const stack = ['a', 'b'];

    assert.equal(shouldPaintBackdrop(stack, 'b'), true);
    assert.equal(shouldPaintBackdrop(stack, 'a'), false);
});

test('z-index ไล่ตามความลึกของชั้น', () => {
    const stack = ['a', 'b', 'c'];

    assert.equal(layerZIndex(stack, 'a'), 1200);
    assert.equal(layerZIndex(stack, 'b'), 1210);
    assert.equal(layerZIndex(stack, 'c'), 1220);
    assert.equal(layerZIndex(stack, 'ไม่มี'), 1200);
});

/* ---------- DOM behaviour ---------- */

function mountStack(t) {
    const env = mountDom();
    t.after(env.cleanup);
    env.document.body.innerHTML = `
        <button id="opener-a">เปิด A</button>
        <button id="opener-b">เปิด B</button>
        <div id="a" hidden><button id="a-close">ปิด A</button></div>
        <div id="b" hidden><button id="b-close">ปิด B</button></div>
    `;

    return {
        ...env,
        layers: createModalStack(env.document),
        a: env.document.getElementById('a'),
        b: env.document.getElementById('b'),
        openerA: env.document.getElementById('opener-a'),
        openerB: env.document.getElementById('opener-b'),
    };
}

test('เปิด modal แล้ว body ถูกล็อกและ backdrop อยู่ชั้นบนสุดเพียงชั้นเดียว', (t) => {
    const ui = mountStack(t);

    ui.layers.open(ui.a, ui.openerA);
    assert.equal(ui.a.hidden, false);
    assert.equal(ui.document.body.classList.contains('modal-open'), true);
    assert.equal(ui.a.dataset.modalBackdrop, 'on');

    ui.layers.open(ui.b, ui.openerB);
    assert.equal(ui.a.dataset.modalBackdrop, 'off', 'ชั้นล่างต้องไม่วาด backdrop ซ้อน');
    assert.equal(ui.b.dataset.modalBackdrop, 'on');
    assert.ok(Number(ui.b.style.zIndex) > Number(ui.a.style.zIndex));
});

test('ชั้นล่างถูกทำให้ inert และคืนสภาพเมื่อกลับมาเป็นชั้นบนสุด', (t) => {
    const ui = mountStack(t);

    ui.layers.open(ui.a);
    assert.equal(ui.a.hasAttribute('inert'), false);

    ui.layers.open(ui.b);
    assert.equal(ui.a.hasAttribute('inert'), true);
    assert.equal(ui.b.hasAttribute('inert'), false);

    ui.layers.close(ui.b);
    assert.equal(ui.a.hasAttribute('inert'), false);
    assert.equal(ui.a.dataset.modalBackdrop, 'on');
});

test('ปิดชั้นบนแล้ว body ต้องยังล็อกอยู่ถ้ายังมีชั้นล่างเปิดค้าง', (t) => {
    const ui = mountStack(t);

    ui.layers.open(ui.a);
    ui.layers.open(ui.b);
    ui.layers.close(ui.b);

    assert.equal(ui.document.body.classList.contains('modal-open'), true, 'นี่คือบั๊กเดิมที่ปลดล็อกเร็วเกินไป');

    ui.layers.close(ui.a);
    assert.equal(ui.document.body.classList.contains('modal-open'), false);
});

test('focus เข้า dialog ตอนเปิด และคืนไปยังปุ่มที่ใช้เปิดตอนปิด', (t) => {
    const ui = mountStack(t);

    ui.openerA.focus();
    ui.layers.open(ui.a, ui.openerA);
    assert.equal(ui.document.activeElement.id, 'a-close');

    ui.layers.close(ui.a);
    assert.equal(ui.document.activeElement.id, 'opener-a');
});

test('Escape ส่งสัญญาณให้เฉพาะชั้นบนสุด', (t) => {
    const ui = mountStack(t);
    const dismissed = [];
    ui.a.addEventListener('modalstack:dismiss', () => dismissed.push('a'));
    ui.b.addEventListener('modalstack:dismiss', () => dismissed.push('b'));

    ui.layers.open(ui.a);
    ui.layers.open(ui.b);

    pressKey(ui.document, 'Escape');
    assert.deepEqual(dismissed, ['b'], 'ชั้นล่างต้องไม่ถูกปิดไปพร้อมกัน');

    ui.layers.close(ui.b);
    pressKey(ui.document, 'Escape');
    assert.deepEqual(dismissed, ['b', 'a']);
});

test('isTop บอกชั้นบนสุดได้ถูกต้องและปิดซ้ำไม่พัง', (t) => {
    const ui = mountStack(t);

    ui.layers.open(ui.a);
    ui.layers.open(ui.b);
    assert.equal(ui.layers.isTop(ui.b), true);
    assert.equal(ui.layers.isTop(ui.a), false);

    ui.layers.close(ui.b);
    ui.layers.close(ui.b);
    assert.deepEqual(ui.layers.stack.length, 1);
    assert.equal(ui.layers.isTop(ui.a), true);
});

test('ปิดทุกชั้นแล้วสถานะการนำเสนอถูกล้างทั้งหมด', (t) => {
    const ui = mountStack(t);

    ui.layers.open(ui.a);
    ui.layers.open(ui.b);
    ui.layers.close(ui.b);
    ui.layers.close(ui.a);

    [ui.a, ui.b].forEach((element) => {
        assert.equal(element.hidden, true);
        assert.equal(element.hasAttribute('inert'), false);
        assert.equal(element.hasAttribute('data-modal-backdrop'), false);
        assert.equal(element.style.zIndex, '');
    });
});
