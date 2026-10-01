import test from 'node:test';
import assert from 'node:assert/strict';
import {mountDom, click, pressKey} from './helpers/dom.js';
import {drag, pointerDown, pointerUp, stubStageRect} from './helpers/pointer.js';
import {boardMarkup} from './helpers/workspace-board.js';
import {initBoardEditor} from '../../resources/js/pages/workspace/index.js';

/*
 * แถบเครื่องมือแถวหลัก แผงรูปทรง และแถบรูปแบบแถวที่สอง
 *
 * เดินเส้นทางจริงตั้งแต่กดปุ่มไปจนถึงผลบนกระดาน เพราะจุดที่พังได้คือการต่อสาย
 * เช่นเลือกเครื่องมือไม่ติด ปุ่มไม่บอกว่าถืออะไรอยู่ หรือแถวรูปแบบไม่โผล่ตามสิ่งที่เลือก
 */

let seed = 0;

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

    const find = (selector) => dom.document.querySelector(selector);

    return {
        dom,
        stage,
        editor,
        find,
        tool: (name) => find(`[data-tool="${name}"]`),
        contextToolbar: find('[data-workspace-context-toolbar]'),
        shapesToggle: () => find('[data-menu-tools="rect ellipse line arrow"]'),
        shapesPanel: () => find('#wsbMenuShapes'),
        shapesIcon: () => find('[data-menu-icon]'),
        visibleGroups: () => Array.from(find('[data-workspace-context-toolbar]')
            .querySelectorAll('[data-context-group]'))
            .filter((group) => ! group.hidden)
            .map((group) => group.dataset.contextGroup),
    };
};

/* ── เครื่องมือบนแถบตรง ๆ ─────────────────────────────────────── */

/*
 * เครื่องมือที่ใช้บ่อยต้องกดถึงในครั้งเดียว ไม่ต้องเปิดเมนูก่อน
 * เคยยุบไว้ในเมนู "วาด" กับ "สร้าง" แล้วผู้ใช้หาไม่เจอและไม่รู้ว่าถืออะไรอยู่
 */
test('เครื่องมือที่ใช้บ่อยทุกตัวกดได้จากแถบโดยไม่ต้องเปิดเมนู', () => {
    const env = mount();

    try {
        ['select', 'hand', 'pen', 'eraser', 'sticky', 'text'].forEach((name) => {
            const button = env.tool(name);

            assert.ok(button, `ต้องมีปุ่ม ${name} บนแถบ`);
            assert.equal(button.closest('[data-menu-panel]'), null, `${name} ต้องไม่ถูกซ่อนในเมนู`);

            click(button);
            assert.equal(env.editor.state.tool, name);
            assert.equal(button.classList.contains('is-active'), true, `${name} ต้องสว่างเมื่อถูกเลือก`);
        });
    } finally {
        env.dom.cleanup();
    }
});

test('เลือกยางลบแล้วปุ่มยางลบสว่าง และปุ่มดินสอดับ', () => {
    const env = mount();

    try {
        click(env.tool('eraser'));

        assert.equal(env.tool('eraser').classList.contains('is-active'), true);
        assert.equal(env.tool('eraser').getAttribute('aria-pressed'), 'true');
        assert.equal(env.tool('pen').classList.contains('is-active'), false);
    } finally {
        env.dom.cleanup();
    }
});

test('แนบรูปภาพเป็นคำสั่งบนแถบ ไม่ใช่เครื่องมือที่ค้างสถานะ', () => {
    const env = mount();

    try {
        click(env.tool('pen'));
        click(env.find('[data-command="attach-image"]'));

        assert.equal(env.editor.state.tool, 'pen', 'เครื่องมือต้องไม่เปลี่ยน');
    } finally {
        env.dom.cleanup();
    }
});

/* ── แผงรูปทรง ────────────────────────────────────────────────── */

test('กดปุ่มรูปทรงแล้วแผงเปิด กดซ้ำแล้วปิด', () => {
    const env = mount();

    try {
        assert.equal(env.shapesPanel().hidden, true);

        click(env.shapesToggle());
        assert.equal(env.shapesPanel().hidden, false);
        assert.equal(env.shapesToggle().getAttribute('aria-expanded'), 'true');

        click(env.shapesToggle());
        assert.equal(env.shapesPanel().hidden, true);
    } finally {
        env.dom.cleanup();
    }
});

test('เลือกรูปทรงจากแผงได้ในคลิกเดียว แล้วแผงปิดเอง', () => {
    const env = mount();

    try {
        click(env.shapesToggle());
        click(env.shapesPanel().querySelector('[data-tool="ellipse"]'));

        assert.equal(env.editor.state.tool, 'ellipse');
        assert.equal(env.shapesPanel().hidden, true);
    } finally {
        env.dom.cleanup();
    }
});

