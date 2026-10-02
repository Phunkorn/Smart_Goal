import test from 'node:test';
import assert from 'node:assert/strict';
import {mountDom, click, pressKey} from './helpers/dom.js';
import {drag, pointerDown, pointerMove, pointerUp, stubStageRect, wheel} from './helpers/pointer.js';
import {boardMarkup, renderedNodes, rightClick} from './helpers/workspace-board.js';
import {initBoardEditor} from '../../resources/js/pages/workspace/index.js';

/*
 * เส้นทางจริงของหน้าวาด ตั้งแต่การกดเมาส์บนผืนผ้าใบไปจนถึงโหนดที่ปรากฏ
 *
 * เทสต์ชุดอื่นตรวจตรรกะทีละโมดูล ชุดนี้ตรวจว่าสายไฟระหว่างโมดูลต่อถูกต้อง
 * ซึ่งเป็นจุดที่ข้อผิดพลาดจริงมักเกิด และเป็นสิ่งที่ CLAUDE.md ระบุว่าเทสต์ UI
 * ต้องครอบคลุมเส้นทางจากการกดปุ่มถึงผลลัพธ์ที่มองเห็น
 */

/*
 * markup และ JSON island มาจากตัวสร้างร่วม tests/js/helpers/workspace-board.js
 * ซึ่งสะท้อนโครงจริงของ Blade (แถบสองแถวในเชลล์เดียว เมนู และเมนูคลิกขวา)
 *
 * ก่อนหน้านี้ไฟล์เทสต์แต่ละไฟล์เขียน markup ของแถบเครื่องมือขึ้นมาเอง ชุดละไม่
 * เหมือนกัน เทสต์จึงผ่านได้ทั้งที่หน้าจริงพัง เพราะสิ่งที่ทดสอบไม่ตรงกับสิ่งที่
 * Blade ปล่อยออกมา
 */

const rect = (id, box) => ({
    id, type: 'rect', z: 1, ...box, stroke: '#1f2937', strokeWidth: 2, fill: '#fde68a',
});

let seed = 0;

const mount = ({canEdit = true, elements = [], routes = {}} = {}) => {
    const dom = mountDom(boardMarkup({
        capabilities: {canEdit, canManageSettings: canEdit, canDelete: canEdit},
        elements,
        routes,
    }));

    const stage = dom.document.querySelector('[data-workspace-stage]');
    stubStageRect(stage, {width: 800, height: 600});

    const navigations = [];
    let reloads = 0;

    const editor = initBoardEditor({
        root: dom.document.querySelector('[data-workspace-board]'),
        doc: dom.document,
        idFactory: () => `el-${++seed}`,
        navigateTo: (url) => navigations.push(url),
        reloadPage: () => { reloads += 1; },
    });

    return {
        dom,
        stage,
        editor,
        clipboard: systemClipboard(),
        navigations,
        reloadCount: () => reloads,
        preview: dom.document.querySelector('[data-workspace-preview]'),
        selection: dom.document.querySelector('[data-workspace-selection]'),
        layers: dom.document.querySelector('[data-workspace-layers]'),
        toolbar: dom.document.querySelector('[data-workspace-toolbar]'),
        /*
         * ชิ้นงานถูกวาดข้ามหลายชั้น (ดู layers.js) เทสต์จึงถามจากทั้งกระดาน
         * ไม่ใช่จากชั้นใดชั้นหนึ่ง และลำดับที่ได้คือลำดับการซ้อนทับจริงบนหน้าจอ
         */
        nodes: () => renderedNodes(dom.document),
        node: (id) => dom.document.querySelector(`[data-workspace-layers] [data-el-id="${id}"]`),
        /*
         * ค้นตัวควบคุมจากทั้งกระดาน ไม่ใช่จากแถวหลักเท่านั้น เพราะตัวควบคุม
         * กระจายอยู่หลายที่แล้ว: เครื่องมืออยู่ในเมนู สีกับความหนาอยู่แถวรูปแบบ
         * และคำสั่งจัดลำดับชั้นอยู่ในเมนูคลิกขวา
         */
        control: (selector) => dom.document.querySelector(selector),
        ids: () => renderedNodes(dom.document).map((n) => n.getAttribute('data-el-id')),
    };
};

test('เนื้อหาที่เซิร์ฟเวอร์ส่งมาถูกวาดตั้งแต่เปิดหน้า', () => {
    const env = mount({elements: [rect('a', {x: 0, y: 0, w: 50, h: 50})]});

    try {
        assert.deepEqual(env.ids(), ['a']);
    } finally {
        env.dom.cleanup();
    }
});

test('เลือกดินสอแล้วลาก ได้เส้นใหม่บนผืนผ้าใบ', () => {
    const env = mount();

    try {
        click(env.control('[data-tool="pen"]'));
        drag(env.stage, {x: 100, y: 100}, {x: 200, y: 160}, {steps: 4});

        const nodes = env.nodes();

        assert.equal(nodes.length, 1);
        assert.equal(nodes[0].tagName.toLowerCase(), 'path');
        assert.equal(env.editor.currentDocument().elements[0].type, 'pen');
    } finally {
        env.dom.cleanup();
    }
});

test('สีและความหนาที่เลือกถูกใช้กับเส้นที่วาดหลังจากนั้น', () => {
    const env = mount();

    try {
        click(env.control('[data-tool="pen"]'));
        click(env.control('[data-color="#dc2626"]'));
        click(env.control('[data-stroke-width="8"]'));
        drag(env.stage, {x: 10, y: 10}, {x: 60, y: 60}, {steps: 2});

        const stroke = env.editor.currentDocument().elements[0];

        assert.equal(stroke.stroke, '#dc2626');
        assert.equal(stroke.strokeWidth, 8);
    } finally {
        env.dom.cleanup();
    }
});

test('รูปที่กำลังลากอยู่ในชั้นตัวอย่าง ยังไม่เข้าฉากจริง', () => {
    const env = mount();

    try {
        click(env.control('[data-tool="rect"]'));
        pointerDown(env.stage, {x: 50, y: 50});
        pointerMove(env.stage, {x: 150, y: 120});

        assert.equal(env.preview.children.length, 1, 'ต้องเห็นตัวอย่างระหว่างลาก');
        assert.equal(env.nodes().length, 0, 'แต่ฉากจริงต้องยังว่าง');

        pointerUp(env.stage, {x: 150, y: 120});

        assert.equal(env.preview.children.length, 0);
        assert.equal(env.nodes().length, 1);
    } finally {
        env.dom.cleanup();
    }
});

test('คลิกเลือกชิ้นงานแล้วเห็นกรอบและมือจับ', () => {
    const env = mount({elements: [rect('a', {x: 100, y: 100, w: 100, h: 100})]});

    try {
        click(env.control('[data-tool="select"]'));
        drag(env.stage, {x: 150, y: 150}, {x: 150, y: 150});

        assert.notEqual(env.selection.querySelector('[data-part="frame"]'), null);
        assert.equal(env.selection.querySelectorAll('[data-handle]').length, 8);
    } finally {
        env.dom.cleanup();
    }
});

test('ลากชิ้นงานแล้วตำแหน่งเปลี่ยนจริงในเอกสาร', () => {
    const env = mount({elements: [rect('a', {x: 100, y: 100, w: 100, h: 100})]});

    try {
        drag(env.stage, {x: 150, y: 150}, {x: 250, y: 200}, {steps: 3});

        const moved = env.editor.currentDocument().elements[0];

        assert.equal(moved.x, 200);
        assert.equal(moved.y, 150);
    } finally {
        env.dom.cleanup();
    }
});

test('ยางลบลบชิ้นงานที่ลากผ่าน', () => {
    const env = mount({elements: [rect('a', {x: 0, y: 0, w: 100, h: 100})]});

    try {
        click(env.control('[data-tool="eraser"]'));
        drag(env.stage, {x: 50, y: 50}, {x: 60, y: 60});

        assert.deepEqual(env.ids(), []);
    } finally {
        env.dom.cleanup();
    }
});

test('ย้อนกลับและทำซ้ำผ่านปุ่มบนแถบเครื่องมือ', () => {
    const env = mount();

    try {
        click(env.control('[data-tool="rect"]'));
        drag(env.stage, {x: 10, y: 10}, {x: 110, y: 60}, {steps: 2});

        assert.equal(env.nodes().length, 1);

        const undoButton = env.control('[data-command="undo"]');
        assert.equal(undoButton.disabled, false, 'ปุ่มย้อนกลับต้องเปิดใช้งานหลังมีการแก้');

        click(undoButton);
        assert.equal(env.nodes().length, 0);

        click(env.control('[data-command="redo"]'));
        assert.equal(env.nodes().length, 1);
    } finally {
        env.dom.cleanup();
    }
});

/*
 * การลากเส้นเดียวยิง pointermove หลายสิบครั้ง ถ้าแต่ละครั้งกลายเป็นก้าว undo
 * ผู้ใช้จะต้องกดย้อนกลับเป็นสิบครั้งกว่าจะลบเส้นเดียวออก
 */
test('การลากเส้นหนึ่งเส้นนับเป็นก้าวย้อนกลับเดียว', () => {
    const env = mount();

    try {
        click(env.control('[data-tool="pen"]'));
        drag(env.stage, {x: 10, y: 10}, {x: 200, y: 200}, {steps: 20});

        click(env.control('[data-command="undo"]'));

        assert.equal(env.nodes().length, 0);
    } finally {
        env.dom.cleanup();
    }
});

test('ปุ่ม Delete ลบชิ้นที่เลือก และ Escape ล้างการเลือก', () => {
    const env = mount({elements: [rect('a', {x: 0, y: 0, w: 100, h: 100})]});

    try {
        drag(env.stage, {x: 50, y: 50}, {x: 50, y: 50});
        assert.notEqual(env.selection.querySelector('[data-part="frame"]'), null);

        pressKey(env.dom.document, 'Escape');
        assert.equal(env.selection.children.length, 0);

        drag(env.stage, {x: 50, y: 50}, {x: 50, y: 50});
        pressKey(env.dom.document, 'Delete');

        assert.deepEqual(env.ids(), []);
    } finally {
        env.dom.cleanup();
    }
});

