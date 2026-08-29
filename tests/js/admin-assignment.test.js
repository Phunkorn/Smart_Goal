import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {initializeAdminAssignment} from '../../resources/js/pages/board/admin-assignment.js';
import {click, clickCheckbox, mountDom, typeInto} from './helpers/dom.js';

const EMPLOYEES = [
    {id: 7, name: 'Member Seven', dept: 'Delivery', departmentId: 3},
    {id: 8, name: 'Member Eight', dept: 'Delivery', departmentId: 3},
    {id: 9, name: 'Member Nine', dept: 'Sales', departmentId: 4},
];

/** markup ที่ต้องตรงกับ resources/views/board/components/admin-assignment-modal.blade.php */
function taskMarkup(index, {assigneeId = '', priority = '2'} = {}) {
    const assigneeOptions = EMPLOYEES.map((person) => `
        <button type="button" class="assignee-option" data-id="${person.id}" data-name="${person.name}" data-dept="${person.dept}" data-search="${person.name.toLowerCase()} ${person.dept.toLowerCase()}">
            <span class="avatar-mini">M</span><span><strong>${person.name}</strong></span>
            <span class="assignee-option-dept">${person.dept}</span>
        </button>`).join('');

    const collaborators = EMPLOYEES.map((person) => `
        <div class="col-md-6 board-collab-item d-none" data-department-id="${person.departmentId}" data-search="${person.name.toLowerCase()} ${person.dept.toLowerCase()}">
            <label class="board-collaborator-choice">
                <input type="checkbox" name="tasks[${index}][collaborators][]" value="${person.id}">
                <strong>${person.name}</strong>
            </label>
        </div>`).join('');

    return `
    <section class="board-project-task" data-admin-task data-task-index="${index}">
        <div><strong data-task-title>งานที่ ${index + 1}</strong>
            <button type="button" class="d-none" data-remove-admin-task>ลบ</button></div>
        <input type="text" name="tasks[${index}][job_topic]" value="">
        <div class="assignee-picker dropdown">
            <button type="button" class="assignee-picker-toggle"><span class="assignee-picker-label text-muted">เลือกผู้รับผิดชอบ...</span></button>
            <div class="dropdown-menu assignee-picker-menu">
                <input type="search" data-task-assignee-search>
                <div class="assignee-picker-list">${assigneeOptions}</div>
                <div class="d-none" data-task-assignee-empty>ไม่พบพนักงานที่ตรงกับคำค้นหา</div>
            </div>
        </div>
        <input type="hidden" name="tasks[${index}][user_id]" data-task-assignee value="${assigneeId}" required>
        <div class="priority-picker dropdown">
            <button type="button" class="priority-picker-toggle"><span class="priority-picker-label text-muted"><span>-- กรุณาเลือกความสำคัญ --</span></span></button>
            <div class="dropdown-menu priority-picker-menu">
                <button type="button" class="priority-option" data-value="3" data-label="สำคัญมาก" data-tone="red"></button>
                <button type="button" class="priority-option" data-value="2" data-label="สำคัญทั่วไป" data-tone="amber"></button>
                <button type="button" class="priority-option" data-value="1" data-label="สำคัญน้อย" data-tone="gray"></button>
            </div>
        </div>
        <input type="hidden" name="tasks[${index}][job_priority]" data-task-priority value="${priority}">
        <select data-task-collaborator-department>
            <option value="">1 เลือกแผนกก่อน...</option>
            <option value="3">Delivery</option>
            <option value="4">Sales</option>
        </select>
        <input type="search" class="d-none" data-task-collaborator-search disabled>
        <div class="board-collaborator-list">${collaborators}
            <div class="board-collaborator-hint" data-task-collaborator-hint></div>
        </div>
        <div><button type="button" data-add-admin-subtask>เพิ่มงานย่อย</button><div data-admin-subtask-list></div></div>
        <input type="file" name="tasks[${index}][attachments][]" data-task-attachments multiple>
        <div class="d-none" data-task-attachments-error></div>
    </section>`;
}

