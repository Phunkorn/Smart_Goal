import assert from 'node:assert/strict';
import test from 'node:test';

import {publishTaskState, synchronizeCompletedTaskGroup, synchronizeTaskManagement, synchronizeTaskSource} from '../../resources/js/pages/mytasks/task-state.js';
import {taskStateStore} from '../../resources/js/pages/mytasks/task-state-store.js';
import {mountDom} from './helpers/dom.js';

test('completed tasks move into the project completed group without reloading', (t) => {
    const dom = mountDom(`
        <div data-board-list-body>
            <header data-project-header data-project-key="project-1"></header>
            <article data-board-task data-task-id="10" data-project-key="project-1"></article>
            <article data-board-task data-task-id="11" data-project-key="project-1"></article>
            <header data-project-header data-project-key="project-2"></header>
        </div>
    `);
    t.after(() => dom.cleanup());

    const cardGrid = dom.document.querySelector('[data-board-list-body]');
    const first = cardGrid.querySelector('[data-task-id="10"]');
    const second = cardGrid.querySelector('[data-task-id="11"]');

    const group = synchronizeCompletedTaskGroup(cardGrid, first, 4);
    assert.ok(group);
    assert.equal(first.closest('[data-completed-group]'), group);
    assert.equal(group.querySelector('summary span').textContent, '1 งาน');
    assert.equal(group.nextElementSibling.dataset.projectKey, 'project-2');

    synchronizeCompletedTaskGroup(cardGrid, second, 4);
    assert.equal(group.querySelectorAll('.board-completed-group__rows > [data-board-task]').length, 2);
    assert.equal(group.querySelector('summary span').textContent, '2 งาน');
});

test('reopened tasks leave the completed group and remove an empty group', (t) => {
    const dom = mountDom(`
        <div data-board-list-body>
            <header data-project-header data-project-key="project-1"></header>
            <details class="board-completed-group" data-completed-group data-project-key="project-1">
                <summary><i></i><strong>งานที่เสร็จแล้ว</strong><span>1 งาน</span></summary>
                <div class="board-completed-group__rows">
                    <article data-board-task data-task-id="10" data-project-key="project-1"></article>
                </div>
            </details>
        </div>
    `);
    t.after(() => dom.cleanup());

    const cardGrid = dom.document.querySelector('[data-board-list-body]');
    const task = cardGrid.querySelector('[data-task-id="10"]');

    assert.equal(synchronizeCompletedTaskGroup(cardGrid, task, 2), null);
    assert.equal(task.closest('[data-completed-group]'), null);
    assert.equal(cardGrid.querySelector('[data-completed-group]'), null);
    assert.equal(task.previousElementSibling.matches('[data-project-header]'), true);
});

test('reopening refreshes the modal edit permission without a page reload', () => {
    const management = {
        10: {
            can_work: false,
            transitions: {can_edit: false, can_reopen: true, is_final: true},
        },
    };

    const meta = synchronizeTaskManagement(management, 10, {
        job_status: 2,
        transitions: {can_edit: true, can_reopen: false, is_final: false},
        interactions: {
            can_comment: true,
            comment_url: '/tasks/10/comments',
        },
    });

    assert.equal(meta.can_work, true);
    assert.equal(meta.transitions.is_final, false);
    assert.equal(meta.status, 2);
    assert.equal(meta.can_comment, true);
    assert.equal(meta.comment_url, '/tasks/10/comments');
    assert.equal(management[10], meta);
});

test('closed subtasks stay inside their parent row instead of moving to the completed group', (t) => {
    const dom = mountDom(`
        <div data-board-list-body>
            <header data-project-header data-project-key="project-1"></header>
            <article data-board-task data-task-id="10" data-project-key="project-1">
                <div data-task-details-panel>
                    <ol data-task-details-list>
                        <li data-board-task data-board-subtask="1" data-task-detail data-task-id="99" data-work-order-id="10" data-project-key="project-1"></li>
                    </ol>
                </div>
            </article>
        </div>
    `);
    t.after(() => dom.cleanup());

    const cardGrid = dom.document.querySelector('[data-board-list-body]');
    const subtask = cardGrid.querySelector('[data-board-subtask]');

    assert.equal(synchronizeCompletedTaskGroup(cardGrid, subtask, 4), null);
    assert.equal(cardGrid.querySelector('[data-completed-group]'), null);
    assert.equal(subtask.closest('[data-task-details-list]')?.dataset.taskDetailsList !== undefined, true);
    assert.equal(subtask.closest('[data-board-task]:not([data-board-subtask])').dataset.taskId, '10');
});

test('a parent moving into the completed group carries its finished subtasks along', (t) => {
    const dom = mountDom(`
        <div data-board-list-body>
            <header data-project-header data-project-key="project-1"></header>
            <article data-board-task data-task-id="10" data-project-key="project-1">
                <div data-task-details-panel>
                    <ol data-task-details-list>
                        <li data-board-task data-board-subtask="1" data-task-detail data-task-id="99" data-work-order-id="10" data-project-key="project-1" data-status="4"></li>
                    </ol>
                </div>
            </article>
        </div>
    `);
    t.after(() => dom.cleanup());

    const cardGrid = dom.document.querySelector('[data-board-list-body]');
    const parent = cardGrid.querySelector('[data-task-id="10"]');

    const group = synchronizeCompletedTaskGroup(cardGrid, parent, 4);
    assert.ok(group);
    // ตัวนับกลุ่มนับเฉพาะงานระดับบนสุด งานย่อยที่ตามมาต้องไม่ถูกนับซ้ำ
    assert.equal(group.querySelector('summary span').textContent, '1 งาน');
    assert.equal(group.querySelectorAll('[data-board-subtask]').length, 1);
    assert.equal(
        group.querySelector('[data-board-subtask]').closest('[data-board-task]:not([data-board-subtask])'),
        parent,
    );
});

