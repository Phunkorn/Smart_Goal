import test from 'node:test';
import assert from 'node:assert/strict';
import {mountDom} from './helpers/dom.js';
import {
    TYPING_SAFE_COMMANDS,
    initKeyboardShortcuts,
    isTypingContext,
    letterOf,
    resolveShortcut,
    toolShortcutsFrom,
} from '../../resources/js/pages/workspace/keyboard.js';

/*
 * การแปลงการกดแป้นเป็นคำสั่ง
 *
 * เทสต์การต่อสายจริงตั้งแต่กดแป้นจนเครื่องมือเปลี่ยนอยู่ใน
 * workspace-board-editor.test.js ชุดนี้ตรวจตารางความหมายของแต่ละปุ่ม
 */

const TOOLS = {
    hand: {shortcut: 'H'},
    select: {shortcut: 'V'},
    pen: {shortcut: 'P'},
    eraser: {shortcut: 'E'},
    sticky: {shortcut: 'N'},
    text: {shortcut: 'T'},
    rect: {shortcut: 'R'},
    ellipse: {shortcut: 'O'},
    line: {shortcut: 'L'},
    arrow: {shortcut: 'A'},
    image: {shortcut: null},
};

const key = (value, options = {}) => ({key: value, code: '', ...options});

test('ตารางปุ่มลัดสร้างจาก WorkspaceDesign::TOOLS และข้ามรายการที่ไม่มีปุ่มลัด', () => {
    assert.deepEqual(toolShortcutsFrom(TOOLS), {
        h: 'hand', v: 'select', p: 'pen', e: 'eraser', n: 'sticky',
        t: 'text', r: 'rect', o: 'ellipse', l: 'line', a: 'arrow',
    });
    assert.deepEqual(toolShortcutsFrom(undefined), {});
});

test('ตัวอักษรทุกตัวเปลี่ยนเป็นเครื่องมือที่ตรงกัน', () => {
    const shortcuts = toolShortcutsFrom(TOOLS);

    Object.entries(shortcuts).forEach(([letter, tool]) => {
        assert.deepEqual(resolveShortcut(key(letter), shortcuts), {tool});
        assert.deepEqual(resolveShortcut(key(letter.toUpperCase()), shortcuts), {tool}, 'Caps Lock เปิดอยู่ก็ต้องได้');
    });
});

/*
 * ผู้ใช้หน้านี้พิมพ์ด้วยแป้นไทยเป็นหลัก ถ้าอ่านแค่ event.key ปุ่มลัดจะใช้ไม่ได้เลย
 * จนกว่าจะสลับภาษา
 */
test('แป้นภาษาไทยเปิดอยู่ ปุ่มลัดยังใช้ได้จากตำแหน่งปุ่มจริง', () => {
    assert.equal(letterOf({key: 'พ', code: 'KeyR'}), 'r');
    assert.deepEqual(resolveShortcut({key: 'ผ', code: 'KeyZ', ctrlKey: true}), {command: 'undo'});
});

test('แป้นที่ตัวอักษรไม่ตรงตำแหน่ง QWERTY ใช้ตัวอักษรที่พิมพ์อยู่บนปุ่ม', () => {
    // AZERTY: ปุ่มที่พิมพ์ z อยู่ตำแหน่ง KeyW ของ QWERTY
    assert.equal(letterOf({key: 'z', code: 'KeyW'}), 'z');
});

test('ปุ่มลัดของเครื่องมือไม่ทำงานเมื่อกดปุ่มเสริมค้าง', () => {
    const shortcuts = toolShortcutsFrom(TOOLS);

    assert.equal(resolveShortcut(key('r', {shiftKey: true}), shortcuts), null);
    assert.equal(resolveShortcut(key('r', {altKey: true}), shortcuts), null);
    assert.notDeepEqual(resolveShortcut(key('v', {ctrlKey: true}), shortcuts), {tool: 'select'},
        'Ctrl+V คือวาง ไม่ใช่เครื่องมือเลือก');
});

