import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {mountDom, click, clickCheckbox} from './helpers/dom.js';
import {mountTaskWorkspace} from './helpers/task-workspace-fixture.js';
import {buildCalendarAgenda, buildTimelineWeek, normalizeCalendarEvent} from '../../resources/js/pages/mytasks/calendar-model.js';

/**
 * "ไม่มีกำหนดส่ง" — งานที่ไม่มีวันครบกำหนดเลย (job_due_at เป็น null)
 *
 * คนละเรื่องกับ "ไม่ระบุเวลากำหนดส่ง" (mytasks-board-due-time-unset.test.js) ซึ่งยังมีวันส่ง
 * ตัวเลือกอยู่ในปฏิทินใบเดียวกัน เปิดให้เฉพาะช่องที่ประกาศ data-due-optional และเจตนา
 * เดินทางไปกับ data-due-unset ของช่อง แล้วผ่านเส้นทางบันทึกเดิมของแต่ละมุมมอง
 *
 * เทสต์ชุดนี้ตามเส้นทางจริงตั้งแต่คลิกช่อง → ติ๊ก → payload ที่ส่ง → ข้อความที่ผู้ใช้เห็น
 * markup ต้องตรงกับ project-board-card.blade.php ถ้า Blade เปลี่ยน hook ต้องแก้ที่นี่ด้วย
 */

const read = (path) => readFile(new URL(`../../${path}`, import.meta.url), 'utf8');

const TRANSITIONS = {can_edit: true, can_reopen: false, is_final: false, allowed_statuses: [2, 5, 4], task_id: 10, child_count: 0, open_child_count: 0, children_restricted: false};

const dueCells = ({due = '2026-09-10', clock = '17:00', late = false} = {}) => {
    const value = due ? `${due}T${clock}` : '';
    const unset = due ? '0' : '1';
    const tone = late ? ' is-late' : '';

    return `
    <label class="board-due board-due-editable${tone}">
        <span data-board-due-label>${due ? '10 ก.ย. 2569' : 'ไม่มีกำหนดส่ง'}</span>
        <input type="datetime-local" data-date-picker data-default-time="17:00" data-board-field="due" data-time-optional data-time-unset="0" data-due-optional data-due-unset="${unset}" value="${value}" data-range-partner="start">
    </label>
    <label class="board-due-time board-due-time-editable${tone}">
        <i class="bi bi-clock"></i>
        <span data-board-due-time>${due ? `${clock} น.` : '-'}</span>
        <input type="datetime-local" data-date-picker data-default-time="17:00" data-board-field="due" data-time-optional data-time-unset="0" data-due-optional data-due-unset="${unset}" value="${value}" data-range-partner="start">
    </label>`;
};

const boardMarkup = (options = {}) => `<!doctype html><html><body>
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
                     data-due="${options.due ?? '2026-09-10'}" data-due-time="${(options.due ?? '2026-09-10') ? (options.clock || '17:00') : ''}" data-due-time-unset="0">
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
<script type="application/json" data-task-management-data>${JSON.stringify({10: {transitions: TRANSITIONS, can_work: true, status: 2}})}</script>
<script type="application/json" data-attachment-data>{}</script>
</body></html>`;