/*
 * นี่คือเรื่องที่ผู้ใช้รายงานตรง ๆ: ปุ่มเมนูแสดงไอคอนของกลุ่มตายตัว เลือกยางลบ
 * ไปแล้วยังเห็นไอคอนเดิมค้างอยู่ จึงไม่รู้ว่ากำลังถืออะไร ปุ่มรูปทรงที่เหลืออยู่
 * ตัวเดียวจึงต้องสลับไอคอนเป็นรูปทรงที่เลือก
 */
test('ปุ่มรูปทรงสลับไอคอนเป็นรูปที่เลือก และกลับเป็นไอคอนกลางเมื่อไปถือเครื่องมืออื่น', () => {
    const env = mount();

    try {
        assert.match(env.shapesIcon().getAttribute('class'), /bi-intersect/);

        click(env.shapesToggle());
        click(env.shapesPanel().querySelector('[data-tool="arrow"]'));

        assert.match(env.shapesIcon().getAttribute('class'), /bi-arrow-up-right/);
        assert.equal(env.shapesToggle().classList.contains('is-active'), true);

        click(env.tool('pen'));

        assert.match(env.shapesIcon().getAttribute('class'), /bi-intersect/);
        assert.equal(env.shapesToggle().classList.contains('is-active'), false);
    } finally {
        env.dom.cleanup();
    }
});

/* ── คีย์บอร์ดและการปิดแผง ────────────────────────────────────── */

test('ลูกศรเดินตัวเลือกในแผงรูปทรงได้ทั้งแนวนอนและแนวตั้ง และวนรอบ', () => {
    const env = mount();

    try {
        click(env.shapesToggle());

        const items = Array.from(env.shapesPanel().querySelectorAll('button'));

        assert.equal(env.dom.document.activeElement, items[0], 'เปิดแผงแล้วต้องโฟกัสตัวแรก');

        pressKey(env.dom.document, 'ArrowRight');
        assert.equal(env.dom.document.activeElement, items[1]);

        pressKey(env.dom.document, 'ArrowDown');
        assert.equal(env.dom.document.activeElement, items[2]);

        pressKey(env.dom.document, 'End');
        assert.equal(env.dom.document.activeElement, items[items.length - 1]);

        pressKey(env.dom.document, 'ArrowRight');
        assert.equal(env.dom.document.activeElement, items[0], 'ถึงท้ายแล้ววนกลับตัวแรก');

        pressKey(env.dom.document, 'ArrowLeft');
        assert.equal(env.dom.document.activeElement, items[items.length - 1]);
    } finally {
        env.dom.cleanup();
    }
});

test('Escape ปิดแผงรูปทรงและคืนโฟกัสให้ปุ่ม', () => {
    const env = mount();

    try {
        click(env.shapesToggle());
        pressKey(env.dom.document, 'Escape');

        assert.equal(env.shapesPanel().hidden, true);
        assert.equal(env.dom.document.activeElement, env.shapesToggle());
    } finally {
        env.dom.cleanup();
    }
});

test('กดข้างนอกปิดแผงรูปทรง', () => {
    const env = mount();

    try {
        click(env.shapesToggle());
        pointerDown(env.stage, {x: 400, y: 300});

        assert.equal(env.shapesPanel().hidden, true);
    } finally {
        env.dom.cleanup();
    }
});

/*
 * เมนูจัดการกระดานบนหัวเรื่องใช้ menu.js ตัวเดียวกัน ปุ่มข้างในยังเป็นปุ่มเดิม
 * ที่ board-settings.js กับ autosave.js ค้นหา การย้ายเข้าเมนูจึงต้องไม่ทำให้หลุด
 */
test('เมนูจัดการกระดานบนหัวเรื่องเปิดได้ และปุ่มข้างในยังอยู่ในเอกสาร', () => {
    const env = mount();

    try {
        click(env.find('[aria-controls="wsbBoardMenu"]'));

        assert.equal(env.find('#wsbBoardMenu').hidden, false);
        assert.ok(env.find('[data-workspace-refresh]'));
        assert.ok(env.find('[data-workspace-board-settings]'));
        assert.ok(env.find('[data-workspace-board-delete]'));
    } finally {
        env.dom.cleanup();
    }
});

/* ── แถบรูปแบบแถวที่สอง ───────────────────────────────────────── */

test('ไม่ได้เลือกอะไรและถือเครื่องมือเลือก แถบรูปแบบต้องไม่โผล่', () => {
    const env = mount({elements: [rect('a', {x: 0, y: 0, w: 100, h: 100})]});

    try {
        assert.equal(env.contextToolbar.hidden, true);
        assert.deepEqual(env.visibleGroups(), []);
    } finally {
        env.dom.cleanup();
    }
});

