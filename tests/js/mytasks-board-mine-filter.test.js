import test from 'node:test';
import assert from 'node:assert/strict';
import {mountDom, click} from './helpers/dom.js';

/**
 * ปุ่ม "เฉพาะงานของฉัน" บนมุมมองบอร์ด — เส้นทางจริงตั้งแต่คลิกจนถึงผลที่เห็นบนหน้า
 *
 * mytasks-project-board.js เป็น IIFE ที่ทำงานทันทีตอน import และ ESM cache โมดูลไว้
 * ทั้งโปรเซส จึงต้องเตรียม DOM ให้เสร็จก่อน import และ import ได้เพียงครั้งเดียวต่อไฟล์เทสต์
 * เทสต์ทั้งหมดในไฟล์นี้จึงใช้บอร์ดชุดเดียวกัน และต้องคืนสถานะปุ่มให้ปิดเมื่อจบทุกครั้ง
 *
 * โครงสร้าง markup ต้องตรงกับ resources/views/tasks/partials/project-board-card.blade.php
 * และ tasks/index.blade.php ถ้า Blade เปลี่ยน hook ต้องแก้ที่นี่ด้วย
 */

const taskRow = ({id, project, title, participate, status = '2', late = '0', subtasks = ''}) => `
    <article class="board-reference-row" data-board-task data-detail-target="0"
             data-project-key="${project}" data-task-id="${id}" data-participate="${participate}"
             data-topic="${title}" data-status="${status}" data-can-review="0" data-late="${late}"
             data-cross-department="0" data-project-name="${project}" data-search-text="${title}">
        <div class="board-reference-task">${title}</div>
        <ul class="board-task-details">${subtasks}</ul>
    </article>`;

