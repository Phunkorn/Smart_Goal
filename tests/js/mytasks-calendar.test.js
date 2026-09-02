import test from 'node:test';
import assert from 'node:assert/strict';
import {
    buildMonthCalendar,
    buildMonthGrid,
    daysUntilDue,
    normalizeCalendarTask,
} from '../../resources/js/pages/mytasks/calendar-model.js';
import {synchronizeTaskSource} from '../../resources/js/pages/mytasks/task-state.js';

test('month grid is Monday-first and always includes six weeks', () => {
    const days = buildMonthGrid(2026, 7);

    assert.equal(days.length, 42);
    assert.equal(days[0].key, '2026-07-27');
    assert.equal(days[41].key, '2026-09-06');
    assert.equal(days.filter((day) => day.isCurrentMonth).length, 31);
});

test('a missing range boundary falls back to a one-day task', () => {
    const startOnly = normalizeCalendarTask({id: 1, start: '2026-08-19', due: ''});
    const dueOnly = normalizeCalendarTask({id: 2, start: '', due: '2026-08-21'});

    assert.equal(startOnly.start, '2026-08-19');
    assert.equal(startOnly.due, '2026-08-19');
    assert.equal(dueOnly.start, '2026-08-21');
    assert.equal(dueOnly.due, '2026-08-21');
    assert.equal(normalizeCalendarTask({id: 3, start: '', due: ''}), null);
});

/*
 * ปฏิทินยึด "วันสิ้นสุดงาน" เท่านั้นตาม requirement ใหม่ ไม่ลากแถบจากวันเริ่มอีกต่อไป
 * และช่องวันที่สรุปเป็น "จำนวนงานต่อความสำคัญ" แทนการวางชื่องานทีละชิ้น
 */
test('a task is anchored on its due date only, never on the days from its start', () => {
    const calendar = buildMonthCalendar([
        {id: 1, title: 'Cross-week task', priority: 3, start: '2026-08-07', due: '2026-08-11'},
    ], 2026, 7);
    const daysWithTask = calendar.days.filter((day) => day.events.length > 0).map((day) => day.key);

    assert.deepEqual(daysWithTask, ['2026-08-11']);
    assert.deepEqual(
        calendar.days.find((day) => day.key === '2026-08-11').groups,
        [{key: 'priority-3', type: 'task', priority: 3, count: 1}],
    );
});

test('a task that ends in the next month appears on that due date alone', () => {
    const calendar = buildMonthCalendar([
        {id: 1, title: 'Month boundary', priority: 2, start: '2026-08-30', due: '2026-09-02'},
    ], 2026, 7);

    assert.equal(calendar.days.find((day) => day.key === '2026-08-30').events.length, 0);
    assert.equal(calendar.days.find((day) => day.key === '2026-09-02').events.length, 1);
    assert.equal(calendar.weeks[5].days[2].key, '2026-09-02');
});

/*
 * ลำดับของกลุ่มต้องตรงกับ legend เสมอ (ด่วนที่สุดขึ้นก่อน ประชุมปิดท้าย)
 * ไม่ใช่ลำดับที่ข้อมูลบังเอิญไหลเข้ามา
 */
test('day summaries follow the legend order and never depend on input order', () => {
    const events = [
        {id: 1, title: 'routine', priority: 1, start: '', due: '2026-08-12'},
        {id: 2, title: 'flexible', priority: 5, start: '', due: '2026-08-12'},
        {id: 3, title: 'urgent A', priority: 3, start: '', due: '2026-08-12'},
        {id: 4, title: 'urgent B', priority: 3, start: '', due: '2026-08-12'},
        {id: 5, title: 'quick', priority: 4, start: '', due: '2026-08-12'},
        {id: 'meeting-1', type: 'meeting', title: 'ประชุม', start: '2026-08-12', due: '2026-08-12'},
    ];
    const shape = (list) => buildMonthCalendar(list, 2026, 7)
        .days.find((day) => day.key === '2026-08-12')
        .groups.map((group) => `${group.key}:${group.count}`);

    assert.deepEqual(shape(events), ['priority-3:2', 'priority-4:1', 'priority-5:1', 'priority-1:1', 'meeting:1']);
    assert.deepEqual(shape([...events].reverse()), shape(events));
});

/*
 * โทนของทั้งช่องวันที่ = ความสำคัญสูงสุดที่มีในวันนั้น
 * วันที่มีทั้งงานด่วนและ routine ต้องอ่านว่า "ด่วน" ไม่ใช่เฉลี่ยหรือเอาตัวที่มากที่สุด
 */
