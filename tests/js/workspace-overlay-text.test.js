import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {mountDom, click} from './helpers/dom.js';
import {drag, pointerDown, pointerUp, stubStageRect} from './helpers/pointer.js';
import {workspaceCss} from './helpers/workspace-css.js';
import {boardMarkup, overlayNodes, renderedNodes} from './helpers/workspace-board.js';
import {
    applyOverlayCamera,
    initOverlayEditing,
    isOverlayType,
    renderOverlay,
} from '../../resources/js/pages/workspace/overlay-text.js';
import {initBoardEditor} from '../../resources/js/pages/workspace/index.js';

/*
 * ชั้นข้อความ - กระดาษโน้ตและกล่องข้อความ
 *
 * เป็นเหตุผลหลักที่เลือก HTML overlay แทน <canvas> หรือ <text> ของ SVG
 * ภาษาไทยไม่มีเว้นวรรคระหว่างคำ ถ้าใช้สองอย่างนั้นต้องเขียนตัวตัดบรรทัดเอง
 *
 * ประเด็นที่ต้องล็อกไว้คือกล่องต้องแก้ได้ทีละกล่องและเฉพาะตอนที่เปิดแก้ไข
 * ไม่งั้นกล่องจะดูดการคลิกไปหมดจนลากย้ายและเลือกไม่ได้
 */

const DESIGN = {
    defaultTool: 'select',
    defaultStroke: '#1f2937',
    defaultStrokeWidth: 4,
    defaultStickyColor: '#fde68a',
    defaultFontSize: 20,
    minFontSize: 8,
    maxFontSize: 96,
    minLetterSpacing: -2,
    maxLetterSpacing: 8,
    defaultLetterSpacing: 0,
    maxTextLength: 20,
    minScale: 0.1,
    maxScale: 4,
};

const note = (id, overrides = {}) => ({
    id, type: 'sticky', z: 1, x: 100, y: 100, w: 180, h: 180,
    text: '', fill: '#fde68a', fontSize: 16, ...overrides,
});

/* ── ตัวเรนเดอร์ของชั้น overlay ─────────────────────────────── */

const mountLayer = () => {
    const dom = mountDom('<!doctype html><html><body><div class="wsb-overlay"></div></body></html>');

    return {dom, layer: dom.document.querySelector('.wsb-overlay')};
};

test('กระดาษโน้ตและกล่องข้อความถูกระบุว่าอยู่ในชั้น HTML', () => {
    assert.equal(isOverlayType('sticky'), true);
    assert.equal(isOverlayType('text'), true);
    assert.equal(isOverlayType('rect'), false);
    assert.equal(isOverlayType('pen'), false);
});

test('โน้ตถูกวางตามพิกัดโลก และรับสีกับขนาดตัวอักษรของตัวเอง', () => {
    const {dom, layer} = mountLayer();

    try {
        renderOverlay(layer, [note('n1', {text: 'ทดสอบ', fill: '#bfdbfe', fontSize: 22})], {doc: dom.document});

        const node = layer.firstElementChild;

        assert.equal(node.dataset.elId, 'n1');
        assert.equal(node.className, 'wsb-note');
        assert.equal(node.style.left, '100px');
        assert.equal(node.style.width, '180px');
        assert.equal(node.style.fontSize, '22px');
        assert.equal(node.textContent, 'ทดสอบ');
    } finally {
        dom.cleanup();
    }
});

/*
 * ข้อความบนกระดานเป็นข้อความอิสระที่เพื่อนร่วมแผนกคนไหนก็พิมพ์เข้ามาได้
 * ต้องถูกเขียนเป็นข้อความล้วน ไม่ใช่ถูกตีความเป็น HTML
 */
test('ข้อความที่มีแท็กถูกแสดงเป็นตัวอักษร ไม่ถูกตีความเป็น HTML', () => {
    const {dom, layer} = mountLayer();

    try {
        renderOverlay(layer, [note('n1', {text: '<img src=x onerror=alert(1)>'})], {doc: dom.document});

        const node = layer.firstElementChild;

        assert.equal(node.textContent, '<img src=x onerror=alert(1)>');
        assert.equal(node.querySelector('img'), null, 'ต้องไม่มีโหนดลูก <img> เกิดขึ้นเลย');
        // แต่ละบรรทัดถูกห่อด้วย <div> ของตัวเองเสมอ (ดู renderLines) หนึ่งบรรทัด
        // จึงมีลูกได้หนึ่งใบพอดี ไม่ใช่ศูนย์ ประเด็นด้านความปลอดภัยที่เทสต์นี้
        // ตรวจคือต้องไม่มี <img> โผล่ขึ้นมา ไม่ใช่ว่าต้องไม่มีลูกเลย
        assert.equal(node.children.length, 1);
        assert.equal(node.firstElementChild.tagName, 'DIV');
        assert.equal(node.firstElementChild.textContent, '<img src=x onerror=alert(1)>');
    } finally {
        dom.cleanup();
    }
});

test('ตัวหนา ตัวเอียง และระยะห่างตัวอักษรถูกแปลงเป็นสไตล์ของกล่อง', () => {
    const {dom, layer} = mountLayer();

    try {
        renderOverlay(layer, [note('n1', {bold: true, italic: true, letterSpacing: 4})], {doc: dom.document});

        const node = layer.firstElementChild;

        assert.equal(node.style.fontWeight, '700');
        assert.equal(node.style.fontStyle, 'italic');
        assert.equal(node.style.letterSpacing, '4px');
    } finally {
        dom.cleanup();
    }
});

/*
 * โหนดถูกใช้ซ้ำตามรหัสชิ้นงานเมื่อเรนเดอร์รอบใหม่ (ดูเทสต์ "การเรนเดอร์ซ้ำใช้
 * โหนดเดิม" ด้านล่าง) ถ้าปิดตัวหนาแล้วไม่รีเซ็ต fontWeight โหนดเก่าจะค้างค่า
 * หนาไว้ต่อ ถึงแม้ element ที่ผูกกับมันจะไม่หนาแล้ว
 */
test('ปิดตัวหนา/ตัวเอียงแล้ว โหนดที่ถูกใช้ซ้ำต้องกลับเป็นค่าปกติ ไม่ค้างสไตล์เดิม', () => {
    const {dom, layer} = mountLayer();

    try {
        renderOverlay(layer, [note('n1', {bold: true, italic: true, letterSpacing: 4})], {doc: dom.document});
        renderOverlay(layer, [note('n1')], {doc: dom.document});

        const node = layer.firstElementChild;

        assert.equal(node.style.fontWeight, '400');
        assert.equal(node.style.fontStyle, 'normal');
        assert.equal(node.style.letterSpacing, 'normal');
    } finally {
        dom.cleanup();
    }
});