test('Ctrl+Z ย้อนกลับ และ Ctrl+Shift+Z ทำซ้ำ', () => {
    const env = mount();

    try {
        click(env.control('[data-tool="rect"]'));
        drag(env.stage, {x: 10, y: 10}, {x: 110, y: 60}, {steps: 2});

        pressKey(env.dom.document, 'z', {ctrlKey: true});
        assert.equal(env.nodes().length, 0);

        pressKey(env.dom.document, 'z', {ctrlKey: true, shiftKey: true});
        assert.equal(env.nodes().length, 1);
    } finally {
        env.dom.cleanup();
    }
});

/*
 * ถ้าดัก Ctrl+Z ขณะโฟกัสอยู่ในช่องข้อความ ผู้ใช้จะแก้คำผิดในกระดาษโน้ตไม่ได้เลย
 * ข้อนี้จะสำคัญมากขึ้นเมื่อชั้นข้อความเข้ามาในเฟสถัดไป จึงล็อกไว้ตั้งแต่ตอนนี้
 */
test('Ctrl+Z ไม่ถูกดักเมื่อโฟกัสอยู่ในช่องข้อความ', () => {
    const env = mount();

    try {
        click(env.control('[data-tool="rect"]'));
        drag(env.stage, {x: 10, y: 10}, {x: 110, y: 60}, {steps: 2});

        const field = env.dom.document.createElement('input');
        env.dom.document.body.append(field);
        field.focus();

        pressKey(field, 'z', {ctrlKey: true});

        assert.equal(env.nodes().length, 1, 'กระดานต้องไม่ถูกย้อนกลับ');
    } finally {
        env.dom.cleanup();
    }
});

test('ล้อเมาส์เลื่อนกระดาน และ Ctrl + ล้อ ซูม', () => {
    const env = mount();

    try {
        wheel(env.stage, {deltaY: 100});
        assert.equal(env.editor.state.camera.y, -100);
        assert.equal(env.editor.state.camera.scale, 1, 'ล้อเปล่าต้องไม่ซูม');

        wheel(env.stage, {x: 400, y: 300, deltaY: -100, ctrlKey: true});
        assert.equal(env.editor.state.camera.scale > 1, true);
    } finally {
        env.dom.cleanup();
    }
});

test('ปุ่มซูมปรับระดับและอัปเดตป้ายเปอร์เซ็นต์', () => {
    const env = mount();

    try {
        click(env.control('[data-command="zoom-in"]'));

        assert.equal(env.editor.state.camera.scale > 1, true);
        assert.equal(env.control('[data-zoom-label]').textContent, '120%');

        click(env.control('[data-command="zoom-out"]'));
        assert.equal(env.control('[data-zoom-label]').textContent, '100%');
    } finally {
        env.dom.cleanup();
    }
});

test('เครื่องมือเลื่อนกระดานย้ายกล้องโดยไม่แตะเนื้อหา', () => {
    const env = mount({elements: [rect('a', {x: 0, y: 0, w: 50, h: 50})]});

    try {
        click(env.control('[data-tool="hand"]'));
        drag(env.stage, {x: 100, y: 100}, {x: 160, y: 130}, {steps: 2});

        assert.deepEqual(
            {x: env.editor.state.camera.x, y: env.editor.state.camera.y},
            {x: 60, y: 30}
        );
        assert.equal(env.editor.currentDocument().elements[0].x, 0, 'เนื้อหาต้องไม่ขยับ');
    } finally {
        env.dom.cleanup();
    }
});

/* ── ผู้ที่ดูอย่างเดียว ──────────────────────────────────────── */

test('ผู้ที่ดูอย่างเดียวถูกปิดปุ่มที่แก้เนื้อหาทั้งหมด', () => {
    const env = mount({canEdit: false});

    try {
        env.toolbar.querySelectorAll('[data-requires-edit]').forEach((control) => {
            assert.equal(control.disabled, true, `${control.outerHTML} ต้องถูกปิด`);
            assert.equal(control.getAttribute('aria-disabled'), 'true');
        });

        assert.equal(env.control('[data-tool="hand"]').disabled, false);
        assert.equal(env.control('[data-command="zoom-in"]').disabled, false,
            'ปุ่มมุมมองต้องใช้ได้ เพราะไม่ได้แก้เนื้อหา');
    } finally {
        env.dom.cleanup();
    }
});

test('ผู้ที่ดูอย่างเดียวเริ่มด้วยเครื่องมือเลื่อนกระดาน', () => {
    const env = mount({canEdit: false});

    try {
        assert.equal(env.editor.state.tool, 'hand');
        assert.equal(env.control('[data-tool="hand"]').classList.contains('is-active'), true);
    } finally {
        env.dom.cleanup();
    }
});

/*
 * การปิดปุ่มเป็นเรื่องของหน้าจอเท่านั้น ต่อให้มีคนเรียกเครื่องมือวาดผ่านทางอื่น
 * ก็ต้องไม่เกิดอะไรขึ้น การบังคับใช้จริงยังอยู่ที่ WorkspaceBoardPolicy
 */
test('ผู้ที่ดูอย่างเดียววาดไม่ได้แม้จะข้ามปุ่มที่ถูกปิดไป', () => {
    const env = mount({canEdit: false, elements: [rect('a', {x: 0, y: 0, w: 100, h: 100})]});

    try {
        env.editor.state.tool = 'pen';
        drag(env.stage, {x: 10, y: 10}, {x: 200, y: 200}, {steps: 5});

        assert.deepEqual(env.ids(), ['a'], 'ต้องไม่มีเส้นใหม่เกิดขึ้น');
    } finally {
        env.dom.cleanup();
    }
});

test('ผู้ที่ดูอย่างเดียวยังเลื่อนและซูมกระดานได้', () => {
    const env = mount({canEdit: false});

    try {
        drag(env.stage, {x: 100, y: 100}, {x: 150, y: 120}, {steps: 2});

        assert.deepEqual(
            {x: env.editor.state.camera.x, y: env.editor.state.camera.y},
            {x: 50, y: 20}
        );
    } finally {
        env.dom.cleanup();
    }
});

/*
 * หน้าที่ไม่มีปลายทางบันทึกต้องไม่ตั้งการบันทึกอัตโนมัติขึ้นมาแล้วปล่อยให้ยิงพลาด
 *
 * การยิงพลาดจะเข้าสถานะ "ลองใหม่อัตโนมัติ" ซึ่งวนซ้ำไม่รู้จบและถือตัวจับเวลา
 * ค้างไว้ตลอดอายุของหน้า อาการนี้ถูกพบครั้งแรกตอนที่เทสต์ทั้งไฟล์ค้างไม่ยอมจบ
 */
test('ไม่ตั้งการบันทึกอัตโนมัติเมื่อไม่มี route สำหรับบันทึก', () => {
    const env = mount();

    try {
        assert.equal(env.editor.autosave, null);
    } finally {
        env.dom.cleanup();
    }
});

test('การเรียก init ซ้ำบน root เดิมไม่ผูกตัวจัดการซ้ำ', () => {
    const env = mount();

    try {
        const root = env.dom.document.querySelector('[data-workspace-board]');

        assert.equal(root.dataset.workspaceBoardReady, 'on');
        assert.equal(initBoardEditor({root, doc: env.dom.document}), null);
    } finally {
        env.dom.cleanup();
    }
});

/* ── โหมดเต็มจอ ──────────────────────────────────────────────── */

/*
 * ขอเต็มจอที่ตัวกล่องกระดาน ไม่ใช่ที่ผืนผ้าใบ ไม่งั้นผู้ใช้จะเข้าเต็มจอแล้ว
 * เปลี่ยนเครื่องมือไม่ได้เลย เพราะแถบเครื่องมือไม่ได้ติดไปด้วย
 */
test('ปุ่มเต็มจอขอเต็มจอที่กล่องกระดาน ซึ่งมีแถบเครื่องมืออยู่ข้างใน', () => {
    const env = mount();

    try {
        const root = env.dom.document.querySelector('[data-workspace-board]');
        const requested = [];
        root.requestFullscreen = function () {
            requested.push(this);

            return Promise.resolve();
        };

        click(env.control('[data-command="fullscreen"]'));

        assert.deepEqual(requested, [root]);
        assert.notEqual(root.querySelector('[data-workspace-toolbar]'), null);
    } finally {
        env.dom.cleanup();
    }
});

test('กดซ้ำขณะอยู่เต็มจอเป็นการออกจากเต็มจอ', () => {
    const env = mount();

    try {
        const root = env.dom.document.querySelector('[data-workspace-board]');
        let exited = 0;

        Object.defineProperty(env.dom.document, 'fullscreenElement', {
            configurable: true,
            get: () => root,
        });
        env.dom.document.exitFullscreen = () => {
            exited += 1;

            return Promise.resolve();
        };
        root.requestFullscreen = () => Promise.reject(new Error('ไม่ควรถูกเรียก'));

        click(env.control('[data-command="fullscreen"]'));

        assert.equal(exited, 1);
    } finally {
        env.dom.cleanup();
    }
});

/*
 * requestFullscreen() ถูกปฏิเสธได้เมื่อเบราว์เซอร์ไม่อนุญาต เช่นอยู่ใน iframe
 * ที่ไม่มี allow="fullscreen" ถ้าไม่รับ Promise ไว้ จะเกิด unhandled rejection
 * ที่ผู้ใช้ไม่เห็นอะไรเลยและเราก็ไม่รู้ว่าพลาด
 */
test('การถูกปฏิเสธเต็มจอไม่ทำให้เกิด unhandled rejection', async () => {
    const env = mount();
    const unhandled = [];
    const onUnhandled = (reason) => unhandled.push(reason);
    process.on('unhandledRejection', onUnhandled);

    try {
        const root = env.dom.document.querySelector('[data-workspace-board]');
        root.requestFullscreen = () => Promise.reject(new Error('ไม่อนุญาต'));

        click(env.control('[data-command="fullscreen"]'));

        await new Promise((resolve) => setTimeout(resolve, 10));

        assert.deepEqual(unhandled, []);
    } finally {
        process.off('unhandledRejection', onUnhandled);
        env.dom.cleanup();
    }
});

