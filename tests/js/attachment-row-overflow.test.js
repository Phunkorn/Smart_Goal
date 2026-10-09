import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {mountDom, click} from './helpers/dom.js';

/*
 * ชื่อไฟล์ยาวต้องไม่ดันไอคอนท้ายแถวให้หลุดออกนอกกล่อง
 *
 * ปัญหาเดิม: ทุกรายการไฟล์แนบเป็นกริดคอลัมน์เดียวแบบ auto และแถวยังเป็น min-width: auto
 * ขนาดต่ำสุดของแถวจึงเท่ากับความกว้างของชื่อไฟล์เต็ม ๆ (span ตั้ง white-space: nowrap)
 * ชื่อไฟล์ยาวดันแถวให้กว้างกว่าการ์ด แล้วไอคอนดาวน์โหลด/ลบที่อยู่ท้ายแถว
 * ถูกผลักออกนอกขอบจนมองไม่เห็น ทั้งที่ span มี text-overflow: ellipsis อยู่แล้ว
 * (ellipsis ไม่ช่วย เพราะกริดยอมให้ไอเท็มล้นรางได้ตั้งแต่ตอนคิดขนาดราง)
 *
 * jsdom ไม่คำนวณ layout จึงพิสูจน์ความกว้างจริงไม่ได้ test นี้ตรึงกฎ CSS ที่เป็นตัวแก้
 * ส่วนภาพจริงต้องดูบนเบราว์เซอร์
 */

