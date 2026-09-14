import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {JSDOM} from 'jsdom';

const read = (path) => readFile(new URL('../../' + path, import.meta.url), 'utf8');

/*
 * ปุ่มแชร์งานต้องทำงานจากคลิกจริงถึงผลลัพธ์จริง ไม่ใช่ทดสอบเฉพาะฟังก์ชันภายใน
 * และต้องใช้ตัวจัดการชุดเดียวกันทั้งแถวงานหลักและแถวงานย่อย
 */
const boot = async (html, {fetchImpl, swalResult} = {}) => {
    const dom = new JSDOM(`<meta name="csrf-token" content="token"><div data-toast></div>${html}`, {
        url: 'http://localhost/my-tasks?view=board',
    });

    globalThis.window = dom.window;
    globalThis.document = dom.window.document;
    const calls = [];
    globalThis.window.Swal = {
        fire: async (options) => {
            calls.push(options);

            return swalResult ?? {isConfirmed: false};
        },
    };
    const requests = [];
    globalThis.fetch = async (url, init) => {
        requests.push({url, method: init.method, body: init.body});

        return fetchImpl ? fetchImpl(url, init) : {ok: true, json: async () => ({ok: true, message: 'แชร์งานแล้ว'})};
    };

    // โมดูลอ่าน document/window จาก global โดยตรงเหมือนโมดูลอื่นของบอร์ด
    const source = await read('resources/js/pages/mytasks/task-share.js');
    new Function(source)();

    return {dom, calls, requests};
};

const row = (id, extra = '') => `
    <section data-project-board>
        <article data-board-task data-task-id="${id}">
            <details class="task-more-menu board-reference-menu">
                <summary aria-label="เมนูจัดการรายการงาน"></summary>
                <div class="board-task-menu">
                    <button type="button" data-share-task data-task-id="${id}" data-topic="งานทดสอบ"
                        data-url="/shared-tasks/tasks/${id}" data-shared="0" ${extra}></button>
                </div>
            </details>
        </article>
    </section>
`;

test('clicking the share menu item opens the scope chooser and posts the chosen scope', async () => {
    const {dom, calls, requests} = await boot(row(12), {
        swalResult: {isConfirmed: true, value: {scope: 'organization', note: 'มาช่วยกัน'}},
    });

    const button = dom.window.document.querySelector('[data-share-task]');
    button.click();
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(calls.length, 1, 'ต้องเปิดกล่องเลือกขอบเขตก่อนส่ง');
    assert.match(calls[0].html, /share-scope/, 'กล่องต้องมีตัวเลือกขอบเขต');
    assert.match(calls[0].html, /ข้ามแผนก/, 'ต้องเลือกแชร์ข้ามแผนกได้');

    assert.equal(requests.length, 1);
    assert.equal(requests[0].url, '/shared-tasks/tasks/12');
    assert.equal(requests[0].method, 'POST');
    assert.deepEqual(JSON.parse(requests[0].body), {scope: 'organization', note: 'มาช่วยกัน'});
    assert.equal(button.dataset.shared, '1', 'ปุ่มต้องจำได้ว่างานถูกแชร์แล้ว');
});

test('a task that is already shared asks to close the share instead', async () => {
    const {dom, calls, requests} = await boot(
        row(12, 'data-close-url="/shared-tasks/5"').replace('data-shared="0"', 'data-shared="1"'),
        {swalResult: {isConfirmed: true}},
    );

    dom.window.document.querySelector('[data-share-task]').click();
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.match(calls[0].title, /ปิดประกาศ/);
    assert.equal(requests[0].method, 'DELETE');
    assert.equal(requests[0].url, '/shared-tasks/5');
});