const bootBoard = async (t, options) => {
    const dom = mountDom(boardMarkup(options), {url: 'http://localhost/my-tasks?view=board'});
    t.after(() => dom.cleanup());
    globalThis.MutationObserver = dom.window.MutationObserver;

    const requests = [];
    dom.window.fetch = async (url, init = {}) => {
        const body = init.body ? JSON.parse(init.body) : null;
        requests.push({url, method: init.method || 'GET', body});

        // server เป็นเจ้าของค่าจริง: งานที่ไม่มีกำหนดส่งตอบวันและเวลากลับมาเป็นค่าว่าง
        const noDue = body?.job_due_unset === true;
        const [startDay = '2026-09-01', startClock = '09:00'] = String(body?.job_start_at || '').split('T');
        const [dueDay = '', dueClock = '17:00'] = String(body?.job_due_at || '').split('T');

        return {
            ok: true,
            json: async () => ({
                ok: true,
                job_id: 10,
                job_start_at: startDay,
                job_start_time: startClock,
                job_due_at: noDue ? '' : dueDay,
                job_due_time: noDue ? '' : dueClock,
                job_due_unset: noDue,
                job_due_time_unset: false,
                job_status: 2,
                transitions: TRANSITIONS,
            }),
        };
    };
    globalThis.fetch = dom.window.fetch;

    const picker = await import(`../../resources/js/components/date-picker.js?no-due=${Math.random()}`);
    picker.useDatePickers();
    await import(`../../resources/js/mytasks-project-board.js?no-due=${Math.random()}`);

    return {
        ...dom,
        requests,
        settle: () => new Promise((resolve) => setTimeout(resolve, 0)),
        row: () => dom.document.querySelector('[data-board-task]'),
        dueInputs: () => [...dom.document.querySelectorAll('input[data-board-field="due"]')],
        dueLabel: () => dom.document.querySelector('[data-board-due-label]').textContent,
        dueTimeText: () => dom.document.querySelector('[data-board-due-time]').textContent,
        popover: () => dom.document.querySelector('.sg-date-picker'),
        noDueCheckbox: () => dom.document.querySelector('.sg-date-picker__due-unset input'),
    };
};

/* ------------------------------------------------------------------ ปฏิทินเลือกวัน */

test('a due field offers "no due date" and ticking it travels through the normal change path', async (t) => {
    const ui = mountDom(`<!doctype html><html><body><div class="my-tasks-page"><article data-board-task>${dueCells()}</article></div></body></html>`);
    t.after(() => ui.cleanup());
    (await import(`../../resources/js/components/date-picker.js?no-due-cell=${Math.random()}`)).useDatePickers();

    const input = ui.document.querySelector('.board-due-editable input');
    const changes = [];
    ui.document.addEventListener('change', (event) => {
        if (event.target === input) changes.push(event.target.dataset.dueUnset);
    });

    click(ui.document.querySelector('.board-due-editable'));
    const popover = ui.document.querySelector('.sg-date-picker');
    const row = popover.querySelector('.sg-date-picker__due-unset');

    assert.equal(row.hidden, false, 'ช่องที่ประกาศ data-due-optional ต้องเห็นแถวนี้');
    assert.equal(row.textContent, 'ไม่มีกำหนดส่ง');
    assert.equal(row.querySelector('input').checked, false);
    assert.ok(popover.querySelector('.sg-date-picker__day.is-selected'));

    clickCheckbox(row.querySelector('input'));

    assert.equal(input.dataset.dueUnset, '1', 'เจตนาเดินทางไปกับ dataset ของช่อง');
    assert.equal(input.value, '2026-09-10T17:00', 'วันเดิมยังค้างในช่องให้ติ๊กกลับได้ — ผู้ฟังเป็นผู้ตัดสินว่าจะส่งอะไร');
    assert.deepEqual(changes, ['1'], 'ต้องยิง change หนึ่งครั้งผ่านเส้นทางบันทึกเดิม');
    // ไม่มีวันส่งก็ไม่มีเวลา และไม่มีเวลาให้ "ไม่ระบุ"
    assert.equal(popover.querySelector('.sg-date-picker__time').hidden, true);
    assert.equal(popover.querySelector('.sg-date-picker__time-unset').hidden, true);
    assert.equal(popover.querySelector('.sg-date-picker__day.is-selected'), null, 'ไม่มีวันใดถูกเลือกอีกต่อไป');
    assert.equal(popover.hidden, false, 'ติ๊กแล้วต้องยังเลือกวันต่อได้');

    // ติ๊กกลับขณะที่ช่องยังค้างวันเดิม = ได้วันเดิมคืนทันที
    clickCheckbox(row.querySelector('input'));

    assert.equal(input.dataset.dueUnset, '0');
    assert.deepEqual(changes, ['1', '0']);
    assert.equal(popover.querySelector('.sg-date-picker__time').hidden, false);
});