test('เบราว์เซอร์ที่ไม่รองรับเต็มจอต้องไม่ทำให้หน้าพัง', () => {
    const env = mount();

    try {
        const root = env.dom.document.querySelector('[data-workspace-board]');
        root.requestFullscreen = undefined;

        assert.doesNotThrow(() => click(env.control('[data-command="fullscreen"]')));
    } finally {
        env.dom.cleanup();
    }
});

test('ปุ่มเต็มจอสะท้อนสถานะปัจจุบัน และไอคอนบอกว่ากดแล้วจะเกิดอะไร', () => {
    const env = mount();

    try {
        const root = env.dom.document.querySelector('[data-workspace-board]');
        const button = env.control('[data-command="fullscreen"]');

        assert.equal(button.getAttribute('aria-pressed'), 'false');
        assert.equal(button.querySelector('i').className, 'bi bi-arrows-fullscreen');

        Object.defineProperty(env.dom.document, 'fullscreenElement', {
            configurable: true,
            get: () => root,
        });
        env.dom.document.dispatchEvent(new env.dom.window.Event('fullscreenchange'));

        assert.equal(button.getAttribute('aria-pressed'), 'true');
        assert.equal(button.querySelector('i').className, 'bi bi-fullscreen-exit');
    } finally {
        env.dom.cleanup();
    }
});

test('ปุ่ม F เข้าเต็มจอ แต่ต้องไม่ทำงานขณะพิมพ์อยู่ในช่องข้อความ', () => {
    const env = mount();

    try {
        const root = env.dom.document.querySelector('[data-workspace-board]');
        let requests = 0;
        root.requestFullscreen = () => {
            requests += 1;

            return Promise.resolve();
        };

        pressKey(env.dom.document, 'f');
        assert.equal(requests, 1);

        const field = env.dom.document.createElement('input');
        env.dom.document.body.append(field);
        field.focus();
        pressKey(field, 'f');

        assert.equal(requests, 1, 'การพิมพ์ตัว f ในช่องข้อความต้องไม่เข้าเต็มจอ');
    } finally {
        env.dom.cleanup();
    }
});

/*
 * "จัดให้พอดีจอ" กับ "เต็มจอ" เป็นคนละเรื่อง อันแรกปรับกล้อง อันหลังขยาย
 * หน้าต่าง ถ้าใช้ไอคอนใกล้กันผู้ใช้จะกดผิดตัว (ซึ่งเกิดขึ้นแล้วครั้งหนึ่ง)
 */
test('ปุ่มจัดให้พอดีจอปรับกล้อง ไม่ใช่ขอเต็มจอ', () => {
    const env = mount({elements: [rect('a', {x: 0, y: 0, w: 200, h: 200})]});

    try {
        const root = env.dom.document.querySelector('[data-workspace-board]');
        let requests = 0;
        root.requestFullscreen = () => {
            requests += 1;

            return Promise.resolve();
        };

        click(env.control('[data-command="fit"]'));

        assert.equal(requests, 0);
        assert.notEqual(env.editor.state.camera.scale, 1, 'กล้องต้องถูกปรับ');
    } finally {
        env.dom.cleanup();
    }
});

/* ── สีและความหนามีผลกับชิ้นที่เลือกอยู่ ─────────────────────── */

/*
 * ก่อนหน้านี้การเลือกสีหรือความหนามีผลกับชิ้นที่วาดใหม่เท่านั้น ผู้ใช้จึงแก้สี
 * ของเส้นที่วาดไปแล้วไม่ได้เลย ต้องลบทิ้งแล้ววาดใหม่
 */
test('เลือกสีขณะที่เลือกรูปทรงอยู่ เปลี่ยนสีของรูปนั้นทันที', () => {
    const env = mount({elements: [rect('a', {x: 0, y: 0, w: 100, h: 100})]});

    try {
        drag(env.stage, {x: 50, y: 50}, {x: 50, y: 50});
        click(env.control('[data-color="#dc2626"]'));

        assert.equal(env.editor.currentDocument().elements[0].stroke, '#dc2626');
    } finally {
        env.dom.cleanup();
    }
});

test('เลือกความหนาขณะที่เลือกรูปทรงอยู่ เปลี่ยนความหนาของรูปนั้นทันที', () => {
    const env = mount({elements: [rect('a', {x: 0, y: 0, w: 100, h: 100})]});

    try {
        drag(env.stage, {x: 50, y: 50}, {x: 50, y: 50});
        click(env.control('[data-stroke-width="8"]'));

        assert.equal(env.editor.currentDocument().elements[0].strokeWidth, 8);
    } finally {
        env.dom.cleanup();
    }
});

test('ความหนาที่เลือกยังมีผลกับเส้นที่วาดใหม่ด้วย', () => {
    const env = mount();

    try {
        click(env.control('[data-stroke-width="8"]'));
        click(env.control('[data-tool="pen"]'));
        drag(env.stage, {x: 10, y: 10}, {x: 80, y: 80}, {steps: 3});

        assert.equal(env.editor.currentDocument().elements[0].strokeWidth, 8);
    } finally {
        env.dom.cleanup();
    }
});

test('การเปลี่ยนสีนับเป็นก้าวย้อนกลับหนึ่งก้าว', () => {
    const env = mount({elements: [rect('a', {x: 0, y: 0, w: 100, h: 100})]});

    try {
        drag(env.stage, {x: 50, y: 50}, {x: 50, y: 50});
        click(env.control('[data-color="#dc2626"]'));
        click(env.control('[data-command="undo"]'));

        assert.equal(env.editor.currentDocument().elements[0].stroke, '#1f2937');
    } finally {
        env.dom.cleanup();
    }
});

/*
 * ช่องเลือกสีของเบราว์เซอร์ให้สีได้ไม่จำกัด ค่าที่คืนมาเป็น #rrggbb ซึ่งผ่าน
 * WorkspaceDocumentValidator อยู่แล้ว
 */
test('ช่องเลือกสีเองใช้ได้กับทั้งชิ้นที่เลือกและชิ้นถัดไป', () => {
    const env = mount({elements: [rect('a', {x: 0, y: 0, w: 100, h: 100})]});

    try {
        drag(env.stage, {x: 50, y: 50}, {x: 50, y: 50});

        const picker = env.control('[data-custom-color]');
        picker.value = '#8b5cf6';
        picker.dispatchEvent(new env.dom.window.Event('input', {bubbles: true}));

        assert.equal(env.editor.currentDocument().elements[0].stroke, '#8b5cf6');
        assert.equal(env.editor.state.style.stroke, '#8b5cf6');
    } finally {
        env.dom.cleanup();
    }
});

test('สีที่เลือกไม่ไปแตะชิ้นที่ไม่ได้เลือก', () => {
    const env = mount({elements: [
        rect('a', {x: 0, y: 0, w: 50, h: 50}),
        rect('b', {x: 200, y: 0, w: 50, h: 50}),
    ]});

    try {
        drag(env.stage, {x: 25, y: 25}, {x: 25, y: 25});
        click(env.control('[data-color="#dc2626"]'));

        const elements = env.editor.currentDocument().elements;

        assert.equal(elements[0].stroke, '#dc2626');
        assert.equal(elements[1].stroke, '#1f2937');
    } finally {
        env.dom.cleanup();
    }
});

/* ── ปุ่มจัดการกระดานบนหน้าวาด ───────────────────────────────── */

test('ปุ่มแก้ไขชื่อเปิดกล่องพร้อมค่าปัจจุบันของกระดาน', () => {
    const env = mount({
        routes: {update: '/workspace/boards/7', destroy: '/workspace/boards/7', department: '/workspace/departments/1'},
    });

    try {
        const modal = env.dom.document.querySelector('[data-workspace-modal]');

        assert.equal(modal.hidden, true);

        click(env.dom.document.querySelector('[data-workspace-board-settings]'));

        assert.equal(modal.hidden, false);
        assert.equal(env.dom.document.querySelector('[data-workspace-title]').value, 'กระดานทดสอบ');
        assert.equal(
            env.dom.document.querySelector('input[name="visibility"]:checked').value,
            'organization'
        );
    } finally {
        env.dom.cleanup();
    }
});

/*
 * กระดานที่เพิ่งลบไม่มีให้เปิดแล้ว ต้องพากลับไปหน้าแผนก ไม่ใช่ปล่อยค้างไว้บน
 * หน้าที่จะได้ 404 ทันทีที่รีเฟรช
 */
test('ลบกระดานสำเร็จแล้วพากลับไปหน้าแผนก', async () => {
    const env = mount({
        routes: {update: '/u', destroy: '/workspace/boards/7', department: '/workspace/departments/1'},
    });
    const originalFetch = globalThis.fetch;
    const originalSwal = globalThis.Swal;

    try {
        globalThis.Swal = {fire: async () => ({isConfirmed: true})};
        globalThis.fetch = async () => ({ok: true, json: async () => ({ok: true})});

        click(env.dom.document.querySelector('[data-workspace-board-delete]'));
        await new Promise((resolve) => setTimeout(resolve, 0));

        assert.deepEqual(env.navigations, ['/workspace/departments/1']);
    } finally {
        globalThis.fetch = originalFetch;
        globalThis.Swal = originalSwal;
        env.dom.cleanup();
    }
});

test('กดยกเลิกในกล่องยืนยันแล้วต้องไม่ไปไหน', async () => {
    const env = mount({routes: {update: '/u', destroy: '/d', department: '/dept'}});
    const originalFetch = globalThis.fetch;
    const originalSwal = globalThis.Swal;
    let requests = 0;

    try {
        globalThis.Swal = {fire: async () => ({isConfirmed: false})};
        globalThis.fetch = async () => {
            requests += 1;

            return {ok: true, json: async () => ({ok: true})};
        };

        click(env.dom.document.querySelector('[data-workspace-board-delete]'));
        await new Promise((resolve) => setTimeout(resolve, 0));

        assert.equal(requests, 0);
        assert.deepEqual(env.navigations, []);
    } finally {
        globalThis.fetch = originalFetch;
        globalThis.Swal = originalSwal;
        env.dom.cleanup();
    }
});

