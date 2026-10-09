import test from 'node:test';
import assert from 'node:assert/strict';
import {mountDom, click} from './helpers/dom.js';

/**
 * เปิดงานอีกครั้งแล้วทุกมุมมองต้องตรงกันทันที โดยไม่ต้อง Ctrl + F5
 *
 * ปัญหาจริงที่ผู้ใช้เจอ: backend บันทึกสถานะใหม่แล้ว แต่หน้ายังแสดง "เสร็จแล้ว"
 * สองสาเหตุที่ทดสอบไว้ที่นี่
 *
 *   1. แต่ละโมดูล JSON.parse [data-task-management-data] เป็นสำเนาของตัวเอง เมื่อเปิดงาน
 *      อีกครั้งจากที่หนึ่ง สำเนาของที่อื่นยังถือ is_final = true ปุ่มของมันจึงค้าง
 *   2. แถวงานย่อยที่ปิดแล้วถูกวาดเป็น <span> คงที่ ไม่มีกล่องให้เขียนป้ายใหม่เลย
 *
 * โครงสร้าง markup ต้องตรงกับ project-board-card.blade.php, task-detail-row.blade.php,
 * kanban-card.blade.php และ notion-task-row.blade.php ถ้า Blade เปลี่ยน hook ต้องแก้ที่นี่ด้วย
 */

const DONE = '4';

const statusMenu = (status, {reopen = true} = {}) => `
    <details class="board-status-menu" data-board-status-menu>
        <summary class="board-status-pill status-${status === DONE ? 'done' : 'progress'}">
            <span data-board-status-label>${status === DONE ? 'เสร็จแล้ว' : 'กำลังทำ'}</span>
        </summary>
        <div>
            ${reopen ? `<button type="button" class="board-reopen-option" data-board-status-value="2" data-board-reopen-option${status === DONE ? '' : ' hidden'}>เปิดงานอีกครั้ง</button>` : ''}
            <button type="button" data-board-status-value="5" data-board-normal-status-option${status === DONE ? ' hidden' : ''}>พักงาน</button>
            <button type="button" data-board-status-value="2" data-board-normal-status-option${status === DONE ? ' hidden' : ''}>กำลังทำ</button>
            <button type="button" data-board-status-value="4" data-board-normal-status-option${status === DONE ? ' hidden' : ''}>เสร็จแล้ว</button>
        </div>
    </details>
    <details class="board-status-menu board-priority-menu${status === DONE ? ' is-locked' : ''}" data-board-priority-menu>
        <summary class="board-priority priority-important"><span data-board-priority-label>สำคัญไม่ด่วน</span></summary>
        <div><button type="button" data-board-priority-value="3"${status === DONE ? ' disabled' : ''}>สำคัญด่วน</button></div>
    </details>
    <label class="board-due board-due-editable${status === DONE ? ' is-locked' : ''}">
        <span data-board-due-label>1 ม.ค. 2569</span>
        <input type="datetime-local" data-date-picker data-board-field="due" value="2026-01-01T17:00"${status === DONE ? ' disabled' : ''}>
    </label>`;