test('การเรนเดอร์ซ้ำใช้โหนดเดิม และลบโน้ตที่หายจากฉาก', () => {
    const {dom, layer} = mountLayer();

    try {
        renderOverlay(layer, [note('a'), note('b', {x: 400})], {doc: dom.document});
        const first = layer.firstElementChild;

        renderOverlay(layer, [note('a', {text: 'ใหม่'})], {doc: dom.document});

        assert.equal(layer.children.length, 1);
        assert.equal(layer.firstElementChild, first, 'ต้องเป็นโหนดตัวเดิม');
        assert.equal(first.textContent, 'ใหม่');
    } finally {
        dom.cleanup();
    }
});

/*
 * ถ้าเปิดให้แก้ได้ตลอดเวลา กล่องจะดูดการคลิกไปหมด แล้วผู้ใช้จะลากย้ายหรือ
 * เลือกกระดาษโน้ตไม่ได้เลย เพราะเหตุการณ์ไม่เคยไปถึงผืนผ้าใบข้างใต้
 */
test('โน้ตแก้ไขได้เฉพาะใบที่ถูกเปิดแก้ไขอยู่', () => {
    const {dom, layer} = mountLayer();

    try {
        renderOverlay(layer, [note('a'), note('b', {x: 400})], {doc: dom.document, editingId: 'b'});

        const [first, second] = Array.from(layer.children);

        assert.equal(first.hasAttribute('contenteditable'), false);
        assert.equal(first.classList.contains('is-editing'), false);
        assert.equal(second.getAttribute('contenteditable') !== null, true);
        assert.equal(second.classList.contains('is-editing'), true);
    } finally {
        dom.cleanup();
    }
});

test('ผู้ที่ดูอย่างเดียวไม่มีกล่องไหนแก้ไขได้เลย', () => {
    const {dom, layer} = mountLayer();

    try {
        renderOverlay(layer, [note('a')], {doc: dom.document, editable: false, editingId: 'a'});

        assert.equal(layer.firstElementChild.hasAttribute('contenteditable'), false);
    } finally {
        dom.cleanup();
    }
});

test('ชั้น overlay ใช้ค่ากล้องเดียวกับชั้น SVG', () => {
    const {dom, layer} = mountLayer();

    try {
        applyOverlayCamera(layer, {x: 40, y: -10, scale: 1.5});

        assert.equal(layer.style.transform, 'translate(40px, -10px) scale(1.5)');
    } finally {
        dom.cleanup();
    }
});

test('ข้อความที่ผู้ใช้กำลังพิมพ์อยู่ต้องไม่ถูกเขียนทับ', () => {
    const {dom, layer} = mountLayer();

    try {
        renderOverlay(layer, [note('a', {text: 'เดิม'})], {doc: dom.document, editingId: 'a'});

        const node = layer.firstElementChild;
        node.focus();
        node.textContent = 'กำลังพิมพ์อยู่';

        // เรนเดอร์รอบใหม่ระหว่างที่ยังโฟกัสอยู่ (เช่นมีอะไรอื่นบนกระดานเปลี่ยน)
        renderOverlay(layer, [note('a', {text: 'เดิม'})], {doc: dom.document, editingId: 'a'});

        assert.equal(node.textContent, 'กำลังพิมพ์อยู่', 'เคอร์เซอร์ต้องไม่ถูกดีดกลับต้นข้อความ');
    } finally {
        dom.cleanup();
    }
});

test('การพิมพ์เกินเพดานถูกตัดที่ฝั่งหน้าจอ ก่อนจะไปเจอ 422 จากเซิร์ฟเวอร์', () => {
    const {dom, layer} = mountLayer();

    try {
        initOverlayEditing(layer, {maxLength: 10, onCommit: () => {}});
        renderOverlay(layer, [note('a')], {doc: dom.document, editingId: 'a'});

        const node = layer.firstElementChild;
        node.textContent = 'ก'.repeat(50);
        node.dispatchEvent(new dom.window.Event('input', {bubbles: true}));

        assert.equal(node.textContent.length, 10);
    } finally {
        dom.cleanup();
    }
});

test('ข้อความถูกส่งกลับตอนกล่องเสียโฟกัส', () => {
    const {dom, layer} = mountLayer();
    const commits = [];

    try {
        initOverlayEditing(layer, {onCommit: (id, text) => commits.push({id, text})});
        renderOverlay(layer, [note('a')], {doc: dom.document, editingId: 'a'});

        const node = layer.firstElementChild;
        node.textContent = 'ไอเดียใหม่';
        node.dispatchEvent(new dom.window.FocusEvent('blur'));

        assert.deepEqual(commits, [{id: 'a', text: 'ไอเดียใหม่'}]);
    } finally {
        dom.cleanup();
    }
});

/* ── เส้นทางจริงบนหน้าวาด ────────────────────────────────────── */

let seed = 0;

const mountEditor = (elements = []) => {
    const dom = mountDom(boardMarkup({elements, design: DESIGN}));
    const stage = dom.document.querySelector('[data-workspace-stage]');
    stubStageRect(stage, {width: 800, height: 600});

    const editor = initBoardEditor({
        root: dom.document.querySelector('[data-workspace-board]'),
        doc: dom.document,
        idFactory: () => `note-${++seed}`,
    });

    return {
        dom,
        stage,
        editor,
        toolbar: dom.document.querySelector('[data-workspace-toolbar]'),
        // ตัวควบคุมกระจายหลายที่แล้ว (เครื่องมืออยู่ในเมนู รูปแบบอยู่แถวที่สอง)
        control: (selector) => dom.document.querySelector(selector),
        /*
         * กล่องข้อความอยู่ในชั้น HTML ส่วนรูปทรงอยู่ในชั้น SVG และชั้นทั้งสอง
         * ชนิดสลับกันได้หลายชั้นตามลำดับของเอกสาร (ดู layers.js) เทสต์จึงถาม
         * จากทั้งกระดาน ไม่ใช่จากชั้นใดชั้นหนึ่ง
         */
        notes: () => overlayNodes(dom.document),
        note: () => overlayNodes(dom.document)[0],
        shapes: () => renderedNodes(dom.document, '.wsb-canvas [data-el-id]'),
    };
};

test('แตะครั้งเดียวด้วยเครื่องมือโน้ตสร้างโน้ตขนาดตั้งต้น', () => {
    const env = mountEditor();

    try {
        click(env.control('[data-tool="sticky"]'));
        pointerDown(env.stage, {x: 200, y: 150});
        pointerUp(env.stage, {x: 200, y: 150});

        const created = env.editor.currentDocument().elements[0];

        assert.equal(created.type, 'sticky');
        assert.deepEqual({w: created.w, h: created.h}, {w: 180, h: 180});
        assert.equal(created.fill, '#fde68a');
        assert.equal(env.notes().length, 1, 'ต้องถูกวาดในชั้น HTML');
        assert.equal(env.shapes().length, 0, 'และต้องไม่โผล่ในชั้น SVG');
    } finally {
        env.dom.cleanup();
    }
});