/*
 * Ctrl+C / Ctrl+V ต้องไม่ถูกแปลงที่ keydown เพราะการยกเลิก keydown ของ Ctrl+V ทำให้
 * เบราว์เซอร์ไม่ยิง paste แล้ววางรูปที่แคปมาไม่ได้ ทั้งคู่ไปทาง clipboard-events.js
 */
test('Ctrl+C และ Ctrl+V ถูกปล่อยให้เบราว์เซอร์ยิงเหตุการณ์ copy/paste เอง', () => {
    assert.equal(resolveShortcut(key('c', {ctrlKey: true})), null);
    assert.equal(resolveShortcut(key('v', {ctrlKey: true})), null);
    assert.equal(resolveShortcut(key('v', {metaKey: true})), null);
});

test('ปุ่มผสม Ctrl/Cmd แปลงเป็นคำสั่งแก้ไข', () => {
    assert.deepEqual(resolveShortcut(key('d', {ctrlKey: true})), {command: 'duplicate'});
    assert.deepEqual(resolveShortcut(key('z', {ctrlKey: true})), {command: 'undo'});
    assert.deepEqual(resolveShortcut(key('Z', {ctrlKey: true, shiftKey: true})), {command: 'redo'});
    assert.deepEqual(resolveShortcut(key('y', {ctrlKey: true})), {command: 'redo'});
    assert.equal(resolveShortcut(key('v', {ctrlKey: true, shiftKey: true})), null,
        'Ctrl+Shift+V เป็นของเบราว์เซอร์ (วางแบบข้อความล้วน)');
});

test('ปุ่มเดิมก่อนมีปุ่มลัดเครื่องมือยังทำงานเหมือนเดิม', () => {
    assert.deepEqual(resolveShortcut(key('Delete')), {command: 'delete'});
    assert.deepEqual(resolveShortcut(key('Backspace')), {command: 'delete'});
    assert.deepEqual(resolveShortcut(key('Escape')), {command: 'escape'});
    assert.deepEqual(resolveShortcut(key('f')), {command: 'fullscreen'});
});

test('ปุ่มที่ไม่ใช่ปุ่มลัดไม่มีความหมาย', () => {
    assert.equal(resolveShortcut(key('Shift', {shiftKey: true}), toolShortcutsFrom(TOOLS)), null);
    assert.equal(resolveShortcut(key('q'), toolShortcutsFrom(TOOLS)), null);
    assert.equal(resolveShortcut(key('1'), toolShortcutsFrom(TOOLS)), null);
});

/* ── การกดขณะพิมพ์ ──────────────────────────────────────────── */

test('การกดในช่องกรอก กล่องข้อความ กล่องที่แก้ไขได้ และกล่องโต้ตอบ ถือว่ากำลังพิมพ์', () => {
    const dom = mountDom(`<!doctype html><html><body>
        <input id="field">
        <textarea id="area"></textarea>
        <div id="note" contenteditable="plaintext-only" tabindex="0"><span id="inner">ข้อความ</span></div>
        <div role="dialog" aria-modal="true"><button id="dialog-button">ตกลง</button></div>
        <button id="plain">ปุ่มธรรมดา</button>
    </body></html>`);

    try {
        const {document} = dom;
        const at = (id) => ({target: document.getElementById(id)});

        assert.equal(isTypingContext(document, at('field')), true);
        assert.equal(isTypingContext(document, at('area')), true);
        assert.equal(isTypingContext(document, at('note')), true);
        assert.equal(isTypingContext(document, at('inner')), true, 'ลูกของกล่องที่แก้ไขได้ก็นับ');
        assert.equal(isTypingContext(document, at('dialog-button')), true);
        assert.equal(isTypingContext(document, at('plain')), false);
        assert.equal(isTypingContext(document, {target: document.body}), false);
    } finally {
        dom.cleanup();
    }
});

