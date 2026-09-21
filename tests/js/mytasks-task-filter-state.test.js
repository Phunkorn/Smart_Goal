import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {
    boardFilterStateFrom,
    boardTaskMatches,
    normalizeTaskScope,
    parametersForTaskWorkspace,
} from '../../resources/js/pages/mytasks/task-filter-state.js';

test('task scope normalization only accepts the five supported values', () => {
    assert.equal(normalizeTaskScope('assigned_by_me'), 'assigned_by_me');
    assert.equal(normalizeTaskScope('collaborating'), 'collaborating');
    assert.equal(normalizeTaskScope('someone_else'), 'all');
});

test('board filter state restores valid URL values and rejects invalid state', () => {
    assert.deepEqual(
        boardFilterStateFrom(new URLSearchParams('search=Printer&status=late&due_sort=desc')),
        {search: 'Printer', status: 'late', dueSort: 'desc', mine: false},
    );
    assert.deepEqual(
        boardFilterStateFrom(new URLSearchParams('status=99&due_sort=random')),
        {search: '', status: '', dueSort: '', mine: false},
    );
});

test('workspace parameters combine scope search status and due sorting', () => {
    const parameters = parametersForTaskWorkspace(
        new URLSearchParams('open_task=42'),
        {search: 'Printer', status: '6', dueSort: 'asc'},
        'assigned_by_me',
    );

    assert.equal(parameters.get('task_scope'), 'assigned_by_me');
    assert.equal(parameters.get('search'), 'Printer');
    assert.equal(parameters.has('status'), false);
    assert.equal(parameters.get('due_sort'), 'asc');
    assert.equal(parameters.get('open_task'), '42');

    const defaults = parametersForTaskWorkspace(parameters, {search: '', status: '', dueSort: ''}, 'all');
    assert.equal(defaults.has('task_scope'), false);
    assert.equal(defaults.has('search'), false);
    assert.equal(defaults.has('due_sort'), false);
});

test('board matching combines text and status including late semantics', () => {
    const task = {searchable: 'Hardware Printer User B', status: '2', late: '1'};

    assert.equal(boardTaskMatches(task, {search: 'printer', status: ''}), true);
    assert.equal(boardTaskMatches(task, {search: 'printer', status: 'late'}), true);
    assert.equal(boardTaskMatches(task, {search: 'printer', status: '3'}), false);
    assert.equal(boardTaskMatches(task, {search: 'scanner', status: 'late'}), false);
});

test('my review filter only matches pending tasks the current user can review', () => {
    assert.equal(boardTaskMatches(
        {searchable: 'Approval', status: '3', canReview: '1', late: '0'},
        {search: '', status: 'my_review'},
    ), true);
    assert.equal(boardTaskMatches(
        {searchable: 'Approval', status: '3', canReview: '0', late: '0'},
        {search: '', status: 'my_review'},
    ), false);
    assert.equal(boardTaskMatches(
        {searchable: 'Approval', status: '2', canReview: '1', late: '0'},
        {search: '', status: 'my_review'},
    ), false);
    assert.equal(boardTaskMatches(
        {searchable: 'Parent task', status: '2', canReview: '0', reviewableSubtasks: '1', late: '0'},
        {search: '', status: 'my_review'},
    ), true);
});

test('the two review filters never overlap, so a task lands in exactly one of them', () => {
    /*
     * ของเดิมคู่นี้เป็น 'my_review' กับ '3' ซึ่ง '3' เป็นซูเปอร์เซ็ตของอีกตัว
     * ผู้ใช้จึงเห็นสองบรรทัดที่ขึ้นต้นว่า "รอตรวจ" เหมือนกันแล้วเดาไม่ออกว่าต่างกันตรงไหน
     * ตอนนี้แยกเป็น "รอฉันตรวจ" กับ "รอคนอื่นตรวจ" ที่ตัดกันเป็นศูนย์
     */
    const waitingForMe = {searchable: 'ส่งกลับมาให้เราตรวจ', status: '3', canReview: '1', late: '0'};
    const waitingForSomeoneElse = {searchable: 'เราส่งไปรอตรวจ', status: '3', canReview: '0', late: '0'};

    assert.equal(boardTaskMatches(waitingForMe, {search: '', status: 'my_review'}), true);
    assert.equal(boardTaskMatches(waitingForMe, {search: '', status: 'awaiting_review'}), false);

    assert.equal(boardTaskMatches(waitingForSomeoneElse, {search: '', status: 'awaiting_review'}), true);
    assert.equal(boardTaskMatches(waitingForSomeoneElse, {search: '', status: 'my_review'}), false);

    // งานแม่ที่มีงานย่อยรอเราตรวจ ถือว่าลูกบอลอยู่ที่เรา จึงต้องไม่ไปโผล่ฝั่ง "รอคนอื่นตรวจ"
    const parentWithReviewableChild = {
        searchable: 'งานแม่', status: '3', canReview: '0', reviewableSubtasks: '1', late: '0',
    };
    assert.equal(boardTaskMatches(parentWithReviewableChild, {search: '', status: 'my_review'}), true);
    assert.equal(boardTaskMatches(parentWithReviewableChild, {search: '', status: 'awaiting_review'}), false);
});