/*
 * ปุ่มจัดการถูกซ่อนโดย Blade ตาม policy อยู่แล้ว แต่ถ้ามีคนเผลอเรนเดอร์มันออกมา
 * โดยที่ไม่มี route ให้ยิง ก็ต้องไม่ทำให้หน้าพัง
 */
test('ไม่มี route จัดการกระดาน ปุ่มก็ต้องไม่ทำให้หน้าพัง', () => {
    const env = mount();

    try {
        assert.equal(env.editor.settingsModal, null);
        assert.doesNotThrow(() => click(env.dom.document.querySelector('[data-workspace-board-settings]')));
    } finally {
        env.dom.cleanup();
    }
});

/* ── ปุ่มลัดของเครื่องมือ ────────────────────────────────────── */

/** ยิง keydown แล้วคืนเหตุการณ์กลับมา เพื่อตรวจว่าถูกยกเลิกพฤติกรรมเดิมหรือไม่ */
const keydown = (target, key, options = {}) => {
    const view = target.ownerDocument?.defaultView || target.defaultView;
    const event = new view.KeyboardEvent('keydown', {key, bubbles: true, cancelable: true, ...options});

    target.dispatchEvent(event);

    return event;
};

/*
 * คลิปบอร์ดของระบบจำลอง และลำดับเหตุการณ์แบบเดียวกับเบราว์เซอร์จริง
 * Ctrl+C = keydown แล้วตามด้วยเหตุการณ์ copy, Ctrl+V = keydown แล้วตามด้วย paste
 * (เบราว์เซอร์ยิงแค่ keydown ถ้า keydown ถูกยกเลิก ตัวช่วยจึงจำลองกติกานั้นด้วย)
 */
const systemClipboard = ({files = [], text = ''} = {}) => ({
    data: new Map(text ? [['text/plain', text]] : []),
    files,
});

const fireClipboardEvent = (target, type, clipboard) => {
    const view = target.ownerDocument?.defaultView || target.defaultView;
    const event = new view.Event(type, {bubbles: true, cancelable: true});
    const written = new Map();

    Object.defineProperty(event, 'clipboardData', {
        value: {
            files: type === 'paste' ? clipboard.files : [],
            getData: (format) => clipboard.data.get(format) ?? '',
            setData: (format, value) => written.set(format, value),
        },
    });

    target.dispatchEvent(event);

    // copy ที่ถูกยกเลิกแล้วเขียนข้อมูลเอง = คลิปบอร์ดของระบบถูกแทนทั้งก้อน
    if (type === 'copy' && event.defaultPrevented) {
        clipboard.data = written;
        clipboard.files = [];
    }

    return event;
};

const copyShortcut = (target, clipboard) => {
    if (keydown(target, 'c', {ctrlKey: true}).defaultPrevented) {
        return null;
    }

    return fireClipboardEvent(target, 'copy', clipboard);
};

const pasteShortcut = (target, clipboard) => {
    const keyEvent = keydown(target, 'v', {ctrlKey: true});

    return {keyEvent, pasteEvent: keyEvent.defaultPrevented ? null : fireClipboardEvent(target, 'paste', clipboard)};
};

const keyup = (target, key, options = {}) => {
    const view = target.ownerDocument?.defaultView || target.defaultView;

    target.dispatchEvent(new view.KeyboardEvent('keyup', {key, bubbles: true, cancelable: true, ...options}));
};

const dblclick = (target, {x, y}) => target.dispatchEvent(
    new target.ownerDocument.defaultView.MouseEvent('dblclick', {bubbles: true, clientX: x, clientY: y})
);

const sticky = (id, box, extra = {}) => ({
    id, type: 'sticky', z: 1, ...box, text: 'โน้ต', fill: '#fde68a', fontSize: 16, ...extra,
});

const elementById = (env, id) => env.editor.currentDocument().elements.find((element) => element.id === id);

test('กดตัวอักษรแล้วเปลี่ยนเครื่องมือทันที ครบทุกเครื่องมือ', () => {
    const env = mount();

    try {
        const expected = {
            v: 'select', h: 'hand', p: 'pen', e: 'eraser', n: 'sticky',
            t: 'text', r: 'rect', o: 'ellipse', l: 'line', a: 'arrow',
        };

        Object.entries(expected).forEach(([letter, tool]) => {
            pressKey(env.dom.document, letter);
            assert.equal(env.editor.state.tool, tool, `ปุ่ม ${letter.toUpperCase()} ต้องได้ ${tool}`);
        });
    } finally {
        env.dom.cleanup();
    }
});

test('ปุ่มบนแถบเครื่องมือสะท้อนเครื่องมือที่เลือกด้วยปุ่มลัด', () => {
    const env = mount();

    try {
        pressKey(env.dom.document, 'p');

        assert.equal(env.control('[data-tool="pen"]').classList.contains('is-active'), true);
        assert.equal(env.control('[data-tool="select"]').classList.contains('is-active'), false);
    } finally {
        env.dom.cleanup();
    }
});

test('เปลี่ยนเครื่องมือด้วยปุ่มลัดแล้ววาดได้จริง', () => {
    const env = mount();

    try {
        pressKey(env.dom.document, 'r');
        drag(env.stage, {x: 10, y: 10}, {x: 110, y: 60}, {steps: 2});

        assert.equal(env.editor.currentDocument().elements[0].type, 'rect');
    } finally {
        env.dom.cleanup();
    }
});

test('แป้นภาษาไทยเปิดอยู่ กดปุ่ม R ยังได้เครื่องมือสี่เหลี่ยม', () => {
    const env = mount();

    try {
        pressKey(env.dom.document, 'พ', {code: 'KeyR'});

        assert.equal(env.editor.state.tool, 'rect');
    } finally {
        env.dom.cleanup();
    }
});

test('ปุ่มลัดไม่ทำงานขณะพิมพ์อยู่ในช่องกรอก', () => {
    const env = mount();

    try {
        const field = env.dom.document.createElement('input');
        env.dom.document.body.append(field);
        field.focus();

        const event = keydown(field, 'p');

        assert.equal(env.editor.state.tool, 'select');
        assert.equal(event.defaultPrevented, false, 'ตัว p ต้องพิมพ์ลงช่องได้ตามปกติ');
    } finally {
        env.dom.cleanup();
    }
});

/*
 * เส้นทางจริง: ดับเบิลคลิกเปิดการแก้ไขโน้ต แล้วพิมพ์ ถ้าปุ่มลัดแย่งไป ผู้ใช้จะพิมพ์
 * ตัว r ไม่ได้ วางข้อความไม่ได้ และกด Backspace แล้วโน้ตทั้งแผ่นหายไป
 */
test('ปุ่มลัดทั้งหมดไม่ทำงานขณะแก้ข้อความในกระดาษโน้ต', () => {
    const env = mount({elements: [sticky('note', {x: 100, y: 100, w: 180, h: 180})]});

    try {
        // คัดลอกโน้ตไว้ก่อน เพื่อพิสูจน์ว่า Ctrl+V ในโน้ตไม่ได้ไปวางชิ้นงาน
        drag(env.stage, {x: 150, y: 150}, {x: 150, y: 150});
        copyShortcut(env.dom.document, env.clipboard);

        dblclick(env.stage, {x: 150, y: 150});
        const note = env.node('note');

        assert.equal(env.dom.document.activeElement, note, 'โน้ตต้องได้โฟกัสหลังดับเบิลคลิก');

        const typed = keydown(note, 'r');
        const {keyEvent: pasted, pasteEvent} = pasteShortcut(note, env.clipboard);
        const copyEvent = copyShortcut(note, env.clipboard);
        const erased = keydown(note, 'Backspace');

        assert.equal(env.editor.state.tool, 'select');
        assert.deepEqual(env.editor.currentDocument().elements.map((element) => element.id), ['note']);
        assert.notEqual(pasteEvent, null, 'เบราว์เซอร์ต้องได้ยิง paste เข้ากล่องข้อความ');
        assert.equal(note.getAttribute('contenteditable') !== null, true,
            'วางข้อความแล้วต้องยังอยู่ในโหมดแก้ไข พิมพ์ต่อได้ทันที');
        assert.equal(copyEvent.defaultPrevented, false, 'การคัดลอกข้อความในโน้ตต้องเป็นของเบราว์เซอร์');
        [typed, pasted, erased].forEach((event) => {
            assert.equal(event.defaultPrevented, false, `${event.key} ต้องไปถึงกล่องข้อความตามปกติ`);
        });
    } finally {
        env.dom.cleanup();
    }
});

test('ปุ่มลัดของเครื่องมือไม่ทำงานระหว่างลากค้างอยู่', () => {
    const env = mount({elements: [rect('a', {x: 100, y: 100, w: 100, h: 100})]});

    try {
        pointerDown(env.stage, {x: 150, y: 150});
        pointerMove(env.stage, {x: 180, y: 150});
        pressKey(env.dom.document, 'r');

        assert.equal(env.editor.state.tool, 'select', 'การลากย้ายต้องไม่ถูกทิ้งกลางทาง');

        pointerUp(env.stage, {x: 180, y: 150});
        assert.equal(elementById(env, 'a').x, 130);
    } finally {
        env.dom.cleanup();
    }
});

/* ── Shift ล็อกมุมของเส้น ────────────────────────────────────── */

test('ลากเส้นตรงพร้อม Shift ได้เส้นแนวนอนพอดี', () => {
    const env = mount();

    try {
        pressKey(env.dom.document, 'l');
        drag(env.stage, {x: 100, y: 100}, {x: 300, y: 112}, {steps: 3, shiftKey: true});

        const line = env.editor.currentDocument().elements[0];

        assert.equal(line.h, 0);
        assert.equal(line.w, 200);
    } finally {
        env.dom.cleanup();
    }
});

