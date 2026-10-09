import test from 'node:test';
import assert from 'node:assert/strict';
import {mountDom, click} from './helpers/dom.js';

/**
 * กล่องไฟล์แนบที่เปิดจากปุ่มคลิปหนีบ — ต้องมีปุ่มลบเหมือนการ์ดไฟล์แนบใน Task Workspace
 *
 * ปัญหาเดิม: กล่องนี้วาดแต่ลิงก์เปิดไฟล์ ไม่มีปุ่มถังขยะเลย ทั้งที่ server ส่ง delete_url มาให้
 * บนจอมือถือผู้ใช้เข้าถึงไฟล์แนบทางปุ่มคลิปหนีบเป็นหลัก จึงลบไฟล์ไม่ได้
 * ส่วนบนจอคอมที่เปิด Task Workspace เต็มจอ ปุ่มลบมีอยู่ อาการจึงเหมือน "ลบได้แค่ในคอม"
 *
 * markup ต้องตรงกับ resources/views/tasks/partials/workspace-interactions.blade.php
 * และ project-board-card.blade.php ถ้า Blade เปลี่ยน hook ต้องแก้ที่นี่ด้วย
 */

const attachmentData = {
    9: {
        topic: 'งานทดสอบ',
        can_upload: true,
        is_closed: false,
        upload_url: '/tasks/9/attachments',
        files: [
            {name: 'สัญญา.pdf', url: '/media/1', download_url: '/media/1?download=1', delete_url: '/tasks/9/attachments/1'},
            // ไฟล์ที่ผู้ใช้ไม่มีสิทธิ์ลบ server ไม่ส่ง delete_url มา จึงต้องไม่มีปุ่มลบ
            {name: 'ของคนอื่น.pdf', url: '/media/2', download_url: '/media/2?download=1', delete_url: null},
        ],
    },
};

const markup = `<!doctype html><html><body>
<meta name="csrf-token" content="test-token">
<div class="notion-workspace my-tasks-page" data-workspace data-context="user" data-task-scope="all">
    <div class="project-board" data-project-board>
        <div class="board-reference-list" data-board-list-body>
            <article class="board-reference-row" data-board-task data-detail-target="0" data-project-key="p"
                     data-task-id="9" data-participate="1" data-topic="งานทดสอบ" data-status="2"
                     data-can-review="0" data-late="0" data-cross-department="0" data-project-name="p"
                     data-search-text="งานทดสอบ">
                <button type="button" class="board-attachments has-files" data-board-open-attachments="9">
                    <i class="bi bi-paperclip"></i><strong>2</strong>
                </button>
            </article>
        </div>
    </div>
    <div class="notion-toast" data-toast></div>
</div>

<div class="notion-modal board-attachment-modal" data-board-attachment-modal hidden>
    <section class="board-attachment-modal__card">
        <header>
            <div><span>ATTACHMENTS</span><strong>ไฟล์แนบ</strong><small data-board-attachment-topic></small></div>
            <div class="board-attachment-modal__actions">
                <button type="button" data-open-attachment-download hidden>ดาวน์โหลด</button>
                <button type="button" data-close-board-attachments>ปิด</button>
            </div>
        </header>
        <div class="board-attachment-modal__body">
            <div class="board-attachment-modal__list" data-board-attachment-list></div>
            <div data-board-attachment-empty hidden></div>
            <div class="board-attachment-modal__upload" data-board-attachment-upload>
                <label><input type="file" multiple data-board-modal-attachment-input></label>
            </div>
            <p data-board-attachment-locked hidden></p>
        </div>
    </section>
</div>
<script type="application/json" data-task-management-data>{}</script>
<script type="application/json" data-attachment-data>${JSON.stringify(attachmentData)}</script>
</body></html>`;

const dom = mountDom(markup, {url: 'http://localhost/my-tasks?view=board'});
const {document, window} = dom;

// กล่องยืนยันของจริงเป็น SweetAlert2 ที่ไม่มีใน jsdom จึงตอบ "ยืนยัน" ให้เสมอ
let confirmed = true;
window.Swal = {fire: async () => ({isConfirmed: confirmed})};
globalThis.Swal = window.Swal;

const requests = [];
let response = {ok: true, body: {ok: true, files: []}};
window.fetch = async (url, options) => {
    requests.push({url, options});
    return {ok: response.ok, json: async () => response.body};
};
globalThis.fetch = window.fetch;

await import('../../resources/js/mytasks-project-board.js');

const openModal = () => click(document.querySelector('[data-board-open-attachments="9"]'));
const rows = () => [...document.querySelectorAll('.board-attachment-modal__row')];

test('ปุ่มลบขึ้นเฉพาะไฟล์ที่ server ให้สิทธิ์ลบ', () => {
    openModal();

    assert.equal(rows().length, 2, 'ต้องวาดแถวไฟล์ครบทุกไฟล์');
    assert.ok(
        rows()[0].querySelector('[data-delete-attachment]'),
        'ไฟล์ที่มี delete_url ต้องมีปุ่มถังขยะ',
    );
    assert.equal(
        rows()[1].querySelector('[data-delete-attachment]'),
        null,
        'ไฟล์ที่ไม่มี delete_url ต้องไม่มีปุ่มลบ',
    );
    assert.ok(
        rows()[0].querySelector('[data-delete-attachment] .bi-trash3'),
        'ปุ่มลบต้องเป็นไอคอนถังขยะชุดเดียวกับการ์ดไฟล์แนบ',
    );
    // ปุ่มลบต้องอยู่นอกลิงก์ ไม่งั้นการกดลบจะเปิดไฟล์ไปด้วย
    assert.equal(
        rows()[0].querySelector('a [data-delete-attachment]'),
        null,
        'ปุ่มลบต้องไม่อยู่ภายในลิงก์เปิดไฟล์',
    );
});

test('ยกเลิกในกล่องยืนยันแล้วต้องไม่เรียก server', async () => {
    openModal();
    requests.length = 0;
    confirmed = false;

    click(rows()[0].querySelector('[data-delete-attachment]'));
    await new Promise((resolve) => window.setTimeout(resolve, 0));

    assert.equal(requests.length, 0, 'ยังไม่ยืนยันต้องไม่ลบ');
    assert.equal(rows().length, 2, 'แถวไฟล์ต้องอยู่ครบ');
    confirmed = true;
});

test('กดปุ่มลบแล้วยิง DELETE ไปที่ delete_url แล้วแถวหายจากกล่อง', async () => {
    openModal();
    requests.length = 0;
    response = {ok: true, body: {ok: true, files: [attachmentData[9].files[1]]}};

    click(rows()[0].querySelector('[data-delete-attachment]'));
    await new Promise((resolve) => window.setTimeout(resolve, 0));

    assert.equal(requests.length, 1, 'ต้องเรียก server หนึ่งครั้ง');
    assert.equal(requests[0].url, '/tasks/9/attachments/1');
    assert.equal(requests[0].options.method, 'DELETE');
    assert.equal(requests[0].options.headers['X-CSRF-TOKEN'], 'test-token');

    assert.equal(rows().length, 1, 'กล่องต้องวาดใหม่ตามรายการไฟล์ที่เหลือ');
    const counter = document.querySelector('[data-board-open-attachments="9"] strong');
    assert.equal(counter.textContent, '1', 'ตัวเลขบนปุ่มคลิปหนีบต้องลดลงทันที');
});
