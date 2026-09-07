import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

/*
 * สัญญาระหว่าง Blade, CSS และ JavaScript ของกระดานไอเดีย
 *
 * JavaScript ค้นหา element ด้วย data attribute และ id ของ JSON island ส่วน Blade
 * เป็นผู้เขียนมันออกมา ทั้งสองฝั่งไม่มีอะไรบังคับให้ตรงกันนอกจากเทสต์ชุดนี้
 * ถ้ามีคนเปลี่ยนชื่อ hook ที่ฝั่งใดฝั่งหนึ่ง หน้าจะเงียบสนิทโดยไม่มี error
 *
 * รูปแบบเดียวกับ tests/js/work-log-contract.test.js
 */

const read = (path) => fs.readFileSync(path, 'utf8');

const BLADE = {
    board: 'resources/views/workspace/board.blade.php',
    toolbar: 'resources/views/workspace/components/board-toolbar.blade.php',
    stage: 'resources/views/workspace/components/board-stage.blade.php',
    card: 'resources/views/workspace/components/board-card.blade.php',
    modal: 'resources/views/workspace/components/board-settings-modal.blade.php',
    index: 'resources/views/workspace/index.blade.php',
    department: 'resources/views/workspace/department.blade.php',
};

const JS = {
    editor: 'resources/js/pages/workspace/index.js',
    list: 'resources/js/pages/workspace/board-list.js',
    settings: 'resources/js/pages/workspace/board-settings.js',
    autosave: 'resources/js/pages/workspace/autosave.js',
    toolbar: 'resources/js/pages/workspace/toolbar.js',
    attachments: 'resources/js/pages/workspace/attachments.js',
};

const CSS_PATH = 'resources/css/pages/workspace.css';

test('JSON island ทุกก้อนที่ JavaScript อ่าน ถูกเขียนออกมาจาก Blade', () => {
    const board = read(BLADE.board);
    const editor = read(JS.editor);

    ['workspace-design', 'workspace-board', 'workspace-document', 'workspace-routes'].forEach((id) => {
        assert.match(editor, new RegExp(`'${id}'`), `index.js ต้องอ่าน island ${id}`);
        assert.match(board, new RegExp(`id="${id}"`), `board.blade.php ต้องเขียน island ${id}`);
    });
});

test('หน้ารายการเขียน island ของ route ที่ board-list.js อ่าน', () => {
    const list = read(JS.list);

    assert.match(list, /'workspace-list-routes'/);

    [BLADE.index, BLADE.department].forEach((path) => {
        assert.match(read(path), /id="workspace-list-routes"/, `${path} ต้องเขียน island`);
    });
});

test('ตัวเชื่อมของหน้าวาดมีอยู่จริงทั้งใน Blade และ JavaScript', () => {
    const blade = read(BLADE.board) + read(BLADE.toolbar) + read(BLADE.stage);
    const js = read(JS.editor) + read(JS.autosave) + read(JS.attachments) + read(JS.toolbar);

    [
        'data-workspace-board',
        'data-workspace-toolbar',
        'data-workspace-stage',
        'data-workspace-vector',
        'data-workspace-preview',
        'data-workspace-selection',
        'data-workspace-overlay',
        'data-workspace-save-state',
        'data-workspace-save-label',
        'data-workspace-refresh',
        'data-workspace-image-input',
        'data-custom-color',
        'data-font-size-input',
        'data-font-step',
    ].forEach((hook) => {
        assert.match(blade, new RegExp(hook), `Blade ต้องมี ${hook}`);
        assert.match(js, new RegExp(hook), `JavaScript ต้องอ้าง ${hook}`);
    });
});

test('ตัวเชื่อมของหน้ารายการมีอยู่จริงทั้งสองฝั่ง', () => {
    const blade = read(BLADE.index) + read(BLADE.department) + read(BLADE.card) + read(BLADE.modal);
    // ตัวกล่องอยู่ใน board-settings.js เพราะใช้ร่วมกับหน้าวาด ส่วน board-list.js
    // เหลือแค่การต่อสายเหตุการณ์ของหน้ารายการ
    const js = read(JS.list) + read(JS.settings);

    [
        'data-workspace-list',
        'data-workspace-create',
        'data-workspace-settings',
        'data-workspace-delete',
        'data-workspace-modal',
        'data-workspace-form',
        'data-workspace-title',
        'data-workspace-submit',
        'data-workspace-error',
        'data-workspace-modal-dismiss',
        'data-workspace-modal-title',
    ].forEach((hook) => {
        assert.match(blade, new RegExp(hook), `Blade ต้องมี ${hook}`);
        assert.match(js, new RegExp(hook), `board-list.js ต้องอ้าง ${hook}`);
    });
});

/*
 * ข้อความไทยทุกคำต้องมาจาก App\Support\WorkspaceDesign ผ่าน Blade หรือ JSON island
 * ไม่ใช่เขียนซ้ำในไฟล์ .js ซึ่งเป็นปัญหาที่เคยเกิดกับป้ายสถานะของบอร์ดงาน
 */
