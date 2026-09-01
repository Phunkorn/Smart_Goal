import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {initializeAdminAssignment} from '../../resources/js/pages/board/admin-assignment.js';
import {click, mountDom, typeInto} from './helpers/dom.js';

const EMPLOYEES = [
    {id: 7, name: 'Member Seven', dept: 'Delivery', departmentId: 3},
    {id: 8, name: 'Member Eight', dept: 'Delivery', departmentId: 3},
    {id: 9, name: 'Member Nine', dept: 'Sales', departmentId: 4},
];

function taskMarkup({assigneeId = ''} = {}) {
    const assigneeOptions = EMPLOYEES.map((person) => `
        <button type="button" class="assignee-option" data-id="${person.id}" data-name="${person.name}"
            data-dept="${person.dept}" data-search="${person.name.toLowerCase()} ${person.dept.toLowerCase()}">
            <span class="avatar-mini">M</span><strong>${person.name}</strong>
        </button>`).join('');
    const collaborators = EMPLOYEES.map((person) => `
        <label class="board-collab-item d-none" data-department-id="${person.departmentId}"
            data-search="${person.name.toLowerCase()} ${person.dept.toLowerCase()}">
            <input type="checkbox" name="collaborators[]" value="${person.id}">${person.name}
        </label>`).join('');

    return `<section class="board-project-task" data-admin-task>
        <input name="job_topic" value="First task">
        <select name="job_priority"><option value="2" selected>ทั่วไป</option></select>
        <input name="job_start_at" value="2026-08-31T09:00">
        <input name="job_due_at" value="2026-09-01T09:00">
        <div class="assignee-picker">
            <button type="button" class="assignee-picker-toggle"><span class="assignee-picker-label text-muted">เลือกผู้รับผิดชอบ...</span></button>
            <input type="search" data-task-assignee-search>
            ${assigneeOptions}
            <div class="d-none" data-task-assignee-empty>ไม่พบพนักงาน</div>
        </div>
        <input type="hidden" name="user_id" data-task-assignee value="${assigneeId}" required>
        <select data-task-collaborator-department>
            <option value="">เลือกแผนกก่อน</option><option value="3">Delivery</option><option value="4">Sales</option>
        </select>
        <input type="search" class="d-none" data-task-collaborator-search disabled>
        <div>${collaborators}<div data-task-collaborator-hint></div></div>
        <input type="file" name="attachments[]" data-task-attachments multiple>
        <div class="d-none" data-task-attachments-error></div>
    </section>`;
}