test('ลากลูกศรทแยงขึ้นซ้ายพร้อม Shift ได้ 45 องศาพอดี และจำทิศทางไว้', () => {
    const env = mount();

    try {
        pressKey(env.dom.document, 'a');
        drag(env.stage, {x: 300, y: 300}, {x: 190, y: 205}, {steps: 3, shiftKey: true});

        const arrow = env.editor.currentDocument().elements[0];

        assert.equal(arrow.w, arrow.h);
        assert.equal(arrow.w < 0, true, 'ลูกศรชี้ขึ้นซ้าย w และ h ต้องติดลบ');
    } finally {
        env.dom.cleanup();
    }
});

test('ไม่กด Shift เส้นตรงไปตามเคอร์เซอร์อิสระเหมือนเดิม', () => {
    const env = mount();

    try {
        pressKey(env.dom.document, 'l');
        drag(env.stage, {x: 100, y: 100}, {x: 300, y: 112}, {steps: 3});

        assert.equal(env.editor.currentDocument().elements[0].h, 12);
    } finally {
        env.dom.cleanup();
    }
});

test('กดและปล่อย Shift กลางท่าลากโดยไม่ขยับเมาส์ เส้นตัวอย่างล็อกและปลดทันที', () => {
    const env = mount();

    try {
        pressKey(env.dom.document, 'l');
        pointerDown(env.stage, {x: 100, y: 100});
        pointerMove(env.stage, {x: 300, y: 130});

        const previewLine = () => env.preview.querySelector('line');

        assert.equal(previewLine().getAttribute('y2'), '130');

        pressKey(env.dom.document, 'Shift', {shiftKey: true});
        assert.equal(previewLine().getAttribute('y2'), '100', 'ต้องล็อกเป็นแนวนอนทันทีที่กด Shift');

        keyup(env.dom.document, 'Shift');
        assert.equal(previewLine().getAttribute('y2'), '130', 'ปล่อย Shift แล้วต้องกลับไปตามเคอร์เซอร์');

        pointerUp(env.stage, {x: 300, y: 130});
    } finally {
        env.dom.cleanup();
    }
});

test('Shift + คลิกยังสลับชิ้นเข้าออกจากกลุ่มที่เลือกเหมือนเดิม', () => {
    const env = mount({elements: [
        rect('a', {x: 0, y: 0, w: 50, h: 50}),
        rect('b', {x: 200, y: 0, w: 50, h: 50}),
    ]});

    try {
        drag(env.stage, {x: 25, y: 25}, {x: 25, y: 25});
        drag(env.stage, {x: 225, y: 25}, {x: 225, y: 25}, {shiftKey: true});

        assert.deepEqual(env.editor.state.selection, ['a', 'b']);

        drag(env.stage, {x: 25, y: 25}, {x: 25, y: 25}, {shiftKey: true});
        assert.deepEqual(env.editor.state.selection, ['b']);
    } finally {
        env.dom.cleanup();
    }
});

/* ── คัดลอก วาง และทำสำเนา ───────────────────────────────────── */

test('Ctrl+C แล้ว Ctrl+V ได้สำเนาที่เลื่อนออกไป มีรหัสใหม่ และถูกเลือกทันที', () => {
    const env = mount({elements: [rect('a', {x: 100, y: 100, w: 50, h: 50})]});

    try {
        drag(env.stage, {x: 120, y: 120}, {x: 120, y: 120});

        const copied = copyShortcut(env.dom.document, env.clipboard);
        const {keyEvent, pasteEvent} = pasteShortcut(env.dom.document, env.clipboard);

        const elements = env.editor.currentDocument().elements;
        const copy = elements[1];

        assert.equal(elements.length, 2);
        assert.notEqual(copy.id, 'a');
        assert.deepEqual({x: copy.x, y: copy.y}, {x: 124, y: 124});
        assert.deepEqual(env.editor.state.selection, [copy.id]);
        assert.deepEqual(env.ids(), ['a', copy.id], 'สำเนาต้องปรากฏบนผืนผ้าใบ');
        assert.notEqual(env.selection.querySelector('[data-part="frame"]'), null);
        assert.equal(copied.defaultPrevented, true, 'ต้องฝากเครื่องหมายไว้ในคลิปบอร์ดของระบบ');
        assert.equal(keyEvent.defaultPrevented, false, 'keydown ของ Ctrl+V ต้องไม่ถูกยกเลิก ไม่งั้นเบราว์เซอร์ไม่ยิง paste');
        assert.equal(pasteEvent.defaultPrevented, true);
    } finally {
        env.dom.cleanup();
    }
});

test('วางหลายชิ้นพร้อมกัน undo ครั้งเดียวเอาออกทั้งชุด redo กลับมาทั้งชุด', () => {
    const env = mount({elements: [
        rect('a', {x: 100, y: 100, w: 50, h: 50}),
        rect('b', {x: 200, y: 100, w: 50, h: 50}),
    ]});

    try {
        drag(env.stage, {x: 10, y: 10}, {x: 390, y: 390}, {steps: 2});
        assert.deepEqual(env.editor.state.selection, ['a', 'b']);

        copyShortcut(env.dom.document, env.clipboard);
        pasteShortcut(env.dom.document, env.clipboard);
        assert.equal(env.editor.currentDocument().elements.length, 4);

        pressKey(env.dom.document, 'z', {ctrlKey: true});
        assert.deepEqual(env.ids(), ['a', 'b']);

        pressKey(env.dom.document, 'z', {ctrlKey: true, shiftKey: true});
        assert.equal(env.editor.currentDocument().elements.length, 4);
    } finally {
        env.dom.cleanup();
    }
});

test('วางซ้ำแต่ละครั้งเลื่อนออกไปอีกขั้น', () => {
    const env = mount({elements: [rect('a', {x: 100, y: 100, w: 50, h: 50})]});

    try {
        drag(env.stage, {x: 120, y: 120}, {x: 120, y: 120});
        copyShortcut(env.dom.document, env.clipboard);
        pasteShortcut(env.dom.document, env.clipboard);
        pasteShortcut(env.dom.document, env.clipboard);

        assert.deepEqual(env.editor.currentDocument().elements.map((element) => element.x), [100, 124, 148]);
    } finally {
        env.dom.cleanup();
    }
});

test('Ctrl+V โดยไม่มีอะไรที่วางได้ ปล่อยให้เบราว์เซอร์ทำงานตามปกติ', () => {
    const env = mount({elements: [rect('a', {x: 100, y: 100, w: 50, h: 50})]});

    try {
        const {keyEvent, pasteEvent} = pasteShortcut(env.dom.document, systemClipboard({text: 'ข้อความจากที่อื่น'}));

        assert.equal(keyEvent.defaultPrevented, false);
        assert.equal(pasteEvent.defaultPrevented, false);
        assert.deepEqual(env.ids(), ['a']);
    } finally {
        env.dom.cleanup();
    }
});

test('ลากคลุมข้อความบนหน้าไว้แล้ว Ctrl+C ได้ข้อความนั้นตามปกติ ไม่ใช่ชิ้นงาน', () => {
    const env = mount({elements: [rect('a', {x: 100, y: 100, w: 50, h: 50})]});

    try {
        drag(env.stage, {x: 120, y: 120}, {x: 120, y: 120});

        const title = env.dom.document.createElement('h1');
        title.textContent = 'กระดานทดสอบ';
        env.dom.document.body.append(title);
        env.dom.document.getSelection().selectAllChildren(title);

        const copied = copyShortcut(env.dom.document, env.clipboard);

        assert.equal(copied.defaultPrevented, false);
        assert.equal(env.editor.state.clipboard, null);
    } finally {
        env.dom.cleanup();
    }
});

/* ── วางรูปจากคลิปบอร์ด (แคปหน้าจอแล้ว Ctrl+V) ─────────────────── */

const pngFile = (env, name = 'shot.png') => new env.dom.window.File(['png'], name, {type: 'image/png'});

/** เซิร์ฟเวอร์ปลอมที่รับอัปโหลดแล้วคืนไฟล์แนบตามจำนวนรูปที่ส่งมา */
const withUploadServer = async (run) => {
    const originalFetch = globalThis.fetch;
    const uploads = [];

    globalThis.fetch = async (url, init) => {
        const files = init.body.getAll('images[]');
        uploads.push({url, count: files.length});

        return {
            ok: true,
            json: async () => ({
                attachments: files.map((file, index) => ({id: 40 + index, src: `/media/${40 + index}`, width: 800, height: 600})),
            }),
        };
    };

    try {
        await run(uploads);
    } finally {
        globalThis.fetch = originalFetch;
    }
};

const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

const images = (env) => env.editor.currentDocument().elements.filter((element) => element.type === 'image');

test('วางรูปจากคลิปบอร์ด อัปโหลดแล้ววางกลางจอและเลือกไว้ทันที', async () => {
    await withUploadServer(async (uploads) => {
        const env = mount({routes: {attachments: '/workspace/boards/7/attachments'}});

        try {
            const {pasteEvent} = pasteShortcut(env.dom.document, systemClipboard({files: [pngFile(env)]}));
            await settle();

            assert.equal(pasteEvent.defaultPrevented, true);
            assert.deepEqual(uploads, [{url: '/workspace/boards/7/attachments', count: 1}]);
            assert.equal(images(env).length, 1);
            assert.equal(images(env)[0].attachmentId, 40);
            assert.deepEqual(env.editor.state.selection, [images(env)[0].id]);
        } finally {
            env.dom.cleanup();
        }
    });
});

/*
 * พบในเบราว์เซอร์จริง: หลังกดปุ่มบนแถบเครื่องมือ โฟกัสอยู่ที่ปุ่ม เบราว์เซอร์จึงยิง
 * paste ไปที่ปุ่มนั้น ตัวฟังเดิมที่ผูกไว้กับผืนผ้าใบไม่เคยได้ยินเลย
 */
test('วางรูปได้แม้เพิ่งกดปุ่มบนแถบเครื่องมือ (โฟกัสอยู่ที่ปุ่ม)', async () => {
    await withUploadServer(async () => {
        const env = mount({routes: {attachments: '/att'}});

        try {
            const penButton = env.control('[data-tool="pen"]');
            click(penButton);
            penButton.focus();

            pasteShortcut(penButton, systemClipboard({files: [pngFile(env)]}));
            await settle();

            assert.equal(images(env).length, 1);
        } finally {
            env.dom.cleanup();
        }
    });
});

