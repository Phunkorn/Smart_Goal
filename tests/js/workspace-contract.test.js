import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {workspaceCss, workspaceCssManifest} from './helpers/workspace-css.js';

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
    toolbarContext: 'resources/views/workspace/components/board-toolbar-context.blade.php',
    contextMenu: 'resources/views/workspace/components/board-context-menu.blade.php',
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
    menu: 'resources/js/pages/workspace/menu.js',
    contextMenu: 'resources/js/pages/workspace/context-menu.js',
};

/*
 * แถบเครื่องมือถูกแยกเป็นสองไฟล์: แถวหลัก (เครื่องมือ/มุมมอง) กับแถวรูปแบบ
 * (สี ขนาดตัวอักษร การจัดบรรทัด) ข้อตรวจที่พูดถึง "ตัวควบคุมบนแถบ" จึงต้องอ่าน
 * ทั้งสองไฟล์ต่อกัน ไม่ใช่ไฟล์เดียวเหมือนตอนที่ทุกอย่างอยู่แถวเดียว
 */
const toolbarMarkup = () => read(BLADE.toolbar) + read(BLADE.toolbarContext);

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
    const blade = read(BLADE.board) + toolbarMarkup() + read(BLADE.contextMenu) + read(BLADE.stage);
    const js = read(JS.editor) + read(JS.autosave) + read(JS.attachments) + read(JS.toolbar);

    [
        'data-workspace-board',
        'data-workspace-toolbar',
        'data-workspace-stage',
        'data-workspace-layers',
        'data-workspace-preview',
        'data-workspace-selection',
        'data-workspace-save-state',
        'data-workspace-save-label',
        'data-workspace-refresh',
        'data-workspace-image-input',
        'data-custom-color',
        'data-font-size-input',
        'data-font-step',
        'data-bold-toggle',
        'data-italic-toggle',
        'data-letter-spacing-input',
        'data-letter-spacing-step',
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
    const toolbar = toolbarMarkup();

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
    const css = workspaceCss();
    const stage = css.slice(css.indexOf('.wsb-stage {'), css.indexOf('.wsb-canvas {'));

    assert.match(stage, /touch-action:\s*none/);
});

/*
 * ผืนผ้าใบต้องปิดการลากคลุมข้อความ และกล่องที่กำลังพิมพ์ต้องได้คืน
 *
 * วัดบน Chrome จริงแล้ว: ถ้าไม่ปิด การลากเลื่อนกระดานจะลากคลุมข้อความในกระดาษ
 * โน้ต (pointer-events: none กันไม่ได้ เพราะการเลือกข้อความไม่ได้ใช้การทดสอบ
 * การชนชุดเดียวกับ pointer) แล้วการกดครั้งถัดไปบนข้อความที่ถูกเลือกไว้จะกลายเป็น
 * การลากวางข้อความของเบราว์เซอร์ ซึ่งยิง pointercancel ทำให้ pointer.js จบท่าลาก
 * กลางทาง กระดานค้างทั้งที่เมาส์ยังเลื่อนอยู่
 *
 * เทสต์นี้ยืนยันได้แค่ว่ากฎยังอยู่ ไม่ได้ยืนยันพฤติกรรมของเบราว์เซอร์ ซึ่ง
 * jsdom พิสูจน์ให้ไม่ได้
 */
test('CSS ปิดการลากคลุมข้อความบนผืนผ้าใบ แต่คืนให้กล่องที่กำลังพิมพ์', () => {
    const css = workspaceCss();
    const stage = css.slice(css.indexOf('.wsb-stage {'), css.indexOf('.wsb-canvas {'));

    // ต้องไม่ยอมให้ -webkit-user-select เพียงตัวเดียวผ่าน เพราะ Safari ใช้ตัวนั้น
    // แต่ Chrome กับ Firefox อ่านเฉพาะชื่อมาตรฐาน ขาดตัวใดตัวหนึ่งก็มีเบราว์เซอร์
    // ที่ยังลากคลุมข้อความได้อยู่
    assert.match(stage, /(?<![-\w])user-select:\s*none/);
    assert.match(stage, /-webkit-user-select:\s*none/);

    const editing = css.slice(css.indexOf('.wsb-note.is-editing,'));

    assert.match(editing, /(?<![-\w])user-select:\s*text/);
    assert.match(editing, /-webkit-user-select:\s*text/);
});

/*
 * ชั้นตัวอย่างและชั้นการเลือกต้องไม่ดักการคลิก ไม่งั้นจะบังผืนผ้าใบข้างใต้
 * และชั้น overlay ต้องปล่อยให้คลิกทะลุ ยกเว้นกล่องที่กำลังแก้ไขอยู่
 */