function markup({defaultAssigneeId = '', preselectAssigneeId = '', initialStep = 'project', projectId = '', openOnLoad = false} = {}) {
    const ownerId = defaultAssigneeId || preselectAssigneeId;

    return `<!doctype html><html><body>
        <button type="button" class="admin-assignment-launch" data-open-admin-assignment>มอบหมายงาน</button>
        <div class="modal fade" id="boardCreateTaskModal" data-admin-assignment-modal
            data-initial-step="${initialStep}" data-default-assignee-id="${defaultAssigneeId}"
            data-preselect-assignee-id="${preselectAssigneeId}" ${openOnLoad ? 'data-open-on-load="1"' : ''}>
            <h2 data-assignment-title></h2><p data-assignment-subtitle></p>
            <span data-step-indicator="project"></span><span data-step-indicator="task"></span>
            <div data-admin-assignment-errors hidden></div>
            <form action="/my-tasks/new-task" method="POST" data-admin-project-form ${initialStep === 'task' ? 'hidden' : ''}>
                <input name="project_name" value="Project Alpha">
                <input name="project_priority" value="2">
                ${ownerId ? `<input type="hidden" name="project_owner_id" value="${ownerId}">` : ''}
                <button type="submit" data-project-submit>สร้างโปรเจกต์</button>
            </form>
            <form action="/tasks" method="POST" data-admin-task-form ${initialStep === 'task' ? '' : 'hidden'}>
                <input type="hidden" name="work_order_list_id" data-selected-project-id value="${projectId}">
                <select data-project-select><option value="">เลือกโปรเจกต์</option>${projectId ? `<option value="${projectId}" selected>Existing Project</option>` : ''}</select>
                <button type="button" data-create-another-project>สร้างโปรเจกต์ใหม่</button>
                ${taskMarkup({assigneeId: defaultAssigneeId || preselectAssigneeId})}
                <button type="button" data-back-to-project>ย้อนกลับ</button>
                <button type="submit" data-task-submit="next">บันทึกและเพิ่มงานถัดไป</button>
                <button type="submit" data-task-submit="done">มอบหมายงาน</button>
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

function submit(dom, form, button) {
    const event = new dom.window.Event('submit', {bubbles: true, cancelable: true});
    Object.defineProperty(event, 'submitter', {value: button});
    form.dispatchEvent(event);
    return event;
}

const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

test('member workspace starts on its existing project and preselects the member', (t) => {
    const dom = mountDom(markup({defaultAssigneeId: 7, initialStep: 'task', projectId: 44}));
    t.after(() => { delete globalThis.bootstrap; dom.cleanup(); });
    stubBootstrap(dom);

    const controller = initializeAdminAssignment(dom.document);
    assert.ok(controller);
    assert.equal(controller.projectForm.hidden, true);
    assert.equal(controller.taskForm.hidden, false);
    assert.equal(controller.taskForm.querySelector('[data-selected-project-id]').value, '44');
    assert.equal(controller.taskForm.querySelector('[data-task-assignee]').value, '7');
    assert.equal(controller.taskForm.querySelector('.assignee-picker-label').textContent, 'Member Seven — Delivery');
});

test('creating a project posts the shared project form then advances to one task', async (t) => {
    const dom = mountDom(markup({defaultAssigneeId: 7}));
    t.after(() => { delete globalThis.bootstrap; dom.cleanup(); });
    stubBootstrap(dom);
    const calls = [];
    const notices = [];
    const controller = initializeAdminAssignment(dom.document, {
        fetch: async (url, options) => {
            calls.push({url, options});
            return {ok: true, json: async () => ({list_id: 51, message: 'เพิ่มโปรเจกต์สำเร็จ'})};
        },
        notify: (message) => notices.push(message),
    });

    const event = submit(dom, controller.projectForm, controller.projectForm.querySelector('[data-project-submit]'));
    assert.equal(event.defaultPrevented, true);
    await settle();

    assert.equal(calls.length, 1);
    assert.equal(calls[0].url, 'http://localhost/my-tasks/new-task');
    assert.equal(calls[0].options.body.get('project_name'), 'Project Alpha');
    assert.equal(calls[0].options.body.get('project_owner_id'), '7');
    assert.equal(controller.projectForm.hidden, true);
    assert.equal(controller.taskForm.hidden, false);
    assert.equal(controller.taskForm.querySelector('[data-selected-project-id]').value, '51');
    assert.equal(controller.taskForm.querySelector('[data-project-select]').value, '51');
    assert.deepEqual(notices, ['เพิ่มโปรเจกต์สำเร็จ']);
});

test('save and add next keeps the project and assignee while clearing task content', async (t) => {
    const dom = mountDom(markup({defaultAssigneeId: 7, initialStep: 'task', projectId: 44}));
    t.after(() => { delete globalThis.bootstrap; dom.cleanup(); });
    stubBootstrap(dom);
    const calls = [];
    const controller = initializeAdminAssignment(dom.document, {
        fetch: async (url, options) => {
            calls.push({url, options});
            return {ok: true, json: async () => ({job_id: 90, list_id: 44, message: 'เพิ่มงานสำเร็จ'})};
        },
        notify() {},
    });

    submit(dom, controller.taskForm, controller.taskForm.querySelector('[data-task-submit="next"]'));
    await settle();

    assert.equal(calls.length, 1);
    assert.equal(calls[0].url, 'http://localhost/tasks');
    assert.equal(calls[0].options.body.get('work_order_list_id'), '44');
    assert.equal(calls[0].options.body.get('user_id'), '7');
    assert.equal(calls[0].options.body.has('job_details'), false, 'Admin UI ต้องไม่ส่งฟิลด์รายละเอียดงานแบบ legacy');
    assert.equal(calls[0].options.body.get('job_status'), null, 'สถานะเริ่มต้นต้องมาจาก backend เท่านั้น');
    assert.equal(controller.taskForm.querySelector('[name="job_topic"]').value, '');
    assert.equal(controller.taskForm.querySelector('[data-selected-project-id]').value, '44');
    assert.equal(controller.taskForm.querySelector('[data-task-assignee]').value, '7');
});

test('final task submit hands the response to the completion owner', async (t) => {
    const dom = mountDom(markup({preselectAssigneeId: 8, initialStep: 'task', projectId: 22}));
    t.after(() => { delete globalThis.bootstrap; dom.cleanup(); });
    stubBootstrap(dom);
    const completed = [];
    const controller = initializeAdminAssignment(dom.document, {
        fetch: async () => ({ok: true, json: async () => ({job_id: 91, list_id: 22})}),
        notify() {},
        onDone: (payload) => completed.push(payload),
    });

    submit(dom, controller.taskForm, controller.taskForm.querySelector('[data-task-submit="done"]'));
    await settle();
    assert.deepEqual(completed, [{job_id: 91, list_id: 22}]);
});

test('collaborators stay hidden until a department is chosen and search is scoped', (t) => {
    const dom = mountDom(markup({initialStep: 'task', projectId: 1}));
    t.after(() => { delete globalThis.bootstrap; dom.cleanup(); });
    stubBootstrap(dom);
    initializeAdminAssignment(dom.document);
    const task = dom.document.querySelector('[data-admin-task]');
    const search = task.querySelector('[data-task-collaborator-search]');

    assert.equal(search.disabled, true);
    assert.equal(task.querySelectorAll('.board-collab-item:not(.d-none)').length, 0);
    const department = task.querySelector('[data-task-collaborator-department]');
    department.value = '3';
    department.dispatchEvent(new dom.window.Event('change', {bubbles: true}));
    assert.equal(search.disabled, false);
    assert.equal(task.querySelectorAll('.board-collab-item:not(.d-none)').length, 2);
    typeInto(search, 'nine');
    assert.equal(task.querySelectorAll('.board-collab-item:not(.d-none)').length, 0);
    assert.match(task.querySelector('[data-task-collaborator-hint]').textContent, /ไม่พบพนักงาน/);
});

test('assignee search and attachment validation remain local to the single task', (t) => {
    const dom = mountDom(markup({initialStep: 'task', projectId: 1}));
    t.after(() => { delete globalThis.bootstrap; dom.cleanup(); });
    stubBootstrap(dom);
    initializeAdminAssignment(dom.document);
    const task = dom.document.querySelector('[data-admin-task]');

    typeInto(task.querySelector('[data-task-assignee-search]'), 'nine');
    assert.equal(task.querySelectorAll('.assignee-option:not(.d-none)').length, 1);

    const input = task.querySelector('[data-task-attachments]');
    Object.defineProperty(input, 'files', {configurable: true, value: [{name: 'payload.zip', size: 1024}]});
    input.dispatchEvent(new dom.window.Event('change', {bubbles: true}));
    assert.match(task.querySelector('[data-task-attachments-error]').textContent, /payload\.zip/);
    assert.equal(input.value, '');
});

test('task submit requires both a project and an assignee through SweetAlert2', (t) => {
    const dom = mountDom(markup({initialStep: 'task'}));
    t.after(() => { delete globalThis.bootstrap; delete globalThis.Swal; dom.cleanup(); });
    stubBootstrap(dom);
    const warnings = [];
    globalThis.Swal = {fire: (options) => warnings.push(options.title)};
    const controller = initializeAdminAssignment(dom.document);
    const done = controller.taskForm.querySelector('[data-task-submit="done"]');

    submit(dom, controller.taskForm, done);
    controller.taskForm.querySelector('[data-selected-project-id]').value = '5';
    submit(dom, controller.taskForm, done);
    assert.deepEqual(warnings, ['กรุณาเลือกโปรเจกต์', 'กรุณาเลือกผู้รับผิดชอบ']);
});

test('the assignment trigger opens in place and the initializer binds once', (t) => {
    const dom = mountDom(markup({defaultAssigneeId: 7}));
    t.after(() => { delete globalThis.bootstrap; dom.cleanup(); });
    const shown = stubBootstrap(dom);
    const controller = initializeAdminAssignment(dom.document);

    assert.equal(initializeAdminAssignment(dom.document), null);
    click(dom.document.querySelector('[data-open-admin-assignment]'));
    assert.equal(shown.length, 1);
    assert.equal(shown[0], controller.modal);
    controller.destroy();
    click(dom.document.querySelector('[data-open-admin-assignment]'));
    assert.equal(shown.length, 1);
});

test('Blade pages share one modal and the user/admin project fields share one component', async () => {
    const read = async (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');
    const partial = await read('resources/views/board/components/admin-assignment-modal.blade.php');
    const projectFields = await read('resources/views/tasks/components/project-form-fields.blade.php');
    const userWorkspace = await read('resources/views/tasks/partials/workspace-interactions.blade.php');
    const boardIndex = await read('resources/views/board/index.blade.php');
    const memberWorkspace = await read('resources/views/work-board/admin/member.blade.php');
    const modalCss = await read('resources/css/pages/board/modal.css');

    assert.match(partial, /data-admin-project-form/);
    assert.match(partial, /data-admin-task-form/);
    assert.match(partial, /route\('mytasks\.create'\)/);
    assert.match(partial, /route\('tasks\.store'\)/);
    assert.match(partial, /tasks\.components\.project-form-fields/);
    assert.match(partial, /โปรเจกต์ปลายทาง/);
    assert.match(partial, /ชื่อรายการงาน/);
    assert.doesNotMatch(partial, /name="job_details"/);
    assert.match(modalCss, /\.admin-project-choice__heading\s*\{/);
    assert.match(modalCss, /@media \(max-width: 430px\)[\s\S]*\.admin-task-heading\s*\{\s*flex-direction: column;/);
    assert.match(userWorkspace, /tasks\.components\.project-form-fields/);
    assert.match(projectFields, /WorkBoardDesign::PROJECT_PRIORITIES/);
    assert.match(boardIndex, /@include\('board\.components\.admin-assignment-modal'/);
    assert.match(memberWorkspace, /@include\('board\.components\.admin-assignment-modal'/);
    assert.match(memberWorkspace, /'projectOptions' => \$manageableTaskLists/);
    assert.match(memberWorkspace, /class="admin-assignment-launch admin-assign-button"/);

    for (const source of [boardIndex, memberWorkspace]) {
        assert.equal(source.includes('id="boardCreateTaskModal"'), false);
        assert.equal(source.includes('data-admin-project-form'), false);
    }
});