const markup = `<!doctype html><html><body>
<meta name="csrf-token" content="test-token">
<div class="notion-workspace my-tasks-page" data-workspace data-context="user" data-task-scope="all"
     data-status-template="/tasks/__ID__/status"
     data-priority-template="/my-tasks/__ID__/priority"
     data-kanban-card-template="/my-tasks/__ID__/kanban-card">

    <select data-filter><option value="" selected></option></select>

    <div class="project-board" data-project-board>
        <div class="board-reference-list" data-board-list-body>
            <header class="board-project-group__header" data-project-header data-project-key="p1"
                    data-project-name="p1" data-detail-project-target="0">
                <strong class="board-project-group__title">p1</strong>
                <span><b data-board-visible-count data-board-total-count="1">1</b> งาน</span>
            </header>

            <article class="board-reference-row" data-board-task data-detail-target="0" data-project-key="p1"
                     data-task-id="10" data-context-only="0" data-participate="1" data-topic="งานแม่"
                     data-status="2" data-can-review="0" data-late="0" data-cross-department="0"
                     data-project-name="p1" data-search-text="งานแม่">
                <div class="board-reference-task">
                    <div class="board-task-details" data-task-details data-work-order-id="10">
                        <button type="button" data-task-details-toggle aria-expanded="false">
                            <span class="board-reference-task__title">งานแม่</span>
                            <small data-task-details-progress class="is-complete">
                                งานย่อย <b data-task-details-count>1</b>/<span data-task-details-total>1</span>
                            </small>
                        </button>
                    </div>
                </div>
                ${statusMenu('2', {reopen: false})}

                <div class="board-task-details__panel" data-task-details-panel hidden>
                    <ol class="board-task-details__list" data-task-details-list>
                        <li class="board-task-detail" data-task-detail data-board-task data-board-subtask="1"
                            data-detail-target="0" data-detail-id="11" data-task-id="11" data-work-order-id="10"
                            data-participate="1" data-project-key="p1" data-project-name="p1" data-topic="งานย่อย"
                            data-status="${DONE}" data-can-review="0" data-late="0" data-search-text="งานย่อย">
                            <span class="board-task-detail__name">
                                <button type="button" class="board-task-detail__title" data-open-task-modal data-task-id="11">
                                    <span class="board-reference-task__title" data-task-detail-title>งานย่อย</span>
                                </button>
                            </span>
                            ${statusMenu(DONE)}
                        </li>
                    </ol>
                    <p data-task-details-empty hidden>ยังไม่มีงานย่อย</p>
                </div>
            </article>
        </div>
        <div class="project-board-empty" data-board-empty hidden>
            <p data-board-empty-generic></p><p data-board-empty-mine hidden></p>
        </div>
    </div>

    <section data-kanban>
        <div data-kanban-panel="0">
            <nav data-kanban-status-tabs>
                <button type="button" data-kanban-status-tab="2" aria-selected="true"><span>กำลังทำ</span><b data-kanban-tab-count>0</b></button>
                <button type="button" data-kanban-status-tab="4" aria-selected="false"><span>เสร็จแล้ว</span><b data-kanban-tab-count>0</b></button>
            </nav>
            <div class="mytasks-kanban__columns">
                <section class="mytasks-kanban__column" data-kanban-column="2">
                    <header><span>กำลังทำ</span><b data-kanban-count>0</b></header>
                    <div class="mytasks-kanban__cards"></div>
                </section>
                <section class="mytasks-kanban__column" data-kanban-column="4">
                    <header><span>เสร็จแล้ว</span><b data-kanban-count>0</b></header>
                    <div class="mytasks-kanban__cards">
                        <article class="mytasks-kanban__card priority-2" data-kanban-card data-id="11"
                                 data-status="${DONE}" data-priority="2" data-can-review="0"
                                 data-reviewable-subtasks="0" data-late="0" data-cross-department="0">
                            <strong data-kanban-title>งานย่อย</strong>
                            <span class="mytasks-kanban__priority priority-tone-2">สำคัญไม่ด่วน</span>
                            <button type="button" class="mytasks-kanban__subtasks" data-kanban-subtasks="10">
                                <b data-kanban-subtask-done>1</b>/<span data-kanban-subtask-total>1</span>
                            </button>
                        </article>
                    </div>
                </section>
            </div>
        </div>
    </section>

    <div class="notion-table workspace-task-source" data-table data-workspace-task-source hidden>
        <div data-groups>
            <div class="notion-row" data-row data-id="11" data-child-task="1" data-parent-id="10"
                 data-status="${DONE}" data-late="0" data-topic="งานย่อย" data-priority="2"
                 data-start="2026-01-01" data-due="2026-01-01" data-due-time="17:00" data-start-time="09:00">
                <details class="board-status-menu table-status-menu" data-table-status-menu>
                    <summary class="board-status-pill status-done"><span data-table-status-label>เสร็จแล้ว</span></summary>
                    <div>
                        <button type="button" data-table-status-value="5">พักงาน</button>
                        <button type="button" data-table-status-value="2">กำลังทำ</button>
                        <button type="button" data-table-status-value="4">เสร็จแล้ว</button>
                    </div>
                </details>
                <input type="hidden" data-field="status" value="${DONE}">
                <details class="board-priority-menu table-priority-menu is-locked" data-table-priority-menu>
                    <summary class="board-priority priority-important"><span data-table-priority-label>สำคัญไม่ด่วน</span></summary>
                    <div><button type="button" data-table-priority-value="3" disabled>สำคัญด่วน</button></div>
                </details>
                <input type="hidden" data-field="priority" value="2">
            </div>
        </div>
    </div>

    <div class="notion-toast" data-toast></div>
</div>
<script type="application/json" data-task-management-data>${JSON.stringify({
    10: {
        transitions: {
            can_edit: true, can_reopen: false, is_final: false, allowed_statuses: [2, 5],
            task_id: 10, child_count: 1, open_child_count: 0, children_restricted: false,
        },
        can_work: true, status: 2,
    },
    11: {
        transitions: {
            can_edit: false, can_reopen: true, is_final: true, allowed_statuses: [4, 2],
            task_id: 11, child_count: 0, open_child_count: 0, children_restricted: false,
        },
        can_work: false, status: 4,
    },
})}</script>
<script type="application/json" data-attachment-data>{}</script>
</body></html>`;