test('ลากกรอบด้วยเครื่องมือโน้ตได้ขนาดตามที่ลาก', () => {
    const env = mountEditor();

    try {
        click(env.control('[data-tool="sticky"]'));
        drag(env.stage, {x: 100, y: 100}, {x: 400, y: 300}, {steps: 3});

        const created = env.editor.currentDocument().elements[0];

        assert.deepEqual({w: created.w, h: created.h}, {w: 300, h: 200});
    } finally {
        env.dom.cleanup();
    }
});

/*
 * จังหวะนี้สำคัญมากตอนระดมสมอง ผู้ใช้ต้องแปะแล้วพิมพ์ต่อได้เลย ไม่ต้องคลิกซ้ำ
 */
test('โน้ตที่เพิ่งสร้างเปิดให้พิมพ์ทันที', () => {
    const env = mountEditor();

    try {
        click(env.control('[data-tool="sticky"]'));
        pointerDown(env.stage, {x: 200, y: 150});
        pointerUp(env.stage, {x: 200, y: 150});

        const node = env.note();

        assert.equal(node.classList.contains('is-editing'), true);
        assert.equal(env.dom.document.activeElement, node);
    } finally {
        env.dom.cleanup();
    }
});

test('ข้อความที่พิมพ์ถูกบันทึกลงเอกสารเมื่อเสียโฟกัส', () => {
    const env = mountEditor([note('n1')]);

    try {
        env.stage.dispatchEvent(new env.dom.window.MouseEvent('dblclick', {
            bubbles: true, cancelable: true, clientX: 150, clientY: 150,
        }));

        const node = env.note();
        node.textContent = 'ปรับขั้นตอนแจ้งซ่อม';
        node.dispatchEvent(new env.dom.window.FocusEvent('blur'));

        assert.equal(env.editor.currentDocument().elements[0].text, 'ปรับขั้นตอนแจ้งซ่อม');
    } finally {
        env.dom.cleanup();
    }
});

/*
 * ถ้าโน้ตดูดการคลิกไว้เอง การลากย้ายจะเป็นไปไม่ได้ เทสต์นี้ยืนยันว่าการคลิกทะลุ
 * ลงไปถึงระบบตรวจการชนจากตัวแบบข้อมูลตามที่ออกแบบไว้
 */
test('ลากย้ายกระดาษโน้ตได้ เพราะการคลิกทะลุลงไปถึงผืนผ้าใบ', () => {
    const env = mountEditor([note('n1', {x: 100, y: 100})]);

    try {
        drag(env.stage, {x: 150, y: 150}, {x: 250, y: 200}, {steps: 3});

        const moved = env.editor.currentDocument().elements[0];

        assert.deepEqual({x: moved.x, y: moved.y}, {x: 200, y: 150});
    } finally {
        env.dom.cleanup();
    }
});

test('ดับเบิลคลิกที่โน้ตเปิดการแก้ไขของใบนั้น', () => {
    const env = mountEditor([note('n1', {x: 100, y: 100})]);

    try {
        assert.equal(env.note().classList.contains('is-editing'), false);

        env.stage.dispatchEvent(new env.dom.window.MouseEvent('dblclick', {
            bubbles: true, cancelable: true, clientX: 150, clientY: 150,
        }));

        assert.equal(env.note().classList.contains('is-editing'), true);
        assert.deepEqual(env.editor.state.selection, ['n1']);
    } finally {
        env.dom.cleanup();
    }
});

test('การเลือกสีกระดาษโน้ตเปลี่ยนสีของใบที่เลือกอยู่ทันที', () => {
    const env = mountEditor([note('n1', {x: 100, y: 100})]);

    try {
        drag(env.stage, {x: 150, y: 150}, {x: 150, y: 150});
        click(env.control('[data-sticky-color="#bfdbfe"]'));

        assert.equal(env.editor.currentDocument().elements[0].fill, '#bfdbfe');
        assert.equal(env.note().style.background, 'rgb(191, 219, 254)');
    } finally {
        env.dom.cleanup();
    }
});

test('การเลือกสีโดยไม่ได้เลือกโน้ตไว้ มีผลกับใบถัดไปที่สร้าง', () => {
    const env = mountEditor();

    try {
        click(env.control('[data-sticky-color="#bfdbfe"]'));
        click(env.control('[data-tool="sticky"]'));
        pointerDown(env.stage, {x: 200, y: 150});
        pointerUp(env.stage, {x: 200, y: 150});

        assert.equal(env.editor.currentDocument().elements[0].fill, '#bfdbfe');
    } finally {
        env.dom.cleanup();
    }
});

test('การแก้ข้อความนับเป็นก้าวย้อนกลับหนึ่งก้าว', () => {
    const env = mountEditor([note('n1', {x: 100, y: 100, text: 'เดิม'})]);

    try {
        env.stage.dispatchEvent(new env.dom.window.MouseEvent('dblclick', {
            bubbles: true, cancelable: true, clientX: 150, clientY: 150,
        }));

        const node = env.note();
        node.textContent = 'ใหม่';
        node.dispatchEvent(new env.dom.window.FocusEvent('blur'));

        click(env.control('[data-command="undo"]'));

        assert.equal(env.editor.currentDocument().elements[0].text, 'เดิม');
    } finally {
        env.dom.cleanup();
    }
});

test('การคลิกเข้าออกกล่องโดยไม่แก้อะไรไม่กินก้าวย้อนกลับ', () => {
    const env = mountEditor([note('n1', {x: 100, y: 100, text: 'เดิม'})]);

    try {
        env.stage.dispatchEvent(new env.dom.window.MouseEvent('dblclick', {
            bubbles: true, cancelable: true, clientX: 150, clientY: 150,
        }));

        env.note().dispatchEvent(new env.dom.window.FocusEvent('blur'));

        assert.equal(env.control('[data-command="undo"]').disabled, true);
    } finally {
        env.dom.cleanup();
    }
});

/*
 * ตรวจจากไฟล์ต้นฉบับ เพราะการเรียกอาจอยู่ในเส้นทางที่เทสต์ด้านบนไม่ได้เดินผ่าน
 */
test('ชั้นข้อความไม่ใช้ innerHTML', () => {
    const source = fs.readFileSync('resources/js/pages/workspace/overlay-text.js', 'utf8');
    const code = source
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/(^|[^:])\/\/.*$/gm, '$1');

    assert.doesNotMatch(code, /\.innerHTML\s*=/);
    assert.doesNotMatch(code, /insertAdjacentHTML/);
    assert.match(code, /textContent/);
});

/*
 * ภาษาไทยไม่มีเว้นวรรคระหว่างคำ ถ้าไม่บังคับให้ตัดกลางคำได้ ข้อความยาว ๆ จะ
 * ล้นกล่องออกไปทางขวาเป็นบรรทัดเดียว ตรวจจาก CSS โดยตรงเพราะ jsdom ไม่คำนวณ layout
 */
