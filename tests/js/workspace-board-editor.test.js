import test from 'node:test';
import assert from 'node:assert/strict';
import {mountDom, click, pressKey} from './helpers/dom.js';
import {drag, pointerDown, pointerMove, pointerUp, stubStageRect, wheel} from './helpers/pointer.js';
import {initBoardEditor} from '../../resources/js/pages/workspace/index.js';

/*
 * เส้นทางจริงของหน้าวาด ตั้งแต่การกดเมาส์บนผืนผ้าใบไปจนถึงโหนดที่ปรากฏ
 *
 * เทสต์ชุดอื่นตรวจตรรกะทีละโมดูล ชุดนี้ตรวจว่าสายไฟระหว่างโมดูลต่อถูกต้อง
 * ซึ่งเป็นจุดที่ข้อผิดพลาดจริงมักเกิด และเป็นสิ่งที่ CLAUDE.md ระบุว่าเทสต์ UI
 * ต้องครอบคลุมเส้นทางจากการกดปุ่มถึงผลลัพธ์ที่มองเห็น
 */

const DESIGN = {
    defaultTool: 'select',
    defaultStroke: '#1f2937',
    defaultStrokeWidth: 4,
    minScale: 0.1,
    maxScale: 4,
};

const markup = ({capabilities, elements = [], routes = {}}) => `<!doctype html>
<html><body>
<div class="ws-board" data-workspace-board data-board-id="7">
    <script type="application/json" id="workspace-design">${JSON.stringify(DESIGN)}</script>
    <script type="application/json" id="workspace-board">${JSON.stringify({
        id: 7, title: 'กระดานทดสอบ', visibility: 'organization', departmentId: 1, capabilities, version: 1,
    })}</script>
    <script type="application/json" id="workspace-document">${JSON.stringify({schema: 1, elements})}</script>
    <script type="application/json" id="workspace-routes">${JSON.stringify(routes)}</script>

    <div class="wsb-toolbar" data-workspace-toolbar>
        <button type="button" data-tool="hand"></button>
        <button type="button" data-tool="select"></button>
        <button type="button" data-tool="pen" data-requires-edit></button>
        <button type="button" data-tool="rect" data-requires-edit></button>
        <button type="button" data-tool="eraser" data-requires-edit></button>
        <button type="button" data-color="#dc2626" data-requires-edit></button>
        <input type="color" data-custom-color data-requires-edit value="#1f2937">
        <button type="button" data-stroke-width="8" data-requires-edit></button>
        <button type="button" data-command="undo" data-requires-edit disabled></button>
        <button type="button" data-command="redo" data-requires-edit disabled></button>
        <button type="button" data-command="delete" data-requires-edit disabled></button>
        <button type="button" data-command="zoom-in"></button>
        <button type="button" data-command="zoom-out"></button>
        <button type="button" data-command="fit"></button>
        <button type="button" data-command="fullscreen" data-workspace-fullscreen aria-pressed="false"><i class="bi bi-arrows-fullscreen"></i></button>
        <span data-zoom-label>100%</span>
    </div>

    <button type="button" data-workspace-board-settings></button>
    <button type="button" data-workspace-board-delete></button>

    <div class="ws-modal" data-workspace-modal hidden>
        <div data-workspace-modal-dismiss></div>
        <form data-workspace-form novalidate>
            <h2 data-workspace-modal-title></h2>
            <input type="text" name="title" data-workspace-title>
            <label><input type="radio" name="visibility" value="organization" checked></label>
            <label><input type="radio" name="visibility" value="department"></label>
            <p data-workspace-error hidden></p>
            <button type="submit" data-workspace-submit></button>
        </form>
    </div>

    <div class="wsb-stage" data-workspace-stage>
        <svg class="wsb-canvas">
            <g data-workspace-vector></g>
            <g data-workspace-preview></g>
            <g data-workspace-selection></g>
        </svg>
    </div>
</div>
</body></html>`;

