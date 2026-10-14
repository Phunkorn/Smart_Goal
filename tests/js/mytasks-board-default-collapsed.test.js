import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {mountDom, click} from './helpers/dom.js';

/**
 * โปรเจกต์บนมุมมองบอร์ดพับไว้เป็นค่าเริ่มต้น — เส้นทางจริงตั้งแต่หน้าโหลดจนถึงคลิกและค้นหา
 *
 * ค่าเริ่มต้นเป็นของ Blade (วาด is-collapsed มาจาก server) JS ทำสามอย่างเท่านั้น
 * กางโปรเจกต์ที่ผู้ใช้เปิดค้างไว้ สลับตามปุ่มลูกศร และกางชั่วคราวให้ผลการค้นหา
 *
 * mytasks-project-board.js เป็น IIFE ที่ทำงานตอน import และ ESM cache ไว้ทั้งโปรเซส
 * จึงต้องเตรียม DOM และ sessionStorage ให้เสร็จก่อน import และเทสต์ทั้งไฟล์ใช้บอร์ดชุดเดียวกัน
 *
 * markup ต้องตรงกับ resources/views/tasks/partials/project-board-card.blade.php
 */

const read = (path) => readFile(new URL('../../' + path, import.meta.url), 'utf8');

const taskRow = ({id, project, title, status = '2', participate = '1'}) => `
    <article class="board-reference-row is-project-collapsed" data-board-task data-detail-target="0"
             data-project-key="${project}" data-task-id="${id}" data-participate="${participate}"
             data-topic="${title}" data-status="${status}" data-can-review="0" data-late="0"
             data-cross-department="0" data-project-name="${project}" data-search-text="${title}">
        <div class="board-reference-task">${title}</div>
    </article>`;

const projectHeader = (project, total) => `
    <header class="board-project-group__header is-collapsed project-tone-medium" data-project-header
            data-project-key="${project}" data-project-name="${project}" data-detail-project-target="0">
        <button type="button" class="board-project-collapse" data-board-collapse aria-expanded="false"><i class="bi bi-caret-down-fill"></i></button>
        <strong class="board-project-group__title">${project}</strong>
        <span><b data-board-visible-count data-board-total-count="${total}">${total}</b> งาน</span>
    </header>`;

const markup = `<!doctype html><html><body>
<meta name="csrf-token" content="test-token">
<div class="notion-workspace my-tasks-page" data-workspace data-context="user" data-task-scope="all"
     data-details-template="/tasks/__ID__/details" data-status-template="/tasks/__ID__/status"
     data-priority-template="/my-tasks/__ID__/priority" data-due-template="/my-tasks/__ID__/due-date">
    <button type="button" data-board-mine-toggle aria-pressed="false"></button>
    <input type="search" data-search>
    <button type="button" data-sort></button>
    <select data-filter><option value="" selected></option><option value="late"></option></select>

    <div class="project-board" data-project-board>
        <div class="board-reference-list" data-board-list-body>
            ${projectHeader('project-1', 2)}
            ${taskRow({id: 1, project: 'project-1', title: 'ทำรายงานประจำเดือน'})}
            <details class="board-completed-group is-project-collapsed" data-completed-group data-project-key="project-1">
                <summary>งานที่เสร็จแล้ว</summary>
                <div class="board-completed-group__rows">
                    ${taskRow({id: 2, project: 'project-1', title: 'ส่งใบเสนอราคา', status: '4'})}
                </div>
            </details>

            ${projectHeader('project-2', 1)}
            ${taskRow({id: 3, project: 'project-2', title: 'เตรียมห้องอบรม'})}

            ${projectHeader('project-3', 1)}
            ${taskRow({id: 4, project: 'project-3', title: 'ตรวจรายงานการเงิน'})}
        </div>
        <div class="project-board-empty" data-board-empty hidden>
            <p data-board-empty-generic></p><p data-board-empty-mine hidden></p>
        </div>
    </div>
    <div class="notion-toast" data-toast></div>
</div>
<script type="application/json" data-task-management-data>{}</script>
<script type="application/json" data-attachment-data>{}</script>
</body></html>`;

const {document, window} = mountDom(markup, {url: 'http://localhost/my-tasks?view=board'});
const storageKey = 'smartgoal:board-expanded-projects:/my-tasks';
// ผู้ใช้กาง project-2 ค้างไว้ก่อนหน้าโหลดใหม่ ส่วน project-9 ถูกลบไปแล้วและต้องไม่ทำให้หน้าพัง
window.sessionStorage.setItem(storageKey, JSON.stringify(['project-2', 'project-9']));

await import('../../resources/js/mytasks-project-board.js');

const header = (key) => document.querySelector(`[data-project-header][data-project-key="${key}"]`);
const caret = (key) => header(key).querySelector('[data-board-collapse]');
const row = (id) => document.querySelector(`[data-board-task][data-task-id="${id}"]`);
const completedGroup = (key) => document.querySelector(`[data-completed-group][data-project-key="${key}"]`);
const remembered = () => JSON.parse(window.sessionStorage.getItem(storageKey));
const isCollapsed = (key) => header(key).classList.contains('is-collapsed');
const search = (value) => {
    const input = document.querySelector('[data-search]');
    input.value = value;
    input.dispatchEvent(new window.Event('input', {bubbles: true}));
};