test('choosing "no due date" clears a "no due time" intent on the same field', async (t) => {
    const ui = mountDom(`<!doctype html><html><body><div class="my-tasks-page"><article data-board-task>${dueCells()}</article></div></body></html>`);
    t.after(() => ui.cleanup());
    (await import(`../../resources/js/components/date-picker.js?no-due-both=${Math.random()}`)).useDatePickers();

    const input = ui.document.querySelector('.board-due-editable input');
    input.dataset.timeUnset = '1';

    click(ui.document.querySelector('.board-due-editable'));
    clickCheckbox(ui.document.querySelector('.sg-date-picker__due-unset input'));

    assert.equal(input.dataset.dueUnset, '1');
    assert.equal(input.dataset.timeUnset, '0', 'สองเจตนานี้ขัดกัน งานที่ไม่มีวันส่งต้องไม่ถือ "ไม่ระบุเวลา" ค้างไว้');
});

test('a saved task without a due date keeps the intent until a real day is picked', async (t) => {
    const ui = mountDom(`<!doctype html><html><body><div class="my-tasks-page"><article data-board-task>${dueCells({due: ''})}</article></div></body></html>`);
    t.after(() => ui.cleanup());
    (await import(`../../resources/js/components/date-picker.js?no-due-saved=${Math.random()}`)).useDatePickers();

    const input = ui.document.querySelector('.board-due-editable input');
    const changes = [];
    ui.document.addEventListener('change', (event) => {
        if (event.target === input) changes.push([event.target.dataset.dueUnset, event.target.value]);
    });

    click(ui.document.querySelector('.board-due-editable'));
    const popover = ui.document.querySelector('.sg-date-picker');
    const checkbox = popover.querySelector('.sg-date-picker__due-unset input');

    assert.equal(checkbox.checked, true, 'สถานะต้องอ่านจากช่อง ไม่ใช่เริ่มที่ไม่ติ๊กทุกครั้ง');
    assert.equal(popover.querySelector('.sg-date-picker__time').hidden, true);

    // เลิกติ๊กเฉย ๆ ยังไม่มีวันให้บันทึก — กล่องไม่เดาวันให้ เพราะวันที่เดาอาจอยู่ก่อนวันเริ่มงาน
    clickCheckbox(checkbox);

    assert.deepEqual(changes, [], 'ยังไม่มีอะไรให้บันทึกจนกว่าจะเลือกวัน');
    assert.equal(input.dataset.dueUnset, '1', 'ปิดกล่องตอนนี้งานต้องยังไม่มีกำหนดส่งเหมือนเดิม');
    assert.equal(popover.querySelector('.sg-date-picker__time').hidden, false, 'แถวเวลากลับมาให้ตั้งได้');

    const day = popover.querySelector('.sg-date-picker__day:not(.is-outside)');
    click(day);

    assert.deepEqual(changes, [['0', `${day.dataset.dateValue}T17:00`]], 'เลือกวันแล้วได้เวลาตั้งต้นของช่อง และเจตนาถูกล้าง');
});

test('picking the day that is still sitting in the field also clears the intent', async (t) => {
    const ui = mountDom(`<!doctype html><html><body><div class="my-tasks-page"><article data-board-task>${dueCells()}</article></div></body></html>`);
    t.after(() => ui.cleanup());
    (await import(`../../resources/js/components/date-picker.js?no-due-same=${Math.random()}`)).useDatePickers();

    const input = ui.document.querySelector('.board-due-editable input');
    input.dataset.dueUnset = '1';
    let changed = 0;
    ui.document.addEventListener('change', (event) => { if (event.target === input) changed += 1; });

    click(ui.document.querySelector('.board-due-editable'));
    click(ui.document.querySelector('.sg-date-picker [data-date-value="2026-09-10"]'));

    assert.equal(input.dataset.dueUnset, '0');
    assert.equal(changed, 1, 'ค่าวันเท่าเดิมแต่เจตนาเปลี่ยน ผู้ฟังจึงต้องได้ change');
});

test('fields that must always carry a date never show the option', async (t) => {
    const ui = await bootBoard(t);

    click(ui.document.querySelector('.board-start-editable'));

    assert.equal(ui.document.querySelector('.sg-date-picker__due-unset').hidden, true, 'วันเริ่มงานต้องมีเสมอ');
});

/* ------------------------------------------------------------------ มุมมองตาราง (บอร์ดโปรเจกต์) */

