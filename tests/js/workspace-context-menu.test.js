import test from 'node:test';
import assert from 'node:assert/strict';
import {mountDom, click, pressKey} from './helpers/dom.js';
import {drag, pointerDown, stubStageRect} from './helpers/pointer.js';
import {boardMarkup, overlayNodes, rightClick} from './helpers/workspace-board.js';
import {initBoardEditor} from '../../resources/js/pages/workspace/index.js';

/*
 * เมนูคลิกขวาบนผืนผ้าใบ
 *
 * เดินเส้นทางจริงตั้งแต่คลิกขวาไปจนถึงผลบนเอกสาร ไม่ใช่เรียกฟังก์ชันข้างในตรง ๆ
 * เพราะสิ่งที่พังได้จริงคือการต่อสาย: คลิกขวาไม่เลือกชิ้นงานก่อน, รายการที่ไม่
 * เกี่ยวข้องไม่ถูกซ่อน, หรือคำสั่งไปเรียกอะไรผิดตัว
 */

let seed = 0;

// ต้องมีพื้น ไม่ใช่ fill: 'none' เพราะรูปที่ไม่มีพื้นจะคลิกโดนแค่ขอบเส้น
// (ดู hitTest ใน geometry.js) เทสต์ที่คลิกกลางรูปจึงจะไม่โดนอะไรเลย
const rect = (id, box) => ({
    id, type: 'rect', z: 1, ...box, stroke: '#1f2937', strokeWidth: 2, fill: '#fde68a',
});

const mount = ({canEdit = true, elements = []} = {}) => {
    const dom = mountDom(boardMarkup({
        capabilities: {canEdit, canManageSettings: canEdit, canDelete: canEdit},
        elements,
    }));

    const stage = dom.document.querySelector('[data-workspace-stage]');
    stubStageRect(stage, {width: 800, height: 600});

    const editor = initBoardEditor({
        root: dom.document.querySelector('[data-workspace-board]'),
        doc: dom.document,
        idFactory: () => `el-${++seed}`,
    });

    const menu = dom.document.querySelector('[data-workspace-context-menu]');

    return {
        dom,
        stage,
        editor,
        menu,
        item: (command) => menu.querySelector(`[data-command="${command}"]`),
        ids: () => editor.currentDocument().elements.map((element) => element.id),
    };
};

/* ── การเลือกก่อนเปิดเมนู ─────────────────────────────────────── */

test('คลิกขวาบนชิ้นงานเลือกชิ้นนั้นก่อนเปิดเมนู', () => {
    const env = mount({elements: [rect('a', {x: 0, y: 0, w: 100, h: 100})]});

    try {
        rightClick(env.stage, {x: 50, y: 50});

        assert.deepEqual(env.editor.state.selection, ['a']);
        assert.equal(env.menu.hidden, false);
    } finally {
        env.dom.cleanup();
    }
});

/*
 * ถ้าคลิกขวาแล้วยุบเหลือชิ้นเดียว การจัดลำดับชั้นทั้งกลุ่มจะทำไม่ได้เลย
 * ซึ่งเป็นการใช้งานหลักของเมนูนี้
 */
test('คลิกขวาบนชิ้นที่อยู่ในกลุ่มที่เลือกไว้แล้ว คงกลุ่มเดิมไว้', () => {
    const env = mount({
        elements: [
            rect('a', {x: 0, y: 0, w: 100, h: 100}),
            rect('b', {x: 200, y: 0, w: 100, h: 100}),
        ],
    });

    try {
        drag(env.stage, {x: 400, y: 400}, {x: -10, y: -10}, {steps: 3});
        assert.deepEqual(env.editor.state.selection.sort(), ['a', 'b']);

        rightClick(env.stage, {x: 50, y: 50});

        assert.deepEqual(env.editor.state.selection.sort(), ['a', 'b'], 'ต้องไม่ยุบเหลือชิ้นเดียว');
    } finally {
        env.dom.cleanup();
    }
});

test('คลิกขวาที่ว่างล้างการเลือก และเหลือเฉพาะรายการที่เกี่ยวข้อง', () => {
    const env = mount({elements: [rect('a', {x: 0, y: 0, w: 100, h: 100})]});

    try {
        rightClick(env.stage, {x: 50, y: 50});
        rightClick(env.stage, {x: 700, y: 500});

        assert.deepEqual(env.editor.state.selection, []);
        assert.equal(env.item('paste').hidden, false, 'วางยังเกี่ยวข้องแม้ไม่ได้เลือกอะไร');
        assert.equal(env.item('copy').hidden, true);
        assert.equal(env.item('delete').hidden, true);
        assert.equal(env.item('center').hidden, true);
        assert.equal(env.item('bring-to-front').hidden, true);
        assert.equal(env.item('send-to-back').hidden, true);
    } finally {
        env.dom.cleanup();
    }
});