test('CSS ของกล่องข้อความรองรับการตัดบรรทัดของภาษาไทย', () => {
    const css = workspaceCss();
    const block = css.slice(css.indexOf('.wsb-note,'), css.indexOf('.wsb-note {'));

    assert.match(block, /overflow-wrap:\s*anywhere/);
    assert.match(block, /word-break:\s*break-word/);
    assert.match(block, /white-space:\s*pre-wrap/);
});

/** พิมพ์ขนาดลงในช่องกรอกแล้วยิงเหตุการณ์อย่างที่เบราว์เซอร์ทำ */
const typeFontSize = (env, value) => {
    const field = env.control('[data-font-size-input]');
    field.value = String(value);
    field.dispatchEvent(new env.dom.window.Event('input', {bubbles: true}));

    return field;
};

/*
 * ก่อนหน้านี้ขนาดตัวอักษรถูกฝังไว้ในเครื่องมือ ผู้ใช้จึงเขียนได้แต่ตัวเล็ก
 * และปรับให้ใหญ่ขึ้นไม่ได้เลย ตอนนี้กรอกตัวเลขเองได้ตามที่ต้องการ
 */
test('กรอกขนาดเองได้ แล้วโน้ตใบถัดไปใช้ขนาดนั้น', () => {
    const env = mountEditor();

    try {
        typeFontSize(env, 16);
        click(env.control('[data-tool="sticky"]'));
        pointerDown(env.stage, {x: 200, y: 150});
        pointerUp(env.stage, {x: 200, y: 150});

        assert.equal(env.editor.currentDocument().elements[0].fontSize, 16);
        assert.equal(env.note().style.fontSize, '16px');
    } finally {
        env.dom.cleanup();
    }
});

test('กล่องข้อความก็ใช้ขนาดที่กรอกเช่นเดียวกับโน้ต', () => {
    const env = mountEditor();

    try {
        typeFontSize(env, 48);
        click(env.control('[data-tool="text"]'));
        pointerDown(env.stage, {x: 200, y: 150});
        pointerUp(env.stage, {x: 200, y: 150});

        assert.equal(env.editor.currentDocument().elements[0].fontSize, 48);
    } finally {
        env.dom.cleanup();
    }
});

test('การกรอกขนาดเปลี่ยนขนาดของกล่องที่เลือกอยู่ทันที', () => {
    const env = mountEditor([note('n1', {x: 100, y: 100, fontSize: 14})]);

    try {
        drag(env.stage, {x: 150, y: 150}, {x: 150, y: 150});
        typeFontSize(env, 36);

        assert.equal(env.editor.currentDocument().elements[0].fontSize, 36);
        assert.equal(env.note().style.fontSize, '36px');
    } finally {
        env.dom.cleanup();
    }
});

/*
 * ระหว่างพิมพ์ยังไม่บีบค่า ไม่งั้นการพิมพ์ "1" เพื่อจะไปเป็น "16" จะถูกดัน
 * เป็น "8" ทันทีแล้วพิมพ์ต่อไม่ได้ การบีบเกิดตอนออกจากช่อง
 */
test('ค่านอกช่วงถูกบีบเมื่อออกจากช่อง ไม่ใช่ระหว่างพิมพ์', () => {
    const env = mountEditor();

    try {
        const field = typeFontSize(env, 500);

        assert.equal(env.editor.state.style.fontSize, 20, 'ค่านอกช่วงระหว่างพิมพ์ต้องยังไม่ถูกใช้');

        field.dispatchEvent(new env.dom.window.Event('change', {bubbles: true}));

        assert.equal(field.value, '96');
        assert.equal(env.editor.state.style.fontSize, 96);
    } finally {
        env.dom.cleanup();
    }
});

test('ช่องว่างหรือค่าที่ไม่ใช่ตัวเลขถอยไปที่ขนาดต่ำสุด', () => {
    const env = mountEditor();

    try {
        const field = env.control('[data-font-size-input]');
        field.value = '';
        field.dispatchEvent(new env.dom.window.Event('change', {bubbles: true}));

        assert.equal(field.value, '8');
        assert.equal(env.editor.state.style.fontSize, 8);
    } finally {
        env.dom.cleanup();
    }
});

test('ปุ่มเพิ่มและลดขนาดขยับทีละขั้นจากค่าปัจจุบัน', () => {
    const env = mountEditor();

    try {
        typeFontSize(env, 20);

        click(env.control('[data-font-step="2"]'));
        assert.equal(env.editor.state.style.fontSize, 22);

        click(env.control('[data-font-step="-2"]'));
        assert.equal(env.editor.state.style.fontSize, 20);
    } finally {
        env.dom.cleanup();
    }
});

test('ปุ่มลดไม่พาขนาดต่ำกว่าค่าต่ำสุด', () => {
    const env = mountEditor();

    try {
        typeFontSize(env, 8);
        click(env.control('[data-font-step="-2"]'));

        assert.equal(env.editor.state.style.fontSize, 8);
    } finally {
        env.dom.cleanup();
    }
});

test('ช่องกรอกสะท้อนขนาดของกล่องที่เลือก', () => {
    const env = mountEditor([note('n1', {x: 100, y: 100, fontSize: 44})]);

    try {
        typeFontSize(env, 44);

        assert.equal(env.control('[data-font-size-input]').value, '44');
    } finally {
        env.dom.cleanup();
    }
});

test('ขนาดที่ใช้ได้อยู่ในช่วงที่ตัวกรองฝั่งเซิร์ฟเวอร์ยอมรับ', () => {
    const env = mountEditor();

    try {
        const field = typeFontSize(env, 200);
        field.dispatchEvent(new env.dom.window.Event('change', {bubbles: true}));

        const size = env.editor.state.style.fontSize;

        // WorkspaceDocumentValidator หนีบไว้ที่ 8-96 ค่าที่หลุดช่วงจะถูกปรับเงียบ ๆ
        assert.equal(size >= 8 && size <= 96, true);
    } finally {
        env.dom.cleanup();
    }
});

/* ── ตัวหนา ตัวเอียง และระยะห่างตัวอักษร ─────────────────────── */

test('กดปุ่มตัวหนาสลับสถานะและปรับกล่องที่เลือกอยู่ทันที', () => {
    const env = mountEditor([note('n1', {x: 100, y: 100})]);

    try {
        drag(env.stage, {x: 150, y: 150}, {x: 150, y: 150});

        const button = env.control('[data-bold-toggle]');
        click(button);

        assert.equal(env.editor.state.style.bold, true);
        assert.equal(env.editor.currentDocument().elements[0].bold, true);
        assert.equal(button.getAttribute('aria-pressed'), 'true');
        assert.equal(button.classList.contains('is-active'), true);

        click(button);

        assert.equal(env.editor.state.style.bold, false);
        assert.equal(env.editor.currentDocument().elements[0].bold, false);
        assert.equal(button.getAttribute('aria-pressed'), 'false');
    } finally {
        env.dom.cleanup();
    }
});