test('Blade วาดหัวโปรเจกต์ แถวงาน และกลุ่มงานที่เสร็จแล้วแบบพับมาตั้งแต่ server', async () => {
    const blade = await read('resources/views/tasks/partials/project-board-card.blade.php');

    assert.match(blade, /<header class="board-project-group__header is-collapsed /);
    assert.match(blade, /data-board-collapse aria-expanded="false"/);
    assert.match(blade, /<article class="board-reference-row is-project-collapsed /);
    assert.match(blade, /<details class="board-completed-group is-project-collapsed"/);
});

test('หน้าโหลดมาพับทุกโปรเจกต์ ยกเว้นโปรเจกต์ที่ผู้ใช้กางค้างไว้ในแท็บนี้', () => {
    assert.equal(isCollapsed('project-1'), true);
    assert.equal(row(1).classList.contains('is-project-collapsed'), true);
    assert.equal(completedGroup('project-1').classList.contains('is-project-collapsed'), true);
    assert.equal(caret('project-1').getAttribute('aria-expanded'), 'false');

    assert.equal(isCollapsed('project-2'), false);
    assert.equal(row(3).classList.contains('is-project-collapsed'), false);
    assert.equal(caret('project-2').getAttribute('aria-expanded'), 'true');

    assert.equal(isCollapsed('project-3'), true);
});

test('กดลูกศรกางโปรเจกต์ที่พับอยู่ได้ทั้งแถวงานและกลุ่มงานที่เสร็จแล้ว แล้วกดอีกครั้งพับกลับ', () => {
    click(caret('project-1'));

    assert.equal(isCollapsed('project-1'), false);
    assert.equal(row(1).classList.contains('is-project-collapsed'), false);
    assert.equal(row(2).classList.contains('is-project-collapsed'), false);
    assert.equal(completedGroup('project-1').classList.contains('is-project-collapsed'), false);
    assert.equal(caret('project-1').getAttribute('aria-expanded'), 'true');
    assert.ok(remembered().includes('project-1'), 'ต้องจำโปรเจกต์ที่กางไว้ข้ามการโหลดหน้าใหม่');

    click(caret('project-1'));

    assert.equal(isCollapsed('project-1'), true);
    assert.equal(row(1).classList.contains('is-project-collapsed'), true);
    assert.equal(completedGroup('project-1').classList.contains('is-project-collapsed'), true);
    assert.equal(caret('project-1').getAttribute('aria-expanded'), 'false');
    assert.equal(remembered().includes('project-1'), false);
});

test('ค้นหาแล้วโปรเจกต์ที่มีผลลัพธ์กางให้ชั่วคราว ล้างคำค้นแล้วพับกลับตามเดิม', () => {
    search('รายงาน');

    assert.equal(isCollapsed('project-1'), false, 'โปรเจกต์ที่มีงานตรงคำค้นต้องกางให้เห็นผลลัพธ์');
    assert.equal(row(1).classList.contains('is-project-collapsed'), false);
    assert.equal(isCollapsed('project-3'), false);
    assert.equal(header('project-2').hidden, true, 'โปรเจกต์ที่ไม่มีผลลัพธ์ยังถูกซ่อนเหมือนเดิม');
    assert.equal(remembered().includes('project-1'), false, 'การกางโดยตัวกรองต้องไม่ถูกจำเป็นของผู้ใช้');

    search('');

    assert.equal(isCollapsed('project-1'), true);
    assert.equal(isCollapsed('project-3'), true);
    assert.equal(isCollapsed('project-2'), false, 'โปรเจกต์ที่ผู้ใช้กางเองต้องไม่ถูกพับตามไปด้วย');
});

test('ผู้ใช้พับโปรเจกต์เองระหว่างค้นหา พิมพ์ต่อแล้วตัวกรองต้องไม่กางกลับ', () => {
    search('รายงาน');
    click(caret('project-1'));
    assert.equal(isCollapsed('project-1'), true);

    search('รายงานประจำ');
    assert.equal(isCollapsed('project-1'), true);

    search('');
    assert.equal(isCollapsed('project-1'), true);
    assert.equal(header('project-1').dataset.filterReveal, undefined);
});

test('ปุ่ม "เฉพาะงานของฉัน" เป็นการเลือกขอบเขต ไม่กางโปรเจกต์ที่พับไว้', () => {
    const toggle = document.querySelector('[data-board-mine-toggle]');
    click(toggle);

    assert.equal(isCollapsed('project-1'), true);
    assert.equal(isCollapsed('project-3'), true);

    click(toggle);
});

test('สร้างงานหรือเพิ่มรายการแล้วโปรเจกต์ปลายทางต้องถูกกางไว้หลังโหลดหน้าใหม่', async () => {
    const create = await read('resources/js/pages/mytasks/user-task-create.js');
    const quickAdd = await read('resources/js/mytasks-notion.js');

    assert.match(create, /rememberProjectExpanded\(projectKeyForList\(payload\.list_id\)\)/);
    assert.ok(
        create.indexOf('rememberProjectExpanded(') < create.indexOf('window.location.assign(destination)'),
        'ต้องจำก่อนพาไปหน้าบอร์ด',
    );
    assert.match(quickAdd, /rememberProjectExpanded\(projectKeyForList\(add\.dataset\.listId\)\)/);

    const {projectKeyForList} = await import('../../resources/js/pages/mytasks/board-project-collapse.js');
    const blade = await read('resources/views/tasks/partials/project-board-card.blade.php');
    assert.equal(projectKeyForList(7), 'project-7');
    assert.equal(projectKeyForList(''), 'general');
    assert.match(blade, /\$projectKey = \$project \? 'project-'\.\$project->id : 'general';/);
});