function markup({defaultAssigneeId = '', preselectAssigneeId = '', openOnLoad = false, tasks = [{}]} = {}) {
    const attributes = [
        'data-admin-assignment-modal',
        openOnLoad ? 'data-open-on-load="1"' : '',
        defaultAssigneeId ? `data-default-assignee-id="${defaultAssigneeId}"` : '',
        preselectAssigneeId ? `data-preselect-assignee-id="${preselectAssigneeId}"` : '',
    ].filter(Boolean).join(' ');

    return `<!doctype html><html><body>
        <button type="button" class="admin-assign-button" data-open-admin-assignment>มอบหมายงาน</button>
        <div class="modal fade" id="boardCreateTaskModal" ${attributes}>
            <form action="/admin/tasks" method="POST" data-admin-project-form>
                <input type="text" name="project_name">
                <button type="button" data-add-admin-task>เพิ่มงาน</button>
                <div data-admin-task-list>${tasks.map((task, index) => taskMarkup(index, task)).join('')}</div>
                <button type="submit">สร้างโปรเจกต์</button>
            </form>
        </div>
    </body></html>`;
}

function stubBootstrap(dom) {
    const shown = [];
    globalThis.bootstrap = {
        Modal: {getOrCreateInstance: (element) => ({show: () => shown.push(element)})},
        Dropdown: {getOrCreateInstance: () => ({hide() {}})},
    };
    dom.window.bootstrap = globalThis.bootstrap;

    return shown;
}

test('member workspace context preselects the member on the first task and on every task added later', (t) => {
    const dom = mountDom(markup({defaultAssigneeId: 7}));
    t.after(() => {
        delete globalThis.bootstrap;
        dom.cleanup();
    });
    stubBootstrap(dom);

    const controller = initializeAdminAssignment(dom.document);
    assert.ok(controller);

    const firstTask = dom.document.querySelector('[data-admin-task]');
    assert.equal(firstTask.querySelector('[data-task-assignee]').value, '7');
    assert.equal(firstTask.querySelector('.assignee-picker-label').textContent, 'Member Seven — Delivery');
    assert.equal(firstTask.querySelector('.assignee-picker-label').classList.contains('text-muted'), false);

    click(dom.document.querySelector('[data-add-admin-task]'));

    const tasks = dom.document.querySelectorAll('[data-admin-task]');
    assert.equal(tasks.length, 2);
    assert.equal(tasks[1].querySelector('[data-task-assignee]').value, '7');
    assert.equal(tasks[1].querySelector('[data-task-assignee]').name, 'tasks[1][user_id]');
    assert.equal(tasks[1].querySelector('[data-task-title]').textContent, 'งานที่ 2');
    // Admin ยังเปลี่ยนผู้รับผิดชอบของงานที่เพิ่มใหม่ได้
    click(tasks[1].querySelector('.assignee-option[data-id="9"]'));
    assert.equal(tasks[1].querySelector('[data-task-assignee]').value, '9');
    assert.equal(tasks[0].querySelector('[data-task-assignee]').value, '7');
});

test('board overview context preselects only the first task and leaves new rows empty', (t) => {
    const dom = mountDom(markup({preselectAssigneeId: 8}));
    t.after(() => {
        delete globalThis.bootstrap;
        dom.cleanup();
    });
    stubBootstrap(dom);
    initializeAdminAssignment(dom.document);

    assert.equal(dom.document.querySelector('[data-task-assignee]').value, '8');

    click(dom.document.querySelector('[data-add-admin-task]'));
    const tasks = dom.document.querySelectorAll('[data-admin-task]');
    assert.equal(tasks[1].querySelector('[data-task-assignee]').value, '');
    assert.equal(tasks[1].querySelector('.assignee-picker-label').textContent, 'เลือกผู้รับผิดชอบ...');
});

test('old input rows keep their assignee, priority and collaborator department after a failed validation', (t) => {
    const dom = mountDom(markup({
        defaultAssigneeId: 7,
        openOnLoad: true,
        tasks: [{assigneeId: 9, priority: '3'}, {assigneeId: 7, priority: '1'}],
    }));
    t.after(() => {
        delete globalThis.bootstrap;
        dom.cleanup();
    });
    const shown = stubBootstrap(dom);

    const tasks = dom.document.querySelectorAll('[data-admin-task]');
    tasks[0].querySelector('.board-collab-item[data-department-id="4"] input').checked = true;

    initializeAdminAssignment(dom.document);

    assert.equal(tasks[0].querySelector('.assignee-picker-label').textContent, 'Member Nine — Sales');
    assert.equal(tasks[1].querySelector('.assignee-picker-label').textContent, 'Member Seven — Delivery');
    assert.equal(tasks[0].querySelector('.priority-option[data-value="3"]').classList.contains('active'), true);
    assert.equal(tasks[1].querySelector('.priority-option[data-value="1"]').classList.contains('active'), true);
    // แผนกของผู้ร่วมงานถูกย้อนจากรายชื่อที่ติ๊กไว้ รายการจึงกลับมามองเห็นได้
    assert.equal(tasks[0].querySelector('[data-task-collaborator-department]').value, '4');
    assert.equal(tasks[0].querySelector('.board-collab-item[data-department-id="4"]').classList.contains('d-none'), false);
    assert.equal(tasks[0].querySelector('.board-collab-item[data-department-id="3"]').classList.contains('d-none'), true);
    assert.equal(shown.length, 1);
});