test('กดปุ่มตัวเอียงสลับสถานะและปรับกล่องที่เลือกอยู่ทันที', () => {
    const env = mountEditor([note('n1', {x: 100, y: 100})]);

    try {
        drag(env.stage, {x: 150, y: 150}, {x: 150, y: 150});

        const button = env.control('[data-italic-toggle]');
        click(button);

        assert.equal(env.editor.state.style.italic, true);
        assert.equal(env.editor.currentDocument().elements[0].italic, true);
        assert.equal(button.getAttribute('aria-pressed'), 'true');
    } finally {
        env.dom.cleanup();
    }
});

test('เปิดตัวหนาไว้ก่อนสร้างโน้ตใหม่ ทำให้โน้ตใบใหม่หนาตั้งแต่แรก', () => {
    const env = mountEditor();

    try {
        click(env.control('[data-bold-toggle]'));
        click(env.control('[data-tool="sticky"]'));
        pointerDown(env.stage, {x: 200, y: 150});
        pointerUp(env.stage, {x: 200, y: 150});

        assert.equal(env.editor.currentDocument().elements[0].bold, true);
    } finally {
        env.dom.cleanup();
    }
});

/** พิมพ์ระยะห่างลงในช่องกรอกแล้วยิงเหตุการณ์อย่างที่เบราว์เซอร์ทำ */
const typeLetterSpacing = (env, value) => {
    const field = env.control('[data-letter-spacing-input]');
    field.value = String(value);
    field.dispatchEvent(new env.dom.window.Event('input', {bubbles: true}));

    return field;
};

test('พิมพ์ระยะห่างตัวอักษรอัปเดตกล่องที่เลือกอยู่ทันที', () => {
    const env = mountEditor([note('n1', {x: 100, y: 100})]);

    try {
        drag(env.stage, {x: 150, y: 150}, {x: 150, y: 150});
        typeLetterSpacing(env, 3);

        assert.equal(env.editor.currentDocument().elements[0].letterSpacing, 3);
        assert.equal(env.note().style.letterSpacing, '3px');
    } finally {
        env.dom.cleanup();
    }
});

test('ปุ่มเพิ่มและลดระยะห่างขยับทีละขั้นจากค่าปัจจุบัน', () => {
    const env = mountEditor();

    try {
        typeLetterSpacing(env, 0);

        click(env.control('[data-letter-spacing-step="1"]'));
        assert.equal(env.editor.state.style.letterSpacing, 1);

        click(env.control('[data-letter-spacing-step="-1"]'));
        assert.equal(env.editor.state.style.letterSpacing, 0);
    } finally {
        env.dom.cleanup();
    }
});

test('ค่าระยะห่างนอกช่วงถูกบีบเมื่อออกจากช่อง ไม่ใช่ระหว่างพิมพ์', () => {
    const env = mountEditor();

    try {
        const field = typeLetterSpacing(env, 999);

        assert.equal(env.editor.state.style.letterSpacing, 0, 'ค่านอกช่วงระหว่างพิมพ์ต้องยังไม่ถูกใช้');

        field.dispatchEvent(new env.dom.window.Event('change', {bubbles: true}));

        assert.equal(field.value, '8');
        assert.equal(env.editor.state.style.letterSpacing, 8);
    } finally {
        env.dom.cleanup();
    }
});

test('ระยะห่างที่ใช้ได้อยู่ในช่วงที่ตัวกรองฝั่งเซิร์ฟเวอร์ยอมรับ', () => {
    const env = mountEditor();

    try {
        const field = typeLetterSpacing(env, -999);
        field.dispatchEvent(new env.dom.window.Event('change', {bubbles: true}));

        const spacing = env.editor.state.style.letterSpacing;

        // WorkspaceDocumentValidator หนีบไว้ที่ -2..8 ค่าที่หลุดช่วงจะถูกปรับเงียบ ๆ
        assert.equal(spacing >= -2 && spacing <= 8, true);
    } finally {
        env.dom.cleanup();
    }
});

/* ── การจัดบรรทัด: ชิดซ้าย กึ่งกลาง ชิดขวา ────────────────────── */

/**
 * วางเคอร์เซอร์ไว้ในบรรทัดที่ระบุของกล่องที่กำลังแก้ไขอยู่ แล้วจำลองเหตุการณ์
 * selectionchange ที่เบราว์เซอร์จริงจะยิงตามมา (jsdom ไม่ยิงให้เองหลัง addRange)
 */
const placeCaretInLine = (env, node, lineIndex) => {
    const block = node.children[lineIndex];
    const range = env.dom.document.createRange();
    range.selectNodeContents(block);
    range.collapse(true);

    const selection = env.dom.document.getSelection();
    selection.removeAllRanges();
    selection.addRange(range);

    env.dom.document.dispatchEvent(new env.dom.window.Event('selectionchange'));
};

/**
 * กดปุ่มอย่างที่เบราว์เซอร์จริงทำ คือย้ายโฟกัสไปที่ปุ่มตั้งแต่ mousedown
 *
 * jsdom ไม่ย้ายโฟกัสให้เอง เทสต์ที่กดด้วย click() เฉย ๆ จึงมองไม่เห็นผลข้างเคียง
 * ที่ทำให้เกิดบั๊กจริง: กล่องข้อความ blur แล้วปิดโหมดแก้ไขก่อนที่คำสั่งจะทำงาน
 * จนคำสั่งจัดบรรทัดตกไปที่ทางเลือกสำรอง "จัดทั้งกล่อง"
 */
const clickLikeBrowser = (env, button) => {
    const down = new env.dom.window.MouseEvent('mousedown', {bubbles: true, cancelable: true});

    button.dispatchEvent(down);

    if (! down.defaultPrevented) {
        env.dom.document.activeElement?.blur?.();
        button.focus();
    }

    click(button);
};

/**
 * นี่คือเรื่องที่ผู้ใช้รายงานซ้ำเป็นรอบที่สอง: ตั้งบรรทัดแรกกึ่งกลางไว้แล้ว
 * พอสั่งบรรทัดที่สองให้ชิดขวา บรรทัดแรกก็ย้ายไปชิดขวาตามไปด้วยทั้งกล่อง
 *
 * ต้นเหตุคือปุ่มบนแถบแย่งโฟกัสไปจากกล่องข้อความตั้งแต่ mousedown กล่องจึง blur
 * แล้วปิดโหมดแก้ไขก่อนที่ click จะมาถึง คำสั่งเลยไม่รู้ว่าเคอร์เซอร์อยู่บรรทัดไหน
 * เทสต์นี้กดปุ่มแบบเดียวกับเบราว์เซอร์จริงเพื่อให้ครอบอาการนั้นได้จริง
 */
