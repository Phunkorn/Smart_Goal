import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {JSDOM} from 'jsdom';

const read = (path) => readFile(new URL('../../' + path, import.meta.url), 'utf8');

const row = (id, projectKey, topic, {hidden = false} = {}) => `
    <article class="board-reference-row" data-board-task data-task-id="${id}" data-project-key="${projectKey}" data-project-name="${projectKey}" data-topic="${topic}" ${hidden ? 'hidden' : ''}>
        <div class="board-reference-task">
            <div data-task-details data-work-order-id="${id}">
                <div class="board-task-details__heading" data-task-reorder-handle>
                    <button type="button" data-task-details-toggle><span class="board-reference-task__title">${topic}</span></button>
                    <button type="button" data-open-task-modal data-task-id="${id}"></button>
                </div>
            </div>
        </div>
        <div data-task-details-panel hidden>
            <ol data-task-details-list>
                <li data-task-detail data-board-task data-board-subtask="1" data-task-id="${id}0" data-project-key="${projectKey}"></li>
            </ol>
        </div>
    </article>`;

const boardHtml = () => `
    <div class="my-tasks-page">
    <div data-toast></div>
    <section data-project-board>
        <div class="board-reference-list">
            <header data-project-header data-project-key="project-1"></header>
            ${row(1, 'project-1', 'A')}
            ${row(2, 'project-1', 'B')}
            ${row(3, 'project-1', 'C')}
            <details data-completed-group data-project-key="project-1" open>
                <summary></summary>
                <div class="board-completed-group__rows">
                    ${row(4, 'project-1', 'Done 1')}
                    ${row(5, 'project-1', 'Done 2')}
                </div>
            </details>
            <header data-project-header data-project-key="project-2"></header>
            ${row(6, 'project-2', 'Other project')}
        </div>
    </section>
    </div>`;

let moduleUrl;
const loadModule = async () => {
    if (!moduleUrl) {
        // โมดูลเริ่มทำงานเองตอน import จึงต้องมี document ให้มันก่อน (บอร์ดว่าง = ไม่ทำอะไร)
        const empty = new JSDOM('', {url: 'http://localhost/'});
        globalThis.window = empty.window;
        globalThis.document = empty.window.document;
        moduleUrl = `../../resources/js/pages/mytasks/task-reorder.js?test=${Date.now()}`;
    }
    return import(moduleUrl);
};

/*
 * jsdom ไม่มี layout — ตำแหน่งแถวคำนวณจากลำดับปัจจุบันใน DOM ทุกครั้งที่ถูกถาม
 * แถวสูง 60px เรียงต่อกันในกลุ่มงานที่ยังไม่เสร็จ ส่วนกลุ่มงานที่เสร็จแล้วเริ่มที่ 400px
 * เพราะแถวขยับระหว่างลาก ตำแหน่งที่ได้จึงต้องเปลี่ยนตามเหมือนในเบราว์เซอร์จริง
 */
const stubLayout = (board) => {
    board.querySelectorAll('[data-board-task]:not([data-board-subtask])').forEach((element) => {
        element.getBoundingClientRect = () => {
            const rows = [...element.parentElement.children].filter((child) => child.matches('[data-board-task]'));
            const offset = element.closest('[data-completed-group]') ? 400 : 100;
            const top = offset + rows.indexOf(element) * 60;
            return {top, bottom: top + 60, height: 60, left: 0, right: 800, width: 800};
        };
    });
};

const mount = async (html = boardHtml(), url = 'http://localhost/my-tasks?view=board', storage = null) => {
    const dom = new JSDOM(html, {url});
    if (storage) Object.entries(storage).forEach(([key, value]) => dom.window.localStorage.setItem(key, value));
    const module = await loadModule();
    const board = dom.window.document.querySelector('[data-project-board]');
    stubLayout(board);
    module.initTaskReorder(board, dom.window);
    return {dom, board, module};
};

const order = (board, projectKey) => [...board.querySelectorAll('[data-board-task]:not([data-board-subtask])')]
    .filter((task) => task.dataset.projectKey === projectKey)
    .map((task) => task.dataset.topic);