/*
 * "เกี่ยวข้องแต่ยังใช้ไม่ได้" ต้องปิด ไม่ใช่ซ่อน ไม่งั้นผู้ใช้จะไม่รู้ว่าคำสั่งนั้นมีอยู่
 */
test('วางถูกปิดเมื่อยังไม่ได้คัดลอกอะไร และเปิดเมื่อคัดลอกแล้ว', () => {
    const env = mount({elements: [rect('a', {x: 0, y: 0, w: 100, h: 100})]});

    try {
        rightClick(env.stage, {x: 700, y: 500});
        assert.equal(env.item('paste').disabled, true);

        drag(env.stage, {x: 50, y: 50}, {x: 50, y: 50});
        env.editor.runCommand('copy');

        rightClick(env.stage, {x: 700, y: 500});
        assert.equal(env.item('paste').disabled, false);
    } finally {
        env.dom.cleanup();
    }
});

/* ── คำสั่งจัดลำดับชั้น ───────────────────────────────────────── */

test('คำสั่งจัดลำดับชั้นอยู่ในเมนูตรง ๆ และขยับชิ้นที่เลือกได้ในคลิกเดียว', () => {
    const env = mount({
        elements: [
            rect('a', {x: 0, y: 0, w: 100, h: 100}),
            rect('b', {x: 200, y: 0, w: 100, h: 100}),
            rect('c', {x: 400, y: 0, w: 100, h: 100}),
        ],
    });

    try {
        rightClick(env.stage, {x: 50, y: 50});
        click(env.item('bring-forward'));

        assert.deepEqual(env.ids(), ['b', 'a', 'c']);

        rightClick(env.stage, {x: 250, y: 50});
        click(env.item('bring-to-front'));

        assert.deepEqual(env.ids(), ['a', 'c', 'b']);
    } finally {
        env.dom.cleanup();
    }
});

/*
 * ผู้ใช้รายงานว่ากดจัดลำดับชั้นแล้ว "ไม่เห็นว่า ui จะเปลี่ยนอะไร" ซึ่งถูกต้องตาม
 * ความเป็นจริงเมื่อชิ้นงานไม่ได้ทับกับใคร หรือเมื่อมันอยู่สุดทางแล้ว ปุ่มที่กดแล้ว
 * ไม่เกิดอะไรจึงต้องถูกปิดไว้ให้เห็น ไม่ใช่ปล่อยให้กดได้แล้วเงียบ
 */
test('ปุ่มจัดลำดับชั้นถูกปิดเมื่อชิ้นที่เลือกขยับทางนั้นไม่ได้แล้ว', () => {
    const env = mount({
        elements: [
            rect('a', {x: 0, y: 0, w: 100, h: 100}),
            rect('b', {x: 200, y: 0, w: 100, h: 100}),
        ],
    });

    try {
        // a อยู่ล่างสุด: ขึ้นได้ ลงไม่ได้
        rightClick(env.stage, {x: 50, y: 50});
        assert.equal(env.item('bring-forward').disabled, false);
        assert.equal(env.item('bring-to-front').disabled, false);
        assert.equal(env.item('send-backward').disabled, true);
        assert.equal(env.item('send-to-back').disabled, true);

        // b อยู่บนสุด: ลงได้ ขึ้นไม่ได้
        rightClick(env.stage, {x: 250, y: 50});
        assert.equal(env.item('bring-forward').disabled, true);
        assert.equal(env.item('bring-to-front').disabled, true);
        assert.equal(env.item('send-backward').disabled, false);
        assert.equal(env.item('send-to-back').disabled, false);
    } finally {
        env.dom.cleanup();
    }
});

/*
 * และเมื่อขยับสำเร็จแล้ว การเปิดเมนูอีกครั้งต้องสะท้อนตำแหน่งใหม่ นี่คือสัญญาณ
 * ที่บอกผู้ใช้ว่าคำสั่งทำงานจริง แม้ภาพบนกระดานจะไม่เปลี่ยนเพราะของไม่ได้ทับกัน
 */
test('หลังขยับสำเร็จ เมนูที่เปิดใหม่สะท้อนตำแหน่งชั้นที่เปลี่ยนไป', () => {
    const env = mount({
        elements: [
            rect('a', {x: 0, y: 0, w: 100, h: 100}),
            rect('b', {x: 200, y: 0, w: 100, h: 100}),
        ],
    });

    try {
        rightClick(env.stage, {x: 50, y: 50});
        assert.equal(env.item('bring-forward').disabled, false);

        click(env.item('bring-forward'));
        assert.deepEqual(env.ids(), ['b', 'a']);

        rightClick(env.stage, {x: 50, y: 50});
        assert.equal(env.item('bring-forward').disabled, true, 'a ขึ้นบนสุดแล้ว ปุ่มต้องถูกปิด');
        assert.equal(env.item('send-backward').disabled, false);
    } finally {
        env.dom.cleanup();
    }
});

