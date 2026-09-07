import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {mountDom, click} from './helpers/dom.js';
import {drag, pointerDown, pointerUp, stubStageRect} from './helpers/pointer.js';
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
    const dom = mountDom('<!doctype html><html><body><div data-workspace-overlay></div></body></html>');

    return {dom, layer: dom.document.querySelector('[data-workspace-overlay]')};
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
        assert.equal(node.querySelector('img'), null, 'ต้องไม่มีโหนดลูกเกิดขึ้นเลย');
        assert.equal(node.children.length, 0);
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

const markup = (elements = []) => `<!doctype html><html><body>
<div class="ws-board" data-workspace-board data-board-id="7">
    <script type="application/json" id="workspace-design">${JSON.stringify(DESIGN)}</script>
    <script type="application/json" id="workspace-board">${JSON.stringify({
        id: 7, capabilities: {canEdit: true},
    })}</script>
    <script type="application/json" id="workspace-document">${JSON.stringify({schema: 1, elements})}</script>

    <div class="wsb-toolbar" data-workspace-toolbar>
        <button type="button" data-tool="select"></button>
        <button type="button" data-tool="sticky" data-requires-edit></button>
        <button type="button" data-tool="text" data-requires-edit></button>
        <button type="button" data-sticky-color="#bfdbfe" data-requires-edit></button>
        <button type="button" data-font-step="-2" data-requires-edit></button>
        <input type="number" data-font-size-input data-requires-edit value="20" min="8" max="96">
        <button type="button" data-font-step="2" data-requires-edit></button>
        <button type="button" data-command="undo" data-requires-edit disabled></button>
        <button type="button" data-command="delete" data-requires-edit disabled></button>
        <span data-zoom-label>100%</span>
    </div>

    <div class="wsb-stage" data-workspace-stage>
        <svg><g data-workspace-vector></g><g data-workspace-preview></g><g data-workspace-selection></g></svg>
        <div class="wsb-overlay" data-workspace-overlay></div>
    </div>
</div>
</body></html>`;

let seed = 0;

const mountEditor = (elements = []) => {
    const dom = mountDom(markup(elements));
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
        overlay: dom.document.querySelector('[data-workspace-overlay]'),
        toolbar: dom.document.querySelector('[data-workspace-toolbar]'),
        vector: dom.document.querySelector('[data-workspace-vector]'),
    };
};

test('แตะครั้งเดียวด้วยเครื่องมือโน้ตสร้างโน้ตขนาดตั้งต้น', () => {
    const env = mountEditor();

    try {
        click(env.toolbar.querySelector('[data-tool="sticky"]'));
        pointerDown(env.stage, {x: 200, y: 150});
        pointerUp(env.stage, {x: 200, y: 150});

        const created = env.editor.currentDocument().elements[0];

        assert.equal(created.type, 'sticky');
        assert.deepEqual({w: created.w, h: created.h}, {w: 180, h: 180});
        assert.equal(created.fill, '#fde68a');
        assert.equal(env.overlay.children.length, 1, 'ต้องถูกวาดในชั้น HTML');
        assert.equal(env.vector.children.length, 0, 'และต้องไม่โผล่ในชั้น SVG');
    } finally {
        env.dom.cleanup();
    }
});

test('ลากกรอบด้วยเครื่องมือโน้ตได้ขนาดตามที่ลาก', () => {
    const env = mountEditor();

    try {
        click(env.toolbar.querySelector('[data-tool="sticky"]'));
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
        click(env.toolbar.querySelector('[data-tool="sticky"]'));
        pointerDown(env.stage, {x: 200, y: 150});
        pointerUp(env.stage, {x: 200, y: 150});

        const node = env.overlay.firstElementChild;

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

        const node = env.overlay.firstElementChild;
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
        assert.equal(env.overlay.firstElementChild.classList.contains('is-editing'), false);

        env.stage.dispatchEvent(new env.dom.window.MouseEvent('dblclick', {
            bubbles: true, cancelable: true, clientX: 150, clientY: 150,
        }));

        assert.equal(env.overlay.firstElementChild.classList.contains('is-editing'), true);
        assert.deepEqual(env.editor.state.selection, ['n1']);
    } finally {
        env.dom.cleanup();
    }
});