test('CSS ปล่อยให้การคลิกทะลุชั้นที่ไม่ควรดักไว้', () => {
    const css = workspaceCss();

    assert.match(css, /\.wsb-preview,\s*\n\.wsb-selection \{[^}]*pointer-events:\s*none/);
    assert.match(css, /\.wsb-overlay \{[^}]*pointer-events:\s*none/s);
    assert.match(css, /\.wsb-note\.is-editing,\s*\n\.wsb-textbox\.is-editing \{[^}]*pointer-events:\s*auto/);
});

test('CSS มีจุดเปลี่ยนสำหรับจอเล็กและเป้าหมายการแตะที่ใหญ่พอ', () => {
    const css = workspaceCss();

    assert.match(css, /@media \(max-width: 768px\)/);
    assert.match(css, /@media \(max-width: 576px\)/);

    // 2.75rem = 44px ซึ่งเป็นขนาดขั้นต่ำที่แตะได้ถนัดบนอุปกรณ์สัมผัส
    assert.match(css, /min-width:\s*2\.75rem/);
    assert.match(css, /height:\s*2\.75rem/);
});

test('CSS เคารพการตั้งค่าลดการเคลื่อนไหวของผู้ใช้', () => {
    assert.match(workspaceCss(), /@media \(prefers-reduced-motion: reduce\)/);
});

/*
 * ปุ่มที่แก้เนื้อหาต้องถูกทำเครื่องหมายไว้ให้ toolbar.js ปิดได้ทั้งชุด
 * ส่วนปุ่มมุมมองต้องไม่ถูกทำเครื่องหมาย เพราะผู้ที่ดูอย่างเดียวต้องซูมได้
 */
test('ปุ่มบนแถบเครื่องมือแยกชัดระหว่างปุ่มที่แก้เนื้อหากับปุ่มมุมมอง', () => {
    // กลุ่มมุมมองเป็นกลุ่มสุดท้ายของแถวหลัก ส่วนแถวรูปแบบอยู่อีกไฟล์ (ตัวควบคุม
    // รูปแบบทุกตัวแก้เนื้อหา จึงมี data-requires-edit ทั้งแถวโดยชอบธรรม)
    const toolbar = read(BLADE.toolbar);
    const viewGroup = toolbar.slice(toolbar.indexOf('aria-label="มุมมอง"'));

    assert.match(toolbar, /data-requires-edit/);
    assert.doesNotMatch(viewGroup, /data-requires-edit/, 'ปุ่มมุมมองต้องใช้ได้กับผู้ที่ดูอย่างเดียว');
    assert.match(read('resources/js/pages/workspace/toolbar.js'), /data-requires-edit/);
});

/*
 * ข้อตรวจข้างบนอาศัยว่ากลุ่มมุมมองอยู่ท้ายไฟล์ ซึ่งพังเงียบได้ถ้ามีใครสลับลำดับ
 * กลุ่ม ข้อนี้จึงตรวจเจตนาตรง ๆ ทีละปุ่ม ไม่พึ่งลำดับของข้อความในไฟล์
 */
test('ปุ่มมุมมองทุกปุ่มใช้ได้กับผู้ที่ดูอย่างเดียว ไม่ว่าจะอยู่ตำแหน่งไหนในไฟล์', () => {
    const markup = toolbarMarkup();

    ['zoom-in', 'zoom-out', 'zoom-reset', 'fit', 'fullscreen'].forEach((command) => {
        const button = markup.match(new RegExp(`<button[^>]*data-command="${command}"[^>]*>`));

        assert.ok(button, `ต้องมีปุ่มของคำสั่ง ${command}`);
        assert.doesNotMatch(
            button[0],
            /data-requires-edit/,
            `${command} เป็นคำสั่งมุมมอง ต้องไม่ถูกปิดสำหรับผู้ที่ดูอย่างเดียว`
        );
    });
});

/*
 * รายการในเมนูคลิกขวาถูกเรนเดอร์จากลูป ชื่อคำสั่งจึงไม่ปรากฏเป็นตัวอักษรใน Blade
 * ธงที่ตัดสินว่ารายการไหนถูกปิดสำหรับผู้ที่ดูอย่างเดียวคือ 'edit' ใน CANVAS_MENU
 * (Blade ใส่ data-requires-edit ตามธงนี้) จึงต้องตรวจที่ต้นทาง
 */