const dom = mountDom(markup, {url: 'http://localhost/my-tasks?view=board'});
const {document, window} = dom;

// table-controls.js เฝ้าหัวโปรเจกต์ของตารางด้วย MutationObserver ของ global
globalThis.MutationObserver = window.MutationObserver;

/** คำขอที่ออกไปจริง และสถานะที่ server ตอบกลับ (canonical state) */
const requests = [];
window.fetch = async (url, options = {}) => {
    requests.push({url, method: options.method || 'GET', body: options.body ? JSON.parse(options.body) : null});

    return {
        ok: true,
        json: async () => ({
            ok: true,
            job_id: 11,
            job_status: 2,
            transitions: {
                can_edit: true, can_reopen: false, is_final: false, allowed_statuses: [2, 5, 4],
                task_id: 11, child_count: 0, open_child_count: 0, children_restricted: false,
            },
            interactions: {is_closed: false, can_upload: true, can_comment: true, comment_url: '/tasks/11/comments', files: []},
            parent: {
                job_id: 10,
                job_status: 2,
                transitions: {
                    can_edit: true, can_reopen: false, is_final: false, allowed_statuses: [2, 5],
                    task_id: 10, child_count: 1, open_child_count: 1, children_restricted: false,
                },
            },
        }),
    };
};
globalThis.fetch = window.fetch;

window.Swal = {fire: async () => ({isConfirmed: true, value: ''})};

await import('../../resources/js/mytasks-project-board.js');
await import('../../resources/js/pages/mytasks/table-kanban.js');
await import('../../resources/js/pages/mytasks/table-controls.js');

const subtaskRow = () => document.querySelector('[data-board-subtask][data-task-id="11"]');
const boardPill = () => subtaskRow().querySelector('[data-board-status-label]');
const kanbanCard = () => document.querySelector('[data-kanban-card][data-id="11"]');
const sourceRow = () => document.querySelector('[data-workspace-task-source] [data-row][data-id="11"]');

test('งานย่อยที่ปิดแล้วมีคำสั่งเปิดงานอีกครั้งอยู่บนแถว ไม่ใช่ป้ายอ่านอย่างเดียว', () => {
    const reopen = subtaskRow().querySelector('[data-board-reopen-option]');

    assert.ok(reopen, 'แถวงานย่อยต้องมีคำสั่งเปิดงานอีกครั้ง');
    assert.equal(reopen.hidden, false, 'งานที่ปิดแล้วต้องเห็นคำสั่งนี้');
    assert.equal(boardPill().textContent.trim(), 'เสร็จแล้ว');
});

