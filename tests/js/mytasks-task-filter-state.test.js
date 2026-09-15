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
        {search: 'Printer', status: 'late', dueSort: 'desc'},
    );
    assert.deepEqual(
        boardFilterStateFrom(new URLSearchParams('status=99&due_sort=random')),
        {search: '', status: '', dueSort: ''},
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
