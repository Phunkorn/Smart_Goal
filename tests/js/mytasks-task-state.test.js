import assert from 'node:assert/strict';
import test from 'node:test';

import {synchronizeCompletedTaskGroup, synchronizeTaskManagement} from '../../resources/js/pages/mytasks/task-state.js';
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
        transitions: {can_edit: true, can_reopen: false, is_final: false},
    });

    assert.equal(meta.can_work, true);
    assert.equal(meta.transitions.is_final, false);
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