test('collaborators stay hidden until a department is chosen and the hint tracks the result', (t) => {
    const dom = mountDom(markup());
    t.after(() => {
        delete globalThis.bootstrap;
        dom.cleanup();
    });
    stubBootstrap(dom);
    initializeAdminAssignment(dom.document);

    const task = dom.document.querySelector('[data-admin-task]');
    const hint = task.querySelector('[data-task-collaborator-hint]');
    const search = task.querySelector('[data-task-collaborator-search]');

    assert.equal(hint.classList.contains('d-none'), false);
    assert.equal(search.disabled, true);
    assert.equal(task.querySelectorAll('.board-collab-item:not(.d-none)').length, 0);

    const departmentSelect = task.querySelector('[data-task-collaborator-department]');
    departmentSelect.value = '3';
    departmentSelect.dispatchEvent(new dom.window.Event('change', {bubbles: true}));

    assert.equal(search.disabled, false);
    assert.equal(task.querySelectorAll('.board-collab-item:not(.d-none)').length, 2);
    assert.equal(hint.classList.contains('d-none'), true);

    typeInto(search, 'nine');
    assert.equal(task.querySelectorAll('.board-collab-item:not(.d-none)').length, 0);
    assert.equal(hint.classList.contains('d-none'), false);
    assert.equal(hint.textContent, 'ไม่พบพนักงานในแผนกนี้ที่ตรงกับคำค้นหา');
});

test('assignee search filters the options of its own task only and reports an empty result', (t) => {
    const dom = mountDom(markup({tasks: [{}, {}]}));
    t.after(() => {
        delete globalThis.bootstrap;
        dom.cleanup();
    });
    stubBootstrap(dom);
    initializeAdminAssignment(dom.document);

    const [first, second] = dom.document.querySelectorAll('[data-admin-task]');
    typeInto(first.querySelector('[data-task-assignee-search]'), 'nine');

    assert.equal(first.querySelectorAll('.assignee-option:not(.d-none)').length, 1);
    assert.equal(second.querySelectorAll('.assignee-option:not(.d-none)').length, 3);
    assert.equal(first.querySelector('[data-task-assignee-empty]').classList.contains('d-none'), true);

    typeInto(first.querySelector('[data-task-assignee-search]'), 'nobody');
    assert.equal(first.querySelectorAll('.assignee-option:not(.d-none)').length, 0);
    assert.equal(first.querySelector('[data-task-assignee-empty]').classList.contains('d-none'), false);
});

test('every task row validates its own attachments, not only the first one', (t) => {
    const dom = mountDom(markup({tasks: [{}, {}]}));
    t.after(() => {
        delete globalThis.bootstrap;
        dom.cleanup();
    });
    stubBootstrap(dom);
    initializeAdminAssignment(dom.document);

    const secondTask = dom.document.querySelectorAll('[data-admin-task]')[1];
    const input = secondTask.querySelector('[data-task-attachments]');
    Object.defineProperty(input, 'files', {
        configurable: true,
        value: [{name: 'payload.zip', size: 1024}],
    });
    input.dispatchEvent(new dom.window.Event('change', {bubbles: true}));

    const errorBox = secondTask.querySelector('[data-task-attachments-error]');
    assert.equal(errorBox.classList.contains('d-none'), false);
    assert.match(errorBox.textContent, /payload\.zip/);
    assert.equal(input.value, '');
});