test('เมนูคลิกขวาใส่ data-requires-edit ตามธง edit และคำสั่งมุมมองไม่ถูกปิด', () => {
    const design = read('app/Support/WorkspaceDesign.php');
    const menu = read(BLADE.contextMenu);
    const block = design.match(/const CANVAS_MENU = \[([\s\S]*?)\n    \];/)[1];

    assert.match(menu, /@if \(\$item\['edit'\]\) data-requires-edit @endif/);

    // เลื่อนไปหาเป็นคำสั่งมุมมอง ไม่แก้เอกสาร ผู้ที่ดูอย่างเดียวต้องใช้ได้
    assert.match(block, /'command' => 'center'[^\]]*'edit' => false/);

    // ส่วนคำสั่งที่แก้เนื้อหาต้องถูกทำเครื่องหมายไว้ครบ
    ['duplicate', 'paste', 'delete', 'bring-to-front', 'send-to-back'].forEach((command) => {
        assert.match(
            block,
            new RegExp(`'command' => '${command}'[^\\]]*'edit' => true`),
            `${command} ต้องถูกทำเครื่องหมายว่าแก้เนื้อหา`
        );
    });
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
    [
        'scene.js',
        'geometry.js',
        'camera.js',
        'history.js',
        'simplify.js',
        'save-state.js',
        'rotation.js',
        'selection-frame.js',
        'clipboard.js',
        'toolbar-context.js',
    ].forEach((file) => {
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

/*
 * เช่นเดียวกับขนาดตัวอักษร ช่วงระยะห่างตัวอักษรบนหน้าจอต้องตรงกับตัวกรอง
 * ฝั่งเซิร์ฟเวอร์ ไม่งั้นค่าที่กรอกได้บนหน้าจอจะถูกปัดทิ้งเงียบ ๆ แล้วเด้งกลับ
 * หลังรีเฟรชโดยไม่มีอะไรอธิบาย
 */
test('ช่วงระยะห่างตัวอักษรบนหน้าจอตรงกับที่เซิร์ฟเวอร์ยอมรับ', () => {
    const design = read('app/Support/WorkspaceDesign.php');
    const toolbar = toolbarMarkup();

    const min = Number(design.match(/MIN_LETTER_SPACING = (-?\d+)/)[1]);
    const max = Number(design.match(/MAX_LETTER_SPACING = (-?\d+)/)[1]);

    assert.match(toolbar, /data-letter-spacing-input[\s\S]*?min="\{\{ \$design::MIN_LETTER_SPACING \}\}"/);
    assert.match(toolbar, /data-letter-spacing-input[\s\S]*?max="\{\{ \$design::MAX_LETTER_SPACING \}\}"/);
    assert.equal(Number.isFinite(min), true);
    assert.equal(Number.isFinite(max), true);
});

/*
 * ชนิดที่เก็บมุมหมุนต้องตรงกันทั้งสองฝั่ง ถ้าหน้าจอหมุนชนิดที่เซิร์ฟเวอร์ไม่รู้จัก
 * มุมจะถูกตัวกรองตัดทิ้งเงียบ ๆ แล้วชิ้นงานเด้งกลับมาตั้งตรงหลังรีเฟรช
 */
test('ชนิดที่เก็บมุมหมุนตรงกันระหว่าง rotation.js กับ WorkspaceDesign', () => {
    const design = read('app/Support/WorkspaceDesign.php');
    const rotation = read('resources/js/pages/workspace/rotation.js');

    const listFrom = (source, pattern) => [...source.match(pattern)[1].matchAll(/'([a-z]+)'/g)].map((match) => match[1]);

    assert.deepEqual(
        listFrom(rotation, /ROTATABLE_BOX_TYPES = \[([^\]]*)\]/),
        listFrom(design, /ROTATABLE_ELEMENT_TYPES = \[([^\]]*)\]/)
    );
});

/*
 * ป้าย tooltip กับปุ่มลัดที่ใช้งานจริงต้องมาจากที่เดียวกัน ถ้าแยกกัน วันหนึ่ง
 * tooltip จะบอก R แต่การกด R ไปเปิดเครื่องมืออื่น
 */
test('ปุ่มลัดบน tooltip และใน keyboard.js มาจาก WorkspaceDesign::TOOLS ชุดเดียวกัน', () => {
    const toolbar = read(BLADE.toolbar);
    const editor = read(JS.editor);

    assert.match(toolbar, /data-shortcut="\{\{ \$tool\['shortcut'\] \}\}"/);
    assert.match(toolbar, /aria-keyshortcuts="\{\{ \$tool\['shortcut'\] \}\}"/);
    assert.match(editor, /toolShortcutsFrom\(design\.tools\)/);
    assert.match(read('app/Support/WorkspaceDesign.php'), /'tools' => self::TOOLS/);
});

test('ปุ่มหลักบนเดสก์ท็อปมีพื้นที่กดอย่างน้อย 40px และ tooltip ไม่ขึ้นบนจอสัมผัส', () => {
    const css = workspaceCss();
    const buttons = css.slice(css.indexOf('.wsb-tool,\n.wsb-swatch,'), css.indexOf('.wsb-tool:focus-visible'));

    assert.match(buttons, /min-width:\s*2\.5rem/);
    assert.match(buttons, /height:\s*2\.5rem/);
    assert.match(css, /@media \(hover: hover\) and \(min-width: 769px\) \{\s*\.wsb-toolbar \[data-tooltip\]/);
});

/*
 * สีลัดบนแถบต้องเป็นสีที่อยู่ในแผงสีทั้งหมดด้วย ไม่งั้นสีที่ผู้ใช้กดจากแถบจะไม่มี
 * วงแหวน "กำลังใช้อยู่" ในแผง และสองชุดจะค่อย ๆ เพี้ยนออกจากกัน
 */
test('สีลัดบนแถบเป็นส่วนหนึ่งของแผงสีทั้งหมดเสมอ', () => {
    const design = read('app/Support/WorkspaceDesign.php');
    const colorsOf = (name) => {
        const block = design.match(new RegExp(`const ${name} = \\[([\\s\\S]*?)\\];`))[1];

        return [...block.matchAll(/'(#[0-9a-f]{6})'/g)].map((match) => match[1]);
    };

    const palette = colorsOf('PALETTE');
    const sticky = colorsOf('STICKY_COLORS');

    assert.equal(colorsOf('QUICK_PALETTE').length > 0, true);
    colorsOf('QUICK_PALETTE').forEach((color) => assert.ok(palette.includes(color), `${color} ต้องอยู่ใน PALETTE`));
    colorsOf('QUICK_STICKY_COLORS').forEach((color) => assert.ok(sticky.includes(color), `${color} ต้องอยู่ใน STICKY_COLORS`));
});

test('แถบเครื่องมือวางแค่สีลัด ส่วนสีทั้งหมดและความหนาเส้นอยู่ในแผงที่ toolbar-popover.js ควบคุม', () => {
    const toolbar = toolbarMarkup();
    const popover = read('resources/js/pages/workspace/toolbar-popover.js');
    const toolbarJs = read(JS.toolbar);

    assert.match(toolbar, /\$design::QUICK_PALETTE/);
    assert.match(toolbar, /\$design::QUICK_STICKY_COLORS/);

    ['data-picker', 'data-picker-toggle', 'data-picker-panel'].forEach((hook) => {
        assert.match(toolbar, new RegExp(hook), `Blade ต้องมี ${hook}`);
        assert.match(popover, new RegExp(hook), `toolbar-popover.js ต้องอ้าง ${hook}`);
    });

    ['stroke', 'sticky', 'width'].forEach((name) => {
        assert.match(toolbar, new RegExp(`data-picker-current="${name}"`), `Blade ต้องมีตัวบอกค่าปัจจุบัน ${name}`);
        assert.match(toolbarJs, new RegExp(`data-picker-current="${name}"`), `toolbar.js ต้องอัปเดต ${name}`);
    });

    // ชุดเต็มอยู่ในแผงเท่านั้น ไม่ใช่วางค้างไว้บนแถบอีกชุด
    assert.equal((toolbar.match(/data-picker-panel/g) || []).length, 3);
    assert.equal((toolbar.match(/\$design::PALETTE as/g) || []).length, 1);
    assert.equal((toolbar.match(/\$design::STICKY_COLORS as/g) || []).length, 1);
    assert.equal((toolbar.match(/\$design::STROKE_WIDTHS as/g) || []).length, 1);
});

/*
 * Ctrl+V ต้องไปถึงเบราว์เซอร์ ถ้าดัก keydown ไว้ เบราว์เซอร์จะไม่ยิง paste แล้ว
 * รูปที่แคปหน้าจอมาจะวางไม่ได้ (บั๊กที่พบในเบราว์เซอร์จริง)
 */
test('การคัดลอก/วางผ่านเหตุการณ์ copy และ paste ที่ระดับเอกสาร ไม่ใช่ที่ผืนผ้าใบหรือ keydown', () => {
    const events = read('resources/js/pages/workspace/clipboard-events.js');
    const keyboard = read('resources/js/pages/workspace/keyboard.js');
    const attachments = read(JS.attachments);

    assert.match(events, /doc\.addEventListener\('copy'/);
    assert.match(events, /doc\.addEventListener\('paste'/);
    assert.doesNotMatch(keyboard, /case 'v':/);
    assert.doesNotMatch(keyboard, /case 'c':/);
    assert.doesNotMatch(attachments, /addEventListener\('paste'/, 'ต้องมีตัวฟังการวางจุดเดียว');
});

test('แผงสีเป็น popover แบบ fixed และประกาศ hidden ซ้ำไม่ให้ display: grid ชนะ', () => {
    const css = workspaceCss();

    assert.match(css, /\.wsb-popover \{[^}]*position:\s*fixed/);
    assert.match(css, /\.wsb-popover\[hidden\] \{\s*display:\s*none/);
});

/*
 * ป้ายสถานะเคยกระพริบสามสีทุกครั้งที่ขีดเส้น (ยังไม่ได้บันทึก -> กำลังบันทึก -> บันทึกแล้ว)
 * ช่วงหน่วงก่อนบันทึกจึงต้องหน้าตาเดียวกับตอนกำลังบันทึก
 */
test('ช่วงรอบันทึกกับช่วงกำลังบันทึกแสดงป้ายเดียวกัน', () => {
    const design = read('app/Support/WorkspaceDesign.php');
    const stateOf = (name) => design.match(new RegExp(`'${name}' => (\\[[^\\]]*\\])`))[1];

    assert.equal(stateOf('dirty'), stateOf('saving'));
});

/*
 * ป้ายสถานะการบันทึกเริ่มแบบซ่อน autosave.js เปิดเฉพาะตอนบันทึกไม่สำเร็จหรือชนเวอร์ชัน
 * ต้องประกาศ [hidden] ซ้ำใน CSS เพราะ display: inline-flex ชนะ attribute hidden
 */
test('ป้ายสถานะการบันทึกเริ่มแบบซ่อน และซ่อนได้จริงใน CSS', () => {
    const board = read(BLADE.board);
    const css = workspaceCss();

    assert.match(board, /data-workspace-save-state[^>]*role="status" hidden>/);
    assert.match(css, /\.wsb-save\[hidden\] \{\s*display:\s*none/);
    assert.match(read(JS.autosave), /VISIBLE_SAVE_STATES = \['error', 'conflict'\]/);
});

test('ปุ่มบนหัวกระดานมีชื่อใน aria-label เพราะบนโทรศัพท์เหลือแค่ไอคอน', () => {
    const board = read(BLADE.board);

    ['data-workspace-refresh', 'data-workspace-board-settings', 'data-workspace-board-delete'].forEach((hook) => {
        assert.match(board, new RegExp(`${hook}[^>]*aria-label="[^"]+"`), `${hook} ต้องมี aria-label`);
    });
    assert.match(workspaceCss(), /\.ws-board__status \.ws-btn__text \{\s*display:\s*none/);
});

/*
 * workspace.css เป็นสารบัญ ไม่ใช่ที่เก็บกฎ
 *
 * ของเดิมเป็นก้อนเดียว 1,300 บรรทัด คนที่มาแก้ทีหลังจึงเติมกฎใหม่ไว้ท้ายไฟล์
 * แทนการแก้ของเดิม จนกฎของปุ่มชุดเดียวกระจายอยู่สามที่ ข้อนี้กันไม่ให้วนกลับไป
 * เป็นแบบนั้นอีก
 */
test('workspace.css มีแต่สารบัญ @import ไม่มีกฎของตัวเอง', () => {
    const manifest = workspaceCssManifest()
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .trim();

    manifest.split('\n').filter((line) => line.trim()).forEach((line) => {
        assert.match(line.trim(), /^@import '\.\/workspace\/[a-z-]+\.css';$/, `บรรทัดนี้ไม่ใช่ @import: ${line}`);
    });

    assert.match(workspaceCssManifest(), /@import '\.\/workspace\/responsive\.css';\s*$/, 'responsive ต้องเป็นตัวสุดท้าย');
});

/*
 * เมนูคลิกขวาไม่มีตรรกะของตัวเอง ทุกรายการชี้ไปที่คำสั่งเดียวกับที่แถบเครื่องมือ
 * และปุ่มลัดใช้ ถ้ามีคนเพิ่มรายการใหม่ใน CANVAS_MENU แล้วลืมเพิ่ม case เมนูจะมี
 * ปุ่มที่กดแล้วไม่เกิดอะไรโดยไม่มี error ให้เห็น
 */
test('ทุกคำสั่งในเมนูคลิกขวามี case รองรับใน runCommand', () => {
    const design = read('app/Support/WorkspaceDesign.php');
    const editor = read(JS.editor);
    const block = design.match(/const CANVAS_MENU = \[([\s\S]*?)\n    \];/)[1];
    const commands = [...block.matchAll(/'command' => '([a-z-]+)'/g)].map((match) => match[1]);

    assert.equal(commands.length > 0, true, 'ต้องอ่านคำสั่งออกมาได้');

    commands.forEach((command) => {
        assert.match(editor, new RegExp(`case '${command}':`), `runCommand ต้องมี case '${command}'`);
    });
});

/*
 * เมนูทั้งสองชุด (เมนูบนแถบ กับเมนูคลิกขวา) ต้องมีเจ้าของสถานะเปิดปิดคนเดียว
 * คือ menu.js ไม่ใช่ให้ context-menu.js ไปเปิดปิดแผงเองอีกทาง
 */
/*
 * คำสั่งที่ใช้บ่อยต้องอยู่ตื้น กดถึงในคลิกเดียว
 *
 * เคยยุบเครื่องมือลงเมนู "วาด"/"สร้าง" และซ่อนคำสั่งจัดลำดับชั้นในเมนูย่อย
 * แล้วผู้ใช้รายงานว่าหาไม่เจอและกดไม่ติด ข้อนี้กันไม่ให้ความลึกนั้นกลับมา
 */
test('ไม่มีเมนูซ้อนเมนูบนหน้านี้ และเครื่องมือที่ใช้บ่อยอยู่บนแถบตรง ๆ', () => {
    const markup = toolbarMarkup() + read(BLADE.contextMenu);

    ['data-submenu', 'data-submenu-toggle', 'data-submenu-panel'].forEach((hook) => {
        assert.doesNotMatch(markup, new RegExp(hook), `ต้องไม่มี ${hook} เหลืออยู่`);
    });

    assert.doesNotMatch(read(JS.menu), /data-submenu/, 'menu.js ต้องไม่เหลือโค้ดเมนูย่อย');

    const design = read('app/Support/WorkspaceDesign.php');
    const primary = design.match(/const PRIMARY_TOOLS = \[([^\]]*)\]/)[1];

    ['pen', 'eraser', 'sticky', 'text', 'select', 'hand'].forEach((tool) => {
        assert.match(primary, new RegExp(`'${tool}'`), `${tool} ต้องอยู่บนแถบตรง ๆ`);
    });

    // เหลือเมนูเดียวคือรูปทรง และปุ่มของมันต้องสลับไอคอนได้
    assert.equal((design.match(/const TOOL_MENUS = /g) || []).length, 1);
    assert.match(toolbarMarkup(), /data-menu-icon/);
    assert.match(toolbarMarkup(), /data-tool-icon="bi \{\{ \$tool\['icon'\] \}\}"/);
    assert.match(read(JS.toolbar), /data-menu-icon/);
});

test('เมนูมีเจ้าของสถานะเปิดปิดเพียงตัวเดียว', () => {
    const menu = read(JS.menu);
    const contextMenu = read(JS.contextMenu);

    assert.match(menu, /new WeakSet\(\)/, 'menu.js ต้องกัน listener ซ้อนเหมือน toolbar-popover.js');
    assert.match(contextMenu, /from '\.\/menu\.js'|menus\./, 'context-menu.js ต้องเปิดแผงผ่าน menu.js');
    assert.doesNotMatch(contextMenu, /\.hidden = false/, 'context-menu.js ต้องไม่เปิดแผงเอง');
});

/*
 * ทุกไอคอนที่ WorkspaceDesign ประกาศต้องมีอยู่จริงในชุด Bootstrap Icons
 *
 * ชื่อไอคอนที่สะกดผิดหรือไม่มีในชุดจะไม่ทำให้อะไรพัง ไม่มี error ไม่มีคำเตือน
 * ปุ่มแค่ "ว่างเปล่า" เฉย ๆ ซึ่งผู้ใช้รายงานมาจริงกับปุ่มรูปทรงที่เคยตั้งเป็น
 * bi-shapes ทั้งที่ Bootstrap Icons ไม่มีไอคอนชื่อนั้น ปุ่มจึงไม่สื่ออะไรเลย
 *
 * อ่านจากรายการไอคอนของแพ็กเกจจริง ไม่ใช่รายชื่อที่เขียนซ้ำไว้ในเทสต์ ซึ่งจะ
 * ล้าสมัยทันทีที่อัปเกรดแพ็กเกจ
 */
test('ไอคอนทุกตัวที่ประกาศไว้มีอยู่จริงในชุด Bootstrap Icons', () => {
    const catalogue = JSON.parse(read('node_modules/bootstrap-icons/font/bootstrap-icons.json'));
    const sources = [
        read('app/Support/WorkspaceDesign.php'),
        read(BLADE.board),
        toolbarMarkup(),
        read(BLADE.contextMenu),
        read(BLADE.stage),
    ].join('\n');

    const names = [...new Set(sources.match(/bi-[a-z0-9-]+/g) ?? [])];

    assert.equal(names.length > 10, true, 'ต้องอ่านชื่อไอคอนออกมาได้');

    names.forEach((name) => {
        assert.equal(
            Object.hasOwn(catalogue, name.slice(3)),
            true,
            `${name} ไม่มีอยู่ใน Bootstrap Icons ปุ่มที่ใช้ไอคอนนี้จะว่างเปล่า`
        );
    });
});

/*
 * ปุ่มลัดจัดบรรทัดต้องปรากฏบนปุ่มจริง ไม่ใช่มีแต่ใน JavaScript
 *
 * ปุ่มลัดที่ไม่มีอะไรบอกก็เท่ากับไม่มี ผู้ใช้ไม่มีทางเดาได้ว่ามีอยู่ ป้ายบนปุ่ม
 * (data-shortcut) กับ aria-keyshortcuts จึงต้องตรงกับที่ keyboard.js รับจริง
 */
test('ปุ่มจัดบรรทัดโฆษณาปุ่มลัดที่ keyboard.js รับจริง', () => {
    const toolbar = toolbarMarkup();
    const keyboard = read('resources/js/pages/workspace/keyboard.js');

    [
        ['left', 'L'],
        ['center', 'E'],
        ['right', 'R'],
    ].forEach(([align, letter]) => {
        assert.match(
            toolbar,
            new RegExp(String.raw`data-align="${align}"[\s\S]*?data-shortcut="Ctrl\+Shift\+${letter}"`),
            `ปุ่ม ${align} ต้องแสดงปุ่มลัด Ctrl+Shift+${letter}`
        );
        assert.match(
            keyboard,
            new RegExp(String.raw`case '${letter.toLowerCase()}':[\s\S]*?align-${align}`),
            `keyboard.js ต้องรับ ${letter} เป็น align-${align}`
        );
        assert.match(read(JS.editor), new RegExp(`case 'align-${align}':`));
    });
});

/*
 * ไอคอนบนหน้านี้ต้องเป็นแบบเส้นขอบเหมือนกันทั้งหมด ห้ามมีไอคอนทึบตันปน
 *
 * Bootstrap Icons มีคู่แฝดแบบทึบของเกือบทุกตัว (ลงท้าย -fill) และมีตัวทึบใน
 * ตระกูลรูปทรงซ้อนกันอีกสามตัว ไอคอนทึบตัวเดียวบนแถบที่เหลือเป็นเส้นขอบทั้งหมด
 * จะกลายเป็นก้อนดำที่เด่นผิดพวก จนผู้ใช้เข้าใจว่าปุ่มนั้นถูกเลือกค้างอยู่
 * ซึ่งเป็นสิ่งที่ผู้ใช้รายงานมาจริงกับปุ่มรูปทรง
 *
 * ถ้าวันหนึ่งจำเป็นต้องใช้ไอคอนทึบจริง ๆ ให้แก้ข้อนี้พร้อมเหตุผล ไม่ใช่แอบใส่
 * เข้ามาเงียบ ๆ ทีละตัวจนแถบกลับมาไม่สม่ำเสมออีก
 */
test('ไม่มีไอคอนทึบตันปนบนแถบเครื่องมือและเมนู', () => {
    const SOLID = ['bi-union', 'bi-exclude', 'bi-subtract'];
    const sources = [
        read('app/Support/WorkspaceDesign.php'),
        read(BLADE.board),
        toolbarMarkup(),
        read(BLADE.contextMenu),
        read(BLADE.stage),
    ].join('\n');

    const used = [...new Set(sources.match(/bi-[a-z0-9-]+/g) ?? [])];

    used.forEach((name) => {
        assert.equal(
            name.endsWith('-fill'),
            false,
            `${name} เป็นไอคอนทึบ (-fill) ต้องใช้รุ่นเส้นขอบแทน`
        );
        assert.equal(
            SOLID.includes(name),
            false,
            `${name} เป็นไอคอนทึบตัน จะกลายเป็นก้อนดำที่เด่นผิดพวกบนแถบ`
        );
    });
});

/*
 * ผืนผ้าใบต้องไม่ฝังรูปเป็นเคอร์เซอร์
 *
 * เคยทำเป็นรูปดินสอกับยางลบฝังเป็น SVG แล้วออกมาเพี้ยนทุกครั้งที่ลองบนจอจริง
 * (บางไปก็จางเหมือนภาพผี หนาไปขอบก็ล้อมทั้งเส้นนอกเส้นในจนเป็นรูปซ้อนรูป)
 * เจ้าของงานให้ถอดออกหลังลองจริงสองรอบ และให้ไอคอนบนแถบเครื่องมือเป็นตัวบอกแทน
 *
 * ข้อนี้กันไม่ให้ใครเผลอใส่กลับเข้ามาอีก เพราะเป็นความเสียหายที่ไม่มี error
 * และไม่มีเทสต์อื่นจับได้เลย ต้องเห็นด้วยตาบนจอจริงเท่านั้น
 */
test('เคอร์เซอร์ของผืนผ้าใบใช้ค่ามาตรฐานเท่านั้น ไม่ฝังรูปเข้ามา', () => {
    const css = workspaceCss();

    assert.doesNotMatch(css, /cursor:[^;]*url\(/, 'ห้ามฝังรูปเป็นเคอร์เซอร์');
    assert.doesNotMatch(css, /data:image\/svg/, 'ห้ามมี SVG ฝังในไฟล์ CSS ของหน้านี้');

    // เครื่องมือที่ระบบมีเคอร์เซอร์ตรงความหมายอยู่แล้ว ยังต้องได้ของมันตามเดิม
    [
        ['select', 'default'],
        ['hand', 'grab'],
        ['text', 'text'],
    ].forEach(([tool, expected]) => {
        assert.match(
            css,
            new RegExp(String.raw`\.wsb-stage\[data-tool="${tool}"\] \{\s*cursor:\s*${expected};`),
            `เครื่องมือ ${tool} ต้องใช้เคอร์เซอร์ ${expected}`
        );
    });

    // ที่เหลือ (รวมดินสอกับยางลบ) ตกมาที่ค่าปริยายของผืนผ้าใบ
    assert.match(css, /\.wsb-stage\[data-tool\] \{\s*cursor:\s*crosshair;/);
});

/*
 * ไอคอนที่ลอยตามเมาส์ต้องต่อสายครบทั้งสามฝั่ง และต้องสั่งซ่อนเองใน CSS
 *
 * ชิปถูกประกาศ display ไว้ในไฟล์เดียวกัน ซึ่งชนะ [hidden] ของเบราว์เซอร์ ถ้าไม่มี
 * กฎซ่อนคู่กันไว้ ชิปจะค้างอยู่มุมซ้ายบนของกระดานตลอดเวลาแม้ไม่ได้ถือดินสอ
 *
 * ชื่อไอคอนต้องมาจาก WorkspaceDesign ผ่าน island เท่านั้น ไฟล์ .js ห้ามรู้จัก
 * ชื่อไอคอนเอง ตามกติกาเดียวกับป้ายภาษาไทย
 */
test('ไอคอนเครื่องมือที่ลอยตามเมาส์ต่อสายครบและซ่อนตัวเองได้', () => {
    const stage = read(BLADE.stage);
    const js = read('resources/js/pages/workspace/tool-cursor.js');
    const css = workspaceCss();

    ['data-workspace-tool-cursor', 'data-workspace-tool-cursor-icon'].forEach((hook) => {
        assert.match(stage, new RegExp(hook), `Blade ต้องมี ${hook}`);
    });

    assert.match(read(JS.editor), /data-workspace-tool-cursor/, 'index.js ต้องต่อสายชิปนี้');
    assert.match(read(JS.editor), /pointerTools/, 'รายชื่อเครื่องมือต้องมาจาก island');
    assert.match(
        read('app/Support/WorkspaceDesign.php'),
        /'pointerTools' => self::POINTER_TOOLS/,
        'WorkspaceDesign ต้องส่ง pointerTools ให้ฝั่งเบราว์เซอร์'
    );

    assert.doesNotMatch(js, /bi-[a-z]/, 'tool-cursor.js ห้ามรู้จักชื่อไอคอนเอง');
    assert.doesNotMatch(js, /'(pen|eraser)'/, 'tool-cursor.js ห้ามรู้จักชื่อเครื่องมือเอง');

    assert.match(css, /\.wsb-tool-cursor\[hidden\] \{\s*display:\s*none;/, 'ต้องมีกฎซ่อนคู่กับ display');
    assert.match(css, /\.wsb-tool-cursor \{[^}]*pointer-events:\s*none/s, 'ชิปต้องไม่ดักการคลิก');

    /*
     * การซ่อนเคอร์เซอร์กับการวาดไอคอนเป็นฟีเจอร์เดียวกัน จึงต้องถูกสั่งจากที่
     * เดียวกัน ไม่ใช่แยกไปอยู่ในไฟล์ CSS ต่างหาก เคยแยกแล้วผู้ใช้เจอสถานะที่
     * ไอคอนขึ้นแล้วแต่เคอร์เซอร์ยังอยู่ ซึ่งเกิดไม่ได้ถ้าตั้งจากบรรทัดเดียวกัน
     */
    assert.match(js, /style\.cursor = next \? 'none' : ''/, 'ต้องซ่อนเคอร์เซอร์จากที่เดียวกับที่เปลี่ยนไอคอน');
    assert.doesNotMatch(css, /cursor:\s*none/, 'ไฟล์ CSS ต้องไม่มีเจ้าของที่สองของเรื่องนี้');
});