test('เลือกรูปทรงแล้วโผล่เฉพาะสีและความหนาเส้น', () => {
    const env = mount({elements: [rect('a', {x: 0, y: 0, w: 100, h: 100})]});

    try {
        drag(env.stage, {x: 50, y: 50}, {x: 50, y: 50});

        assert.equal(env.contextToolbar.hidden, false);
        assert.deepEqual(env.visibleGroups(), ['stroke', 'width']);
    } finally {
        env.dom.cleanup();
    }
});

test('ถือเครื่องมือกระดาษโน้ตแล้วโผล่กลุ่มของข้อความ ไม่มีความหนาเส้น', () => {
    const env = mount();

    try {
        click(env.tool('sticky'));

        assert.deepEqual(env.visibleGroups(), ['stroke', 'sticky', 'font', 'textstyle', 'align']);
    } finally {
        env.dom.cleanup();
    }
});

test('สร้างกระดาษโน้ตแล้วปรับขนาดตัวอักษรจากแถวที่สองได้จริง', () => {
    const env = mount();

    try {
        click(env.tool('sticky'));
        pointerDown(env.stage, {x: 200, y: 150});
        pointerUp(env.stage, {x: 200, y: 150});

        const field = env.find('[data-font-size-input]');
        field.value = '32';
        field.dispatchEvent(new env.dom.window.Event('input', {bubbles: true}));

        assert.equal(env.editor.currentDocument().elements[0].fontSize, 32);
    } finally {
        env.dom.cleanup();
    }
});

test('เลือกรูปภาพไม่มีรูปแบบให้ปรับ แถบรูปแบบหายไปทั้งแถว', () => {
    const env = mount({
        elements: [{id: 'i1', type: 'image', z: 1, x: 0, y: 0, w: 100, h: 100, attachmentId: 3, src: '/x.png'}],
    });

    try {
        drag(env.stage, {x: 50, y: 50}, {x: 50, y: 50});

        assert.deepEqual(env.editor.state.selection, ['i1']);
        assert.equal(env.contextToolbar.hidden, true);
    } finally {
        env.dom.cleanup();
    }
});

test('ผู้ที่ดูอย่างเดียวไม่เห็นแถบรูปแบบ และกดเครื่องมือวาดไม่ได้ แต่ยังเลือกกับเลื่อนกระดานได้', () => {
    const env = mount({canEdit: false, elements: [rect('a', {x: 0, y: 0, w: 100, h: 100})]});

    try {
        drag(env.stage, {x: 50, y: 50}, {x: 50, y: 50});

        assert.equal(env.contextToolbar.hidden, true);
        assert.equal(env.tool('pen').disabled, true);
        assert.equal(env.tool('eraser').disabled, true);
        assert.equal(env.shapesToggle().disabled, true);
        assert.equal(env.tool('select').disabled, false);
        assert.equal(env.tool('hand').disabled, false);

        click(env.shapesToggle());
        assert.equal(env.shapesPanel().hidden, true);
    } finally {
        env.dom.cleanup();
    }
});

/* ── การพับแถบ ────────────────────────────────────────────────── */

/*
 * การพับเป็นสถานะระดับเชลล์ ไม่ใช่ attribute hidden ของแต่ละแถว เพราะแถวรูปแบบ
 * มี hidden เป็นของ sync() อยู่แล้ว ถ้าสองฝ่ายเขียนตัวเดียวกัน การกางกลับจะทำให้
 * แถวรูปแบบโผล่มาทั้งที่ไม่มีอะไรให้ปรับ
 */
test('พับแถบเครื่องมือเป็นสถานะของเชลล์ ไม่ไปแย่งสถานะซ่อนของแถวรูปแบบ', () => {
    const env = mount({elements: [rect('a', {x: 0, y: 0, w: 100, h: 100})]});

    try {
        const shell = env.find('[data-workspace-toolbar-shell]');
        const handle = env.find('[data-workspace-toolbar-toggle]');

        click(handle);

        assert.equal(shell.dataset.collapsed, 'on');
        assert.equal(handle.getAttribute('aria-expanded'), 'false');
        assert.equal(
            env.find('[data-workspace-toolbar]').hidden,
            false,
            'ตัวซ่อนจริงคือ CSS ไม่ใช่ attribute hidden'
        );

        click(handle);

        assert.equal(shell.dataset.collapsed, undefined);
        assert.equal(handle.getAttribute('aria-expanded'), 'true');
        assert.equal(
            env.contextToolbar.hidden,
            true,
            'กางกลับแล้วแถวรูปแบบต้องยังซ่อนอยู่ เพราะยังไม่ได้เลือกอะไร'
        );
    } finally {
        env.dom.cleanup();
    }
});