const read = async (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

/** อ่านค่า property จากกฎที่ selector ตรงเป๊ะ รองรับทั้ง CSS จัดรูปแบบและแบบย่อ */
function declaration(source, selector, property) {
    // ตัดคอมเมนต์ทิ้งก่อน ไม่งั้นมันจะติดมากับ selector ของกฎถัดไป
    const rules = [...source.replace(/\/\*[\s\S]*?\*\//g, '').matchAll(/([^{}]+)\{([^{}]*)\}/g)]
        .filter(([, head]) => head.split(',').some((part) => part.trim() === selector));

    for (const [, , body] of rules.reverse()) {
        const found = body.match(new RegExp(`(?:^|;)\\s*${property}\\s*:([^;]+)`));
        if (found) return found[1].trim();
    }

    return null;
}

const LISTS = [
    {
        name: 'การ์ดไฟล์แนบใน Task Workspace',
        file: 'resources/css/components/task-workspace/workspace-modal.css',
        list: '.task-workspace .task-inline-files',
        row: '.task-workspace .task-inline-files article',
    },
    {
        name: 'กล่องเลือกดาวน์โหลดไฟล์แนบ',
        file: 'resources/css/components/task-workspace/attachments.css',
        list: '.attachment-download-modal__list',
        row: '.attachment-download-modal__row',
    },
    {
        // กล่องนี้ใช้กฎก้อนเดียวกับกล่องดาวน์โหลด จึงอ่านจากไฟล์เดียวกัน
        name: 'กล่องไฟล์แนบของบอร์ด',
        file: 'resources/css/components/task-workspace/attachments.css',
        list: '.board-attachment-modal__list',
        row: '.board-attachment-modal__row',
    },
];

for (const {name, file, list, row} of LISTS) {
    test(`${name}: รางของรายการไฟล์บีบแถวได้ และแถวยอมหดต่ำกว่าความยาวชื่อไฟล์`, async () => {
        const css = await read(file);

        assert.match(
            declaration(css, list, 'grid-template-columns') || '',
            /minmax\(\s*0\s*,\s*1fr\s*\)/,
            `${list} ต้องประกาศคอลัมน์เป็น minmax(0, 1fr) ไม่ใช่ auto`,
        );
        assert.equal(
            declaration(css, row, 'min-width'),
            '0',
            `${row} ต้องตั้ง min-width: 0 ไม่งั้นแถวกว้างตามชื่อไฟล์เต็ม`,
        );
    });
}

/*
 * ชิ้นส่วนของแถวไฟล์ในกล่องไฟล์แนบของบอร์ด กับ property ที่เป็นหัวใจของชิ้นนั้น
 *
 * ทุก selector นับจาก .board-attachment-modal__row เพราะ JS ห่อลิงก์ไว้ในแถว
 * (ที่ว่างของปุ่มลบ) ลิงก์จึงไม่ใช่ลูกตรงของ list
 */
const BOARD_FILE_PARTS = [
    ['.board-attachment-modal__row > a', 'flex'],
    ['.board-attachment-modal__row > a > i:first-child', 'font-size'],
    ['.board-attachment-modal__row > a > span', 'text-overflow'],
    ['.board-attachment-modal__row > a > i:last-child', 'width'],
];

/* ---------- ปุ่มดาวน์โหลดในกล่องไฟล์แนบของบอร์ด ---------- */

/** markup ย่อที่ตรงกับ resources/views/tasks/partials/workspace-interactions.blade.php */
async function bootBoard(t, files) {
    const env = mountDom();
    t.after(env.cleanup);

    env.document.body.innerHTML = `
        <meta name="csrf-token" content="test-token">
        <div data-workspace>
            <div data-project-board>
                <div data-board-list-body>
                    <button type="button" data-board-open-attachments="7">ไฟล์แนบ</button>
                </div>
            </div>
        </div>
        <div class="notion-modal board-attachment-modal" data-board-attachment-modal hidden>
            <section class="board-attachment-modal__card">
                <header>
                    <div><small data-board-attachment-topic></small></div>
                    <div class="board-attachment-modal__actions">
                        <button type="button" class="task-workspace__panel-button" data-open-attachment-download data-task-id="" hidden>ดาวน์โหลด</button>
                        <button type="button" data-close-board-attachments aria-label="ปิด">x</button>
                    </div>
                </header>
                <div class="board-attachment-modal__body">
                    <div class="board-attachment-modal__list" data-board-attachment-list></div>
                    <div data-board-attachment-empty hidden></div>
                    <div data-board-attachment-upload>
                        <label><input type="file" multiple data-board-modal-attachment-input></label>
                    </div>
                    <p data-board-attachment-locked hidden></p>
                </div>
            </section>
        </div>
        <script type="application/json" data-attachment-data>${JSON.stringify({
            7: {id: 7, topic: 'งานทดสอบ', can_upload: true, upload_url: '/tasks/7/attachments', files},
        })}</script>`;

    env.window.fetch = async () => ({ok: true, json: async () => ({ok: true, files})});
    globalThis.fetch = env.window.fetch;
    env.window.Swal = {fire: async () => ({isConfirmed: true})};

    await import(`../../resources/js/mytasks-project-board.js?rows=${files.length}-${Date.now()}`);

    click(env.document.querySelector('[data-board-open-attachments]'));

    return {
        ...env,
        download: env.document.querySelector('[data-board-attachment-modal] [data-open-attachment-download]'),
    };
}

test('กล่องไฟล์แนบของบอร์ดเปิดปุ่มดาวน์โหลดพร้อมบอกว่าเป็นงานไหน', async (t) => {
    const ui = await bootBoard(t, [
        {name: 'สรุปงาน.docx', url: '/media/task-attachments/1', download_url: '/media/task-attachments/1?download=1'},
    ]);

    // โมดูลกล่องดาวน์โหลดอ่านงานที่เปิดอยู่จาก dataset ของปุ่มที่กด จึงต้องถูกเขียนทุกครั้งที่เปิดกล่อง
    assert.equal(ui.download.hidden, false);
    assert.equal(ui.download.dataset.taskId, '7');
});

test('งานที่ยังไม่มีไฟล์แนบไม่ต้องมีปุ่มดาวน์โหลดให้กด', async (t) => {
    const ui = await bootBoard(t, []);

    assert.equal(ui.download.hidden, true);
});

/*
 * กฎหน้าตาต้องจับ markup จริงที่ JS สร้าง
 *
 * ของเดิมเขียนเป็น `.board-attachment-modal__list > a` ตั้งแต่ตอนที่ลิงก์ยังเป็นลูกตรงของ list
 * พอเพิ่มปุ่มลบ ลิงก์ถูกย้ายไปอยู่ใต้ .board-attachment-modal__row กฎจึงไม่จับอะไรเลย
 * ผลคือรายการไฟล์กลายเป็นลิงก์เปล่าเรียงลงมาเป็นคอลัมน์ ไม่มีกรอบ ไม่มีชิปไอคอน
 * และชื่อไฟล์ยาวไม่ตัดด้วย ellipsis ทั้งที่ไฟล์ CSS ยังมีกฎครบทุกบรรทัด
 *
 * jsdom ไม่คำนวณ layout แต่ตรวจได้ว่า selector ของกฎตรงกับอิลิเมนต์จริงหรือไม่
 */
test('กฎหน้าตาแถวไฟล์ของบอร์ดต้องตรงกับ markup ที่ JS สร้างจริง', async (t) => {
    // กฎของแถวนี้อยู่สองไฟล์: ส่วนที่ใช้ร่วมกับกล่องดาวน์โหลด และส่วนของบอร์ดเอง
    const css = [
        await read('resources/css/components/task-workspace/attachments.css'),
        await read('resources/css/pages/mytasks/project-board.css'),
    ].join('\n');

    const ui = await bootBoard(t, [
        {name: 'สรุปงาน.docx', url: '/media/task-attachments/1', download_url: '/media/task-attachments/1?download=1'},
    ]);

    const row = ui.document.querySelector('[data-board-attachment-list] .board-attachment-modal__row');
    assert.ok(row, 'กล่องต้องสร้างแถวไฟล์ออกมา');

    for (const [selector, property] of BOARD_FILE_PARTS) {
        assert.ok(declaration(css, selector, property), `CSS ต้องยังมีกฎ ${selector}`);

        const inner = selector.replace('.board-attachment-modal__row > ', '');
        assert.ok(row.querySelector(inner), `${selector} ต้องจับอิลิเมนต์จริงในแถว`);
    }
});

/*
 * ไอคอนต้องเป็นชุดเดียวกับกล่องดาวน์โหลดและการ์ดไฟล์แนบ
 *
 * ก่อนหน้านี้กล่องนี้ fix ไว้ที่ bi-file-earmark ใบเดียวทุกไฟล์ ทั้งที่ iconFor()/toneFor()
 * เป็นแหล่งความจริงร่วมอยู่แล้ว ผู้ใช้จึงแยกไม่ออกว่าไฟล์ไหนเป็น Word/Excel/รูป
 */
test('ไอคอนไฟล์ในกล่องของบอร์ดมาจากตารางชนิดไฟล์ชุดเดียวกับกล่องดาวน์โหลด', async (t) => {
    const ui = await bootBoard(t, [
        {name: 'สรุปงาน.docx', url: '/media/task-attachments/1'},
        {name: 'ยอดขาย.xlsx', url: '/media/task-attachments/2'},
        {name: '123.jpg', url: '/media/task-attachments/3'},
        {name: 'แบบแปลน.pdf', url: '/media/task-attachments/4'},
        {name: 'ไฟล์ไม่รู้จัก.bin', url: '/media/task-attachments/5'},
    ]);

    const icons = [...ui.document.querySelectorAll('[data-board-attachment-list] a > i:first-child')]
        .map((icon) => icon.className);

    assert.deepEqual(icons, [
        'bi bi-file-earmark-word file-tone-word',
        'bi bi-file-earmark-excel file-tone-excel',
        'bi bi-file-earmark-image file-tone-image',
        'bi bi-file-earmark-pdf file-tone-pdf',
        'bi bi-file-earmark file-tone-other',
    ]);
});

/*
 * โทนสีไอคอนต้องมีชุดเดียวในระบบ
 *
 * เคยถูกคัดลอกไว้ทั้งใน attachments.css และ workspace-modal.css แล้วหลุดจากกันจริง
 * (ชุดใน workspace-modal.css ขาด pdf กับ zip ไฟล์สองชนิดนั้นจึงเป็นสีเทาในการ์ดไฟล์แนบ
 * แต่เป็นสีแดง/ส้มในกล่องดาวน์โหลด)
 */
test('ตารางสีไอคอนตามชนิดไฟล์ถูกประกาศที่เดียว', async () => {
    const files = [
        'resources/css/components/task-workspace/attachments.css',
        'resources/css/components/task-workspace/workspace-modal.css',
        'resources/css/pages/mytasks/project-board.css',
    ];

    const heads = [];
    for (const file of files) {
        const source = (await read(file)).replace(/\/\*[\s\S]*?\*\//g, '');
        heads.push(...[...source.matchAll(/([^{}]*\.file-tone-[a-z]+)\s*\{/g)].map(([, head]) => head.trim()));
    }

    assert.deepEqual(heads, [
        '.file-tone-word',
        '.file-tone-excel',
        '.file-tone-ppt',
        '.file-tone-image',
        '.file-tone-pdf',
        '.file-tone-zip',
        '.file-tone-other',
    ]);
});