test('the subtask row uses the same handler and registers no duplicate listener', async () => {
    const subtaskRow = `
        <section data-project-board>
            <article data-board-task data-task-id="12">
                <li data-board-task data-board-subtask="1" data-task-id="77">
                    <details class="task-more-menu board-reference-menu board-task-detail__menu">
                        <summary aria-label="เมนูจัดการงานย่อย"></summary>
                        <div class="board-task-menu">
                            <button type="button" data-share-task data-task-id="77" data-topic="งานย่อย"
                                data-url="/shared-tasks/tasks/77" data-shared="0"></button>
                        </div>
                    </details>
                </li>
            </article>
        </section>
    `;

    const {dom, requests} = await boot(subtaskRow, {
        swalResult: {isConfirmed: true, value: {scope: 'department', note: null}},
    });

    const button = dom.window.document.querySelector('[data-share-task]');
    button.click();
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(requests.length, 1, 'คลิกหนึ่งครั้งต้องยิง request ครั้งเดียว ไม่ใช่ซ้อนหลายชั้น');
    assert.equal(requests[0].url, '/shared-tasks/tasks/77', 'ต้องยิงไปที่งานย่อยใบนั้น ไม่ใช่งานแม่');
});

/*
 * กล่องแชร์งานถูกเปิดจากหน้าบอร์ด ไม่ใช่หน้า /shared-tasks
 *
 * เคยวาง CSS ไว้ใน pages/shares.css ซึ่งหน้าบอร์ดไม่ได้โหลด กฎทั้งหมดจึงไม่ถูกใช้
 * และข้อความสองบรรทัดในตัวเลือกไหลต่อกันจนอ่านไม่ออก เทสต์นี้กันไม่ให้ย้อนกลับไป
 */
test('the share dialog styles live in a stylesheet the board pages actually load', async () => {
    const workspaceModal = await read('resources/css/components/task-workspace/workspace-modal.css');
    const sharesPage = await read('resources/css/pages/shares.css');
    const mytasks = await read('resources/css/pages/mytasks.css');
    const js = await read('resources/js/pages/mytasks/task-share.js');

    assert.match(mytasks, /@import '\.\.\/components\/task-workspace\/workspace-modal\.css';/,
        'หน้าบอร์ดโหลด mytasks.css ซึ่งต้อง import workspace-modal.css');
    assert.match(workspaceModal, /\.swal-share__option/);
    assert.doesNotMatch(sharesPage, /\.swal-share__option/,
        'ห้ามวางสไตล์ของกล่องแชร์งานไว้ใน shares.css อีก หน้าบอร์ดไม่ได้โหลดไฟล์นั้น');

    // ต้นเหตุเดิมของการซ้อนบรรทัด: ทั้งสามชั้นต้องเป็น block ไม่ใช่เฉพาะ <strong>
    assert.match(workspaceModal, /\.swal-share__option > span \{[^}]*display:\s*block/);
    assert.match(workspaceModal, /\.swal-share__option strong \{[^}]*display:\s*block/);
    assert.match(workspaceModal, /\.swal-share__option small \{[^}]*display:\s*block/);

    // กฎผูกกับคลาสของกล่องใบนี้ใบเดียว จึงต้องส่ง customClass ไปด้วย
    assert.match(js, /customClass:\s*\{popup:\s*'swal-share'\}/);
    assert.match(workspaceModal, /\.swal2-popup\.swal-share/);
});

test('the share menu item lives directly inside the menu div so the board can close it', async () => {
    const blade = await read('resources/views/tasks/partials/share-task-menu-item.blade.php');
    const board = await read('resources/js/mytasks-project-board.js');
    const card = await read('resources/views/tasks/partials/project-board-card.blade.php');
    const detailRow = await read('resources/views/tasks/components/task-detail-row.blade.php');

    // ตัวปิดเมนูใช้ selector นี้ ปุ่มจึงต้องเป็นลูกตรงของ div ไม่ห่อชั้นเพิ่ม
    assert.match(board, /\.board-reference-menu > div button/);
    assert.match(blade, /<button type="button"\s+data-share-task/);
    assert.doesNotMatch(blade, /<div[^>]*>\s*<button type="button"\s+data-share-task/);

    // ทั้งสองแถวต้อง include partial เดียวกัน ห้ามคัดลอกปุ่มไปเขียนซ้ำ
    assert.match(card, /@include\('tasks\.partials\.share-task-menu-item'/);
    assert.match(detailRow, /@include\('tasks\.partials\.share-task-menu-item'/);
});