test('ticking "no due date" in the board saves null and repaints both due columns', async (t) => {
    const ui = await bootBoard(t, {late: true});

    click(ui.document.querySelector('.board-due-time-editable'));
    clickCheckbox(ui.noDueCheckbox());
    await ui.settle();

    const schedule = ui.requests.find((entry) => entry.url.includes('/schedule'));
    assert.ok(schedule, 'ต้องบันทึกผ่าน endpoint ตารางเวลาเส้นทางเดิม');
    assert.equal(schedule.body.job_due_unset, true);
    assert.equal(schedule.body.job_due_at, null, 'วันเดิมที่ค้างในช่องต้องไม่ถูกส่งไป');
    assert.equal(schedule.body.job_due_time_unset, false);
    assert.equal(schedule.body.job_start_at, '2026-09-01T09:00', 'วันเริ่มต้องไม่ถูกลากตามวันที่กำลังจะถูกทิ้ง');

    assert.equal(ui.dueLabel(), 'ไม่มีกำหนดส่ง');
    assert.equal(ui.dueTimeText(), '-');
    assert.equal(ui.row().dataset.due, '');
    assert.equal(ui.row().dataset.dueTime, '');

    // ช่องวันที่และช่องเวลาผูกกับค่าเดียวกัน ทั้งคู่จึงต้องถือเจตนาใหม่พร้อมกัน
    for (const input of ui.dueInputs()) {
        assert.equal(input.dataset.dueUnset, '1');
        assert.equal(input.value, '');
    }
    // งานที่ไม่มีกำหนดส่งเลยกำหนดไม่ได้ สีเตือนที่ server วาดไว้ต้องหายไปด้วย
    assert.equal(ui.document.querySelectorAll('.is-late').length, 0);
});

test('moving the start date of a task without a due date keeps it without one', async (t) => {
    const ui = await bootBoard(t, {due: ''});

    const start = ui.document.querySelector('input[data-board-field="start"]');
    start.value = '2026-09-20T09:00';
    start.dispatchEvent(new ui.window.Event('change', {bubbles: true}));
    await ui.settle();

    const schedule = ui.requests.find((entry) => entry.url.includes('/schedule'));
    assert.equal(schedule.body.job_due_unset, true, 'ไม่งั้น server จะปฏิเสธว่าไม่ได้ส่งกำหนดส่งมา');
    assert.equal(schedule.body.job_due_at, null);
    assert.equal(ui.dueLabel(), 'ไม่มีกำหนดส่ง');
    assert.equal(ui.row().dataset.start, '2026-09-20');
    assert.equal(ui.row().dataset.due, '');
});

test('picking a day on a task without a due date gives it one again', async (t) => {
    const ui = await bootBoard(t, {due: ''});

    click(ui.document.querySelector('.board-due-editable'));
    assert.equal(ui.noDueCheckbox().checked, true);

    // ช่องว่าง ปฏิทินจึงเปิดที่เดือนปัจจุบันของเครื่อง — เลือกวันจากตารางที่เห็นจริง ไม่ผูกเทสต์กับวันที่ตายตัว
    const day = ui.popover().querySelector('.sg-date-picker__day:not(.is-outside)');
    const picked = day.dataset.dateValue;
    click(day);
    await ui.settle();

    const schedule = ui.requests.at(-1);
    assert.equal(schedule.body.job_due_unset, false);
    assert.equal(schedule.body.job_due_at, `${picked}T17:00`, 'ได้เวลาตั้งต้นของช่องกำหนดส่ง');
    assert.notEqual(ui.dueLabel(), 'ไม่มีกำหนดส่ง');
    assert.equal(ui.dueTimeText(), '17:00 น.');
    assert.equal(ui.row().dataset.due, picked);
    for (const input of ui.dueInputs()) {
        assert.equal(input.dataset.dueUnset, '0');
        assert.equal(input.value, `${picked}T17:00`);
    }
});