test('ไฟล์ JavaScript ไม่มีป้ายชื่อภาษาไทยของเครื่องมือหรือสถานะฝังอยู่', () => {
    const forbidden = [
        'กระดาษโน้ต',
        'เลื่อนกระดาน',
        'ทั้งองค์กร',
        'เฉพาะแผนก',
        'บันทึกแล้ว',
        'กำลังบันทึก',
    ];

    Object.entries(JS).forEach(([name, path]) => {
        const code = read(path)
            .replace(/\/\*[\s\S]*?\*\//g, '')
            .replace(/(^|[^:])\/\/.*$/gm, '$1');

        forbidden.forEach((label) => {
            assert.doesNotMatch(
                code,
                new RegExp(label),
                `${name} ต้องไม่เขียนป้าย "${label}" ซ้ำ ให้รับมาจาก WorkspaceDesign`
            );
        });
    });
});

test('ป้ายชื่อบนแถบเครื่องมือถูกอ่านจาก WorkspaceDesign ไม่ใช่พิมพ์ในเทมเพลต', () => {
    const toolbar = read(BLADE.toolbar);

    assert.match(toolbar, /WorkspaceDesign::class/);
    assert.match(toolbar, /\$design::TOOLS/);
    assert.match(toolbar, /\$design::PALETTE/);
    assert.match(toolbar, /\$design::STICKY_COLORS/);
    assert.match(toolbar, /\$design::STROKE_WIDTHS/);
});

/*
 * ผืนผ้าใบต้องกินทุก gesture เอง ไม่งั้นเบราว์เซอร์บนมือถือจะเลื่อนหน้าแทนการวาด
 * และการหุบนิ้วจะไปซูมทั้งหน้าเว็บแทนที่จะซูมกระดาน
 */
test('CSS ของผืนผ้าใบตั้ง touch-action ไว้', () => {
    const css = read(CSS_PATH);
    const stage = css.slice(css.indexOf('.wsb-stage {'), css.indexOf('.wsb-canvas {'));

    assert.match(stage, /touch-action:\s*none/);
});

/*
 * ชั้นตัวอย่างและชั้นการเลือกต้องไม่ดักการคลิก ไม่งั้นจะบังผืนผ้าใบข้างใต้
 * และชั้น overlay ต้องปล่อยให้คลิกทะลุ ยกเว้นกล่องที่กำลังแก้ไขอยู่
 */
test('CSS ปล่อยให้การคลิกทะลุชั้นที่ไม่ควรดักไว้', () => {
    const css = read(CSS_PATH);

    assert.match(css, /\.wsb-preview,\s*\n\.wsb-selection \{[^}]*pointer-events:\s*none/);
    assert.match(css, /\.wsb-overlay \{[^}]*pointer-events:\s*none/s);
    assert.match(css, /\.wsb-note\.is-editing,\s*\n\.wsb-textbox\.is-editing \{[^}]*pointer-events:\s*auto/);
});

test('CSS มีจุดเปลี่ยนสำหรับจอเล็กและเป้าหมายการแตะที่ใหญ่พอ', () => {
    const css = read(CSS_PATH);

    assert.match(css, /@media \(max-width: 768px\)/);
    assert.match(css, /@media \(max-width: 576px\)/);

    // 2.75rem = 44px ซึ่งเป็นขนาดขั้นต่ำที่แตะได้ถนัดบนอุปกรณ์สัมผัส
    assert.match(css, /min-width:\s*2\.75rem/);
    assert.match(css, /height:\s*2\.75rem/);
});

test('CSS เคารพการตั้งค่าลดการเคลื่อนไหวของผู้ใช้', () => {
    assert.match(read(CSS_PATH), /@media \(prefers-reduced-motion: reduce\)/);
});

/*
 * ปุ่มที่แก้เนื้อหาต้องถูกทำเครื่องหมายไว้ให้ toolbar.js ปิดได้ทั้งชุด
 * ส่วนปุ่มมุมมองต้องไม่ถูกทำเครื่องหมาย เพราะผู้ที่ดูอย่างเดียวต้องซูมได้
 */
test('ปุ่มบนแถบเครื่องมือแยกชัดระหว่างปุ่มที่แก้เนื้อหากับปุ่มมุมมอง', () => {
    const toolbar = read(BLADE.toolbar);
    const viewGroup = toolbar.slice(toolbar.indexOf('aria-label="มุมมอง"'));

    assert.match(toolbar, /data-requires-edit/);
    assert.doesNotMatch(viewGroup, /data-requires-edit/, 'ปุ่มมุมมองต้องใช้ได้กับผู้ที่ดูอย่างเดียว');
    assert.match(read('resources/js/pages/workspace/toolbar.js'), /data-requires-edit/);
});

/*
 * ไฟล์ทุกไฟล์ที่ Blade เรียกผ่าน @vite ต้องอยู่ในรายการ input ของ vite.config.js
 * ไม่งั้นหน้าจะพังตอน build ด้วยข้อความ "Unable to locate file in Vite manifest"
 */
test('ทุก entry ที่ Blade เรียกถูกลงทะเบียนไว้ใน vite.config.js', () => {
    const vite = read('vite.config.js');
    const blades = Object.values(BLADE).map(read).join('\n');
    const referenced = [...blades.matchAll(/@vite\('([^']+)'\)/g)].map((match) => match[1]);

    assert.equal(referenced.length > 0, true, 'ต้องเจอการเรียก @vite อย่างน้อยหนึ่งจุด');

    [...new Set(referenced)].forEach((entry) => {
        assert.match(vite, new RegExp(`'${entry.replace(/\//g, '\\/')}'`), `${entry} ต้องอยู่ใน vite.config.js`);
    });
});

/*
 * โมดูลที่ตัดสินใจอะไรก็ตามต้องไม่พึ่ง DOM เพื่อให้ทดสอบได้โดยไม่ต้องมีเบราว์เซอร์
 * และเพื่อให้โค้ดเส้นทางเดียวกันทำงานทั้งใน jsdom และในเบราว์เซอร์จริง
 */
test('โมดูลตรรกะบริสุทธิ์ไม่แตะ DOM หรือ global ของเบราว์เซอร์', () => {
    ['scene.js', 'geometry.js', 'camera.js', 'history.js', 'simplify.js', 'save-state.js'].forEach((file) => {
        const code = read(`resources/js/pages/workspace/${file}`)
            .replace(/\/\*[\s\S]*?\*\//g, '')
            .replace(/(^|[^:])\/\/.*$/gm, '$1');

        assert.doesNotMatch(code, /\bdocument\./, `${file} ต้องไม่แตะ document`);
        assert.doesNotMatch(code, /\bwindow\./, `${file} ต้องไม่แตะ window`);
        assert.doesNotMatch(code, /querySelector/, `${file} ต้องไม่ค้นหา element`);
    });
});

test('เครื่องมือทุกตัวเป็นตรรกะบริสุทธิ์เช่นกัน', () => {
    fs.readdirSync('resources/js/pages/workspace/tools')
        .filter((file) => file.endsWith('.js'))
        .forEach((file) => {
            const code = read(`resources/js/pages/workspace/tools/${file}`)
                .replace(/\/\*[\s\S]*?\*\//g, '')
                .replace(/(^|[^:])\/\/.*$/gm, '$1');

            assert.doesNotMatch(code, /\bdocument\./, `tools/${file} ต้องไม่แตะ document`);
            assert.doesNotMatch(code, /querySelector/, `tools/${file} ต้องไม่ค้นหา element`);
        });
});

/*
 * หน้าวาดกับหน้ารายการต้องใช้กล่องแก้ไขใบเดียวกัน ไม่ใช่คนละชุด
 * CLAUDE.md ระบุว่าพฤติกรรมร่วมต้องมีแหล่งความจริงเดียว
 */
test('หน้าวาดใช้กล่องแก้ไขและคำสั่งลบชุดเดียวกับหน้ารายการ', () => {
    const board = read(BLADE.board);
    const editor = read(JS.editor);

    assert.match(board, /data-workspace-board-settings/);
    assert.match(board, /data-workspace-board-delete/);
    assert.match(board, /board-settings-modal/, 'หน้าวาดต้อง include กล่องใบเดียวกัน');

    assert.match(editor, /from '\.\/board-settings\.js'/, 'ต้อง import จากโมดูลร่วม');
    assert.match(editor, /createBoardModal/);
    assert.match(editor, /confirmDeleteBoard/);

    // และต้องไม่มีตัวควบคุมกล่องชุดที่สองในไฟล์ของหน้าวาด
    assert.doesNotMatch(editor, /data-workspace-modal-dismiss/);
});

/*
 * ค่าต่ำสุด/สูงสุดของขนาดตัวอักษรต้องตรงกันระหว่างหน้าจอกับตัวกรองฝั่งเซิร์ฟเวอร์
 * ถ้าหน้าจอกว้างกว่า ผู้ใช้จะกรอกค่าที่ถูกเซิร์ฟเวอร์ปัดทิ้งเงียบ ๆ แล้วขนาด
 * จะเด้งกลับหลังรีเฟรชโดยไม่มีอะไรอธิบาย
 */
test('ช่วงขนาดตัวอักษรบนหน้าจอตรงกับที่เซิร์ฟเวอร์ยอมรับ', () => {
    const design = read('app/Support/WorkspaceDesign.php');
    const validator = read('app/Support/WorkspaceDocumentValidator.php');

    const min = Number(design.match(/MIN_FONT_SIZE = (\d+)/)[1]);
    const max = Number(design.match(/MAX_FONT_SIZE = (\d+)/)[1]);

    // ตัวกรองหนีบ fontSize ด้วย self::integer($..., 8, 96) ทั้งของโน้ตและข้อความ
    const bounds = [...validator.matchAll(/fontSize'\] \?\? \d+, (\d+), (\d+)\)/g)];

    assert.equal(bounds.length > 0, true, 'ต้องเจอการหนีบค่าในตัวกรอง');

    bounds.forEach(([, low, high]) => {
        assert.equal(Number(low), min);
        assert.equal(Number(high), max);
    });
});
