import test from 'node:test';
import assert from 'node:assert/strict';
import {mountDom} from './helpers/dom.js';
import {pointerDown, pointerMove, pointerUp, stubStageRect} from './helpers/pointer.js';
import {initPointer} from '../../resources/js/pages/workspace/pointer.js';

/*
 * ด่านของ pointer.js ที่ตัดสินว่า "การกดครั้งนี้เป็นของเราหรือของเบราว์เซอร์"
 *
 * กดลงในกล่องข้อความที่กำลังพิมพ์อยู่ต้องเป็นของเบราว์เซอร์ ไม่งั้นลากเลือก
 * ข้อความในโน้ตไม่ได้ แต่เครื่องมือที่ทำงานกับมุมมองอย่างเดียว (มือเลื่อน
 * กระดาน) ไม่ได้แตะเนื้อหาในกล่องเลย จึงต้องได้ท่าลากนั้นไป ไม่งั้นกล่องที่
 * เปิดโหมดแก้ไขค้างอยู่หนึ่งใบจะกลายเป็นหลุมที่เลื่อนกระดานไม่ได้ และผู้ใช้
 * ต้องไปคลิกที่อื่นให้กล่องปิดก่อนทุกครั้ง
 */

const mount = (options = {}) => {
    const dom = mountDom(`<!doctype html><html><body>
        <div data-stage>
            <div data-box contenteditable="true">โน้ต</div>
        </div>
    </body></html>`);

    const stage = dom.document.querySelector('[data-stage]');
    stubStageRect(stage, {width: 800, height: 600});

    const calls = {down: [], move: [], up: []};

    initPointer(stage, {
        getCamera: () => ({x: 0, y: 0, scale: 1}),
        onDown: (event) => calls.down.push(event),
        onMove: (event) => calls.move.push(event),
        onUp: (event) => calls.up.push(event),
        ...options,
    });

    return {dom, stage, calls, box: dom.document.querySelector('[data-box]')};
};

test('กดลงในกล่องข้อความที่กำลังพิมพ์อยู่ ยังเป็นของเบราว์เซอร์ตามเดิม', () => {
    const env = mount();

    try {
        pointerDown(env.box, {x: 150, y: 150});
        pointerMove(env.box, {x: 180, y: 160});
        pointerUp(env.box, {x: 180, y: 160});

        assert.deepEqual(
            [env.calls.down.length, env.calls.move.length, env.calls.up.length],
            [0, 0, 0],
            'ท่าลากในกล่องข้อความต้องไม่ถูกส่งต่อให้เครื่องมือเลย'
        );
    } finally {
        env.dom.cleanup();
    }
});

test('เครื่องมือที่ขอท่านี้ได้รับการลากแม้เริ่มลงบนกล่องข้อความ', () => {
    const env = mount({claimsEditableTarget: () => true});

    try {
        pointerDown(env.box, {x: 150, y: 150});
        pointerMove(env.box, {x: 210, y: 180});
        pointerUp(env.box, {x: 210, y: 180});

        assert.equal(env.calls.down.length, 1, 'มือเลื่อนกระดานต้องได้เริ่มท่าลาก');
        assert.deepEqual(env.calls.move.at(-1).screenPoint, {x: 210, y: 180});
        assert.equal(env.calls.up.length, 1);
    } finally {
        env.dom.cleanup();
    }
});

test('กดบนผืนผ้าใบเปล่ายังเป็นของเครื่องมือเหมือนเดิม', () => {
    const env = mount();

    try {
        pointerDown(env.stage, {x: 20, y: 20});
        pointerUp(env.stage, {x: 20, y: 20});

        assert.equal(env.calls.down.length, 1);
        assert.equal(env.calls.up.length, 1);
    } finally {
        env.dom.cleanup();
    }
});