test('a failed save puts the field back on the intent the row really has', async (t) => {
    const ui = await bootBoard(t);
    globalThis.fetch = async () => ({ok: false, json: async () => ({message: 'บันทึกไม่สำเร็จ'})});
    ui.window.fetch = globalThis.fetch;

    click(ui.document.querySelector('.board-due-editable'));
    clickCheckbox(ui.noDueCheckbox());
    await ui.settle();

    assert.equal(ui.dueLabel(), '10 ก.ย. 2569');
    assert.equal(ui.row().dataset.due, '2026-09-10');
    for (const input of ui.dueInputs()) {
        assert.equal(input.dataset.dueUnset, '0', 'ช่องต้องไม่ค้างเจตนาที่บันทึกไม่สำเร็จ');
    }
});

/*
 * แก้กำหนดส่งจากที่อื่น (โมดัลรายละเอียดงาน ตาราง ปฏิทิน) ต้องเห็นผลที่คอลัมน์ของบอร์ดทันที
 * ทุกทางเข้ายิง 'mytasks:changed' ผ่าน synchronizeTaskSource() เส้นทางเดียว
 */
test('a "no due date" change made elsewhere repaints the board right away', async (t) => {
    const ui = await bootBoard(t);

    ui.document.dispatchEvent(new ui.window.CustomEvent('mytasks:changed', {
        detail: {id: '10', start: '2026-09-01', startTime: '09:00', due: '', dueTime: '', dueTimeUnset: false},
    }));

    assert.equal(ui.dueLabel(), 'ไม่มีกำหนดส่ง');
    assert.equal(ui.dueTimeText(), '-');
    for (const input of ui.dueInputs()) {
        assert.equal(input.dataset.dueUnset, '1');
        assert.equal(input.value, '');
    }

    ui.document.dispatchEvent(new ui.window.CustomEvent('mytasks:changed', {
        detail: {id: '10', start: '2026-09-01', startTime: '09:00', due: '2026-09-12', dueTime: '08:15', dueTimeUnset: false},
    }));

    assert.equal(ui.dueLabel(), '12 ก.ย. 2569');
    assert.equal(ui.dueTimeText(), '08:15 น.');
    assert.equal(ui.dueInputs()[0].dataset.dueUnset, '0');
});

/* ------------------------------------------------------------------ โมดัลรายละเอียดงาน */

const openWorkspace = async (t, row = {}) => {
    const ui = await mountTaskWorkspace();
    t.after(ui.cleanup);

    const picker = await import(`../../resources/js/components/date-picker.js?no-due-modal=${Math.random()}`);
    picker.useDatePickers();

    const requests = [];
    globalThis.fetch = async (url, init = {}) => {
        const body = init.body ? JSON.parse(init.body) : null;
        requests.push({url, body});
        const noDue = body?.job_due_unset === true;

        return {
            ok: true,
            json: async () => ({
                ok: true,
                job_due_at: noDue ? '' : String(body?.job_due_at || '').split('T')[0],
                job_due_time: noDue ? '' : String(body?.job_due_at || '').split('T')[1] || '',
                job_due_unset: noDue,
                job_due_time_unset: false,
                job_status: 2,
            }),
        };
    };

    Object.assign(ui.document.querySelector('[data-row]').dataset, row);
    if (row.due === '') ui.document.querySelector('[data-row] input[data-field="due"]').value = '';
    click(ui.openTask());

    return {
        ...ui,
        requests,
        settle: () => new Promise((resolve) => setTimeout(resolve, 0)),
        sourceRow: () => ui.document.querySelector('[data-row]'),
        dueInput: () => ui.document.querySelector('[data-task-form] input[name="job_due_at"]'),
        dueOutput: () => ui.document.querySelector('[data-workspace-due-unset]'),
        submit: () => ui.document.querySelector('[data-task-form]')
            .dispatchEvent(new ui.window.Event('submit', {bubbles: true, cancelable: true})),
    };
};

test('opening a task without a due date shows the label instead of an empty required field', async (t) => {
    const ui = await openWorkspace(t, {due: '', dueTime: ''});

    assert.equal(ui.dueInput().hidden, true);
    assert.equal(ui.dueInput().required, false, 'ช่องที่ซ่อนและว่างแต่ยัง required จะบล็อกการบันทึกโดยไม่บอกผู้ใช้');
    assert.equal(ui.dueInput().dataset.dueUnset, '1');
    assert.equal(ui.dueOutput().hidden, false);
    assert.equal(ui.dueOutput().textContent, 'ไม่มีกำหนดส่ง');
});