test('ชิ้นที่อยู่บนสุดแล้วกดขึ้นหนึ่งชั้นไม่กินก้าวย้อนกลับ', () => {
    const env = mount({
        elements: [rect('a', {x: 0, y: 0, w: 100, h: 100}), rect('b', {x: 200, y: 0, w: 100, h: 100})],
    });

    try {
        drag(env.stage, {x: 250, y: 50}, {x: 250, y: 50});

        assert.equal(env.editor.runCommand('bring-forward'), false);
        assert.deepEqual(env.ids(), ['a', 'b']);
        assert.equal(env.dom.document.querySelector('[data-command="undo"]').disabled, true);
    } finally {
        env.dom.cleanup();
    }
});

/* ── เลื่อนไปหา ───────────────────────────────────────────────── */

/*
 * เลื่อนไปหาเป็นคำสั่งมุมมอง ไม่ใช่การแก้เอกสาร ถ้าเผลอทำให้เอกสารเปลี่ยน
 * การบันทึกอัตโนมัติจะยิงทุกครั้งที่ผู้ใช้แค่เลื่อนไปดูของ
 */
test('เลื่อนไปหาขยับกล้องโดยไม่แตะเอกสารและไม่กินก้าวย้อนกลับ', () => {
    const env = mount({elements: [rect('a', {x: 2000, y: 1500, w: 100, h: 100})]});

    try {
        const before = env.editor.state.scene;

        env.editor.state.selection = ['a'];
        assert.equal(env.editor.runCommand('center'), true);

        assert.equal(env.editor.state.scene, before, 'ฉากต้องเป็นอ็อบเจ็กต์เดิม');
        assert.equal(env.editor.state.camera.scale, 1, 'ระดับซูมต้องไม่เปลี่ยน');
        assert.equal(env.dom.document.querySelector('[data-command="undo"]').disabled, true);

        // กลางจอคือ (400, 300) กลางวัตถุคือ (2050, 1550) ที่ซูม 1
        assert.deepEqual(
            {x: Math.round(env.editor.state.camera.x), y: Math.round(env.editor.state.camera.y)},
            {x: -1650, y: -1250}
        );
    } finally {
        env.dom.cleanup();
    }
});

test('เลื่อนไปหาโดยไม่ได้เลือกอะไรไม่ทำอะไรเลย', () => {
    const env = mount({elements: [rect('a', {x: 0, y: 0, w: 100, h: 100})]});

    try {
        const camera = env.editor.state.camera;

        assert.equal(env.editor.runCommand('center'), false);
        assert.equal(env.editor.state.camera, camera);
    } finally {
        env.dom.cleanup();
    }
});

/* ── การปิดเมนู ───────────────────────────────────────────────── */

test('Escape ปิดเมนู และกดข้างนอกก็ปิด', () => {
    const env = mount({elements: [rect('a', {x: 0, y: 0, w: 100, h: 100})]});

    try {
        rightClick(env.stage, {x: 50, y: 50});
        assert.equal(env.menu.hidden, false);

        pressKey(env.dom.document, 'Escape');
        assert.equal(env.menu.hidden, true);

        rightClick(env.stage, {x: 50, y: 50});
        assert.equal(env.menu.hidden, false);

        pointerDown(env.stage, {x: 400, y: 400});
        assert.equal(env.menu.hidden, true);
    } finally {
        env.dom.cleanup();
    }
});

/*
 * Escape ต้องปิดแค่สิ่งที่อยู่บนสุด การกดครั้งเดียวไม่ควรทั้งปิดเมนูและล้าง
 * การเลือกชิ้นงานไปพร้อมกัน
 */
test('Escape ที่ปิดเมนูไม่ล้างการเลือกชิ้นงานไปด้วย', () => {
    const env = mount({elements: [rect('a', {x: 0, y: 0, w: 100, h: 100})]});

    try {
        rightClick(env.stage, {x: 50, y: 50});
        pressKey(env.dom.document, 'Escape');

        assert.deepEqual(env.editor.state.selection, ['a']);
    } finally {
        env.dom.cleanup();
    }
});

test('เลือกรายการแล้วเมนูปิดเอง', () => {
    const env = mount({elements: [rect('a', {x: 0, y: 0, w: 100, h: 100})]});

    try {
        rightClick(env.stage, {x: 50, y: 50});
        click(env.item('duplicate'));

        assert.equal(env.menu.hidden, true);
        assert.equal(env.ids().length, 2);
    } finally {
        env.dom.cleanup();
    }
});