const rowOf = (board, topic) => board.querySelector(`[data-board-task][data-topic="${topic}"]`);
const titleOf = (board, topic) => rowOf(board, topic).querySelector('[data-task-details-toggle]');
const centerY = (element) => {
    const rect = element.closest('[data-board-task]').getBoundingClientRect();
    return rect.top + rect.height / 2;
};
const flush = (dom) => new Promise((resolve) => dom.window.setTimeout(resolve, 0));

const pointer = (dom, target, type, clientY, clientX = 200) => target.dispatchEvent(new dom.window.MouseEvent(type, {
    bubbles: true,
    cancelable: true,
    button: 0,
    clientX,
    clientY,
}));

// เส้นทางจริงของผู้ใช้: กดที่ชื่องาน → ลาก → ปล่อย → เบราว์เซอร์ยิง click ตามมา
const drag = (dom, from, toY, {release = true} = {}) => {
    const startY = centerY(from);
    pointer(dom, from, 'pointerdown', startY);
    pointer(dom, dom.window, 'pointermove', startY + Math.sign(toY - startY) * 10);
    pointer(dom, dom.window, 'pointermove', toY);
    if (release) {
        pointer(dom, dom.window, 'pointerup', toY);
        from.dispatchEvent(new dom.window.MouseEvent('click', {bubbles: true, cancelable: true}));
    }
};

test('saved order keeps unsaved tasks in their server slot and ignores missing ids', async () => {
    const {orderBySaved} = await loadModule();

    assert.deepEqual(orderBySaved(['1', '2', '3'], ['3', '1']), ['3', '2', '1']);
    assert.deepEqual(orderBySaved(['1', '2', '3'], []), ['1', '2', '3']);
    assert.deepEqual(orderBySaved(['1', '2'], ['99', '2', '1']), ['2', '1']);
});

test('the drop position is the first row whose middle is still below the pointer', async () => {
    const {insertionIndex} = await loadModule();

    assert.equal(insertionIndex([130, 190], 10), 0);
    assert.equal(insertionIndex([130, 190], 150), 1);
    assert.equal(insertionIndex([130, 190], 500), 2);
    assert.equal(insertionIndex([], 50), 0);
});

test('dragging task B by its name above task A moves it to the top of its project', async () => {
    const {dom, board} = await mount();
    let toggled = 0;
    titleOf(board, 'B').addEventListener('click', () => toggled++);

    drag(dom, titleOf(board, 'B'), 105);

    assert.deepEqual(order(board, 'project-1'), ['B', 'A', 'C', 'Done 1', 'Done 2']);
    assert.deepEqual(order(board, 'project-2'), ['Other project']);
    // งานย่อยติดไปกับแถวงานแม่ ไม่ถูกแยกออก
    assert.ok(rowOf(board, 'B').querySelector('[data-board-subtask][data-task-id="20"]'));
    // click ที่ตามหลังการลากต้องไม่ไปกางงานย่อย
    assert.equal(toggled, 0);
    assert.equal(board.classList.contains('is-task-reordering'), false);
    assert.equal(rowOf(board, 'B').classList.contains('is-reorder-dragging'), false);
    assert.equal(
        dom.window.localStorage.getItem('smartgoal:board-task-order:/my-tasks'),
        JSON.stringify({'project-1': ['2', '1', '3', '4', '5']}),
    );

    // การคลิกครั้งถัดไป (ไม่ได้ลาก) ยังกางงานย่อยได้ตามปกติ
    await flush(dom);
    titleOf(board, 'B').dispatchEvent(new dom.window.MouseEvent('click', {bubbles: true, cancelable: true}));
    assert.equal(toggled, 1);
});

