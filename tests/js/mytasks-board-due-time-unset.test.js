import test from 'node:test';
import assert from 'node:assert/strict';
import {mountDom, click, clickCheckbox} from './helpers/dom.js';

/**
 * คอลัมน์ "เวลากำหนดส่ง" ของบอร์ดต้องตั้ง "ไม่ระบุเวลากำหนดส่ง" ได้
 *
 * ช่องวันที่และช่องเวลาเป็นมุมมองคนละด้านของ job_due_at ค่าเดียวกัน ทั้งคู่จึงต้องถือ
 * เจตนาเดียวกันหลังบันทึก ไม่เช่นนั้นการกดแก้ครั้งถัดไปจะส่งเจตนาเก่ากลับไปทับค่าที่เพิ่งบันทึก
 *
 * markup ต้องตรงกับ resources/views/tasks/partials/project-board-card.blade.php
 * ถ้า Blade เปลี่ยน hook ต้องแก้ที่นี่ด้วย เทสต์จึงจะยังสะท้อนของจริง
 */

const dueCells = ({unset = '0', clock = '17:00'} = {}) => `
    <label class="board-due board-due-editable">
        <span data-board-due-label>10 ก.ย. 2569</span>
        <input type="datetime-local" data-date-picker data-default-time="17:00" data-board-field="due" data-time-optional data-time-unset="${unset}" value="2026-09-10T${clock}" data-range-partner="start">
    </label>
    <label class="board-due-time board-due-time-editable">
        <i class="bi bi-clock"></i>
        <span data-board-due-time>${unset === '1' ? 'ไม่ระบุเวลา' : `${clock} น.`}</span>
        <input type="datetime-local" data-date-picker data-default-time="17:00" data-board-field="due" data-time-optional data-time-unset="${unset}" value="2026-09-10T${clock}" data-range-partner="start">
    </label>`;

const markup = (options) => `<!doctype html><html><body>
<meta name="csrf-token" content="test-token">
<div class="notion-workspace my-tasks-page" data-workspace data-context="user" data-task-scope="all"
     data-status-template="/tasks/__ID__/status"
     data-priority-template="/my-tasks/__ID__/priority"
     data-schedule-template="/tasks/__ID__/schedule">
    <div class="project-board" data-project-board>
        <div class="board-reference-list" data-board-list-body>
            <header class="board-project-group__header" data-project-header data-project-key="p1" data-project-name="p1">
                <strong class="board-project-group__title">p1</strong>
                <span><b data-board-visible-count data-board-total-count="1">1</b> งาน</span>
            </header>
            <article class="board-reference-row" data-board-task data-detail-target="0" data-project-key="p1"
                     data-task-id="10" data-context-only="0" data-participate="1" data-topic="งานทดสอบ"
                     data-status="2" data-priority="2" data-late="0" data-search-text="งานทดสอบ"
                     data-start="2026-09-01" data-start-time="09:00"
                     data-due="2026-09-10" data-due-time="${options?.clock || '17:00'}" data-due-time-unset="${options?.unset || '0'}">
                <div class="board-reference-task"><span>งานทดสอบ</span></div>
                <label class="board-start board-start-editable">
                    <span data-board-start-label>1 ก.ย. 2569</span>
                    <input type="datetime-local" data-date-picker data-default-time="00:00" data-board-field="start" value="2026-09-01T09:00" data-range-partner="due">
                </label>
                ${dueCells(options)}
            </article>
        </div>
    </div>
    <div class="notion-toast" data-toast></div>
</div>
<script type="application/json" data-task-management-data>${JSON.stringify({
    10: {
        transitions: {can_edit: true, can_reopen: false, is_final: false, allowed_statuses: [2, 5, 4], task_id: 10, child_count: 0, open_child_count: 0, children_restricted: false},
        can_work: true,
        status: 2,
    },
})}</script>
<script type="application/json" data-attachment-data>{}</script>
</body></html>`;

const bootBoard = async (t, options) => {
    const dom = mountDom(markup(options), {url: 'http://localhost/my-tasks?view=board'});
    t.after(() => dom.cleanup());
    globalThis.MutationObserver = dom.window.MutationObserver;

    const requests = [];
    dom.window.fetch = async (url, init = {}) => {
        const body = init.body ? JSON.parse(init.body) : null;
        requests.push({url, method: init.method || 'GET', body});

        // server เป็นผู้ย้ายเวลาไปสิ้นวันเมื่อผู้ใช้เลือกไม่ระบุเวลา แล้วตอบค่าจริงกลับมา
        const unset = body?.job_due_time_unset === true;

        return {
            ok: true,
            json: async () => ({
                ok: true,
                job_id: 10,
                job_start_at: '2026-09-01',
                job_start_time: '09:00',
                job_due_at: '2026-09-10',
                job_due_time: unset ? '23:59' : String(body?.job_due_at || '').split('T')[1] || '17:00',
                job_due_time_unset: unset,
                job_status: 2,
                transitions: {can_edit: true, can_reopen: false, is_final: false, allowed_statuses: [2, 5, 4], task_id: 10, child_count: 0, open_child_count: 0, children_restricted: false},
            }),
        };
    };
    globalThis.fetch = dom.window.fetch;

    const picker = await import(`../../resources/js/components/date-picker.js?board-due=${Math.random()}`);
    picker.useDatePickers();
    await import(`../../resources/js/mytasks-project-board.js?board-due=${Math.random()}`);

    const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

    return {
        ...dom,
        requests,
        settle,
        row: () => dom.document.querySelector('[data-board-task]'),
        dueInputs: () => [...dom.document.querySelectorAll('input[data-board-field="due"]')],
        dueTimeText: () => dom.document.querySelector('[data-board-due-time]').textContent,
        popover: () => dom.document.querySelector('.sg-date-picker'),
    };
};

