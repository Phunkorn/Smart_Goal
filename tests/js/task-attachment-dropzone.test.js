import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {mountDom} from './helpers/dom.js';

/*
 * การ์ด "ไฟล์แนบ" ใน modal แก้ไขงาน (form.task-workspace)
 *
 * ปัญหาเดิม: drop handler ผูกอยู่กับปุ่ม "+ เพิ่มไฟล์" เล็ก ๆ ที่หัวการ์ดเท่านั้น
 * ผู้ใช้ลากไฟล์มาที่กรอบเส้นประกลางการ์ด (ซึ่งเป็นเป้าที่ใหญ่และเป็นธรรมชาติที่สุด)
 * แล้วไม่มีอะไรเกิดขึ้น ทั้งยังไม่มีที่ไหนบอกว่าแนบไฟล์ชนิดไหนได้บ้าง
 */

const read = async (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

let fixtureCount = 0;

async function boot(t, {canUpload = true, files = []} = {}) {
    const env = mountDom();
    t.after(env.cleanup);

    env.document.body.innerHTML = `
        <meta name="csrf-token" content="test-token">
        <div data-task-modal>
            <form class="task-workspace" data-task-form data-readonly="false">
                <section class="task-workspace__panel--files" data-task-attachments>
                    <header>
                        <label class="task-workspace__add-file" data-task-inline-drop>
                            <input type="file" multiple data-task-inline-file-input>
                        </label>
                    </header>
                    <div class="task-workspace__panel-body" data-task-drop-zone>
                        <div class="task-inline-files" data-task-inline-files></div>
                        <p data-attachment-types></p>
                        <p data-attachment-status hidden></p>
                        <div data-attachment-drop-overlay></div>
                    </div>
                </section>
            </form>
        </div>
        <button data-open-task-modal data-task-id="7">เปิด</button>
        <script type="application/json" data-attachment-data>${JSON.stringify({
            7: {can_upload: canUpload, upload_url: '/tasks/7/attachments', files},
        })}</script>`;

    const uploads = [];
    let respondWith = {ok: true, body: {ok: true, files: []}};
    env.window.fetch = async (url, options) => {
        uploads.push({url, options});
        return {ok: respondWith.ok, json: async () => respondWith.body};
    };
    globalThis.fetch = env.window.fetch;


    fixtureCount += 1;
    await import(`../../resources/js/mytasks-task-modal.js?dropzone=${fixtureCount}`);

    env.document.querySelector('[data-open-task-modal]')
        .dispatchEvent(new env.window.MouseEvent('click', {bubbles: true}));

    return {
        ...env,
        uploads,
        respond: (body, ok = true) => { respondWith = {ok, body}; },
        zone: env.document.querySelector('[data-task-drop-zone]'),
        list: env.document.querySelector('[data-task-inline-files]'),
        status: env.document.querySelector('[data-attachment-status]'),
        hint: env.document.querySelector('[data-attachment-types]'),
    };
}

/** จำลอง drag ไฟล์ของเบราว์เซอร์เท่าที่ jsdom รองรับ (ไม่มี DataTransfer จริง) */
function dragFilesOnto(window, target, names) {
    const transfer = {
        types: ['Files'],
        dropEffect: '',
        files: names.map((name) => ({name, size: 1024})),
    };
    const fire = (type) => {
        const event = new window.Event(type, {bubbles: true, cancelable: true});
        event.dataTransfer = transfer;
        target.dispatchEvent(event);
        return event;
    };

    return {enter: fire('dragenter'), over: fire('dragover'), drop: fire('drop')};
}

test('the whole attachment card accepts a dropped file, not only the small add button', async (t) => {
    const ui = await boot(t);

    const events = dragFilesOnto(ui.window, ui.zone, ['สรุปงาน.docx']);

    assert.equal(events.over.defaultPrevented, true, 'ต้อง preventDefault ไม่งั้นเบราว์เซอร์เปิดไฟล์แทน');
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(ui.uploads.length, 1, 'วางไฟล์ที่กรอบกลางการ์ดต้องอัปโหลดจริง');
    assert.equal(ui.uploads[0].url, '/tasks/7/attachments');
});

/*
 * เดิมอัปโหลดสำเร็จแล้วเรียก window.location.reload() ทั้งหน้า
 * modal แก้ไขงานจึงปิดหายไปทันที ผู้ใช้ไม่เห็นว่าไฟล์ถูกแนบแล้ว
 * ต้องกดเปิด modal ใหม่เองถึงจะรู้ — นี่คือ regression ที่ test นี้กันไว้
 */
test('a successful upload updates the list in place and never reloads the page', async (t) => {
    const ui = await boot(t);
    ui.respond({ok: true, files: [{name: 'สรุปงาน.docx', url: '/media/9', delete_url: '/media/9/delete'}]});

    dragFilesOnto(ui.window, ui.zone, ['สรุปงาน.docx']);
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.match(ui.list.textContent, /สรุปงาน\.docx/, 'ไฟล์ใหม่ต้องขึ้นในรายการทันที');
    assert.match(ui.status.textContent, /แนบไฟล์แล้ว/);
    assert.equal(ui.status.hidden, false);
    assert.equal(ui.document.querySelector('[data-task-modal]').hidden, false, 'modal ต้องยังเปิดอยู่');
});

test('a failed upload reports the server message and keeps the modal open', async (t) => {
    const ui = await boot(t);
    ui.respond({ok: false, message: 'เพิ่มไฟล์อ้างอิงงานได้สูงสุด 5 ไฟล์ต่องาน'}, false);

    dragFilesOnto(ui.window, ui.zone, ['สรุปงาน.docx']);
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(ui.status.textContent, 'เพิ่มไฟล์อ้างอิงงานได้สูงสุด 5 ไฟล์ต่องาน');
    assert.equal(ui.status.classList.contains('is-error'), true);
});

test('dragging over the card shows a visible drop state and clears it afterwards', async (t) => {
    const ui = await boot(t);

    const transfer = {types: ['Files'], dropEffect: '', files: []};
    const fire = (type) => {
        const event = new ui.window.Event(type, {bubbles: true, cancelable: true});
        event.dataTransfer = transfer;
        ui.zone.dispatchEvent(event);
    };

    fire('dragenter');
    assert.equal(ui.zone.classList.contains('is-dragover'), true);

    // เข้า element ลูกแล้วออก ต้องไม่ทำให้กรอบกะพริบหาย
    fire('dragenter');
    fire('dragleave');
    assert.equal(ui.zone.classList.contains('is-dragover'), true, 'ผ่าน element ลูกต้องไม่ล้างสถานะ');

    fire('dragleave');
    assert.equal(ui.zone.classList.contains('is-dragover'), false);
});

test('an unsupported file is rejected with the allowed list instead of a silent failure', async (t) => {
    const ui = await boot(t);

    dragFilesOnto(ui.window, ui.zone, ['สัญญา.pdf']);
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(ui.uploads.length, 0, 'ชนิดที่ server ไม่รับ ต้องไม่ถูกยิงออกไป');
    assert.match(ui.status.textContent, /\.pdf/);
    assert.match(ui.status.textContent, /Word/);
    assert.equal(ui.status.hidden, false);
});

test('a task the user cannot upload to neither invites a drop nor accepts one', async (t) => {
    const ui = await boot(t, {canUpload: false});

    assert.equal(ui.hint.hidden, true, 'ห้ามชวนลากไฟล์ในงานที่แนบไม่ได้');
    assert.equal(ui.zone.classList.contains('is-droppable'), false);

    dragFilesOnto(ui.window, ui.zone, ['สรุปงาน.docx']);
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(ui.uploads.length, 0);
});

test('each attached file is rendered with an icon matching its type', async (t) => {
    const ui = await boot(t, {
        files: [
            {name: 'สรุป.docx', url: '/media/1'},
            {name: 'งบ.xlsx', url: '/media/2'},
            {name: 'สไลด์.pptx', url: '/media/3'},
            {name: 'ภาพ.png', url: '/media/4'},
        ],
    });

    const icons = [...ui.list.querySelectorAll('a > i')].map((icon) => icon.className);
    assert.match(icons[0], /bi-file-earmark-word .*file-tone-word/);
    assert.match(icons[1], /bi-file-earmark-excel .*file-tone-excel/);
    assert.match(icons[2], /bi-file-earmark-ppt .*file-tone-ppt/);
    assert.match(icons[3], /bi-file-earmark-image .*file-tone-image/);
});

/*
 * ชนิดไฟล์ที่โฆษณาไว้บนหน้าจอต้องตรงกับ allow-list ฝั่ง server เสมอ
 * ถ้าวันหนึ่งมีคนเพิ่ม/ลดนามสกุลที่ ValidatesAttachments แล้วลืมแก้ UI ผู้ใช้จะโดนหลอก
 */
/*
 * jsdom ห้ามแทน window.location จึงดักการ reload ด้วยการอ่าน source แทน
 * เส้นทางอัปโหลด/ลบไฟล์ต้องอัปเดตในที่เดิมเสมอ ห้ามกลับไปโหลดหน้าใหม่อีก
 */
test('the attachment flow no longer reloads the page on success', async () => {
    const script = await read('resources/js/mytasks-task-modal.js');
    const attachments = script.slice(script.indexOf('[data-task-attachments]'));

    assert.doesNotMatch(attachments, /location\.reload/);
    assert.match(attachments, /task\.files = Array\.isArray\(result\.files\)/);
});

test('the client hint matches the server allow-list exactly', async () => {
    const trait = await read('app/Support/Concerns/ValidatesAttachments.php');
    const script = await read('resources/js/mytasks-task-modal.js');
    const blade = await read('resources/views/tasks/partials/workspace-interactions.blade.php');

    const allowed = trait
        .slice(trait.indexOf('ALLOWED_ATTACHMENT_EXTENSIONS'), trait.indexOf('MIME type จริง'))
        .match(/'([a-z]+)'/g)
        .map((value) => value.replaceAll("'", ''));

    assert.deepEqual(allowed, ['jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx']);

    const iconMap = script.slice(script.indexOf('const FILE_ICONS'), script.indexOf('const extensionOf'));
    allowed.forEach((extension) => {
        assert.match(iconMap, new RegExp(`\\b${extension}:`), `FILE_ICONS ต้องรู้จัก .${extension}`);
    });

    assert.match(blade, /รองรับ JPG, PNG, Word, Excel, PowerPoint/);
    assert.match(blade, /ลากไฟล์มาวางที่นี่ได้/);
});