/*
 * เมนูกับแผงสีเป็น overlay สองตัว ถ้าเปิดค้างพร้อมกันจะซ้อนทับกันและ Escape
 * ครั้งเดียวจะปิดไม่หมด
 */
test('เปิดแผงสีแล้วเมนูที่ค้างอยู่ต้องปิด และกลับกัน', () => {
    const env = mount({elements: [rect('a', {x: 0, y: 0, w: 100, h: 100})]});

    try {
        const picker = env.dom.document.querySelector('[data-picker-toggle]');
        const pickerPanel = env.dom.document.querySelector('[data-picker-panel]');

        rightClick(env.stage, {x: 50, y: 50});
        assert.equal(env.menu.hidden, false);

        click(picker);
        assert.equal(env.menu.hidden, true, 'เมนูต้องปิดเมื่อแผงสีเปิด');
        assert.equal(pickerPanel.hidden, false);

        rightClick(env.stage, {x: 50, y: 50});
        assert.equal(pickerPanel.hidden, true, 'แผงสีต้องปิดเมื่อเมนูเปิด');
        assert.equal(env.menu.hidden, false);
    } finally {
        env.dom.cleanup();
    }
});

/* ── ผู้ที่ดูอย่างเดียว ───────────────────────────────────────── */

test('ผู้ที่ดูอย่างเดียวเปิดเมนูได้ แต่รายการที่แก้เนื้อหาถูกปิด ส่วนเลื่อนไปหายังใช้ได้', () => {
    const env = mount({canEdit: false, elements: [rect('a', {x: 0, y: 0, w: 100, h: 100})]});

    try {
        rightClick(env.stage, {x: 50, y: 50});

        assert.equal(env.menu.hidden, false);
        assert.equal(env.item('delete').disabled, true);
        assert.equal(env.item('duplicate').disabled, true);
        assert.equal(env.item('paste').disabled, true);
        assert.equal(env.item('center').disabled, false, 'คำสั่งมุมมองต้องใช้ได้');
        assert.equal(env.item('copy').disabled, false, 'คัดลอกไม่ได้แก้กระดาน');
    } finally {
        env.dom.cleanup();
    }
});

/*
 * ด่านจริงคือ runCommand ไม่ใช่ปุ่มที่ถูกปิดบนหน้าจอ เพราะ DOM ถูกแก้จาก
 * คอนโซลได้ CLAUDE.md ระบุว่าการซ่อนปุ่มไม่ใช่การตรวจสิทธิ์
 */
test('ผู้ที่ดูอย่างเดียวเรียกคำสั่งจัดลำดับชั้นตรง ๆ ก็ไม่มีผล', () => {
    const env = mount({
        canEdit: false,
        elements: [rect('a', {x: 0, y: 0, w: 100, h: 100}), rect('b', {x: 200, y: 0, w: 100, h: 100})],
    });

    try {
        env.editor.state.selection = ['a'];

        assert.equal(env.editor.runCommand('bring-to-front'), false);
        assert.equal(env.editor.runCommand('bring-forward'), false);
        assert.equal(env.editor.runCommand('send-backward'), false);
        assert.equal(env.editor.runCommand('send-to-back'), false);
        assert.deepEqual(env.ids(), ['a', 'b']);
    } finally {
        env.dom.cleanup();
    }
});

/*
 * คลิกขวาในกล่องข้อความที่กำลังพิมพ์ต้องได้เมนูของเบราว์เซอร์ เพราะตรงนั้นผู้ใช้
 * ต้องการตรวจคำสะกดและวางข้อความ ซึ่งเมนูของเราไม่มีให้
 */
test('คลิกขวาในกล่องข้อความที่กำลังแก้ไข ปล่อยให้เมนูของเบราว์เซอร์ทำงาน', () => {
    const env = mount({
        elements: [{
            id: 'n1', type: 'sticky', z: 1, x: 0, y: 0, w: 180, h: 180,
            text: 'ไอเดีย', fill: '#fde68a', fontSize: 16,
        }],
    });

    try {
        env.stage.dispatchEvent(new env.dom.window.MouseEvent('dblclick', {
            bubbles: true, cancelable: true, clientX: 50, clientY: 50,
        }));

        const node = overlayNodes(env.dom.document)[0];

        assert.equal(node.classList.contains('is-editing'), true);

        const prevented = ! rightClick(node, {x: 50, y: 50});

        assert.equal(prevented, false, 'ต้องไม่ยกเลิกเหตุการณ์');
        assert.equal(env.menu.hidden, true, 'เมนูของเราต้องไม่โผล่');
    } finally {
        env.dom.cleanup();
    }
});