test('ticking "no due time" in the board column saves the intent and repaints the cell', async (t) => {
    const ui = await bootBoard(t);

    click(ui.document.querySelector('.board-due-time-editable'));
    const checkbox = ui.popover().querySelector('.sg-date-picker__time-unset input');
    assert.equal(checkbox.checked, false);

    clickCheckbox(checkbox);
    await ui.settle();

    const schedule = ui.requests.find((entry) => entry.url.includes('/schedule'));
    assert.ok(schedule, 'ต้องบันทึกผ่าน endpoint ตารางเวลาเส้นทางเดิม');
    assert.equal(schedule.body.job_due_time_unset, true);

    assert.equal(ui.dueTimeText(), 'ไม่ระบุเวลา', 'คอลัมน์ต้องไม่ขึ้น 23:59 น. ที่ผู้ใช้ไม่ได้ตั้ง');
    assert.equal(ui.row().dataset.dueTimeUnset, '1');
    assert.equal(ui.row().dataset.dueTime, '23:59', 'ขณะเวลาที่ใช้ตัดสินความล่าช้ายังถูกเก็บไว้');

    // ช่องวันที่และช่องเวลาผูกกับค่าเดียวกัน ทั้งคู่จึงต้องถือเจตนาใหม่พร้อมกัน
    for (const input of ui.dueInputs()) {
        assert.equal(input.dataset.timeUnset, '1');
        assert.equal(input.value, '2026-09-10T23:59');
    }
});

test('moving the start date keeps an existing "no due time" intent', async (t) => {
    const ui = await bootBoard(t, {unset: '1', clock: '23:59'});

    const start = ui.document.querySelector('input[data-board-field="start"]');
    start.value = '2026-09-02T09:00';
    start.dispatchEvent(new ui.window.Event('change', {bubbles: true}));
    await ui.settle();

    const schedule = ui.requests.find((entry) => entry.url.includes('/schedule'));
    assert.equal(schedule.body.job_due_time_unset, true, 'เลื่อนวันเริ่มต้องไม่ล้างเจตนาของกำหนดส่ง');
    assert.equal(ui.dueTimeText(), 'ไม่ระบุเวลา');
    assert.equal(ui.row().dataset.dueTimeUnset, '1');
});

test('turning the option back off restores a real due time in the column', async (t) => {
    const ui = await bootBoard(t, {unset: '1', clock: '23:59'});

    click(ui.document.querySelector('.board-due-time-editable'));
    const checkbox = ui.popover().querySelector('.sg-date-picker__time-unset input');
    assert.equal(checkbox.checked, true);

    clickCheckbox(checkbox);
    await ui.settle();

    assert.equal(ui.requests.at(-1).body.job_due_time_unset, false);
    assert.equal(ui.dueTimeText(), '23:59 น.');
    assert.equal(ui.row().dataset.dueTimeUnset, '0');
    for (const input of ui.dueInputs()) {
        assert.equal(input.dataset.timeUnset, '0');
    }
});

/*
 * แก้กำหนดส่งจากที่อื่น (โมดัลรายละเอียดงาน ตาราง ปฏิทิน) ต้องเห็นผลที่คอลัมน์ของบอร์ดทันที
 *
 * ทุกทางเข้ายิง 'mytasks:changed' ผ่าน synchronizeTaskSource() เส้นทางเดียว บอร์ดจึงต้อง
 * วาดป้ายใหม่จาก event นั้น ไม่ใช่รอให้ผู้ใช้โหลดหน้า — ไม่งั้นสองมุมมองจะบอกคนละอย่าง
 * สำหรับงานใบเดียวกัน ซึ่งเห็นชัดที่สุดตอนเลือก "ไม่ระบุเวลากำหนดส่ง"
 */
test('a due-time change made elsewhere repaints the board column right away', async (t) => {
    const ui = await bootBoard(t);

    ui.document.dispatchEvent(new ui.window.CustomEvent('mytasks:changed', {
        detail: {id: '10', start: '2026-09-01', startTime: '09:00', due: '2026-09-10', dueTime: '23:59', dueTimeUnset: true},
    }));

    assert.equal(ui.dueTimeText(), 'ไม่ระบุเวลา');
    assert.equal(ui.row().dataset.dueTimeUnset, '1');
    for (const input of ui.dueInputs()) {
        assert.equal(input.dataset.timeUnset, '1');
        assert.equal(input.value, '2026-09-10T23:59');
    }

    ui.document.dispatchEvent(new ui.window.CustomEvent('mytasks:changed', {
        detail: {id: '10', start: '2026-09-01', startTime: '09:00', due: '2026-09-12', dueTime: '08:15', dueTimeUnset: false},
    }));

    assert.equal(ui.dueTimeText(), '08:15 น.');
    assert.equal(ui.document.querySelector('[data-board-due-label]').textContent, '12 ก.ย. 2569');
});