test('การพิมพ์ผ่าน IME (เช่นกำลังประกอบคำ) ไม่ถูกตีความเป็นปุ่มลัด', () => {
    const dom = mountDom();

    try {
        assert.equal(isTypingContext(dom.document, {target: dom.document.body, isComposing: true}), true);
        assert.equal(isTypingContext(dom.document, {target: dom.document.body, keyCode: 229}), true);
    } finally {
        dom.cleanup();
    }
});

/* ── ปุ่มลัดจัดบรรทัด ────────────────────────── */

test('Ctrl+Shift+L/E/R เป็นคำสั่งจัดบรรทัด ตามชุดของ Word และ Google Docs', () => {
    assert.deepEqual(resolveShortcut(key('L', {ctrlKey: true, shiftKey: true})), {command: 'align-left'});
    assert.deepEqual(resolveShortcut(key('E', {ctrlKey: true, shiftKey: true})), {command: 'align-center'});
    assert.deepEqual(resolveShortcut(key('R', {ctrlKey: true, shiftKey: true})), {command: 'align-right'});
});

test('ตัวอักษรเดิมที่ไม่ได้กด Shift ยังคงความหมายเดิม ไม่ถูกปุ่มลัดจัดบรรทัดกลืน', () => {
    const shortcuts = toolShortcutsFrom(TOOLS);

    assert.deepEqual(resolveShortcut(key('l'), shortcuts), {tool: 'line'});
    assert.deepEqual(resolveShortcut(key('e'), shortcuts), {tool: 'eraser'});
    assert.deepEqual(resolveShortcut(key('r'), shortcuts), {tool: 'rect'});
    assert.equal(resolveShortcut(key('l', {ctrlKey: true}), shortcuts), null, 'Ctrl+L เป็นของเบราว์เซอร์');
});

/*
 * ข้อยกเว้นเดียวของกติกา "ปุ่มลัดต้องเงียบขณะพิมพ์" การจัดบรรทัดมีความหมาย
 * เฉพาะตอนที่มีเคอร์เซอร์อยู่ในกล่องข้อความอยู่แล้ว ถ้าเงียบตามกติกาก็ไร้ประโยชน์
 * แต่ต้องเปิดให้เฉพาะกล่องข้อความของกระดาน ไม่ใช่ทุกช่องกรอกบนหน้า
 */
test('ขณะพิมพ์ ปล่อยผ่านเฉพาะคำสั่งจัดบรรทัด และเฉพาะในกล่องข้อความของกระดาน', () => {
    const dom = mountDom('<!doctype html><html><body><input id="field"></body></html>');

    try {
        const commands = [];
        let inBoardText = true;

        initKeyboardShortcuts(dom.document, {
            toolShortcuts: toolShortcutsFrom(TOOLS),
            onCommand: (command) => { commands.push(command); return true; },
            isTextEditing: () => inBoardText,
        });

        const press = (options) => dom.document.getElementById('field').dispatchEvent(
            new dom.window.KeyboardEvent('keydown', {bubbles: true, cancelable: true, ...options})
        );

        press({key: 'R', code: 'KeyR', ctrlKey: true, shiftKey: true});
        assert.deepEqual(commands, ['align-right'], 'ปุ่มลัดจัดบรรทัดต้องผ่าน');

        press({key: 'z', code: 'KeyZ', ctrlKey: true});
        assert.deepEqual(commands, ['align-right'], 'คำสั่งอื่นต้องยังเงียบขณะพิมพ์');

        inBoardText = false;
        press({key: 'R', code: 'KeyR', ctrlKey: true, shiftKey: true});
        assert.deepEqual(commands, ['align-right'], 'ช่องกรอกอื่นบนหน้าต้องไม่ถูกแย่งปุ่มไป');
    } finally {
        dom.cleanup();
    }
});

test('รายการคำสั่งที่ผ่านได้ขณะพิมพ์มีแต่การจัดบรรทัด', () => {
    assert.deepEqual(TYPING_SAFE_COMMANDS, ['align-left', 'align-center', 'align-right']);
});