/*
 * พบในเบราว์เซอร์จริง: เคยคัดลอกชิ้นงานบนกระดาน แล้วไปแคปหน้าจอมาวาง ได้ชิ้นงานเก่า
 * แทนรูป ของที่คัดลอกล่าสุดต้องชนะเสมอ
 */
test('คัดลอกชิ้นงานบนกระดาน แล้วไปแคปรูปมา Ctrl+V ได้รูป ไม่ใช่ชิ้นงานเก่า', async () => {
    await withUploadServer(async () => {
        const env = mount({elements: [rect('a', {x: 100, y: 100, w: 50, h: 50})], routes: {attachments: '/att'}});

        try {
            drag(env.stage, {x: 120, y: 120}, {x: 120, y: 120});
            copyShortcut(env.dom.document, env.clipboard);

            // ผู้ใช้ไปแคปหน้าจอ คลิปบอร์ดของระบบถูกแทนด้วยรูป
            env.clipboard = systemClipboard({files: [pngFile(env)]});
            pasteShortcut(env.dom.document, env.clipboard);
            await settle();

            assert.equal(images(env).length, 1);
            assert.equal(env.editor.currentDocument().elements.filter((element) => element.type === 'rect').length, 1);
        } finally {
            env.dom.cleanup();
        }
    });
});

test('แคปรูปไว้ก่อน แล้วคัดลอกชิ้นงานบนกระดาน Ctrl+V ได้ชิ้นงาน ไม่ใช่รูปเก่า', async () => {
    await withUploadServer(async (uploads) => {
        const env = mount({elements: [rect('a', {x: 100, y: 100, w: 50, h: 50})], routes: {attachments: '/att'}});

        try {
            env.clipboard = systemClipboard({files: [pngFile(env)]});

            drag(env.stage, {x: 120, y: 120}, {x: 120, y: 120});
            copyShortcut(env.dom.document, env.clipboard);
            pasteShortcut(env.dom.document, env.clipboard);
            await settle();

            assert.equal(uploads.length, 0);
            assert.deepEqual(env.editor.currentDocument().elements.map((element) => element.type), ['rect', 'rect']);
        } finally {
            env.dom.cleanup();
        }
    });
});

test('วางหลายรูปพร้อมกัน ไม่ทับกันสนิท และ undo ครั้งเดียวเอาออกทั้งชุด', async () => {
    await withUploadServer(async () => {
        const env = mount({routes: {attachments: '/att'}});

        try {
            pasteShortcut(env.dom.document, systemClipboard({files: [pngFile(env, 'a.png'), pngFile(env, 'b.png')]}));
            await settle();

            const [first, second] = images(env);

            assert.equal(images(env).length, 2);
            assert.notDeepEqual({x: first.x, y: first.y}, {x: second.x, y: second.y});

            pressKey(env.dom.document, 'z', {ctrlKey: true});
            assert.equal(images(env).length, 0);
        } finally {
            env.dom.cleanup();
        }
    });
});

test('ผู้ที่ดูอย่างเดียววางรูปจากคลิปบอร์ดไม่ได้', async () => {
    await withUploadServer(async (uploads) => {
        const env = mount({canEdit: false, routes: {attachments: '/att'}});

        try {
            const {pasteEvent} = pasteShortcut(env.dom.document, systemClipboard({files: [pngFile(env)]}));
            await settle();

            assert.equal(pasteEvent.defaultPrevented, false);
            assert.equal(uploads.length, 0);
            assert.equal(images(env).length, 0);
        } finally {
            env.dom.cleanup();
        }
    });
});

test('Ctrl+D ทำสำเนาชิ้นที่เลือก กันบุ๊กมาร์กของเบราว์เซอร์ และ undo ได้ในก้าวเดียว', () => {
    const env = mount({elements: [rect('a', {x: 100, y: 100, w: 50, h: 50})]});

    try {
        drag(env.stage, {x: 120, y: 120}, {x: 120, y: 120});

        const event = keydown(env.dom.document, 'd', {ctrlKey: true});
        const [, copy] = env.editor.currentDocument().elements;

        assert.equal(event.defaultPrevented, true);
        assert.equal(copy.x, 124);
        assert.deepEqual(env.editor.state.selection, [copy.id]);

        pressKey(env.dom.document, 'z', {ctrlKey: true});
        assert.deepEqual(env.ids(), ['a']);
    } finally {
        env.dom.cleanup();
    }
});

test('รหัสของสำเนาไม่ซ้ำกันเลย แม้ทำสำเนาต่อจากสำเนาหลายรอบ', () => {
    const env = mount({elements: [
        rect('a', {x: 100, y: 100, w: 50, h: 50}),
        rect('b', {x: 200, y: 100, w: 50, h: 50}),
    ]});

    try {
        drag(env.stage, {x: 10, y: 10}, {x: 390, y: 390}, {steps: 2});

        pressKey(env.dom.document, 'd', {ctrlKey: true});
        pressKey(env.dom.document, 'd', {ctrlKey: true});
        copyShortcut(env.dom.document, env.clipboard);
        pasteShortcut(env.dom.document, env.clipboard);

        const ids = env.editor.currentDocument().elements.map((element) => element.id);

        assert.equal(ids.length, 8);
        assert.equal(new Set(ids).size, ids.length, `รหัสซ้ำ: ${ids.join(', ')}`);
    } finally {
        env.dom.cleanup();
    }
});

test('กดค้าง Ctrl+D จนแป้นยิงซ้ำ ไม่ได้สำเนาเพิ่มจากการยิงซ้ำ', () => {
    const env = mount({elements: [rect('a', {x: 100, y: 100, w: 50, h: 50})]});

    try {
        drag(env.stage, {x: 120, y: 120}, {x: 120, y: 120});
        pressKey(env.dom.document, 'd', {ctrlKey: true});
        pressKey(env.dom.document, 'd', {ctrlKey: true, repeat: true});
        pressKey(env.dom.document, 'd', {ctrlKey: true, repeat: true});

        assert.equal(env.editor.currentDocument().elements.length, 2);
    } finally {
        env.dom.cleanup();
    }
});

/* ── การหมุน ──────────────────────────────────────────────────── */

test('เลือกชิ้นงานแล้วเห็นมือจับหมุนเหนือกรอบ', () => {
    const env = mount({elements: [rect('a', {x: 100, y: 100, w: 100, h: 100})]});

    try {
        drag(env.stage, {x: 150, y: 150}, {x: 150, y: 150});

        const knob = env.selection.querySelector('[data-part="rotate-handle"]');

        assert.notEqual(knob, null);
        assert.equal(knob.getAttribute('cx'), '150');
        assert.equal(knob.getAttribute('cy'), '72');
    } finally {
        env.dom.cleanup();
    }
});

test('ลากมือจับหมุนแล้วชิ้นงานหมุนจริง ทั้งในเอกสารและบนผืนผ้าใบ', () => {
    const env = mount({elements: [rect('a', {x: 100, y: 100, w: 100, h: 100})]});

    try {
        drag(env.stage, {x: 150, y: 150}, {x: 150, y: 150});
        drag(env.stage, {x: 150, y: 72}, {x: 250, y: 150}, {steps: 4});

        assert.equal(elementById(env, 'a').rotation, 90);
        assert.equal(env.node('a').getAttribute('transform'), 'rotate(90 150 150)');
        assert.equal(
            env.selection.querySelector('[data-part="frame"]').getAttribute('transform'),
            'rotate(90 150 150)',
            'กรอบการเลือกต้องเอียงตามชิ้นงาน'
        );
    } finally {
        env.dom.cleanup();
    }
});

test('กด Shift ระหว่างหมุน มุมล็อกทีละ 15 องศา', () => {
    const env = mount({elements: [rect('a', {x: 100, y: 100, w: 100, h: 100})]});

    try {
        drag(env.stage, {x: 150, y: 150}, {x: 150, y: 150});

        // จุดปลายอยู่ที่มุม -40 องศาจากจุดกลาง คือหมุนไป 50 องศาจากมือจับด้านบน
        const radians = (-40 * Math.PI) / 180;
        const end = {x: 150 + 100 * Math.cos(radians), y: 150 + 100 * Math.sin(radians)};
        drag(env.stage, {x: 150, y: 72}, end, {steps: 3, shiftKey: true});

        assert.equal(elementById(env, 'a').rotation, 45);
    } finally {
        env.dom.cleanup();
    }
});

test('การหมุนนับเป็นก้าวย้อนกลับเดียว และ undo แล้วกลับมาตั้งตรงบนจอด้วย', () => {
    const env = mount({elements: [rect('a', {x: 100, y: 100, w: 100, h: 100})]});

    try {
        drag(env.stage, {x: 150, y: 150}, {x: 150, y: 150});
        drag(env.stage, {x: 150, y: 72}, {x: 250, y: 150}, {steps: 6});
        pressKey(env.dom.document, 'z', {ctrlKey: true});

        assert.equal('rotation' in elementById(env, 'a'), false);
        assert.equal(env.node('a').hasAttribute('transform'), false);

        pressKey(env.dom.document, 'z', {ctrlKey: true, shiftKey: true});
        assert.equal(elementById(env, 'a').rotation, 90);
    } finally {
        env.dom.cleanup();
    }
});

test('หมุนหลายชิ้นพร้อมกันรอบจุดกลางของกลุ่ม', () => {
    const env = mount({elements: [
        rect('a', {x: 100, y: 100, w: 50, h: 50}),
        rect('b', {x: 250, y: 100, w: 50, h: 50}),
    ]});

    try {
        drag(env.stage, {x: 10, y: 10}, {x: 390, y: 390}, {steps: 2});

        // กรอบกลุ่ม x 100..300, y 100..150 กลาง (200,125) มือจับหมุนอยู่ที่ (200,72)
        drag(env.stage, {x: 200, y: 72}, {x: 200, y: 400}, {steps: 6});

        const a = elementById(env, 'a');

        assert.equal(a.rotation, 180);
        assert.equal(Math.round(a.x), 250, 'ชิ้นซ้ายต้องโคจรไปอยู่ทางขวา');
    } finally {
        env.dom.cleanup();
    }
});