test('a press that barely moves is a normal click, not a drag', async () => {
    const {dom, board} = await mount();
    let toggled = 0;
    titleOf(board, 'B').addEventListener('click', () => toggled++);
    const y = centerY(titleOf(board, 'B'));

    pointer(dom, titleOf(board, 'B'), 'pointerdown', y);
    pointer(dom, dom.window, 'pointermove', y + 3);
    pointer(dom, dom.window, 'pointerup', y + 3);
    titleOf(board, 'B').dispatchEvent(new dom.window.MouseEvent('click', {bubbles: true, cancelable: true}));

    assert.deepEqual(order(board, 'project-1').slice(0, 3), ['A', 'B', 'C']);
    assert.equal(toggled, 1);
    assert.equal(dom.window.localStorage.getItem('smartgoal:board-task-order:/my-tasks'), null);
});

test('the row follows the pointer while dragging and dropping down works too', async () => {
    const {dom, board} = await mount();

    drag(dom, titleOf(board, 'A'), 190, {release: false});
    assert.ok(board.classList.contains('is-task-reordering'));
    assert.ok(rowOf(board, 'A').classList.contains('is-reorder-dragging'));
    assert.deepEqual(order(board, 'project-1').slice(0, 3), ['B', 'A', 'C']);

    pointer(dom, dom.window, 'pointermove', 260);
    pointer(dom, dom.window, 'pointerup', 260);

    assert.deepEqual(order(board, 'project-1').slice(0, 3), ['B', 'C', 'A']);
});

test('a task never leaves its project or crosses into the completed group', async () => {
    const {dom, board} = await mount();

    // ลากลงไปไกลกว่าแถวสุดท้ายของทุกกลุ่ม — ต้องหยุดที่ท้ายกลุ่มงานที่ยังไม่เสร็จของตัวเอง
    drag(dom, titleOf(board, 'A'), 700);
    assert.deepEqual(order(board, 'project-1'), ['B', 'C', 'A', 'Done 1', 'Done 2']);
    assert.equal(rowOf(board, 'A').closest('[data-completed-group]'), null);
    assert.deepEqual(order(board, 'project-2'), ['Other project']);

    // งานที่เสร็จแล้วสลับกันได้เฉพาะในกลุ่มของตัวเอง
    await flush(dom);
    drag(dom, titleOf(board, 'Done 2'), 105);
    assert.deepEqual(order(board, 'project-1'), ['B', 'C', 'A', 'Done 2', 'Done 1']);
    assert.ok(rowOf(board, 'Done 2').closest('[data-completed-group]'));
});

test('rows hidden by a filter or a collapsed project are not drop slots', async () => {
    const html = boardHtml().replace('data-topic="B"', 'data-topic="B" hidden');
    const {dom, board} = await mount(html);

    drag(dom, titleOf(board, 'C'), 105);

    assert.deepEqual(order(board, 'project-1').slice(0, 3), ['C', 'A', 'B']);
});

test('pressing the open-task button never starts a drag', async () => {
    const {dom, board} = await mount();
    const open = rowOf(board, 'B').querySelector('[data-open-task-modal]');

    pointer(dom, open, 'pointerdown', centerY(open));
    pointer(dom, dom.window, 'pointermove', 100);
    pointer(dom, dom.window, 'pointerup', 100);

    assert.deepEqual(order(board, 'project-1').slice(0, 3), ['A', 'B', 'C']);
    assert.equal(board.classList.contains('is-task-reordering'), false);
});

test('Escape during a drag puts the row back and saves nothing', async () => {
    const {dom, board} = await mount();

    drag(dom, titleOf(board, 'C'), 105, {release: false});
    assert.deepEqual(order(board, 'project-1').slice(0, 3), ['C', 'A', 'B']);

    dom.window.document.dispatchEvent(new dom.window.KeyboardEvent('keydown', {key: 'Escape', bubbles: true, cancelable: true}));
    pointer(dom, dom.window, 'pointerup', 105);

    assert.deepEqual(order(board, 'project-1').slice(0, 3), ['A', 'B', 'C']);
    assert.equal(board.classList.contains('is-task-reordering'), false);
    assert.equal(dom.window.localStorage.getItem('smartgoal:board-task-order:/my-tasks'), null);
});

