import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {mountDom, click, clickCheckbox} from './helpers/dom.js';
import {publishTaskFiles} from '../../resources/js/pages/mytasks/attachment-store.js';
import {
    DOWNLOAD_INTERVAL_MS,
    downloadLabel,
    fileKey,
    initAttachmentDownload,
    pruneKeys,
    selectAllState,
    selectedFiles,
    selectionLabel,
    toggleKey,
} from '../../resources/js/pages/mytasks/attachment-download.js';

/*
 * กล่อง "ดาวน์โหลดไฟล์แนบ" ของ Task Workspace
 *
 * ปัญหาเดิม: การ์ดไฟล์แนบมีแต่ลิงก์เปิดดูไฟล์ ไม่มีปุ่มดาวน์โหลด และเลือกหลายรายการ
 * พร้อมกันไม่ได้ ผู้ใช้ที่ต้องเก็บเอกสารทั้งงานจึงต้องเปิดทีละไฟล์แล้วสั่งบันทึกเอง
 */

const read = async (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

const FILES = [
    {name: 'สรุปงาน.docx', url: '/media/task-attachments/1', download_url: '/media/task-attachments/1?download=1'},
    {name: 'ยอดขาย.xlsx', url: '/media/task-attachments/2', download_url: '/media/task-attachments/2?download=1'},
    {name: 'หลักฐาน.png', url: '/media/task-attachments/3', download_url: '/media/task-attachments/3?download=1'},
];

/** markup ที่ตรงกับ resources/views/tasks/partials/workspace-interactions.blade.php */
function boot(t, {files = FILES, topic = 'งานทดสอบ'} = {}) {
    const env = mountDom();
    t.after(env.cleanup);

    env.document.body.innerHTML = `
        <button type="button" data-open-attachment-download data-task-id="7">ดาวน์โหลด</button>
        <div class="notion-modal attachment-download-modal" data-attachment-download-modal hidden>
            <section class="attachment-download-modal__card" role="dialog" aria-modal="true" aria-labelledby="attachment-download-title">
                <header>
                    <div><span>DOWNLOAD</span><strong id="attachment-download-title">ดาวน์โหลดไฟล์แนบ</strong><small data-attachment-download-topic></small></div>
                    <button type="button" data-close-attachment-download aria-label="ปิด">x</button>
                </header>
                <div class="attachment-download-modal__body">
                    <div class="attachment-download-modal__toolbar" data-attachment-download-toolbar>
                        <label><input type="checkbox" data-attachment-download-all checked><span>เลือกทั้งหมด</span></label>
                        <span data-attachment-download-count></span>
                    </div>
                    <div class="attachment-download-modal__list" data-attachment-download-list></div>
                    <p data-attachment-download-empty hidden>ยังไม่มีไฟล์แนบ</p>
                    <p data-attachment-download-status hidden></p>
                </div>
                <footer>
                    <button type="button" class="notion-primary" data-attachment-download-start disabled><span data-attachment-download-label>ดาวน์โหลด</span></button>
                    <button type="button" class="task-secondary" data-close-attachment-download>ปิด</button>
                </footer>
            </section>
        </div>
        <script type="application/json" data-attachment-data>${JSON.stringify({
            7: {id: 7, topic, can_upload: true, files},
        })}</script>`;

    // jsdom ไม่นำทางจริง จึงดักการคลิกลิงก์เพื่อพิสูจน์ว่าสั่งดาวน์โหลดไฟล์ไหนไปบ้าง
    const downloads = [];
    env.window.HTMLAnchorElement.prototype.click = function recordClick() {
        downloads.push({href: this.getAttribute('href'), download: this.getAttribute('download')});
    };

    initAttachmentDownload(env.document);

    return {
        ...env,
        downloads,
        trigger: env.document.querySelector('[data-open-attachment-download]'),
        modal: env.document.querySelector('[data-attachment-download-modal]'),
        list: env.document.querySelector('[data-attachment-download-list]'),
        toggleAll: env.document.querySelector('[data-attachment-download-all]'),
        startButton: env.document.querySelector('[data-attachment-download-start]'),
        startLabel: env.document.querySelector('[data-attachment-download-label]'),
        status: env.document.querySelector('[data-attachment-download-status]'),
        count: env.document.querySelector('[data-attachment-download-count]'),
        rows: () => [...env.document.querySelectorAll('[data-attachment-download-key]')],
    };
}

/** เดินเวลาให้ครบทุกไฟล์ที่ตั้งคิวดาวน์โหลดไว้ */
const flushDownloads = (total) => new Promise((resolve) => {
    setTimeout(resolve, (total + 1) * DOWNLOAD_INTERVAL_MS);
});

/* ---------- pure state ---------- */

test('selection state is computed from the file list, not from DOM order', () => {
    const keys = FILES.map(fileKey);

    assert.deepEqual(keys, ['/media/task-attachments/1', '/media/task-attachments/2', '/media/task-attachments/3']);
    assert.deepEqual(toggleKey(keys, keys[1]), [keys[0], keys[2]]);
    assert.deepEqual(toggleKey([keys[0]], keys[1]), [keys[0], keys[1]]);
    assert.deepEqual(selectedFiles(FILES, [keys[2]]).map((file) => file.name), ['หลักฐาน.png']);
});

/*
 * ลบไฟล์จากการ์ดด้านหลังขณะกล่องนี้เปิดอยู่ คีย์ที่ค้างต้องหายไปด้วย
 * ไม่อย่างนั้นตัวนับจะบอกจำนวนสูงกว่าไฟล์ที่มีจริง และปุ่มดาวน์โหลดจะชี้ไปไฟล์ที่ไม่มีแล้ว
 */
test('keys of deleted files are pruned instead of inflating the counter', () => {
    const remaining = FILES.slice(0, 2);
    const stale = FILES.map(fileKey);

    assert.deepEqual(pruneKeys(remaining, stale), remaining.map(fileKey));
    assert.deepEqual(selectAllState(remaining, stale), {checked: true, indeterminate: false, disabled: false});
});

test('select-all is indeterminate while only part of the list is picked', () => {
    assert.deepEqual(selectAllState(FILES, [fileKey(FILES[0])]), {checked: false, indeterminate: true, disabled: false});
    assert.deepEqual(selectAllState(FILES, FILES.map(fileKey)), {checked: true, indeterminate: false, disabled: false});
    assert.deepEqual(selectAllState([], []), {checked: false, indeterminate: false, disabled: true});
});

test('labels count what will actually be downloaded', () => {
    assert.equal(downloadLabel(0), 'ดาวน์โหลด');
    assert.equal(downloadLabel(3), 'ดาวน์โหลด 3 ไฟล์');
    assert.equal(selectionLabel(2, 3), 'เลือกแล้ว 2 จาก 3 ไฟล์');
    assert.equal(selectionLabel(0, 0), '');
});

/* ---------- real DOM path ---------- */

test('the panel button opens the modal with every file pre-selected', async (t) => {
    const ui = boot(t);

    assert.equal(ui.modal.hidden, true, 'ยังไม่กดต้องปิดอยู่');

    click(ui.trigger);

    assert.equal(ui.modal.hidden, false);
    assert.equal(ui.rows().length, 3, 'ต้องแสดงไฟล์แนบทุกไฟล์ของงานนั้น');
    assert.equal(ui.document.querySelector('[data-attachment-download-topic]').textContent, 'งานทดสอบ');
    assert.equal(ui.startButton.disabled, false);
    assert.equal(ui.startLabel.textContent, 'ดาวน์โหลด 3 ไฟล์');
    assert.equal(ui.count.textContent, 'เลือกแล้ว 3 จาก 3 ไฟล์');
    assert.equal(ui.toggleAll.checked, true);
    assert.ok(ui.rows().every((row) => row.querySelector('input[type="checkbox"]').checked));
});

/*
 * หัวใจของงานนี้: เลือกหลายรายการแล้วกดโหลดครั้งเดียว
 * ต้องยิงเฉพาะไฟล์ที่ติ๊กไว้ และต้องใช้ลิงก์ ?download=1 ไม่ใช่ลิงก์เปิดดู
 */
test('downloading sends one request per selected file using the download link', async (t) => {
    const ui = boot(t);
    click(ui.trigger);

    // เอาไฟล์กลางออก เหลือสองไฟล์ที่ต้องโหลด
    clickCheckbox(ui.rows()[1].querySelector('input[type="checkbox"]'));

    assert.equal(ui.startLabel.textContent, 'ดาวน์โหลด 2 ไฟล์');
    assert.equal(ui.toggleAll.indeterminate, true);

    click(ui.startButton);
    await flushDownloads(2);

    assert.deepEqual(ui.downloads, [
        {href: '/media/task-attachments/1?download=1', download: 'สรุปงาน.docx'},
        {href: '/media/task-attachments/3?download=1', download: 'หลักฐาน.png'},
    ]);
    assert.match(ui.status.textContent, /เริ่มดาวน์โหลด 2 ไฟล์แล้ว/);
    assert.equal(ui.status.hidden, false);
});

test('clearing select-all disables the download button so nothing is sent', async (t) => {
    const ui = boot(t);
    click(ui.trigger);

    clickCheckbox(ui.toggleAll);

    assert.equal(ui.startButton.disabled, true);
    assert.equal(ui.startLabel.textContent, 'ดาวน์โหลด');
    assert.ok(ui.rows().every((row) => !row.querySelector('input[type="checkbox"]').checked));

    click(ui.startButton);
    await flushDownloads(1);

    assert.deepEqual(ui.downloads, [], 'ไม่เลือกไฟล์ไหนเลยต้องไม่ยิงดาวน์โหลด');

    // ติ๊กกลับต้องเลือกครบทุกไฟล์อีกครั้ง ไม่ใช่ค้างว่างเปล่า
    clickCheckbox(ui.toggleAll);
    assert.equal(ui.startLabel.textContent, 'ดาวน์โหลด 3 ไฟล์');
});

test('each row keeps a direct download link for a single file', async (t) => {
    const ui = boot(t);
    click(ui.trigger);

    const links = ui.rows().map((row) => row.querySelector('a'));

    assert.equal(links.length, 3);
    assert.equal(links[0].getAttribute('href'), '/media/task-attachments/1?download=1');
    assert.equal(links[0].getAttribute('download'), 'สรุปงาน.docx');
    // ลิงก์ต้องอยู่นอก <label> ไม่งั้นการกดจะไปสลับ checkbox ของแถวพ่วงด้วย
    assert.equal(links[0].closest('label'), null);
});

test('a task with no attachment shows the empty state and no download action', async (t) => {
    const ui = boot(t, {files: []});
    click(ui.trigger);

    assert.equal(ui.rows().length, 0);
    assert.equal(ui.document.querySelector('[data-attachment-download-empty]').hidden, false);
    assert.equal(ui.document.querySelector('[data-attachment-download-toolbar]').hidden, true);
    assert.equal(ui.startButton.disabled, true);
});

test('the modal closes from the close button, the backdrop and Escape', async (t) => {
    const ui = boot(t);

    click(ui.trigger);
    click(ui.document.querySelector('header [data-close-attachment-download]'));
    assert.equal(ui.modal.hidden, true);

    click(ui.trigger);
    click(ui.modal);
    assert.equal(ui.modal.hidden, true, 'คลิกนอกกล่องต้องปิด');

    click(ui.trigger);
    ui.modal.dispatchEvent(new ui.window.CustomEvent('modalstack:dismiss'));
    assert.equal(ui.modal.hidden, true, 'Escape ผ่าน modal-stack ต้องปิด');
});

/*
 * แนบหรือลบไฟล์จากการ์ดด้านหลังขณะกล่องนี้เปิดอยู่ รายการต้องตามทันที
 * ไม่ใช่ค้างรายการเดิมจนผู้ใช้กดโหลดไฟล์ที่ถูกลบไปแล้ว
 */
test('the list follows attachments changed while the modal stays open', async (t) => {
    const ui = boot(t);
    click(ui.trigger);

    assert.equal(ui.rows().length, 3, 'fixture ต้องเริ่มจากสามไฟล์จริง');

    // เส้นทางจริงที่การ์ดไฟล์แนบใช้หลังลบไฟล์สำเร็จ ไม่ใช่ยิง event เปล่า ๆ
    publishTaskFiles('7', FILES.slice(0, 1), ui.document);

    assert.equal(ui.rows().length, 1);
    assert.equal(ui.startLabel.textContent, 'ดาวน์โหลด 1 ไฟล์');
});

/* ---------- markup และฝั่ง server ต้องตรงกัน ---------- */

test('the workspace files panel carries the download trigger and the modal', async () => {
    const blade = await read('resources/views/tasks/partials/workspace-interactions.blade.php');
    const modalScript = await read('resources/js/mytasks-task-modal.js');

    // ปุ่มอยู่บนหัวการ์ดไฟล์แนบ และซ่อนไว้จนกว่างานนั้นจะมีไฟล์จริง
    assert.match(blade, /data-open-attachment-download hidden/);
    assert.match(blade, /data-attachment-download-modal/);
    assert.match(blade, /aria-modal="true" aria-labelledby="attachment-download-title"/);
    assert.match(modalScript, /downloadTrigger\.hidden = files\.length === 0;/);
    assert.match(modalScript, /downloadTrigger\.dataset\.taskId = String\(taskId \?\? ''\);/);
});

/*
 * ลิงก์ดาวน์โหลดต้องมาจาก server ไม่ใช่ประกอบเองฝั่ง client
 * ชื่อไฟล์บนดิสก์ถูก hash ไว้ ชื่อเดิมของผู้ใช้ออกมาจาก Content-Disposition เท่านั้น
 */
test('download links come from the server payload, not built in JavaScript', async () => {
    const presenter = await read('app/Support/TaskAttachmentPresenter.php');
    const script = await read('resources/js/pages/mytasks/attachment-download.js');

    assert.match(presenter, /'download_url' => self::downloadUrl\(\$file\)/);
    assert.match(presenter, /route\('media\.task-attachments\.show', \[\$file, 'download' => 1\]\)/);
    assert.match(script, /file\.download_url \|\| file\.url/);
    // ห้ามประกอบ query string ของ server เองฝั่ง client (มองหา literal ที่เริ่มด้วย ?download)
    assert.doesNotMatch(script, /['"`]\?download/);
});