test('cross department filter matches only rows the server marked as cross department', () => {
    assert.equal(boardFilterStateFrom('status=cross_department').status, 'cross_department');
    assert.equal(boardTaskMatches(
        {searchable: 'ทดสอบข้ามแผนก', status: '2', late: '0', crossDepartment: '1'},
        {search: '', status: 'cross_department'},
    ), true);
    assert.equal(boardTaskMatches(
        {searchable: 'งานในแผนก', status: '2', late: '0', crossDepartment: '0'},
        {search: '', status: 'cross_department'},
    ), false);
    // แถวที่ไม่มี attribute เลยต้องไม่ถูกนับว่าข้ามแผนก
    assert.equal(boardTaskMatches({searchable: 'x', status: '2'}, {search: '', status: 'cross_department'}), false);
});

test('every workspace filter caller forwards the cross department flag', async () => {
    const sources = await Promise.all([
        '../../resources/js/mytasks-project-board.js',
        '../../resources/js/mytasks-notion.js',
        '../../resources/js/pages/mytasks/table-kanban.js',
    ].map((path) => readFile(new URL(path, import.meta.url), 'utf8')));

    for (const source of sources) assert.match(source, /crossDepartment: \w+\.dataset\.crossDepartment/);

    const [filterPartial, boardRow] = await Promise.all([
        readFile(new URL('../../resources/views/tasks/partials/status-filter.blade.php', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/views/tasks/partials/project-board-card.blade.php', import.meta.url), 'utf8'),
    ]);
    assert.match(filterPartial, /<option value="cross_department"/);
    assert.match(boardRow, /data-cross-department=/);
});

test('the retired all-review filter value is no longer accepted from the url', () => {
    // ตัวเลือก "รอตรวจสอบทั้งหมด" ถูกเอาออกแล้ว ลิงก์เก่าที่ยังถือ ?status=3 ต้องตกกลับไปเป็นทุกสถานะ
    assert.equal(boardFilterStateFrom('status=3').status, '');
    assert.equal(boardFilterStateFrom('status=awaiting_review').status, 'awaiting_review');
    assert.equal(boardFilterStateFrom('status=my_review').status, 'my_review');
});

test('table cards can reuse board matching for reviewable subtasks', () => {
    const parentCard = {
        searchable: 'Parent task',
        status: '2',
        canReview: '0',
        reviewableSubtasks: '1',
        late: '0',
    };

    assert.equal(boardTaskMatches(parentCard, {search: '', status: 'my_review'}), true);
    assert.equal(boardTaskMatches(parentCard, {search: '', status: '2'}), true);
    assert.equal(boardTaskMatches(parentCard, {search: '', status: '4'}), false);
});

test('board search reads the explicit searchable text rendered for names hidden inside avatars', async () => {
    const [boardScript, boardRow] = await Promise.all([
        readFile(new URL('../../resources/js/mytasks-project-board.js', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/views/tasks/partials/project-board-card.blade.php', import.meta.url), 'utf8'),
    ]);

    assert.match(boardScript, /task\.dataset\.searchText/);
    assert.match(boardRow, /data-search-text=/);
    assert.match(boardRow, /\$collaborators->pluck\('name'\)/);
    assert.match(boardRow, /\$assigneeName/);
});

test('the mine flag round-trips through the url exactly like the other board filters', () => {
    assert.equal(boardFilterStateFrom('mine=1').mine, true);

    // ค่าเริ่มต้นต้องเป็นปิดเสมอ บอร์ดตอนโหลดหน้าจึงยังแสดงทั้งโปรเจกต์เหมือนเดิม
    assert.equal(boardFilterStateFrom('').mine, false);
    assert.equal(boardFilterStateFrom('mine=0').mine, false);
    assert.equal(boardFilterStateFrom('mine=true').mine, false);
    assert.equal(boardFilterStateFrom('mine=yes').mine, false);

    const enabled = parametersForTaskWorkspace(
        new URLSearchParams('open_task=42'),
        {search: '', status: '', dueSort: '', mine: true},
        'all',
    );
    assert.equal(enabled.get('mine'), '1');
    assert.equal(enabled.get('open_task'), '42', 'พารามิเตอร์อื่นต้องไม่หายไป');

    const disabled = parametersForTaskWorkspace(
        new URLSearchParams('mine=1&open_task=42'),
        {search: '', status: '', dueSort: '', mine: false},
        'all',
    );
    assert.equal(disabled.has('mine'), false, 'ปิดปุ่มแล้วพารามิเตอร์ต้องหายจาก URL');
    assert.equal(disabled.get('open_task'), '42');
});

test('the mine filter only narrows, never resurrects a row another filter removed', () => {
    const mine = {searchable: 'งานของฉัน', status: '2', late: '1', participate: '1'};
    const sibling = {searchable: 'งานของเพื่อนร่วมโปรเจกต์', status: '2', late: '1', participate: '0'};

    // ปิดอยู่ = ไม่สนใจความเป็นเจ้าของเลย บอร์ดจึงเหมือนเดิมทุกประการ
    assert.equal(boardTaskMatches(sibling, {search: '', status: '', mine: false}), true);
    assert.equal(boardTaskMatches(sibling, {search: '', status: ''}), true);

    // เปิดอยู่ = ตัดงานที่ไม่ได้ร่วมออก
    assert.equal(boardTaskMatches(mine, {search: '', status: '', mine: true}), true);
    assert.equal(boardTaskMatches(sibling, {search: '', status: '', mine: true}), false);

    // ประกอบกับตัวกรองอื่นเป็น AND ได้ผลลัพธ์เป็นอินเตอร์เซกชันเสมอ
    assert.equal(boardTaskMatches(mine, {search: '', status: 'late', mine: true}), true);
    assert.equal(boardTaskMatches(mine, {search: '', status: '4', mine: true}), false);
    assert.equal(boardTaskMatches(mine, {search: 'ของฉัน', status: '', mine: true}), true);
    assert.equal(boardTaskMatches(mine, {search: 'ไม่มีคำนี้', status: '', mine: true}), false);

    // แถวที่ไม่มี attribute เลยต้องไม่ถูกนับว่าเป็นงานของเรา
    assert.equal(boardTaskMatches({searchable: 'x', status: '2'}, {search: '', status: '', mine: true}), false);
});

test('every board filter caller forwards the participation flag from the server', async () => {
    const [boardScript, viewsScript, boardRow, subtaskRow, filterPartial, indexView] = await Promise.all([
        readFile(new URL('../../resources/js/mytasks-project-board.js', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/js/mytasks-views.js', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/views/tasks/partials/project-board-card.blade.php', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/views/tasks/components/task-detail-row.blade.php', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/views/tasks/partials/mine-filter.blade.php', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/views/tasks/index.blade.php', import.meta.url), 'utf8'),
    ]);

    // งานแม่และงานย่อยต้องส่ง flag เข้าตัวกรองทั้งคู่ ไม่งั้นงานย่อยของเราจะหายไปพร้อมงานแม่
    assert.match(boardScript, /participate: hasOwnSubtask \? '1' : task\.dataset\.participate/);
    assert.match(boardScript, /participate: subtask\.dataset\.participate/);
    // การยกเว้นให้งานแม่ต้องผ่าน boardTaskMatches เสมอ ห้ามปลดเงื่อนไข task.hidden ทีหลัง
    // ไม่งั้นงานแม่จะโผล่ข้ามตัวกรองสถานะและคำค้นที่ตัดมันทิ้งไปแล้ว
    assert.match(boardScript, /task\.hidden = !taskMatches && reviewSubtasks\.length === 0;/);

    // ความเป็นเจ้าของตัดสินที่ server ด้วย ability participate ที่เดียว
    assert.match(boardRow, /can\('participate', \$task\)/);
    assert.match(boardRow, /data-participate="\{\{ \$taskIsMine \? 1 : 0 \}\}"/);
    assert.match(subtaskRow, /can\('participate', \$detail\)/);
    assert.match(subtaskRow, /data-participate="\{\{ \$detailIsMine \? 1 : 0 \}\}"/);

    // ปุ่มต้องเริ่มที่ "ปิด" เสมอ
    assert.match(filterPartial, /aria-pressed="false"/);
    assert.match(filterPartial, /data-board-mine-toggle/);

    // และต้องอยู่ก่อนปุ่มคลังโปรเจกต์ ซึ่งเป็นตัวถือ margin-inline-start:auto ของแถว
    assert.ok(
        indexView.indexOf("tasks.partials.mine-filter") < indexView.indexOf('data-open-completed-projects'),
        'ปุ่มเฉพาะงานของฉันต้องอยู่ก่อนปุ่มคลังโปรเจกต์',
    );

    // แสดงเฉพาะมุมมองบอร์ด และต้องคำนวณใหม่ทุกครั้งที่สลับมุมมอง ไม่ใช่เชื่อค่าจาก Blade
    assert.match(viewsScript, /const MINE_FILTER_VIEWS = \['board'\];/);
    assert.match(viewsScript, /mineFilter\.hidden = ! MINE_FILTER_VIEWS\.includes\(view\)/);
});