const rect = (id, box) => ({
    id, type: 'rect', z: 1, ...box, stroke: '#1f2937', strokeWidth: 2, fill: '#fde68a',
});

let seed = 0;

const mount = ({canEdit = true, elements = [], routes = {}} = {}) => {
    const dom = mountDom(markup({
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
        navigations,
        reloadCount: () => reloads,
        vector: dom.document.querySelector('[data-workspace-vector]'),
        preview: dom.document.querySelector('[data-workspace-preview]'),
        selection: dom.document.querySelector('[data-workspace-selection]'),
        toolbar: dom.document.querySelector('[data-workspace-toolbar]'),
        ids: () => Array.from(
            dom.document.querySelectorAll('[data-workspace-vector] > *')
        ).map((n) => n.getAttribute('data-el-id')),
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
        click(env.toolbar.querySelector('[data-tool="pen"]'));
        drag(env.stage, {x: 100, y: 100}, {x: 200, y: 160}, {steps: 4});

        const nodes = Array.from(env.vector.children);

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
        click(env.toolbar.querySelector('[data-tool="pen"]'));
        click(env.toolbar.querySelector('[data-color="#dc2626"]'));
        click(env.toolbar.querySelector('[data-stroke-width="8"]'));
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
        click(env.toolbar.querySelector('[data-tool="rect"]'));
        pointerDown(env.stage, {x: 50, y: 50});
        pointerMove(env.stage, {x: 150, y: 120});

        assert.equal(env.preview.children.length, 1, 'ต้องเห็นตัวอย่างระหว่างลาก');
        assert.equal(env.vector.children.length, 0, 'แต่ฉากจริงต้องยังว่าง');

        pointerUp(env.stage, {x: 150, y: 120});

        assert.equal(env.preview.children.length, 0);
        assert.equal(env.vector.children.length, 1);
    } finally {
        env.dom.cleanup();
    }
});

test('คลิกเลือกชิ้นงานแล้วเห็นกรอบและมือจับ', () => {
    const env = mount({elements: [rect('a', {x: 100, y: 100, w: 100, h: 100})]});

    try {
        click(env.toolbar.querySelector('[data-tool="select"]'));
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
        click(env.toolbar.querySelector('[data-tool="eraser"]'));
        drag(env.stage, {x: 50, y: 50}, {x: 60, y: 60});

        assert.deepEqual(env.ids(), []);
    } finally {
        env.dom.cleanup();
    }
});

test('ย้อนกลับและทำซ้ำผ่านปุ่มบนแถบเครื่องมือ', () => {
    const env = mount();

    try {
        click(env.toolbar.querySelector('[data-tool="rect"]'));
        drag(env.stage, {x: 10, y: 10}, {x: 110, y: 60}, {steps: 2});

        assert.equal(env.vector.children.length, 1);

        const undoButton = env.toolbar.querySelector('[data-command="undo"]');
        assert.equal(undoButton.disabled, false, 'ปุ่มย้อนกลับต้องเปิดใช้งานหลังมีการแก้');

        click(undoButton);
        assert.equal(env.vector.children.length, 0);

        click(env.toolbar.querySelector('[data-command="redo"]'));
        assert.equal(env.vector.children.length, 1);
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
        click(env.toolbar.querySelector('[data-tool="pen"]'));
        drag(env.stage, {x: 10, y: 10}, {x: 200, y: 200}, {steps: 20});

        click(env.toolbar.querySelector('[data-command="undo"]'));

        assert.equal(env.vector.children.length, 0);
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
        click(env.toolbar.querySelector('[data-tool="rect"]'));
        drag(env.stage, {x: 10, y: 10}, {x: 110, y: 60}, {steps: 2});

        pressKey(env.dom.document, 'z', {ctrlKey: true});
        assert.equal(env.vector.children.length, 0);

        pressKey(env.dom.document, 'z', {ctrlKey: true, shiftKey: true});
        assert.equal(env.vector.children.length, 1);
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
        click(env.toolbar.querySelector('[data-tool="rect"]'));
        drag(env.stage, {x: 10, y: 10}, {x: 110, y: 60}, {steps: 2});

        const field = env.dom.document.createElement('input');
        env.dom.document.body.append(field);
        field.focus();

        pressKey(field, 'z', {ctrlKey: true});

        assert.equal(env.vector.children.length, 1, 'กระดานต้องไม่ถูกย้อนกลับ');
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
        click(env.toolbar.querySelector('[data-command="zoom-in"]'));

        assert.equal(env.editor.state.camera.scale > 1, true);
        assert.equal(env.toolbar.querySelector('[data-zoom-label]').textContent, '120%');

        click(env.toolbar.querySelector('[data-command="zoom-out"]'));
        assert.equal(env.toolbar.querySelector('[data-zoom-label]').textContent, '100%');
    } finally {
        env.dom.cleanup();
    }
});

test('เครื่องมือเลื่อนกระดานย้ายกล้องโดยไม่แตะเนื้อหา', () => {
    const env = mount({elements: [rect('a', {x: 0, y: 0, w: 50, h: 50})]});

    try {
        click(env.toolbar.querySelector('[data-tool="hand"]'));
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

        assert.equal(env.toolbar.querySelector('[data-tool="hand"]').disabled, false);
        assert.equal(env.toolbar.querySelector('[data-command="zoom-in"]').disabled, false,
            'ปุ่มมุมมองต้องใช้ได้ เพราะไม่ได้แก้เนื้อหา');
    } finally {
        env.dom.cleanup();
    }
});

test('ผู้ที่ดูอย่างเดียวเริ่มด้วยเครื่องมือเลื่อนกระดาน', () => {
    const env = mount({canEdit: false});

    try {
        assert.equal(env.editor.state.tool, 'hand');
        assert.equal(env.toolbar.querySelector('[data-tool="hand"]').classList.contains('is-active'), true);
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

        click(env.toolbar.querySelector('[data-command="fullscreen"]'));

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

        click(env.toolbar.querySelector('[data-command="fullscreen"]'));

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

        click(env.toolbar.querySelector('[data-command="fullscreen"]'));

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

        assert.doesNotThrow(() => click(env.toolbar.querySelector('[data-command="fullscreen"]')));
    } finally {
        env.dom.cleanup();
    }
});

test('ปุ่มเต็มจอสะท้อนสถานะปัจจุบัน และไอคอนบอกว่ากดแล้วจะเกิดอะไร', () => {
    const env = mount();

    try {
        const root = env.dom.document.querySelector('[data-workspace-board]');
        const button = env.toolbar.querySelector('[data-command="fullscreen"]');

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

        click(env.toolbar.querySelector('[data-command="fit"]'));

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
        click(env.toolbar.querySelector('[data-color="#dc2626"]'));

        assert.equal(env.editor.currentDocument().elements[0].stroke, '#dc2626');
    } finally {
        env.dom.cleanup();
    }
});

test('เลือกความหนาขณะที่เลือกรูปทรงอยู่ เปลี่ยนความหนาของรูปนั้นทันที', () => {
    const env = mount({elements: [rect('a', {x: 0, y: 0, w: 100, h: 100})]});

    try {
        drag(env.stage, {x: 50, y: 50}, {x: 50, y: 50});
        click(env.toolbar.querySelector('[data-stroke-width="8"]'));

        assert.equal(env.editor.currentDocument().elements[0].strokeWidth, 8);
    } finally {
        env.dom.cleanup();
    }
});

test('ความหนาที่เลือกยังมีผลกับเส้นที่วาดใหม่ด้วย', () => {
    const env = mount();

    try {
        click(env.toolbar.querySelector('[data-stroke-width="8"]'));
        click(env.toolbar.querySelector('[data-tool="pen"]'));
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
        click(env.toolbar.querySelector('[data-color="#dc2626"]'));
        click(env.toolbar.querySelector('[data-command="undo"]'));

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

        const picker = env.toolbar.querySelector('[data-custom-color]');
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
        click(env.toolbar.querySelector('[data-color="#dc2626"]'));

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