test('the day tone follows the most urgent item on that day', () => {
    const toneOf = (events) => buildMonthCalendar(events, 2026, 7)
        .days.find((day) => day.key === '2026-08-12').tone;

    assert.equal(toneOf([
        {id: 1, title: 'routine A', priority: 1, start: '', due: '2026-08-12'},
        {id: 2, title: 'routine B', priority: 1, start: '', due: '2026-08-12'},
        {id: 3, title: 'urgent', priority: 3, start: '', due: '2026-08-12'},
    ]), 'priority-3');

    assert.equal(toneOf([
        {id: 1, title: 'important', priority: 2, start: '', due: '2026-08-12'},
        {id: 2, title: 'flexible', priority: 5, start: '', due: '2026-08-12'},
    ]), 'priority-2');

    // ประชุมอยู่ท้ายลำดับเสมอ วันที่มีแต่ประชุมจึงได้โทนประชุม แต่ถ้ามีงานปนงานต้องชนะ
    assert.equal(toneOf([
        {id: 'meeting-1', type: 'meeting', title: 'ประชุม', start: '2026-08-12', due: '2026-08-12'},
    ]), 'meeting');
    assert.equal(toneOf([
        {id: 'meeting-1', type: 'meeting', title: 'ประชุม', start: '2026-08-12', due: '2026-08-12'},
        {id: 1, title: 'routine', priority: 1, start: '', due: '2026-08-12'},
    ]), 'priority-1');

    assert.equal(buildMonthCalendar([], 2026, 7).days[0].tone, null);
});

test('a crowded day shows the first three groups and reports the rest as hidden', () => {
    const calendar = buildMonthCalendar([
        {id: 1, title: 'a', priority: 3, start: '', due: '2026-08-12'},
        {id: 2, title: 'b', priority: 4, start: '', due: '2026-08-12'},
        {id: 3, title: 'c', priority: 2, start: '', due: '2026-08-12'},
        {id: 4, title: 'd', priority: 5, start: '', due: '2026-08-12'},
        {id: 'meeting-1', type: 'meeting', title: 'ประชุม', start: '2026-08-12', due: '2026-08-12'},
    ], 2026, 7);
    const day = calendar.days.find((candidate) => candidate.key === '2026-08-12');

    assert.equal(day.groups.length, 5);
    assert.deepEqual(day.visibleGroups.map((group) => group.key), ['priority-3', 'priority-4', 'priority-2']);
    assert.equal(day.hiddenGroups, 2);
    // จำนวนที่ซ่อนคือ "กลุ่ม" ที่ล้น ไม่ใช่จำนวนงาน รายการเต็มยังอ่านได้ใน modal รายวัน
    assert.equal(day.events.length, 5);
});

test('an unknown priority falls back to the default group instead of disappearing', () => {
    const day = buildMonthCalendar([
        {id: 1, title: 'ไม่มีกำหนดความสำคัญ', priority: 99, start: '', due: '2026-08-12'},
    ], 2026, 7).days.find((candidate) => candidate.key === '2026-08-12');

    assert.deepEqual(day.groups.map((group) => group.key), ['priority-2']);
    assert.equal(day.events.length, 1);
});

test('days remaining is counted in whole days around the given today', () => {
    assert.equal(daysUntilDue(Date.UTC(2026, 7, 20), '2026-08-18'), 2);
    assert.equal(daysUntilDue(Date.UTC(2026, 7, 18), '2026-08-18'), 0);
    assert.equal(daysUntilDue(Date.UTC(2026, 7, 15), '2026-08-18'), -3);
    assert.equal(daysUntilDue(Date.UTC(2026, 7, 15), 'invalid'), null);
});

test('successful mutations synchronize the shared task row and emit one common event', () => {
    const fields = {status: {value: '2'}, priority: {value: '2'}, due: {value: '2026-08-20'}};
    const title = {textContent: 'Original'};
    const row = {
        dataset: {id: '17', topic: 'Original', status: '2', priority: '2', start: '2026-08-19', due: '2026-08-20'},
        querySelector(selector) {
            if (selector === '.row-title strong') return title;
            const match = /input\[data-field="(status|priority|due)"\]/.exec(selector);
            return match ? fields[match[1]] : null;
        },
    };
    const workspace = {querySelectorAll: () => [row]};
    const events = [];
    const eventTarget = {dispatchEvent: (event) => events.push(event)};

    const detail = synchronizeTaskSource(workspace, 17, {
        topic: 'Updated',
        status: 3,
        priority: 4,
        start: '2026-08-21',
        due: '2026-08-25',
    }, eventTarget);

    assert.deepEqual(row.dataset, {
        id: '17', topic: 'Updated', status: '3', priority: '4', start: '2026-08-21', due: '2026-08-25',
    });
    assert.equal(fields.status.value, '3');
    assert.equal(fields.priority.value, '4');
    assert.equal(fields.due.value, '2026-08-25');
    assert.equal(title.textContent, 'Updated');
    assert.equal(events.length, 1);
    assert.equal(events[0].type, 'mytasks:changed');
    assert.deepEqual(events[0].detail, detail);
});