test('กดปุ่มจัดบรรทัดแบบเดียวกับเบราว์เซอร์จริง ไม่ดึงเคอร์เซอร์ออกจากกล่องและไม่ลามไปบรรทัดอื่น', () => {
    const env = mountEditor([note('n1', {x: 100, y: 100, text: 'บรรทัด1\nบรรทัด2'})]);

    try {
        env.stage.dispatchEvent(new env.dom.window.MouseEvent('dblclick', {
            bubbles: true, cancelable: true, clientX: 150, clientY: 150,
        }));

        const node = env.note();

        placeCaretInLine(env, node, 0);
        clickLikeBrowser(env, env.control('[data-align="center"]'));

        assert.equal(env.dom.document.activeElement, node, 'เคอร์เซอร์ต้องยังอยู่ในกล่อง');
        assert.equal(node.classList.contains('is-editing'), true, 'ต้องยังอยู่ในโหมดแก้ไข');

        placeCaretInLine(env, node, 1);
        clickLikeBrowser(env, env.control('[data-align="right"]'));

        assert.equal(node.children[0].style.textAlign, 'center', 'บรรทัดแรกต้องยังกึ่งกลาง');
        assert.equal(node.children[1].style.textAlign, 'right');

        node.dispatchEvent(new env.dom.window.FocusEvent('blur'));

        assert.deepEqual(
            env.editor.currentDocument().elements[0].lineAligns,
            {0: 'center', 1: 'right'}
        );
    } finally {
        env.dom.cleanup();
    }
});

/**
 * บั๊กจริงรอบที่สามของการจัดบรรทัด: "กดชิดซ้ายแล้วบรรทัดแรกชิดซ้ายตาม บางทีก็ทำได้ บางทีก็ไม่"
 *
 * คนละต้นเหตุกับเรื่อง mousedown ข้างบน อันนี้เกิดกับกล่องที่ "ยังไม่เคยกด Enter"
 * ซึ่งเนื้อหาเป็น text node ล้วน ยังไม่มีบล็อกลูกสักใบ โค้ดเดิมจึงไปตั้ง
 * text-align ไว้ที่ "ตัวกล่อง" แทน แล้วไม่มีโค้ดส่วนไหนล้างค่านั้นอีกเลย
 * พอกด Enter ขึ้นบรรทัดใหม่ ทุกบรรทัดจึงสืบทอดกึ่งกลางมาจากกล่อง และการสั่ง
 * "ชิดซ้าย" ให้บรรทัดเดียวก็ไม่มีผล เพราะของเดิมลบ text-align ทิ้งแล้วสืบทอดกึ่งกลางคืนมา
 */
test('กล่องที่ยังไม่มีบรรทัด การจัดกึ่งกลางต้องลงที่บล็อก ไม่ใช่ที่ตัวกล่อง', () => {
    const env = mountEditor([note('n1', {x: 100, y: 100, text: 'บรรทัดแรก'})]);

    try {
        env.stage.dispatchEvent(new env.dom.window.MouseEvent('dblclick', {
            bubbles: true, cancelable: true, clientX: 150, clientY: 150,
        }));

        const node = env.note();

        // สภาพจริงของกล่องที่พิมพ์สด ๆ โดยยังไม่กด Enter: มีแต่ text node ไม่มีบล็อก
        node.textContent = 'บรรทัดแรก';

        const range = env.dom.document.createRange();
        range.selectNodeContents(node);
        range.collapse(false);
        const selection = env.dom.document.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);

        clickLikeBrowser(env, env.control('[data-align="center"]'));

        assert.equal(node.style.textAlign, '', 'ห้ามตั้ง text-align ที่ตัวกล่อง เพราะจะสืบทอดไปทุกบรรทัดตลอดกาล');
        assert.equal(node.children.length, 1, 'เนื้อหาต้องถูกห่อเข้าบล็อกของตัวเอง');
        assert.equal(node.children[0].style.textAlign, 'center');
        assert.equal(node.children[0].textContent, 'บรรทัดแรก');

        /*
         * ผู้ใช้กด Enter — เบราว์เซอร์จริงคัดลอกบล็อกเดิมพร้อมสไตล์ของมันมาเป็น
         * บรรทัดใหม่ บรรทัดที่สองจึงเริ่มต้นด้วย center ติดมาด้วย
         */
        const second = env.dom.document.createElement('div');
        second.style.textAlign = 'center';
        second.textContent = 'บรรทัดสอง';
        node.appendChild(second);

        placeCaretInLine(env, node, 1);
        clickLikeBrowser(env, env.control('[data-align="left"]'));

        assert.equal(node.children[0].style.textAlign, 'center', 'บรรทัดแรกต้องยังกึ่งกลาง');
        assert.equal(node.children[1].style.textAlign, 'left', 'บรรทัดสองต้องชิดซ้ายจริง ไม่ใช่ค่าว่างที่แปลว่าสืบทอด');

        node.dispatchEvent(new env.dom.window.FocusEvent('blur'));

        assert.deepEqual(
            env.editor.currentDocument().elements[0].lineAligns,
            {0: 'center'},
            'บันทึกแล้วบรรทัดแรกต้องยังเป็น center ส่วนบรรทัดชิดซ้ายไม่ต้องเก็บ'
        );
    } finally {
        env.dom.cleanup();
    }
});

/*
 * ปุ่มลัดจัดบรรทัดต้องทำงานระหว่างพิมพ์ ซึ่งเป็นข้อยกเว้นเดียวของกติกา
 * "ปุ่มลัดต้องเงียบขณะพิมพ์" ถ้าเงียบตามกติกาไปด้วยก็ไม่เหลือประโยชน์อะไรเลย
 */
test('ปุ่มลัด Ctrl+Shift+R ระหว่างพิมพ์ จัดเฉพาะบรรทัดที่เคอร์เซอร์อยู่', () => {
    const env = mountEditor([note('n1', {
        x: 100, y: 100, text: 'บรรทัด1\nบรรทัด2', lineAligns: {0: 'center'},
    })]);

    try {
        env.stage.dispatchEvent(new env.dom.window.MouseEvent('dblclick', {
            bubbles: true, cancelable: true, clientX: 150, clientY: 150,
        }));

        const node = env.note();

        placeCaretInLine(env, node, 1);

        const event = new env.dom.window.KeyboardEvent('keydown', {
            key: 'R', code: 'KeyR', ctrlKey: true, shiftKey: true, bubbles: true, cancelable: true,
        });

        node.dispatchEvent(event);

        assert.equal(event.defaultPrevented, true, 'ปุ่มลัดต้องทำงาน ไม่ใช่ถูกปล่อยผ่าน');
        assert.equal(node.children[0].style.textAlign, 'center', 'บรรทัดแรกต้องไม่ถูกแตะ');
        assert.equal(node.children[1].style.textAlign, 'right');
    } finally {
        env.dom.cleanup();
    }
});