test('submitting without an assignee is blocked through SweetAlert2 instead of a native alert', (t) => {
    const dom = mountDom(markup());
    t.after(() => {
        delete globalThis.bootstrap;
        delete globalThis.Swal;
        dom.cleanup();
    });
    stubBootstrap(dom);
    const warnings = [];
    globalThis.Swal = {fire: (options) => warnings.push(options.title)};
    initializeAdminAssignment(dom.document);

    const form = dom.document.querySelector('[data-admin-project-form]');
    const submit = new dom.window.Event('submit', {bubbles: true, cancelable: true});
    form.dispatchEvent(submit);

    assert.equal(submit.defaultPrevented, true);
    assert.deepEqual(warnings, ['กรุณาเลือกผู้รับผิดชอบให้ครบทุกงาน']);
});

test('the assign button opens the modal in place and the initializer never binds twice', (t) => {
    const dom = mountDom(markup({defaultAssigneeId: 7}));
    t.after(() => {
        delete globalThis.bootstrap;
        dom.cleanup();
    });
    const shown = stubBootstrap(dom);

    const controller = initializeAdminAssignment(dom.document);
    assert.equal(shown.length, 0, 'ไม่มี validation error จึงต้องยังไม่เปิดเอง');
    assert.equal(initializeAdminAssignment(dom.document), null);

    click(dom.document.querySelector('[data-open-admin-assignment]'));
    assert.equal(shown.length, 1);
    assert.equal(shown[0], dom.document.querySelector('[data-admin-assignment-modal]'));

    controller.destroy();
    click(dom.document.querySelector('[data-open-admin-assignment]'));
    assert.equal(shown.length, 1);
});

test('subtask rows are added and reindexed inside their own task', (t) => {
    const dom = mountDom(markup({tasks: [{}, {}]}));
    t.after(() => {
        delete globalThis.bootstrap;
        dom.cleanup();
    });
    stubBootstrap(dom);
    initializeAdminAssignment(dom.document);

    const secondTask = dom.document.querySelectorAll('[data-admin-task]')[1];
    click(secondTask.querySelector('[data-add-admin-subtask]'));
    click(secondTask.querySelector('[data-add-admin-subtask]'));

    const names = Array.from(secondTask.querySelectorAll('.board-project-subtask [name]')).map((field) => field.name);
    assert.deepEqual(names, [
        'tasks[1][subtasks][0][title]',
        'tasks[1][subtasks][0][details]',
        'tasks[1][subtasks][1][title]',
        'tasks[1][subtasks][1][details]',
    ]);

    click(secondTask.querySelector('[data-remove-admin-subtask]'));
    assert.equal(secondTask.querySelectorAll('.board-project-subtask').length, 1);
    assert.equal(secondTask.querySelector('.board-project-subtask [name]').name, 'tasks[1][subtasks][0][title]');
});

test('the Blade partial and both admin pages share one assignment modal implementation', async () => {
    const read = async (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

    const partial = await read('resources/views/board/components/admin-assignment-modal.blade.php');
    const boardIndex = await read('resources/views/board/index.blade.php');
    const memberWorkspace = await read('resources/views/work-board/admin/member.blade.php');

    assert.match(partial, /data-admin-assignment-modal/);
    assert.match(boardIndex, /@include\('board\.components\.admin-assignment-modal'/);
    assert.match(memberWorkspace, /@include\('board\.components\.admin-assignment-modal'/);
    assert.match(memberWorkspace, /'assignmentOrigin' => \['department_id' => \$department->id, 'member_id' => \$member->id\]/);

    // โมดัลต้องอยู่ในไฟล์เดียว ไม่ถูก copy กลับไปฝังในหน้าใดหน้าหนึ่ง
    for (const source of [boardIndex, memberWorkspace]) {
        assert.equal(source.includes('id="boardCreateTaskModal"'), false);
        assert.equal(source.includes('data-admin-project-form'), false);
        assert.equal(source.includes('data-admin-task-list'), false);
    }

    // พฤติกรรมของโมดัลย้ายไป module เดียว ไม่เหลือ inline script ในหน้า board
    assert.equal(boardIndex.includes('data-open-admin-assignment'), false);
    assert.match(boardIndex, /resources\/js\/pages\/board\/admin-assignment\.js/);
    assert.match(memberWorkspace, /resources\/js\/pages\/board\/admin-assignment\.js/);
    // ปุ่มเดิมยังเป็น .btn.btn-primary.admin-assign-button แต่เป็น button ไม่ใช่ลิงก์ออกนอกหน้า
    assert.match(memberWorkspace, /<button type="button" class="btn btn-primary admin-assign-button" data-open-admin-assignment>/);
    assert.equal(memberWorkspace.includes('open_assignment'), false);
});