test('กดเปิดงานอีกครั้งแล้วทุก representation ของงานย่อยเปลี่ยนทันที', async () => {
    await click(subtaskRow().querySelector('[data-board-reopen-option]'));
    await new Promise((resolve) => window.setTimeout(resolve, 0));

    // ยิงไปที่ endpoint เดียวพร้อม action=reopen
    assert.equal(requests.length, 1);
    assert.equal(requests[0].url, '/tasks/11/status');
    assert.deepEqual(requests[0].body, {job_status: 2, action: 'reopen'});

    // 1) ป้ายสถานะบนแถวบอร์ด
    assert.equal(boardPill().textContent.trim(), 'กำลังทำ');
    assert.equal(subtaskRow().dataset.status, '2');

    // 2) ปุ่มเปิดงานอีกครั้งหายไป และตัวเลือกสถานะปกติกลับมา
    assert.equal(subtaskRow().querySelector('[data-board-reopen-option]').hidden, true);
    subtaskRow().querySelectorAll('[data-board-normal-status-option]').forEach((button) => {
        assert.equal(button.hidden, false, 'ตัวเลือกสถานะปกติต้องกลับมาทันที');
    });

    // 3) ปุ่มที่ถูกปิดเพราะงานปิดแล้ว ต้องกลับมากดได้ตามสิทธิ์ชุดใหม่
    assert.equal(subtaskRow().querySelector('[data-board-priority-value="3"]').disabled, false);
    assert.equal(subtaskRow().querySelector('[data-board-priority-menu]').classList.contains('is-locked'), false);
    assert.equal(subtaskRow().querySelector('input[data-board-field="due"]').disabled, false);

    // 4) การ์ด kanban ย้ายคอลัมน์และตัวนับตรงตามจริง
    assert.equal(kanbanCard().dataset.status, '2');
    assert.equal(kanbanCard().closest('[data-kanban-column]').dataset.kanbanColumn, '2');
    assert.equal(document.querySelector('[data-kanban-column="2"] [data-kanban-count]').textContent, '1');
    assert.equal(document.querySelector('[data-kanban-column="4"] [data-kanban-count]').textContent, '0');

    // 5) แถวต้นทางที่ทุกมุมมองอ่าน
    assert.equal(sourceRow().dataset.status, '2');
    assert.equal(sourceRow().querySelector('[data-table-status-label]').textContent.trim(), 'กำลังทำ');
    assert.equal(sourceRow().querySelector('input[data-field="status"]').value, '2');

    // 6) ด่านงานย่อยของงานแม่อ่านจาก state ที่ server ส่งมา ไม่ใช่ตัวเลขค้าง
    assert.equal(document.querySelector('[data-task-details-count]').textContent, '0');
    assert.equal(document.querySelector('[data-kanban-subtask-done]').textContent, '0');
});

test('การเปลี่ยนสถานะจากมุมมองหนึ่งอัปเดตสิทธิ์ของทุกมุมมอง ไม่ใช่แค่สำเนาของตัวเอง', () => {
    /*
     * ตรวจผ่านพฤติกรรมที่มองเห็น: ถ้าโมดูล kanban ยังถือ is_final = true จากสำเนาของตัวเอง
     * ป้ายขั้นถัดไปจะยังเป็น "ขั้นถัดไป: เปิดงานอีกครั้ง" แบบกดไม่ได้ ตามกฎใน task-transitions.js
     */
    const badge = kanbanCard().querySelector('[data-kanban-next]');

    assert.equal(badge.tagName, 'BUTTON', 'การ์ดที่เปิดงานใหม่แล้วต้องเสนอขั้นถัดไปที่กดได้');
    assert.equal(kanbanCard().classList.contains('is-locked'), false);
    assert.equal(kanbanCard().draggable, true, 'ลากการ์ดได้อีกครั้งหลังงานถูกเปิดใหม่');
});

test('งานที่ server เคยกรองออกจาก kanban ถูกดึง fragment กลับมาแทนการโหลดหน้าใหม่', async () => {
    const card = `<article class="mytasks-kanban__card priority-2" data-kanban-card data-id="12"
        data-status="2" data-priority="2" data-can-review="0" data-reviewable-subtasks="0"
        data-late="0" data-cross-department="0"><strong data-kanban-title>งานที่ปิดไปเมื่อวาน</strong></article>`;

    const fetched = [];
    window.fetch = async (url) => {
        fetched.push(url);

        return {ok: true, json: async () => ({ok: true, job_id: 12, job_status: 2, card})};
    };
    globalThis.fetch = window.fetch;

    assert.equal(document.querySelector('[data-kanban-card][data-id="12"]'), null);

    document.dispatchEvent(new window.CustomEvent('mytasks:changed', {
        detail: {id: '12', topic: 'งานที่ปิดไปเมื่อวาน', status: 2, priority: 2},
    }));
    await new Promise((resolve) => window.setTimeout(resolve, 0));

    assert.deepEqual(fetched, ['/my-tasks/12/kanban-card'], 'ต้องดึงเฉพาะ fragment ของงานใบนั้น');
    const inserted = document.querySelector('[data-kanban-card][data-id="12"]');
    assert.ok(inserted, 'การ์ดต้องถูกแทรกเข้าหน้าโดยไม่ต้องโหลดหน้าใหม่');
    assert.equal(inserted.closest('[data-kanban-column]').dataset.kanbanColumn, '2');
    assert.equal(document.querySelector('[data-kanban-column="2"] [data-kanban-count]').textContent, '2');
});