/*
 * ตัว r เปล่า ๆ ยังต้องเป็นตัวอักษรในโน้ต ไม่ใช่เครื่องมือสี่เหลี่ยม
 * การเปิดทางให้ปุ่มลัดจัดบรรทัดต้องไม่เปิดประตูให้ปุ่มลัดอื่นตามเข้ามาด้วย
 */
test('พิมพ์ตัวอักษรของปุ่มลัดเครื่องมือในโน้ต ยังไม่เปลี่ยนเครื่องมือ', () => {
    const env = mountEditor([note('n1', {x: 100, y: 100, text: 'บรรทัด1'})]);

    try {
        env.stage.dispatchEvent(new env.dom.window.MouseEvent('dblclick', {
            bubbles: true, cancelable: true, clientX: 150, clientY: 150,
        }));

        env.note().dispatchEvent(new env.dom.window.KeyboardEvent('keydown', {
            key: 'r', code: 'KeyR', bubbles: true, cancelable: true,
        }));

        assert.notEqual(env.editor.state.tool, 'rect');
    } finally {
        env.dom.cleanup();
    }
});

/**
 * เจ้าของโครงการสั่งใหม่: การจัดบรรทัดทำงานทีละบรรทัดเสมอ
 *
 * เดิมการเลือกกล่องไว้เฉย ๆ แล้วกดจัดบรรทัดจะล้างการจัดรายบรรทัดที่ตั้งไว้ทั้งหมด
 * ผู้ใช้รายงานว่า "ตอนแรกพอกดแล้วมันไปทั้งบรรทัดเลย" เพราะปุ่มเดียวกันทำสองความหมาย
 * โดยหน้าจอไม่บอกว่าตอนนี้อยู่โหมดไหน ปุ่มจึงถูกปิดจนกว่าจะดับเบิลคลิกเข้าโหมดพิมพ์
 */
test('เลือกกล่องทั้งใบโดยไม่เปิดพิมพ์ ปุ่มจัดบรรทัดกดไม่ได้ และการจัดรายบรรทัดเดิมไม่ถูกแตะ', () => {
    const env = mountEditor([note('n1', {
        x: 100, y: 100, text: 'บรรทัด1\nบรรทัด2', lineAligns: {0: 'center'},
    })]);

    try {
        drag(env.stage, {x: 150, y: 150}, {x: 150, y: 150});

        const center = env.control('[data-align="center"]');
        const right = env.control('[data-align="right"]');

        assert.equal(center.disabled, true, 'แค่เลือกกล่องยังกดจัดบรรทัดไม่ได้');
        assert.equal(right.disabled, true);
        assert.match(center.title, /ดับเบิลคลิก/, 'ต้องบอกวิธีเปิดใช้งาน ไม่ใช่ปิดเฉย ๆ');

        click(right);

        assert.deepEqual(
            env.editor.currentDocument().elements[0].lineAligns,
            {0: 'center'},
            'การจัดรายบรรทัดที่ตั้งไว้ต้องคงเดิมทุกประการ'
        );
    } finally {
        env.dom.cleanup();
    }
});

/*
 * ค่าตั้งต้นของกล่องถัดไปมาจากการจัดบรรทัดครั้งล่าสุดขณะพิมพ์ ไม่ใช่การกดปุ่มลอย ๆ
 * ตอนที่ยังไม่ได้เลือกอะไร เพราะปุ่มกลุ่มนี้ถูกปิดนอกโหมดพิมพ์แล้ว
 */
test('กล่องใหม่รับการจัดบรรทัดล่าสุดที่ใช้ขณะพิมพ์เป็นค่าตั้งต้น', () => {
    const env = mountEditor([note('n1', {x: 100, y: 100, text: 'เดิม'})]);

    try {
        env.stage.dispatchEvent(new env.dom.window.MouseEvent('dblclick', {
            bubbles: true, cancelable: true, clientX: 150, clientY: 150,
        }));

        placeCaretInLine(env, env.note(), 0);
        clickLikeBrowser(env, env.control('[data-align="center"]'));

        assert.equal(env.editor.state.style.align, 'center', 'ค่าล่าสุดต้องถูกจำไว้');

        env.note().dispatchEvent(new env.dom.window.FocusEvent('blur'));

        click(env.control('[data-tool="text"]'));
        pointerDown(env.stage, {x: 400, y: 350});
        pointerUp(env.stage, {x: 400, y: 350});

        assert.deepEqual(env.editor.currentDocument().elements.at(-1).lineAligns, {0: 'center'});
    } finally {
        env.dom.cleanup();
    }
});

test('ชิดซ้ายเป็นค่าปริยาย ไม่ถูกเก็บลงเอกสาร', () => {
    const env = mountEditor();

    try {
        click(env.control('[data-tool="sticky"]'));
        pointerDown(env.stage, {x: 200, y: 150});
        pointerUp(env.stage, {x: 200, y: 150});

        assert.equal('lineAligns' in env.editor.currentDocument().elements[0], false);
    } finally {
        env.dom.cleanup();
    }
});

/*
 * นี่คือเรื่องที่ผู้ใช้รายงานโดยตรง: จัดบรรทัดแรกกึ่งกลางแล้วบรรทัดถัดมาก็โดน
 * บังคับตามไปด้วย เพราะของเดิมการจัดบรรทัดเป็นคุณสมบัติเดียวของทั้งกล่อง
 * เทสต์นี้ยืนยันว่าตอนนี้กดปุ่มขณะเคอร์เซอร์อยู่ที่บรรทัดหนึ่ง มีผลกับบรรทัด
 * นั้นบรรทัดเดียว บรรทัดอื่นในกล่องเดียวกันต้องไม่ถูกแตะเลย
 */
test('กดปุ่มจัดบรรทัดขณะเคอร์เซอร์อยู่ที่บรรทัดหนึ่ง มีผลเฉพาะบรรทัดนั้น บรรทัดอื่นไม่ถูกแตะ', () => {
    const env = mountEditor([note('n1', {x: 100, y: 100, text: 'บรรทัด1\nบรรทัด2\nบรรทัด3'})]);

    try {
        env.stage.dispatchEvent(new env.dom.window.MouseEvent('dblclick', {
            bubbles: true, cancelable: true, clientX: 150, clientY: 150,
        }));

        const node = env.note();
        assert.equal(node.classList.contains('is-editing'), true);
        assert.equal(node.children.length, 3, 'ต้องมีบล็อกลูกสามใบ หนึ่งใบต่อบรรทัด');

        placeCaretInLine(env, node, 1);
        click(env.control('[data-align="right"]'));

        assert.equal(node.children[0].style.textAlign, '', 'บรรทัดแรกต้องไม่ถูกแตะ');
        assert.equal(node.children[1].style.textAlign, 'right');
        assert.equal(node.children[2].style.textAlign, '', 'บรรทัดสามต้องไม่ถูกแตะ');

        node.dispatchEvent(new env.dom.window.FocusEvent('blur'));

        const saved = env.editor.currentDocument().elements[0];

        assert.equal(saved.text, 'บรรทัด1\nบรรทัด2\nบรรทัด3', 'เนื้อความต้องไม่เปลี่ยน');
        assert.deepEqual(saved.lineAligns, {1: 'right'});
    } finally {
        env.dom.cleanup();
    }
});