test('the modal sends the intent, not the stale date, and the source row follows the server', async (t) => {
    const ui = await openWorkspace(t);

    assert.equal(ui.dueInput().hidden, false);
    assert.equal(ui.dueInput().required, true, 'งานที่มีกำหนดส่งยังต้องกรอกเหมือนเดิม');

    click(ui.dueInput());
    const row = ui.document.querySelector('.sg-date-picker__due-unset');
    assert.equal(row.hidden, false, 'เซลล์กำหนดส่งของโมดัลต้องมีตัวเลือกนี้เหมือนบอร์ดและตาราง');
    clickCheckbox(row.querySelector('input'));

    assert.equal(ui.dueOutput().textContent, 'ไม่มีกำหนดส่ง');
    assert.equal(ui.dueInput().hidden, true);
    assert.equal(ui.taskModal.hidden, false, 'ติ๊กแล้วต้องยังอยู่ในโมดัลเดิม');
    assert.equal(ui.requests.length, 0, 'โมดัลบันทึกเมื่อกดปุ่มบันทึกเท่านั้น');

    ui.submit();
    await ui.settle();
    await ui.settle();

    const save = ui.requests.find((entry) => entry.url.includes('/due-date'));
    assert.ok(save, 'ต้องบันทึกผ่าน endpoint กำหนดส่งเส้นทางเดิม');
    assert.equal(save.body.job_due_unset, true);
    assert.equal(save.body.job_due_at, null);
    assert.equal(save.body.job_due_time_unset, false);

    assert.equal(ui.sourceRow().dataset.due, '');
    assert.equal(ui.sourceRow().dataset.dueTime, '');
    assert.equal(ui.sourceRow().querySelector('input[data-field="due"]').dataset.dueUnset, '1');
});

/*
 * เจตนาทั้งสองเป็นค่าที่ผู้ใช้แก้ได้จริง จึงต้องอยู่ในชุดที่โมดัลใช้ตัดสินว่า "มีอะไรเปลี่ยน"
 *
 * เดิม WORKSPACE_FIELDS มีแค่ค่าของช่อง ติ๊ก "ไม่ระบุเวลากำหนดส่ง" อย่างเดียวไม่ทำให้ค่าในช่องเปลี่ยน
 * กดบันทึกแล้วจึงได้ "ไม่มีการเปลี่ยนแปลงที่ต้องบันทึก" และเจตนาหายไปเงียบ ๆ
 */
test('an intent ticked on its own in the modal is still a change worth saving', async (t) => {
    const ui = await openWorkspace(t);

    click(ui.dueInput());
    clickCheckbox(ui.document.querySelector('.sg-date-picker__time-unset input'));
    assert.equal(ui.dueInput().value, '2026-08-05T17:00', 'ค่าในช่องไม่เปลี่ยน มีแต่เจตนาที่เปลี่ยน');

    ui.submit();
    await ui.settle();
    await ui.settle();

    const save = ui.requests.find((entry) => entry.url.includes('/due-date'));
    assert.ok(save, 'ติ๊กไม่ระบุเวลาอย่างเดียวต้องถูกส่งไปบันทึก');
    assert.equal(save.body.job_due_time_unset, true);
    assert.equal(save.body.job_due_unset, false);
});

/* ------------------------------------------------------------------ ปฏิทินงาน */

const calendarTask = (id, start, due) => ({id: `task-${id}`, taskId: String(id), type: 'task', title: `งาน ${id}`, project: 'โปรเจกต์', status: 2, priority: 2, start, due});

test('the calendar places a task without a due date on its start day only', () => {
    const event = normalizeCalendarEvent(calendarTask(1, '2026-08-10', ''));

    assert.equal(event.noDue, true);
    assert.equal(event.start, '2026-08-10');
    assert.equal(event.startStamp, event.dueStamp, 'งานวันเดียวที่วันเริ่ม');
    assert.equal(normalizeCalendarEvent(calendarTask(2, '2026-08-10', '2026-08-12')).noDue, false);
});