test('ย่อขยายชิ้นที่หมุนอยู่ มุมยังคงเดิม', () => {
    const env = mount({elements: [rect('a', {x: 100, y: 100, w: 100, h: 100, rotation: 90})]});

    try {
        drag(env.stage, {x: 150, y: 150}, {x: 150, y: 150});
        // มือจับ e ของกรอบที่หมุน 90 องศาอยู่ที่ขอบล่างกลาง (150,200) บนจอ
        drag(env.stage, {x: 150, y: 200}, {x: 150, y: 230}, {steps: 2});

        const a = elementById(env, 'a');

        assert.equal(a.rotation, 90);
        assert.equal(Math.round(a.w), 130);
    } finally {
        env.dom.cleanup();
    }
});

test('ชิ้นที่หมุนอยู่คลิกเลือกได้จากส่วนที่เห็นจริง ไม่ใช่จากตำแหน่งเดิม', () => {
    const env = mount({elements: [rect('a', {x: 100, y: 100, w: 100, h: 100, rotation: 45})]});

    try {
        drag(env.stage, {x: 104, y: 104}, {x: 104, y: 104});
        assert.deepEqual(env.editor.state.selection, [], 'มุมเดิมของกรอบว่างไปแล้วหลังหมุน');

        drag(env.stage, {x: 150, y: 85}, {x: 150, y: 85});
        assert.deepEqual(env.editor.state.selection, ['a'], 'ยอดที่ยื่นออกไปหลังหมุนต้องคลิกโดน');
    } finally {
        env.dom.cleanup();
    }
});

/*
 * บันทึกแล้วโหลดใหม่คือการ serialize ออกไปแล้วเปิดหน้าใหม่ด้วยเอกสารนั้น
 * (ฝั่งเซิร์ฟเวอร์ตรวจแยกไว้ใน WorkspaceBoardDocumentSaveTest)
 */
test('มุมที่หมุนไว้ยังอยู่หลังบันทึกและเปิดหน้าใหม่', () => {
    const first = mount({elements: [rect('a', {x: 100, y: 100, w: 100, h: 100})]});
    let saved;

    try {
        drag(first.stage, {x: 150, y: 150}, {x: 150, y: 150});
        drag(first.stage, {x: 150, y: 72}, {x: 250, y: 150}, {steps: 4});
        saved = JSON.parse(JSON.stringify(first.editor.currentDocument()));
    } finally {
        first.dom.cleanup();
    }

    const reopened = mount({elements: saved.elements});

    try {
        assert.equal(elementById(reopened, 'a').rotation, 90);
        assert.equal(
            reopened.node('a').getAttribute('transform'),
            'rotate(90 150 150)'
        );
    } finally {
        reopened.dom.cleanup();
    }
});

test('กระดาษโน้ตที่หมุนอยู่ เอียงทั้งกล่องข้อความ', () => {
    const env = mount({elements: [sticky('note', {x: 0, y: 0, w: 180, h: 180}, {rotation: 30})]});

    try {
        assert.equal(env.node('note').style.transform, 'rotate(30deg)');
    } finally {
        env.dom.cleanup();
    }
});

test('เอกสารเก่าที่ไม่มี rotation เปิดได้ตามปกติ และไม่มีการหมุนติดมา', () => {
    const env = mount({elements: [
        rect('a', {x: 100, y: 100, w: 100, h: 100}),
        sticky('note', {x: 300, y: 0, w: 180, h: 180}),
    ]});

    try {
        assert.deepEqual(env.ids(), ['a', 'note']);
        assert.equal(env.node('a').hasAttribute('transform'), false);
        assert.equal(env.node('note').style.transform, '');
        assert.equal('rotation' in elementById(env, 'a'), false, 'เอกสารที่ส่งกลับต้องไม่มีคีย์ใหม่งอกขึ้นมาเอง');
    } finally {
        env.dom.cleanup();
    }
});

/* ── ผู้ที่ดูอย่างเดียวกับปุ่มลัดและมือจับ ───────────────────── */

test('ผู้ที่ดูอย่างเดียวกดปุ่มลัดเครื่องมือวาดไม่ได้ แต่สลับระหว่างเลื่อนกับเลือกได้', () => {
    const env = mount({canEdit: false});

    try {
        ['p', 'e', 'n', 't', 'r', 'o', 'l', 'a'].forEach((letter) => {
            pressKey(env.dom.document, letter);
            assert.equal(env.editor.state.tool, 'hand', `ปุ่ม ${letter} ต้องไม่เปลี่ยนเครื่องมือ`);
        });

        pressKey(env.dom.document, 'v');
        assert.equal(env.editor.state.tool, 'select');

        pressKey(env.dom.document, 'h');
        assert.equal(env.editor.state.tool, 'hand');
    } finally {
        env.dom.cleanup();
    }
});

test('ผู้ที่ดูอย่างเดียวแก้กระดานผ่านปุ่มลัดไม่ได้เลย', () => {
    const env = mount({canEdit: false, elements: [rect('a', {x: 100, y: 100, w: 100, h: 100})]});

    try {
        pressKey(env.dom.document, 'v');
        drag(env.stage, {x: 150, y: 150}, {x: 150, y: 150});
        assert.deepEqual(env.editor.state.selection, ['a'], 'ยังเลือกเพื่อชี้ให้เพื่อนดูได้');

        copyShortcut(env.dom.document, env.clipboard);
        pasteShortcut(env.dom.document, env.clipboard);
        pressKey(env.dom.document, 'd', {ctrlKey: true});
        pressKey(env.dom.document, 'Delete');
        pressKey(env.dom.document, 'z', {ctrlKey: true});

        assert.deepEqual(env.ids(), ['a']);
        assert.deepEqual(env.editor.currentDocument().elements, [rect('a', {x: 100, y: 100, w: 100, h: 100})]);
    } finally {
        env.dom.cleanup();
    }
});

test('ผู้ที่ดูอย่างเดียวไม่เห็นมือจับหมุน และลากย้ายหรือหมุนชิ้นงานไม่ได้', () => {
    const env = mount({canEdit: false, elements: [rect('a', {x: 100, y: 100, w: 100, h: 100})]});

    try {
        pressKey(env.dom.document, 'v');
        drag(env.stage, {x: 150, y: 150}, {x: 150, y: 150});

        assert.notEqual(env.selection.querySelector('[data-part="frame"]'), null);
        assert.equal(env.selection.querySelector('[data-part="rotate-handle"]'), null);

        drag(env.stage, {x: 150, y: 150}, {x: 250, y: 250}, {steps: 3});
        drag(env.stage, {x: 150, y: 72}, {x: 250, y: 150}, {steps: 3});

        const a = elementById(env, 'a');

        assert.deepEqual({x: a.x, y: a.y}, {x: 100, y: 100});
        assert.equal('rotation' in a, false);
    } finally {
        env.dom.cleanup();
    }
});

/* ── ลำดับชั้นข้ามชนิด และการเล็งโดน ─────────────────────────── */

/*
 * เรื่องที่ผู้ใช้รายงานตรง ๆ: "ผมจะย้ายพวกเส้นให้ขึ้นมาอยู่ด้านบน กลับไม่แสดง
 * อยู่ด้านบน" ต้นเหตุคือเส้นอยู่ในชั้น SVG ส่วนกระดาษโน้ตอยู่ในชั้น HTML ที่ทับ
 * อยู่ข้างบนตายตัว ลำดับในเอกสารถูกต้องแล้วแต่หน้าจอไม่เคยสะท้อนออกมา
 *
 * เทียบจากลำดับโหนดใน DOM โดยตรง เพราะนั่นคือสิ่งที่ตัดสินว่าอะไรทับอะไรจริง ๆ
 * ไม่ใช่เทียบค่า z ในเอกสารซึ่งเป็นสิ่งที่ผ่านอยู่แล้วตอนที่ผู้ใช้เจอปัญหา
 */
test('สั่งให้เส้นขึ้นบนสุด เส้นต้องขึ้นไปอยู่เหนือกระดาษโน้ตบนหน้าจอจริง', () => {
    const env = mount({elements: [
        {id: 'l1', type: 'line', z: 1, x: 0, y: 200, w: 400, h: 0, stroke: '#1f2937', strokeWidth: 3},
        sticky('note', {x: 100, y: 100, w: 200, h: 200}),
    ]});

    try {
        assert.deepEqual(env.ids(), ['l1', 'note'], 'เริ่มต้นเส้นอยู่ล่างโน้ต');

        click(env.control('[data-tool="select"]'));
        drag(env.stage, {x: 20, y: 200}, {x: 20, y: 200});

        assert.deepEqual(env.editor.state.selection, ['l1']);
        assert.equal(env.editor.runCommand('bring-to-front'), true);
        assert.deepEqual(env.ids(), ['note', 'l1'], 'เส้นต้องอยู่หลังโน้ตใน DOM คืออยู่บนจอ');

        assert.equal(env.editor.runCommand('send-to-back'), true);
        assert.deepEqual(env.ids(), ['l1', 'note']);
    } finally {
        env.dom.cleanup();
    }
});

/*
 * "กดยากมาก" ของผู้ใช้คือการเล็งคลิกขวาให้โดนเส้นบาง ๆ ซึ่งเดิมไม่มีระยะผ่อนผัน
 * เลย ต่างจากคลิกซ้ายที่ผ่อนผันได้ 6 พิกเซล จุดเดียวกันจึงคลิกซ้ายโดนแต่คลิกขวา
 * ไม่โดน ตอนนี้ทั้งสองทางใช้ค่าเดียวกันจาก geometry.js
 */
test('คลิกขวาเฉียดเส้นบาง ๆ ยังเลือกเส้นนั้นได้เหมือนคลิกซ้าย', () => {
    const env = mount({elements: [
        {id: 'l1', type: 'line', z: 1, x: 0, y: 200, w: 400, h: 0, stroke: '#1f2937', strokeWidth: 2},
    ]});

    try {
        rightClick(env.stage, {x: 200, y: 204});

        assert.deepEqual(env.editor.state.selection, ['l1']);
    } finally {
        env.dom.cleanup();
    }
});