test('เลือกข้อความคร่อมสองบรรทัดแล้วกดจัดบรรทัด มีผลกับทั้งสองบรรทัดที่คร่อมอยู่', () => {
    const env = mountEditor([note('n1', {x: 100, y: 100, text: 'บรรทัด1\nบรรทัด2\nบรรทัด3'})]);

    try {
        env.stage.dispatchEvent(new env.dom.window.MouseEvent('dblclick', {
            bubbles: true, cancelable: true, clientX: 150, clientY: 150,
        }));

        const node = env.note();
        const range = env.dom.document.createRange();
        range.setStart(node.children[0].firstChild, 0);
        range.setEnd(node.children[1].firstChild, 1);

        const selection = env.dom.document.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);

        click(env.control('[data-align="center"]'));

        assert.equal(node.children[0].style.textAlign, 'center');
        assert.equal(node.children[1].style.textAlign, 'center');
        assert.equal(node.children[2].style.textAlign, '', 'บรรทัดสามอยู่นอกตัวเลือก ต้องไม่ถูกแตะ');
    } finally {
        env.dom.cleanup();
    }
});

test('ปุ่มจัดบรรทัดบนแถบเครื่องมือไฮไลต์ตามบรรทัดจริงที่เคอร์เซอร์อยู่ ไม่ใช่ค่าที่กดครั้งล่าสุด', () => {
    const env = mountEditor([note('n1', {
        x: 100, y: 100, text: 'บรรทัด1\nบรรทัด2', lineAligns: {0: 'center'},
    })]);

    try {
        env.stage.dispatchEvent(new env.dom.window.MouseEvent('dblclick', {
            bubbles: true, cancelable: true, clientX: 150, clientY: 150,
        }));

        const node = env.note();

        placeCaretInLine(env, node, 0);
        assert.equal(env.control('[data-align="center"]').getAttribute('aria-pressed'), 'true');

        placeCaretInLine(env, node, 1);
        assert.equal(env.control('[data-align="left"]').getAttribute('aria-pressed'), 'true');
        assert.equal(env.control('[data-align="center"]').getAttribute('aria-pressed'), 'false');
    } finally {
        env.dom.cleanup();
    }
});

test('การจัดบรรทัดต่อบรรทัดถูกส่งไปเซิร์ฟเวอร์เป็น lineAligns และคงอยู่ข้ามการเปิดแก้ไขใหม่', () => {
    const env = mountEditor([note('n1', {
        x: 100, y: 100, text: 'บรรทัด1\nบรรทัด2', lineAligns: {1: 'right'},
    })]);

    try {
        assert.equal(env.editor.currentDocument().elements[0].lineAligns[1], 'right');

        env.stage.dispatchEvent(new env.dom.window.MouseEvent('dblclick', {
            bubbles: true, cancelable: true, clientX: 150, clientY: 150,
        }));

        const node = env.note();

        assert.equal(node.children[0].style.textAlign, '');
        assert.equal(node.children[1].style.textAlign, 'right');
    } finally {
        env.dom.cleanup();
    }
});

/* ── ลากเลือกข้อความในกล่องที่กำลังแก้ไข ต้องไม่ลากย้ายกล่องไปด้วย ────── */

/*
 * ก่อนแก้ไข การลากเมาส์เพื่อเลือกข้อความในกล่องที่เปิดแก้ไขอยู่ทำให้ทั้งกล่อง
 * (หรือทั้งกลุ่มที่เลือกอยู่) ขยับตามไปด้วย เพราะ pointerdown บนกล่องยังคง
 * ไหลขึ้นไปถึง stage แล้วเครื่องมือเลือกเริ่มท่าลากย้ายควบคู่ไปกับที่เบราว์เซอร์
 * กำลังลากเลือกข้อความอยู่ในเวลาเดียวกัน ดู pointer.js ว่าทำไมต้องกันไว้
 */
test('ลากเมาส์ในกล่องที่กำลังแก้ไขอยู่ ไม่ทำให้กล่องขยับ', () => {
    const env = mountEditor([note('n1', {x: 100, y: 100, text: 'ทดสอบลากเลือกข้อความ'})]);

    try {
        env.stage.dispatchEvent(new env.dom.window.MouseEvent('dblclick', {
            bubbles: true, cancelable: true, clientX: 150, clientY: 150,
        }));

        const node = env.note();
        assert.equal(node.classList.contains('is-editing'), true);

        drag(node, {x: 110, y: 150}, {x: 250, y: 150}, {steps: 3});

        const element = env.editor.currentDocument().elements[0];

        assert.deepEqual({x: element.x, y: element.y}, {x: 100, y: 100});
        assert.equal(env.editor.state.draft, null, 'เครื่องมือเลือกต้องไม่เริ่มท่าลากย้ายเลย');
    } finally {
        env.dom.cleanup();
    }
});

/*
 * ตัวกันนี้ต้องดูจาก event.target จริง ๆ ว่าคลิกโดนกล่องที่แก้ไขอยู่หรือไม่
 * ไม่ใช่ปิดกั้นทุกการลากบนผืนผ้าใบเพียงเพราะมี editingId ค้างอยู่ ไม่งั้นผู้ใช้
 * ที่เปิดกล่องหนึ่งแก้ไขค้างไว้จะลากย้ายชิ้นงานอื่นบนกระดานไม่ได้เลย
 */
test('มีกล่องกำลังแก้ไขอยู่ก็ตาม การลากที่ผืนผ้าใบ (ไม่ใช่ตัวกล่อง) ยังลากย้ายชิ้นงานได้ตามปกติ', () => {
    const env = mountEditor([note('n1', {x: 100, y: 100, text: 'a'})]);

    try {
        env.stage.dispatchEvent(new env.dom.window.MouseEvent('dblclick', {
            bubbles: true, cancelable: true, clientX: 150, clientY: 150,
        }));

        assert.equal(env.note().classList.contains('is-editing'), true);

        // dispatch ที่ stage เอง ไม่ใช่ที่ตัวโหนดกล่อง จำลองการคลิกที่ผืนผ้าใบ
        // ใต้กล่อง (ระบบตรวจการชนจากตัวแบบข้อมูล ไม่ใช่ DOM hit-test ของเบราว์เซอร์)
        drag(env.stage, {x: 150, y: 150}, {x: 250, y: 200}, {steps: 3});

        const element = env.editor.currentDocument().elements[0];

        assert.deepEqual({x: element.x, y: element.y}, {x: 200, y: 150});
    } finally {
        env.dom.cleanup();
    }
});