test('the arrow hint says which way and how far the task is moving', async () => {
    const {reorderHint} = await loadModule();

    assert.deepEqual(reorderHint(2, 1, 4), {direction: 'up', steps: 1, label: 'เลื่อนขึ้น 1 ลำดับ', position: 'ลำดับที่ 2 จาก 4'});
    assert.deepEqual(reorderHint(3, 0, 4), {direction: 'up', steps: 3, label: 'เลื่อนขึ้นบนสุด', position: 'ลำดับที่ 1 จาก 4'});
    assert.deepEqual(reorderHint(0, 2, 4), {direction: 'down', steps: 2, label: 'เลื่อนลง 2 ลำดับ', position: 'ลำดับที่ 3 จาก 4'});
    assert.deepEqual(reorderHint(0, 3, 4), {direction: 'down', steps: 3, label: 'เลื่อนลงล่างสุด', position: 'ลำดับที่ 4 จาก 4'});
    assert.deepEqual(reorderHint(1, 1, 3), {direction: 'same', steps: 0, label: 'ตำแหน่งเดิม', position: 'ลำดับที่ 2 จาก 3'});
});

test('while dragging, an arrow hint follows the pointer and flips between up, same and down', async () => {
    const {dom, board} = await mount();
    const hint = () => dom.window.document.querySelector('[data-task-reorder-hint]');
    const read = () => ({
        hidden: hint().hidden,
        direction: ['is-up', 'is-down', 'is-same'].find((name) => hint().classList.contains(name)),
        icon: hint().querySelector('i').className,
        label: hint().querySelector('strong').textContent,
        position: hint().querySelector('small').textContent,
    });

    drag(dom, titleOf(board, 'B'), 105, {release: false});
    // ป้ายต้องอยู่ใต้ .my-tasks-page เพื่อได้ token สีของธีม และไม่ถูกโปรแกรมอ่านหน้าจออ่านซ้ำ
    assert.ok(hint().parentElement.matches('.my-tasks-page'));
    assert.equal(hint().getAttribute('aria-hidden'), 'true');
    assert.deepEqual(read(), {hidden: false, direction: 'is-up', icon: 'bi bi-arrow-up', label: 'เลื่อนขึ้นบนสุด', position: 'ลำดับที่ 1 จาก 3'});

    pointer(dom, dom.window, 'pointermove', 200);
    assert.deepEqual(order(board, 'project-1').slice(0, 3), ['A', 'B', 'C']);
    assert.deepEqual(read(), {hidden: false, direction: 'is-same', icon: 'bi bi-arrow-down-up', label: 'ตำแหน่งเดิม', position: 'ลำดับที่ 2 จาก 3'});

    pointer(dom, dom.window, 'pointermove', 700);
    assert.deepEqual(read(), {hidden: false, direction: 'is-down', icon: 'bi bi-arrow-down', label: 'เลื่อนลงล่างสุด', position: 'ลำดับที่ 3 จาก 3'});

    pointer(dom, dom.window, 'pointerup', 700);
    assert.equal(hint().hidden, true);
    // ข้อความยืนยันหลังวางบอกทิศทางเดียวกับป้าย
    assert.equal(dom.window.document.querySelector('[data-toast]').textContent, 'เลื่อนลงล่างสุดแล้ว');
});

test('a cancelled drag hides the arrow hint too', async () => {
    const {dom, board} = await mount();

    drag(dom, titleOf(board, 'C'), 105, {release: false});
    assert.equal(dom.window.document.querySelector('[data-task-reorder-hint]').hidden, false);
    dom.window.document.dispatchEvent(new dom.window.KeyboardEvent('keydown', {key: 'Escape', bubbles: true, cancelable: true}));

    assert.equal(dom.window.document.querySelector('[data-task-reorder-hint]').hidden, true);
});