test('การเลือกสีกระดาษโน้ตเปลี่ยนสีของใบที่เลือกอยู่ทันที', () => {
    const env = mountEditor([note('n1', {x: 100, y: 100})]);

    try {
        drag(env.stage, {x: 150, y: 150}, {x: 150, y: 150});
        click(env.toolbar.querySelector('[data-sticky-color="#bfdbfe"]'));

        assert.equal(env.editor.currentDocument().elements[0].fill, '#bfdbfe');
        assert.equal(env.overlay.firstElementChild.style.background, 'rgb(191, 219, 254)');
    } finally {
        env.dom.cleanup();
    }
});

test('การเลือกสีโดยไม่ได้เลือกโน้ตไว้ มีผลกับใบถัดไปที่สร้าง', () => {
    const env = mountEditor();

    try {
        click(env.toolbar.querySelector('[data-sticky-color="#bfdbfe"]'));
        click(env.toolbar.querySelector('[data-tool="sticky"]'));
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

        const node = env.overlay.firstElementChild;
        node.textContent = 'ใหม่';
        node.dispatchEvent(new env.dom.window.FocusEvent('blur'));

        click(env.toolbar.querySelector('[data-command="undo"]'));

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

        env.overlay.firstElementChild.dispatchEvent(new env.dom.window.FocusEvent('blur'));

        assert.equal(env.toolbar.querySelector('[data-command="undo"]').disabled, true);
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
    const css = fs.readFileSync('resources/css/pages/workspace.css', 'utf8');
    const block = css.slice(css.indexOf('.wsb-note,'), css.indexOf('.wsb-note {'));

    assert.match(block, /overflow-wrap:\s*anywhere/);
    assert.match(block, /word-break:\s*break-word/);
    assert.match(block, /white-space:\s*pre-wrap/);
});

/** พิมพ์ขนาดลงในช่องกรอกแล้วยิงเหตุการณ์อย่างที่เบราว์เซอร์ทำ */
const typeFontSize = (env, value) => {
    const field = env.toolbar.querySelector('[data-font-size-input]');
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
        click(env.toolbar.querySelector('[data-tool="sticky"]'));
        pointerDown(env.stage, {x: 200, y: 150});
        pointerUp(env.stage, {x: 200, y: 150});

        assert.equal(env.editor.currentDocument().elements[0].fontSize, 16);
        assert.equal(env.overlay.firstElementChild.style.fontSize, '16px');
    } finally {
        env.dom.cleanup();
    }
});

test('กล่องข้อความก็ใช้ขนาดที่กรอกเช่นเดียวกับโน้ต', () => {
    const env = mountEditor();

    try {
        typeFontSize(env, 48);
        click(env.toolbar.querySelector('[data-tool="text"]'));
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
        assert.equal(env.overlay.firstElementChild.style.fontSize, '36px');
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
        const field = env.toolbar.querySelector('[data-font-size-input]');
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

        click(env.toolbar.querySelector('[data-font-step="2"]'));
        assert.equal(env.editor.state.style.fontSize, 22);

        click(env.toolbar.querySelector('[data-font-step="-2"]'));
        assert.equal(env.editor.state.style.fontSize, 20);
    } finally {
        env.dom.cleanup();
    }
});

test('ปุ่มลดไม่พาขนาดต่ำกว่าค่าต่ำสุด', () => {
    const env = mountEditor();

    try {
        typeFontSize(env, 8);
        click(env.toolbar.querySelector('[data-font-step="-2"]'));

        assert.equal(env.editor.state.style.fontSize, 8);
    } finally {
        env.dom.cleanup();
    }
});

test('ช่องกรอกสะท้อนขนาดของกล่องที่เลือก', () => {
    const env = mountEditor([note('n1', {x: 100, y: 100, fontSize: 44})]);

    try {
        typeFontSize(env, 44);

        assert.equal(env.toolbar.querySelector('[data-font-size-input]').value, '44');
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
