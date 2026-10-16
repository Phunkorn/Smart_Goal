import test from 'node:test';
import assert from 'node:assert/strict';
import {mountDom} from './helpers/dom.js';

/**
 * ตัวกรอง "ผู้รับผิดชอบ" บนมุมมองตาราง (data-view="board") — เส้นทางจริงตั้งแต่เลือกจนถึงผลที่เห็นบนหน้า
 *
 * ปุ่ม "งานทั้งหมด / งานของฉัน / งานของทีม" ถูกถอดออกแล้ว เพราะซ้ำกับตัวกรองขอบเขตงาน
 * (tasks/partials/scope-menu.blade.php) ที่อยู่ข้าง ๆ เหลือเฉพาะตัวกรองผู้รับผิดชอบ
 *
 * mytasks-project-board.js เป็น IIFE ที่ทำงานทันทีตอน import และ ESM cache โมดูลไว้
 * ทั้งโปรเซส จึงต้องเตรียม DOM ให้เสร็จก่อน import และ import ได้เพียงครั้งเดียวต่อไฟล์เทสต์
 * เทสต์ทั้งหมดในไฟล์นี้จึงใช้บอร์ดชุดเดียวกัน และต้องคืนตัวกรองเป็น "ทุกคน" เมื่อจบทุกครั้ง
 *
 * โครงสร้าง markup ต้องตรงกับ resources/views/tasks/partials/project-board-card.blade.php
 * tasks/partials/mine-filter.blade.php และ tasks/index.blade.php ถ้า Blade เปลี่ยน hook ต้องแก้ที่นี่ด้วย
 */

const taskRow = ({id, project, title, assignee = '5', status = '2', late = '0', subtasks = ''}) => `
    <article class="board-reference-row" data-board-task data-detail-target="0"
             data-project-key="${project}" data-task-id="${id}" data-participate="1" data-assignee="${assignee}"
             data-topic="${title}" data-status="${status}" data-can-review="0" data-late="${late}"
             data-cross-department="0" data-project-name="${project}" data-search-text="${title}">
        <div class="board-reference-task">${title}</div>
        <ul class="board-task-details">${subtasks}</ul>
    </article>`;

const subtaskRow = ({id, project, title}) => `
    <li class="board-task-detail" data-task-detail data-board-task data-board-subtask="1"
        data-detail-target="0" data-detail-id="${id}" data-task-id="${id}"
        data-participate="0" data-project-key="${project}" data-project-name="${project}"
        data-topic="${title}" data-status="2" data-can-review="0" data-late="0"
        data-search-text="${title}">${title}</li>`;

const projectHeader = (project, total) => `
    <header class="board-project-group__header" data-project-header data-project-key="${project}"
            data-project-name="${project}" data-detail-project-target="0">
        <strong class="board-project-group__title">${project}</strong>
        <span><b data-board-visible-count data-board-total-count="${total}">${total}</b> งาน</span>
    </header>`;

const markup = `<!doctype html><html><body>
<meta name="csrf-token" content="test-token">
<div class="notion-workspace my-tasks-page" data-workspace data-context="user" data-task-scope="all"
     data-details-template="/tasks/__ID__/details" data-status-template="/tasks/__ID__/status"
     data-priority-template="/my-tasks/__ID__/priority" data-due-template="/my-tasks/__ID__/due-date">

    <div class="mytasks-owner-filter" data-board-mine-filter data-owner-filter-views="board">
        <div class="notion-filter" data-sg-select>
            <select data-board-assignee-filter>
                <option value="">ผู้รับผิดชอบทุกคน</option>
                <option value="5">ฉัน</option>
                <option value="9">เพื่อนร่วมโปรเจกต์</option>
                <option value="12">เพื่อนต่างโปรเจกต์</option>
            </select>
        </div>
    </div>
    <div class="notion-toolbar" data-board-toolbar>
        <label class="notion-search"><input type="search" data-search></label>
        <button type="button" data-sort></button>
    </div>
    <select data-filter><option value="" selected></option><option value="late"></option></select>

    <div class="project-board" data-project-board>
        <div class="board-reference-list" data-board-list-body>
            ${projectHeader('โปรเจกต์ร่วม', 4)}
            ${taskRow({id: 1, project: 'โปรเจกต์ร่วม', title: 'งานของฉันที่ล่าช้า', late: '1'})}
            ${taskRow({id: 2, project: 'โปรเจกต์ร่วม', title: 'งานของเพื่อนร่วมโปรเจกต์', assignee: '9'})}
            ${taskRow({
                id: 3,
                project: 'โปรเจกต์ร่วม',
                title: 'งานแม่ของเพื่อน',
                assignee: '9',
                subtasks: subtaskRow({id: 31, project: 'โปรเจกต์ร่วม', title: 'งานย่อยของงานแม่'}),
            })}

            ${projectHeader('โปรเจกต์อื่น', 1)}
            ${taskRow({id: 4, project: 'โปรเจกต์อื่น', title: 'งานของเพื่อนต่างโปรเจกต์', assignee: '12'})}

            ${projectHeader('โปรเจกต์ว่าง', 0)}

            <div class="board-completed-group" data-completed-group data-project-key="โปรเจกต์ร่วม">
                ${taskRow({id: 5, project: 'โปรเจกต์ร่วม', title: 'งานของเพื่อนที่ปิดแล้ว', assignee: '9', status: '4'})}
            </div>
        </div>
        <div class="project-board-empty" data-board-empty hidden>
            <p data-board-empty-generic>ไม่พบงานในบอร์ดตามตัวกรองที่เลือก</p>
        </div>
    </div>

    <div class="notion-toast" data-toast></div>
</div>
<script type="application/json" data-task-management-data>{}</script>
<script type="application/json" data-attachment-data>{}</script>
</body></html>`;

