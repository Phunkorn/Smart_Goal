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

test('รายการไฟล์ในกล่องไฟล์แนบของบอร์ดก็บีบแถวเหมือนกัน', async () => {
    const css = await read('resources/css/pages/mytasks/project-board.css');

    // กล่องนี้ใช้สามคอลัมน์ (ไอคอน / ชื่อ / ปุ่มเปิดไฟล์) ช่องชื่อต้องเป็นช่องที่หดได้
    assert.match(
        declaration(css, '.board-attachment-modal__list>a', 'grid-template-columns') || '',
        /minmax\(\s*0\s*,\s*1fr\s*\)/,
    );
});

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