const subtaskRow = ({id, project, title, participate, status = '2'}) => `
    <li class="board-task-detail" data-task-detail data-board-task data-board-subtask="1"
        data-detail-target="0" data-detail-id="${id}" data-task-id="${id}"
        data-participate="${participate}" data-project-key="${project}" data-project-name="${project}"
        data-topic="${title}" data-status="${status}" data-can-review="0" data-late="0"
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

    <div class="mytasks-mine-filter" data-board-mine-filter>
        <button type="button" class="mytasks-mine-filter__button" data-board-mine-toggle aria-pressed="false">
            <i class="bi bi-person-check"></i><span>เฉพาะงานของฉัน</span>
        </button>
    </div>
    <div class="notion-toolbar" data-board-toolbar>
        <label class="notion-search"><input type="search" data-search></label>
        <button type="button" data-sort></button>
    </div>
    <select data-filter><option value="" selected></option><option value="late"></option></select>

    <div class="project-board" data-project-board>
        <div class="board-reference-list" data-board-list-body>
            ${projectHeader('โปรเจกต์ร่วม', 4)}
            ${taskRow({id: 1, project: 'โปรเจกต์ร่วม', title: 'งานที่ฉันถูกมอบหมาย', participate: '1', late: '1'})}
            ${taskRow({id: 2, project: 'โปรเจกต์ร่วม', title: 'งานของเพื่อนร่วมโปรเจกต์', participate: '0'})}
            ${taskRow({
                id: 3,
                project: 'โปรเจกต์ร่วม',
                title: 'งานแม่ของคนอื่นที่มีงานย่อยของฉัน',
                participate: '0',
                subtasks: subtaskRow({id: 31, project: 'โปรเจกต์ร่วม', title: 'งานย่อยของฉัน', participate: '1'})
                    + subtaskRow({id: 32, project: 'โปรเจกต์ร่วม', title: 'งานย่อยของคนอื่น', participate: '0'}),
            })}

            ${projectHeader('โปรเจกต์ที่ฉันไม่มีงาน', 1)}
            ${taskRow({id: 4, project: 'โปรเจกต์ที่ฉันไม่มีงาน', title: 'งานของแผนกที่ฉันไม่ได้ร่วม', participate: '0'})}

            ${projectHeader('โปรเจกต์ว่าง', 0)}

            <div class="board-completed-group" data-completed-group data-project-key="โปรเจกต์ร่วม">
                ${taskRow({
                    id: 5,
                    project: 'โปรเจกต์ร่วม',
                    title: 'งานของเพื่อนที่ปิดแล้ว',
                    participate: '0',
                    status: '4',
                })}
            </div>
        </div>
        <div class="project-board-empty" data-board-empty hidden>
            <p data-board-empty-generic>ไม่พบงานในบอร์ดตามตัวกรองที่เลือก</p>
            <p data-board-empty-mine hidden>คุณยังไม่ได้รับมอบหมายงานในบอร์ดนี้</p>
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

const toggle = document.querySelector('[data-board-mine-toggle]');
const row = (id) => document.querySelector(`[data-board-task][data-task-id="${id}"]`);
const header = (name) => [...document.querySelectorAll('[data-project-header]')]
    .find((node) => node.dataset.projectKey === name);
const count = (name) => header(name).querySelector('[data-board-visible-count]').textContent;
const subtask = (id) => document.querySelector(`[data-board-subtask][data-task-id="${id}"]`);

/** คืนบอร์ดให้อยู่สถานะตั้งต้น (ปุ่มปิด ไม่มีคำค้น ไม่มีตัวกรองสถานะ) */
const resetBoard = () => {
    if (toggle.getAttribute('aria-pressed') === 'true') click(toggle);
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

test('ค่าเริ่มต้นคือปิด บอร์ดจึงแสดงงานทั้งโปรเจกต์เหมือนเดิมทุกใบ', () => {
    resetBoard();

    assert.equal(toggle.getAttribute('aria-pressed'), 'false');
    for (const id of [1, 2, 3, 4]) {
        assert.equal(row(id).hidden, false, `งาน ${id} ต้องไม่ถูกซ่อนตอนปุ่มยังปิด`);
    }
    assert.equal(window.location.search.includes('mine'), false);
});

test('กดปุ่มแล้วเหลือเฉพาะงานที่ตัวเองร่วม ส่วนงานพี่น้องถูกซ่อน', () => {
    resetBoard();
    click(toggle);

    assert.equal(toggle.getAttribute('aria-pressed'), 'true');
    assert.ok(toggle.classList.contains('is-active'));

    assert.equal(row(1).hidden, false, 'งานที่ถูกมอบหมายต้องยังอยู่');
    assert.equal(row(2).hidden, true, 'งานของเพื่อนร่วมโปรเจกต์ต้องถูกซ่อน');
    assert.equal(row(4).hidden, true, 'งานของโปรเจกต์ที่เราไม่มีส่วนร่วมต้องถูกซ่อน');
});

test('งานแม่ของคนอื่นยังแสดงอยู่เมื่อมีงานย่อยที่เราถูกมอบหมาย', () => {
    resetBoard();
    click(toggle);

    // ถ้าซ่อนงานแม่ทิ้ง ผู้ใช้จะมองไม่เห็นงานย่อยที่ตัวเองรับผิดชอบเลย
    assert.equal(row(3).hidden, false, 'งานแม่ที่มีงานย่อยของเราต้องไม่หายไป');
});

test('งานย่อยที่เราไม่ได้ร่วมถูกซ่อนไปด้วย ไม่ใช่แค่งานระดับบนสุด', () => {
    resetBoard();
    for (const id of [31, 32]) {
        assert.equal(subtask(id).hidden, false, `งานย่อย ${id} ต้องแสดงตอนปุ่มยังปิด`);
    }

    click(toggle);

    assert.equal(subtask(31).hidden, false, 'งานย่อยที่เราถูกมอบหมายต้องยังอยู่');
    assert.equal(subtask(32).hidden, true, 'งานย่อยของคนอื่นต้องถูกซ่อนไปด้วย');
});

test('กดปิดแล้วงานย่อยที่เคยถูกซ่อนต้องกลับมาครบ', () => {
    resetBoard();
    click(toggle);
    assert.equal(subtask(32).hidden, true);

    click(toggle);

    assert.equal(subtask(32).hidden, false, 'ปิดปุ่มแล้วงานย่อยของคนอื่นต้องกลับมา');
});

test('ตัวเลขหัวกลุ่มลดลงตามงานที่เหลือ แต่ยอดรวมที่ server ส่งมาต้องไม่ถูกแก้', () => {
    resetBoard();
    // งานที่ปิดแล้วอยู่ในโปรเจกต์เดียวกัน จึงถูกนับรวมเป็น 4 ใบ
    assert.equal(count('โปรเจกต์ร่วม'), '4');

    click(toggle);

    // เหลืองานที่ฉันถูกมอบหมาย + งานแม่ที่มีงานย่อยของฉัน
    assert.equal(count('โปรเจกต์ร่วม'), '2');
    assert.equal(
        header('โปรเจกต์ร่วม').querySelector('[data-board-visible-count]').dataset.boardTotalCount,
        '4',
        'ยอดรวมของโปรเจกต์ต้องยังเป็นความจริงจาก server',
    );
});

test('โปรเจกต์ที่ไม่มีงานของเราเลยหายไปทั้งกลุ่ม รวมถึงโปรเจกต์ที่ว่างอยู่แล้ว', () => {
    resetBoard();
    assert.equal(header('โปรเจกต์ที่ฉันไม่มีงาน').hidden, false);
    assert.equal(header('โปรเจกต์ว่าง').hidden, false, 'โปรเจกต์ว่างต้องคงอยู่ตอนไม่มีตัวกรอง');

    click(toggle);

    assert.equal(header('โปรเจกต์ที่ฉันไม่มีงาน').hidden, true);
    assert.equal(header('โปรเจกต์ว่าง').hidden, true, 'ระหว่างดูเฉพาะงานของฉัน โปรเจกต์ว่างต้องหายไปด้วย');
    assert.equal(header('โปรเจกต์ร่วม').hidden, false);
});

test('กลุ่มงานเสร็จแล้วที่ไม่มีงานของเราถูกซ่อน ไม่เหลือหัวกลุ่มว่าง', () => {
    resetBoard();
    const group = document.querySelector('[data-completed-group]');
    assert.equal(group.hidden, false);

    click(toggle);

    assert.equal(group.hidden, true);
});

test('ตัวกรองสถานะกับปุ่มนี้ให้ผลเป็นอินเตอร์เซกชัน ไม่ตีกัน', () => {
    resetBoard();
    click(toggle);

    const filter = document.querySelector('[data-filter]');
    filter.value = 'late';
    filter.dispatchEvent(new window.Event('change', {bubbles: true}));

    assert.equal(row(1).hidden, false, 'งานของฉันที่ล่าช้าต้องยังอยู่');
    assert.equal(row(2).hidden, true);
    // งานแม่ของคนอื่นไม่ล่าช้า ตัวกรองสถานะจึงตัดทิ้ง แม้จะมีงานย่อยของเราอยู่
    assert.equal(row(3).hidden, true, 'ปุ่มนี้ต้องไม่ดึงงานที่ตัวกรองสถานะตัดไปแล้วกลับมา');
});

test('สถานะปุ่มถูกเขียนลง URL และหายไปเมื่อกดปิด', () => {
    resetBoard();

    click(toggle);
    assert.equal(new URLSearchParams(window.location.search).get('mine'), '1');

    click(toggle);
    assert.equal(new URLSearchParams(window.location.search).has('mine'), false);
    assert.equal(toggle.getAttribute('aria-pressed'), 'false');
    assert.equal(toggle.classList.contains('is-active'), false);
    assert.equal(row(2).hidden, false, 'กดปิดแล้วงานพี่น้องต้องกลับมา');
});

test('ข้อความบอร์ดว่างบอกว่าปุ่มนี้เป็นคนซ่อน ไม่ใช่ว่าโปรเจกต์ไม่มีงานเหลือ', () => {
    resetBoard();

    // ค้นหาคำที่ไม่ตรงกับงานของเราเลย เพื่อให้บอร์ดว่างระหว่างเปิดปุ่ม
    const search = document.querySelector('[data-search]');
    click(toggle);
    search.value = 'ไม่มีคำนี้ในงานใดเลย';
    search.dispatchEvent(new window.Event('input', {bubbles: true}));

    const empty = document.querySelector('[data-board-empty]');
    assert.equal(empty.hidden, false);
    // มีคำค้นอยู่ด้วย ข้อความทั่วไปจึงยังถูกต้องกว่า
    assert.equal(empty.querySelector('[data-board-empty-generic]').hidden, false);
    assert.equal(empty.querySelector('[data-board-empty-mine]').hidden, true);

    search.value = '';
    search.dispatchEvent(new window.Event('input', {bubbles: true}));
    resetBoard();
});

test.after(() => dom.cleanup());