test('a start day is never read as a due date in the agenda or the due marker', () => {
    const noDue = calendarTask(1, '2026-08-15', '');
    const dueToday = calendarTask(2, '2026-08-10', '2026-08-15');
    const agenda = buildCalendarAgenda([noDue, dueToday], 2026, 7, '2026-08-15');

    assert.deepEqual(agenda.todayTasks.map((event) => event.id), ['task-2'], 'งานที่เริ่มวันนี้และไม่มีกำหนดส่ง ไม่ใช่งานครบกำหนดวันนี้');
    assert.deepEqual(agenda.monthTasks.map((event) => event.id), ['task-2']);

    const weekDays = Array.from({length: 7}, (_, index) => {
        const stamp = Date.UTC(2026, 7, 10 + index);
        return {stamp, key: new Date(stamp).toISOString().slice(0, 10)};
    });
    const events = [normalizeCalendarEvent(noDue), normalizeCalendarEvent(dueToday)];

    const both = buildTimelineWeek(events, weekDays).segments;
    const segment = both.find((candidate) => candidate.event.id === 'task-1');
    assert.equal(segment.spanDays, 1);
    assert.equal(segment.showStartMarker, true);
    assert.equal(segment.showDueMarker, false);

    // เปิดดูเฉพาะจุดกำหนดส่ง งานที่ไม่มีกำหนดส่งก็ไม่มีจุดให้แสดง
    const dueOnly = buildTimelineWeek(events, weekDays, 4, {start: false, due: true}).segments;
    assert.deepEqual(dueOnly.map((candidate) => candidate.event.id), ['task-2']);
});

/* ------------------------------------------------------------------ สัญญาระหว่าง Blade กับ JS */

test('every due field opts in from Blade and every view reads the label from one module', async () => {
    const shared = await read('resources/js/pages/mytasks/due-time.js');
    const support = await read('app/Support/TodayWorkspace.php');

    // ข้อความมีแหล่งเดียวต่อฝั่ง และสองฝั่งต้องตรงกัน ไม่งั้นหลังบันทึกป้ายจะเปลี่ยนคำเอง
    assert.match(shared, /NO_DUE_LABEL = 'ไม่มีกำหนดส่ง'/);
    assert.match(support, /NO_DUE_LABEL = 'ไม่มีกำหนดส่ง'/);

    for (const view of [
        'resources/js/mytasks-project-board.js',
        'resources/js/mytasks-notion.js',
        'resources/js/mytasks-task-modal.js',
        'resources/js/pages/mytasks/task-state.js',
        'resources/js/pages/mytasks/calendar.js',
    ]) {
        const source = await read(view);
        assert.match(source, /NO_DUE_LABEL/, `${view} ต้องอ่านป้ายจากโมดูลร่วม`);
        assert.doesNotMatch(source, /'ไม่มีกำหนดส่ง'/, `${view} ต้องไม่เขียนข้อความซ้ำเอง`);
    }

    // ทุกจุดที่ผู้ใช้แก้กำหนดส่งได้ต้องประกาศตัวเลือกนี้ และบอกสถานะตั้งต้นของงานนั้น
    assert.equal((await read('resources/views/tasks/partials/project-board-card.blade.php')).match(/data-board-field="due"[^>]*data-due-optional data-due-unset=/g)?.length, 2);
    assert.equal((await read('resources/views/tasks/components/task-detail-row.blade.php')).match(/data-board-field="due"[^>]*data-due-optional data-due-unset=/g)?.length, 2);
    assert.match(await read('resources/views/tasks/partials/notion-task-row.blade.php'), /data-field="due"[^>]*data-due-optional data-due-unset=/);
    assert.match(await read('resources/views/tasks/partials/workspace-interactions.blade.php'), /data-due-optional[^>]*name="job_due_at"/);

    // ช่องวันเริ่มและฟอร์มสร้างงานต้องมีวันเสมอ จึงต้องไม่ประกาศตัวเลือกนี้
    assert.doesNotMatch(await read('resources/views/tasks/partials/project-board-card.blade.php'), /data-board-field="start"[^>]*data-due-optional/);
    assert.doesNotMatch(await read('resources/views/tasks/components/user-task-create-modal.blade.php'), /data-due-optional/);
});