const dom = mountDom(markup, {url: 'http://localhost/my-tasks?view=board'});
const {document, window} = dom;

await import('../../resources/js/mytasks-project-board.js');

const assigneeSelect = document.querySelector('[data-board-assignee-filter]');
const chooseAssignee = (value) => {
    assigneeSelect.value = value;
    assigneeSelect.dispatchEvent(new window.Event('change', {bubbles: true}));
};
const row = (id) => document.querySelector(`[data-board-task][data-task-id="${id}"]`);
const header = (name) => [...document.querySelectorAll('[data-project-header]')]
    .find((node) => node.dataset.projectKey === name);
const count = (name) => header(name).querySelector('[data-board-visible-count]').textContent;
const subtask = (id) => document.querySelector(`[data-board-subtask][data-task-id="${id}"]`);

/** คืนบอร์ดให้อยู่สถานะตั้งต้น (ผู้รับผิดชอบทุกคน ไม่มีคำค้น ไม่มีตัวกรองสถานะ) */
const resetBoard = () => {
    if (assigneeSelect.value) chooseAssignee('');
    const search = document.querySelector('[data-search]');
    if (search.value) {
        search.value = '';
        search.dispatchEvent(new window.Event('input', {bubbles: true}));
    }
    const filter = document.querySelector('[data-filter]');
    if (filter.value) {
        filter.value = '';
        filter.dispatchEvent(new window.Event('change', {bubbles: true}));
    }
};

test('ไม่มีปุ่ม "งานของฉัน / งานของทีม" อีกแล้ว เหลือเฉพาะตัวกรองผู้รับผิดชอบ', () => {
    assert.equal(document.querySelector('[data-board-owner-option]'), null);
    assert.ok(assigneeSelect);
});

test('ค่าเริ่มต้นคือผู้รับผิดชอบทุกคน บอร์ดจึงแสดงงานทั้งโปรเจกต์เหมือนเดิมทุกใบ', () => {
    resetBoard();

    for (const id of [1, 2, 3, 4]) {
        assert.equal(row(id).hidden, false, `งาน ${id} ต้องไม่ถูกซ่อนตอนยังไม่ได้กรอง`);
    }
    assert.equal(subtask(31).hidden, false);
    assert.equal(window.location.search.includes('assignee'), false);
});

test('เลือกผู้รับผิดชอบแล้วเหลือเฉพาะงานของคนนั้น และตัวเลขหัวกลุ่มลดตาม', () => {
    resetBoard();
    chooseAssignee('9');

    assert.equal(row(1).hidden, true);
    assert.equal(row(2).hidden, false);
    assert.equal(row(3).hidden, false);
    assert.equal(row(4).hidden, true);
    // งานย่อยติดตามงานแม่ไปเสมอ
    assert.equal(subtask(31).hidden, false);
    // งานของเพื่อน 2 ใบที่ยังเปิด + งานของเพื่อนที่ปิดแล้ว 1 ใบ
    assert.equal(count('โปรเจกต์ร่วม'), '3');
    assert.equal(
        header('โปรเจกต์ร่วม').querySelector('[data-board-visible-count]').dataset.boardTotalCount,
        '4',
        'ยอดรวมของโปรเจกต์ต้องยังเป็นความจริงจาก server',
    );
});

test('โปรเจกต์ที่ไม่มีงานของคนที่เลือกหายไปทั้งกลุ่ม รวมถึงโปรเจกต์ที่ว่างอยู่แล้ว', () => {
    resetBoard();
    assert.equal(header('โปรเจกต์อื่น').hidden, false);
    assert.equal(header('โปรเจกต์ว่าง').hidden, false, 'โปรเจกต์ว่างต้องคงอยู่ตอนไม่มีตัวกรอง');

    chooseAssignee('5');

    assert.equal(header('โปรเจกต์อื่น').hidden, true);
    assert.equal(header('โปรเจกต์ว่าง').hidden, true);
    assert.equal(header('โปรเจกต์ร่วม').hidden, false);
});

test('กลุ่มงานเสร็จแล้วที่ไม่มีงานของคนที่เลือกถูกซ่อน ไม่เหลือหัวกลุ่มว่าง', () => {
    resetBoard();
    const group = document.querySelector('[data-completed-group]');
    assert.equal(group.hidden, false);

    chooseAssignee('5');
    assert.equal(group.hidden, true);

    chooseAssignee('9');
    assert.equal(group.hidden, false, 'คนที่มีงานปิดแล้วในกลุ่มนี้ กลุ่มต้องยังอยู่');
});

test('ตัวกรองสถานะกับตัวกรองผู้รับผิดชอบให้ผลเป็นอินเตอร์เซกชัน ไม่ตีกัน', () => {
    resetBoard();
    chooseAssignee('9');

    const filter = document.querySelector('[data-filter]');
    filter.value = 'late';
    filter.dispatchEvent(new window.Event('change', {bubbles: true}));

    // งานล่าช้ามีแค่ของฉัน คนที่เลือกไม่มีงานล่าช้า บอร์ดจึงว่าง
    for (const id of [1, 2, 3, 4]) {
        assert.equal(row(id).hidden, true, `งาน ${id}`);
    }
    assert.equal(document.querySelector('[data-board-empty]').hidden, false);
    resetBoard();
});

test('ตัวกรองถูกเขียนลง URL และหายไปเมื่อกลับไปผู้รับผิดชอบทุกคน', () => {
    resetBoard();

    chooseAssignee('12');
    assert.equal(new URLSearchParams(window.location.search).get('assignee'), '12');

    chooseAssignee('');
    assert.equal(new URLSearchParams(window.location.search).has('assignee'), false);
    assert.equal(row(1).hidden, false);
});

test.after(() => dom.cleanup());