/*
 * แถวต้นทาง ([data-workspace-task-source]) เป็นสิ่งที่โมดัล ตาราง และปฏิทินอ่านทั้งหมด
 * การบันทึก "ไม่ระบุเวลากำหนดส่ง" จากบอร์ดจึงต้องไปถึงที่นั่นด้วย ไม่ใช่แค่แถวของบอร์ด
 * ไม่เช่นนั้นผู้ใช้สลับไปมุมมองตารางหรือเปิดโมดัลจะเห็นเวลา 23:59 น. ที่เขาไม่ได้ตั้งไว้
 */
test('an unset due time reaches the source row that every other view reads', (t) => {
    const dom = mountDom(`
        <div data-workspace>
            <div data-workspace-task-source>
                <div class="notion-row" data-row data-id="4" data-due="2026-10-15" data-due-time="17:00" data-due-time-unset="0">
                    <label class="row-duration">
                        <small class="row-duration-time" data-due-time-label>ส่งภายใน 17:00 น.</small>
                        <input class="cell-date" type="datetime-local" data-field="due" data-time-optional data-time-unset="0" value="2026-10-15T17:00">
                    </label>
                </div>
            </div>
        </div>
    `);
    t.after(() => dom.cleanup());

    const workspace = dom.document.querySelector('[data-workspace]');
    const row = dom.document.querySelector('[data-row]');
    const detail = synchronizeTaskSource(workspace, 4, {
        due: '2026-10-15',
        dueTime: '23:59',
        dueTimeUnset: '1',
    });

    assert.equal(row.querySelector('[data-due-time-label]').textContent, 'ไม่ระบุเวลากำหนดส่ง');
    // ค่าในช่องยังเป็น datetime-local ที่ถูกต้อง เจตนาเดินทางแยกไปที่ dataset
    assert.equal(row.querySelector('input[data-field="due"]').value, '2026-10-15T23:59');
    assert.equal(row.querySelector('input[data-field="due"]').dataset.timeUnset, '1');
    assert.equal(detail.dueTimeUnset, true, 'มุมมองที่ฟัง mytasks:changed ต้องได้เจตนาไปด้วย');

    // ตั้งเวลาจริงกลับมาแล้วป้ายต้องกลับเป็นเวลา ไม่ใช่ค้างคำว่าไม่ระบุ
    synchronizeTaskSource(workspace, 4, {due: '2026-10-15', dueTime: '09:30', dueTimeUnset: '0'});

    assert.equal(row.querySelector('[data-due-time-label]').textContent, 'ส่งภายใน 09:30 น.');
    assert.equal(row.querySelector('input[data-field="due"]').dataset.timeUnset, '0');
});

/*
 * ผู้ดูแลระบบปิดงานแม่พร้อมงานย่อยที่ค้าง — server ส่ง closed_subtasks มากับคำตอบของงานแม่
 * ทุกแถวของงานย่อยต้องถูกวาดใหม่ผ่าน publishTaskState() ทางเดียวกับการปิดทีละใบ
 * และต้องวาดก่อนงานแม่ ตัวนับงานย่อยใน DOM จึงเป็นค่าหลังปิดแล้วตอนที่งานแม่ถูกวาด
 */
test('closing a parent with its open subtasks republishes every closed subtask before the parent', (t) => {
    const dom = mountDom(`
        <script type="application/json" data-task-management-data>{
            "10": {"status": 2, "transitions": {"is_final": false}},
            "11": {"status": 2, "transitions": {"is_final": false}},
            "12": {"status": 5, "transitions": {"is_final": false}}
        }</script>
        <div data-workspace>
            <div data-workspace-task-source>
                <div data-row data-id="10" data-status="2"></div>
                <div data-row data-id="11" data-status="2"></div>
                <div data-row data-id="12" data-status="5"></div>
            </div>
        </div>
    `);
    t.after(() => dom.cleanup());

    const workspace = dom.document.querySelector('[data-workspace]');
    const order = [];
    dom.document.addEventListener('mytasks:changed', (event) => {
        order.push([event.detail.id, event.detail.status]);
    });

    publishTaskState(workspace, 10, {
        job_id: 10,
        job_status: 4,
        transitions: {is_final: true},
        closed_subtasks: [
            {job_id: 11, job_status: 4, transitions: {is_final: true}},
            {job_id: 12, job_status: 4, transitions: {is_final: true}},
        ],
    }, 4, dom.document);

    const store = taskStateStore(dom.document);
    ['10', '11', '12'].forEach((id) => {
        assert.equal(dom.document.querySelector(`[data-row][data-id="${id}"]`).dataset.status, '4');
        assert.equal(store[id].transitions.is_final, true);
        assert.equal(store[id].status, 4);
    });
    assert.deepEqual(order, [['11', 4], ['12', 4], ['10', 4]]);
});