test('the dragged order survives a reload and stays scoped to the board page', async () => {
    const first = await mount();
    drag(first.dom, titleOf(first.board, 'B'), 105);
    const storage = Object.fromEntries(Object.entries(first.dom.window.localStorage));

    const reloaded = await mount(boardHtml(), 'http://localhost/my-tasks?view=board', storage);
    assert.deepEqual(order(reloaded.board, 'project-1').slice(0, 3), ['B', 'A', 'C']);

    // Workspace ของสมาชิกเป็นบอร์ดคนละใบ ลำดับที่จัดบนหน้า "งานของฉัน" ต้องไม่ตามไป
    const otherBoard = await mount(boardHtml(), 'http://localhost/work-board/admin/member/7', storage);
    assert.deepEqual(order(otherBoard.board, 'project-1').slice(0, 3), ['A', 'B', 'C']);

    // ผู้ใช้ที่เลือกเรียงตามกำหนดส่งไว้ใน URL ต้องเห็นลำดับตามที่เลือก
    const dueSorted = await mount(boardHtml(), 'http://localhost/my-tasks?view=board&due_sort=asc', storage);
    assert.deepEqual(order(dueSorted.board, 'project-1').slice(0, 3), ['A', 'B', 'C']);
});

test('dragging still works when browser storage is blocked', async () => {
    const dom = new JSDOM(boardHtml(), {url: 'http://localhost/my-tasks?view=board'});
    Object.defineProperty(dom.window, 'localStorage', {get() { throw new Error('blocked'); }});
    const {initTaskReorder} = await loadModule();
    const board = dom.window.document.querySelector('[data-project-board]');
    stubLayout(board);

    initTaskReorder(board, dom.window);
    drag(dom, titleOf(board, 'B'), 105);

    assert.deepEqual(order(board, 'project-1').slice(0, 2), ['B', 'A']);
});

test('initialising twice does not register duplicate listeners', async () => {
    const {dom, board, module} = await mount();
    module.initTaskReorder(board, dom.window);

    drag(dom, titleOf(board, 'C'), 165);

    assert.deepEqual(order(board, 'project-1').slice(0, 3), ['A', 'C', 'B']);
    assert.equal(dom.window.document.querySelectorAll('[data-task-reorder-hint]').length, 1);
});

test('the task name itself is the drag handle and the grip icon and menu are gone', async () => {
    const [blade, index, floating, css, javascript] = await Promise.all([
        read('resources/views/tasks/components/task-details.blade.php'),
        read('resources/js/pages/mytasks/index.js'),
        read('resources/js/pages/mytasks/board-floating-menu.js'),
        read('resources/css/pages/mytasks/task-details.css'),
        read('resources/js/pages/mytasks/task-reorder.js'),
    ]);

    // task-details.blade.php ถูกใช้ทั้งหน้า "งานของฉัน" และ Workspace ของสมาชิกฝั่ง Admin
    assert.match(blade, /class="board-task-details__heading" data-task-reorder-handle/);
    assert.doesNotMatch(blade, /bi-grip-vertical/);
    assert.doesNotMatch(blade, /data-task-reorder-menu|data-task-reorder="/);
    assert.doesNotMatch(floating, /task-reorder/);
    assert.doesNotMatch(css, /\.board-task-reorder(__|\s*\{|\[)/);
    assert.match(css, /\.board-task-details__heading\s*\{[^}]*grid-template-columns:\s*minmax\(0, 1fr\) 28px/s);
    assert.match(index, /import '\.\/task-reorder\.js';/);
    // ห้ามใช้ HTML5 drag and drop ที่นี่ งานย่อยบนบอร์ดเดียวกันใช้อยู่แล้ว
    assert.doesNotMatch(javascript, /addEventListener\('drag|\.draggable\b|draggable=/);
    assert.doesNotMatch(javascript, /\b(alert|confirm|prompt)\(/);
    // ไม่มีคีย์ลัดเลื่อนลำดับ — ตัดออกตามที่เจ้าของงานตัดสิน
    assert.doesNotMatch(javascript, /altKey|ArrowUp|ArrowDown/);
    // ป้ายลูกศรระหว่างลากใช้ token สีของธีม
    assert.match(css, /\.board-task-reorder-hint\s*\{[^}]*position:\s*fixed/s);
    assert.match(css, /\.board-task-reorder-hint\.is-up > i,[^{]*\{[^}]*var\(--sg-blue\)/s);
});