/*
 * เคอร์เซอร์ต้องบอกว่าถือเครื่องมืออะไรอยู่ ก่อนหน้านี้เป็นกากบาทตัวเดียวตลอด
 * ผู้ใช้จึงไม่รู้จากปลายเมาส์เลยว่ากำลังถือดินสอหรือยางลบ รูปร่างจริงอยู่ใน
 * workspace/stage.css ที่นี่ตรวจว่าสถานะถูกส่งออกไปให้ CSS ใช้
 */
test('ดินสอกับยางลบมีไอคอนลอยตามเมาส์ บอกว่ากำลังถืออะไรอยู่', () => {
    const env = mount();
    const badge = () => env.control('[data-workspace-tool-cursor]');
    const iconClass = () => env.control('[data-workspace-tool-cursor-icon]').getAttribute('class');

    try {
        assert.equal(badge().hidden, true, 'เครื่องมือเลือกไม่ต้องมีไอคอนลอย');

        click(env.control('[data-tool="pen"]'));

        /*
         * เคอร์เซอร์ต้องหายตั้งแต่กดปุ่ม ไม่ใช่รอให้เมาส์ขยับก่อน และต้องหายจริง
         * ไม่ใช่แค่มีกฎอยู่ในไฟล์ CSS — ผู้ใช้รายงานซ้ำหลายรอบว่าเห็นทั้งเคอร์เซอร์
         * ลูกศรและไอคอนดินสอพร้อมกัน ข้อนี้จึงตรวจที่ตัว element จริง
         */
        assert.equal(env.stage.style.cursor, 'none', 'กดดินสอแล้วเคอร์เซอร์ต้องหายทันที');

        pointerMove(env.stage, {x: 120, y: 90});

        assert.equal(badge().hidden, false);
        assert.match(iconClass(), /bi-pencil/, 'ต้องเป็นไอคอนเดียวกับปุ่มดินสอบนแถบ');
        assert.equal(badge().style.transform, 'translate(120px, 90px)', 'ต้องตามเมาส์ไป');

        click(env.control('[data-tool="eraser"]'));
        assert.match(iconClass(), /bi-eraser/);
        assert.equal(env.stage.style.cursor, 'none', 'ยางลบก็ต้องไม่มีเคอร์เซอร์เช่นกัน');

        click(env.control('[data-tool="select"]'));
        assert.equal(badge().hidden, true, 'เครื่องมือที่ระบบมีเคอร์เซอร์ให้อยู่แล้วไม่ต้องมีไอคอน');
        assert.equal(env.stage.style.cursor, '', 'และต้องได้เคอร์เซอร์ของระบบคืนมา');
    } finally {
        env.dom.cleanup();
    }
});

/*
 * ไอคอนต้องหายไปเมื่อเมาส์ออกนอกกระดาน ไม่ค้างอยู่กลางจอ และต้องไม่โผล่บนจอสัมผัส
 * ซึ่งไม่มีเคอร์เซอร์ให้ตามอยู่แล้ว ชิปที่วิ่งตามนิ้วมีแต่จะบังงาน
 */
test('ไอคอนลอยหายไปเมื่อเมาส์ออกนอกกระดาน และไม่โผล่เมื่อใช้นิ้ว', () => {
    const env = mount();
    const badge = () => env.control('[data-workspace-tool-cursor]');

    try {
        click(env.control('[data-tool="pen"]'));
        pointerMove(env.stage, {x: 50, y: 50});
        assert.equal(badge().hidden, false);

        env.stage.dispatchEvent(new env.dom.window.MouseEvent('pointerleave', {bubbles: false}));
        assert.equal(badge().hidden, true);

        const touch = new env.dom.window.MouseEvent('pointermove', {bubbles: true, clientX: 60, clientY: 60});
        Object.defineProperty(touch, 'pointerType', {value: 'touch'});
        env.stage.dispatchEvent(touch);

        assert.equal(badge().hidden, true, 'นิ้วไม่ต้องมีไอคอนตาม');
    } finally {
        env.dom.cleanup();
    }
});

test('ผืนผ้าใบประกาศเครื่องมือที่ถืออยู่ให้ CSS เปลี่ยนเคอร์เซอร์ได้', () => {
    const env = mount();

    try {
        assert.equal(env.stage.dataset.tool, 'select');

        click(env.control('[data-tool="pen"]'));
        assert.equal(env.stage.dataset.tool, 'pen');

        click(env.control('[data-tool="eraser"]'));
        assert.equal(env.stage.dataset.tool, 'eraser');

        click(env.control('[data-tool="hand"]'));
        assert.equal(env.stage.dataset.tool, 'hand');
    } finally {
        env.dom.cleanup();
    }
});

/* ── มือเลื่อนกระดานกับกล่องข้อความ ──────────────────────────── */

/*
 * อาการที่ผู้ใช้รายงาน: ถือมือเลื่อนกระดานแล้วเลื่อนไปโดนกระดาษโน้ต กระดานก็
 * เลื่อนไม่ได้อีก ต้องไปคลิกที่อื่นก่อนทุกครั้ง
 *
 * ต้นเหตุคือการเลื่อนกระดานมักเป็นการลากสั้น ๆ ติดกันหลายครั้งที่จุดเดิม
 * เบราว์เซอร์นับเป็นดับเบิลคลิก โน้ตใต้เมาส์จึงเปิดโหมดพิมพ์ แล้วกล่องที่เปิด
 * โหมดพิมพ์ถือ pointer-events: auto ไว้เอง การลากครั้งต่อไปจึงไม่ถึงผืนผ้าใบ
 */
test('ถือมือเลื่อนกระดานแล้วดับเบิลคลิกโน้ต ต้องไม่เปิดโหมดพิมพ์', () => {
    const env = mount({elements: [sticky('note', {x: 100, y: 100, w: 180, h: 180})]});

    try {
        click(env.control('[data-tool="hand"]'));
        dblclick(env.stage, {x: 150, y: 150});

        const note = env.node('note');

        assert.equal(note.classList.contains('is-editing'), false, 'โน้ตต้องไม่เข้าโหมดพิมพ์');
        assert.equal(note.getAttribute('contenteditable'), null);
        assert.equal(env.editor.state.editingId, null);
        assert.notEqual(env.dom.document.activeElement, note, 'โฟกัสต้องไม่ย้ายไปที่โน้ต');
    } finally {
        env.dom.cleanup();
    }
});

test('เปลี่ยนไปถือมือเลื่อนกระดานกลางการพิมพ์ แล้วลากเลื่อนจากบนกล่องนั้นได้ทันที', () => {
    const env = mount({elements: [sticky('note', {x: 100, y: 100, w: 180, h: 180})]});

    try {
        dblclick(env.stage, {x: 150, y: 150});
        click(env.control('[data-tool="hand"]'));

        /*
         * ยิงเหตุการณ์ที่ตัวกล่องเอง ซึ่งคือสิ่งที่เบราว์เซอร์ส่งมาจริงเมื่อกล่อง
         * ยังเปิดโหมดพิมพ์อยู่ (CSS ให้ pointer-events: auto เฉพาะกล่องที่กำลังแก้)
         * jsdom ไม่คำนวณ CSS จึงต้องเลือกเป้าหมายเองแทนการพึ่งการทดสอบการชน
         */
        const note = env.node('note');

        drag(note, {x: 150, y: 150}, {x: 210, y: 180}, {steps: 2});

        assert.deepEqual(
            {x: env.editor.state.camera.x, y: env.editor.state.camera.y},
            {x: 60, y: 30},
            'ไม่ต้องไปคลิกที่อื่นก่อนจึงจะเลื่อนกระดานได้'
        );
        assert.equal(elementById(env, 'note').x, 100, 'โน้ตต้องไม่ขยับ');
    } finally {
        env.dom.cleanup();
    }
});

/*
 * เครื่องมือเลือกยังต้องเปิดพิมพ์ด้วยดับเบิลคลิกได้เหมือนเดิม ด่านด้านบนกันเฉพาะ
 * เครื่องมือที่ทำงานกับมุมมองเท่านั้น ไม่ใช่ปิดการเปิดพิมพ์ไปทั้งหน้า
 */
test('เครื่องมือเลือกยังเปิดโหมดพิมพ์ด้วยดับเบิลคลิกได้ตามเดิม', () => {
    const env = mount({elements: [sticky('note', {x: 100, y: 100, w: 180, h: 180})]});

    try {
        dblclick(env.stage, {x: 150, y: 150});

        assert.equal(env.node('note').classList.contains('is-editing'), true);
        assert.equal(env.editor.state.editingId, 'note');
    } finally {
        env.dom.cleanup();
    }
});

/*
 * เปลี่ยนเครื่องมือต้องจบการพิมพ์ด้วย ไม่ใช่ปล่อยให้กล่องเปิดโหมดพิมพ์ค้างไว้
 * ข้ามเครื่องมือ และต้องบันทึกข้อความที่พิมพ์ไว้ด้วยเส้นทางเดียวกับการคลิกออก
 */
test('เปลี่ยนเครื่องมือจบการพิมพ์ในโน้ตและบันทึกข้อความที่พิมพ์ไว้', () => {
    const env = mount({elements: [sticky('note', {x: 100, y: 100, w: 180, h: 180})]});

    try {
        dblclick(env.stage, {x: 150, y: 150});

        const note = env.node('note');

        assert.equal(env.dom.document.activeElement, note, 'โน้ตต้องได้โฟกัสหลังดับเบิลคลิก');

        note.textContent = 'ปรับขั้นตอนแจ้งซ่อม';

        click(env.control('[data-tool="hand"]'));

        assert.equal(env.editor.state.editingId, null);
        assert.equal(env.node('note').getAttribute('contenteditable'), null,
            'กล่องต้องไม่เปิดโหมดพิมพ์ค้างข้ามเครื่องมือ');
        assert.equal(elementById(env, 'note').text, 'ปรับขั้นตอนแจ้งซ่อม',
            'ข้อความที่พิมพ์ไว้ต้องถูกบันทึก ไม่ใช่หายไปพร้อมการเปลี่ยนเครื่องมือ');
    } finally {
        env.dom.cleanup();
    }
});
