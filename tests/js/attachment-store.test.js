import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {mountDom} from './helpers/dom.js';

const read = async (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

/*
 * ปุ่มคลิปหนีบบนการ์ดบอร์ดเคยไม่ขยับเมื่อแนบไฟล์จากโมดัลรายละเอียดงาน
 * เพราะสามโมดูลต่างคน ต่าง JSON.parse โหนด [data-attachment-data] เป็นสำเนาของตัวเอง
 * เดิมปัญหาถูกกลบด้วย location.reload() ซึ่งทำให้โมดัลปิดหายไปเอง
 */

const mount = (t) => {
    const env = mountDom();
    t.after(env.cleanup);

    env.document.body.innerHTML = `
        <button class="board-attachments has-files" data-board-open-attachments="7"><i></i><strong>2</strong></button>
        <button class="row-files has-files" data-open-attachments="7"><i></i><b>2</b></button>
        <script type="application/json" data-attachment-data
            data-max-files="20" data-max-kilobytes="51200"
            data-extensions="jpg,png,zip" data-types-label="JPG, PNG, ZIP">${JSON.stringify({
                7: {topic: 'งาน', files: [{name: 'a.png'}, {name: 'b.png'}], can_upload: true},
            })}</script>`;

    return env;
};

test('every view shares one attachment object instead of its own copy', async (t) => {
    const env = mount(t);
    const {attachmentStore} = await import('../../resources/js/pages/mytasks/attachment-store.js');

    const board = attachmentStore(env.document);
    const modal = attachmentStore(env.document);

    assert.equal(board, modal, 'ต้องเป็นอ็อบเจกต์ตัวเดียวกัน ไม่ใช่สำเนา');

    modal['7'].files = [{name: 'c.png'}];
    assert.equal(board['7'].files.length, 1, 'แก้จากที่หนึ่งต้องเห็นจากอีกที่ทันที');
});

test('publishing files repaints every paperclip button and announces the change', async (t) => {
    const env = mount(t);
    const {attachmentStore, publishTaskFiles} = await import('../../resources/js/pages/mytasks/attachment-store.js');

    const heard = [];
    env.document.addEventListener('mytasks:attachments-changed', (event) => heard.push(event.detail));

    publishTaskFiles(7, [{name: 'a.png'}, {name: 'b.png'}, {name: 'c.zip'}], env.document);

    assert.equal(env.document.querySelector('[data-board-open-attachments="7"] strong').textContent, '3');
    assert.equal(env.document.querySelector('[data-open-attachments="7"] b').textContent, '3');
    assert.equal(attachmentStore(env.document)['7'].files.length, 3);
    assert.deepEqual(heard.map((detail) => detail.id), ['7']);
});

test('emptying the list clears the highlight and shows the board placeholder', async (t) => {
    const env = mount(t);
    const {publishTaskFiles} = await import('../../resources/js/pages/mytasks/attachment-store.js');

    publishTaskFiles(7, [], env.document);

    const boardButton = env.document.querySelector('[data-board-open-attachments="7"]');
    assert.equal(boardButton.querySelector('strong').textContent, '-', 'การ์ดบอร์ดใช้ขีดแทนเลขศูนย์');
    assert.equal(boardButton.classList.contains('has-files'), false);
    assert.equal(env.document.querySelector('[data-open-attachments="7"] b').textContent, '0');
});

test('the task modal and the board both go through the shared store', async () => {
    const modal = await read('resources/js/mytasks-task-modal.js');
    const board = await read('resources/js/mytasks-project-board.js');
    const calendar = await read('resources/js/pages/mytasks/calendar.js');

    for (const [name, source] of [['task modal', modal], ['board', board], ['calendar', calendar]]) {
        assert.match(source, /attachmentStore\(document\)/, `${name} ต้องใช้ store ร่วม`);
        assert.doesNotMatch(source, /JSON\.parse\([^)]*attachment-data/, `${name} ต้องไม่ parse สำเนาของตัวเอง`);
    }

    // แนบและลบจากโมดัลต้องประกาศผลออกไป ไม่ใช่รีโหลดหน้า
    assert.equal(modal.match(/publishTaskFiles\(/g).length, 2);
    assert.doesNotMatch(board, /window\.location\.reload\(\), 450/);
});

test('confirmation dialogs opened inside the workspace modal sit above it', async () => {
    const modal = await read('resources/js/mytasks-task-modal.js');
    const css = await read('resources/css/components/task-workspace/workspace-modal.css');

    // SweetAlert2 ตั้ง container ไว้ที่ z-index 1060 ซึ่งต่ำกว่าโมดัลทุกตัวของ workspace
    assert.match(modal, /task-workspace-dialog/);
    assert.equal(modal.match(/\.\.\.workspaceDialogLayer,/g).length, 3, 'Swal ทุกกล่องในโมดัลต้องยกเลเยอร์');

    const layer = Number(css.match(/\.swal2-container\.task-workspace-dialog\s*\{[\s\S]*?z-index:\s*(\d+)/)?.[1]);
    const workspaceModal = Number(css.match(/\.task-workspace-modal\s*\{[\s\S]*?z-index:\s*(\d+)/)?.[1]);

    assert.ok(layer > 1060, 'ต้องสูงกว่าค่าเริ่มต้นของ SweetAlert2');
    assert.ok(layer > workspaceModal, 'ต้องสูงกว่าโมดัลที่เปิดมันขึ้นมา');
});
