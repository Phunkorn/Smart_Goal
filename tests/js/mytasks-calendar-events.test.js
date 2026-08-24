import test from 'node:test';
import assert from 'node:assert/strict';
import {
    buildMonthCalendar,
    calendarMonthKey,
    monthsNeedingFetch,
    normalizeCalendarEvent,
    rangeForMonths,
} from '../../resources/js/pages/mytasks/calendar-model.js';

const task = (id, start, due) => ({id: `task-${id}`, taskId: String(id), type: 'task', title: `งาน ${id}`, project: 'โปรเจกต์', status: 2, priority: 2, start, due});
const meeting = (id, start, due) => ({id: `meeting-${id}`, type: 'meeting', title: `ประชุม ${id}`, location: 'ห้อง A', organizer: 'ผู้จัด', startTime: '09:00', endTime: '10:00', start, due});

test('events default to the task type and meetings keep theirs', () => {
    assert.equal(normalizeCalendarEvent({id: '7', start: '2026-08-10', due: '2026-08-10'}).type, 'task');
    assert.equal(normalizeCalendarEvent(meeting(7, '2026-08-10', '2026-08-10')).type, 'meeting');
    assert.equal(normalizeCalendarEvent({id: 'x', start: '', due: ''}), null);
});

test('tasks and meetings sharing a numeric id never collide on the calendar', () => {
    const calendar = buildMonthCalendar([task(1, '2026-08-10', '2026-08-10'), meeting(1, '2026-08-10', '2026-08-10')], 2026, 7);
    const day = calendar.days.find((candidate) => candidate.key === '2026-08-10');

    assert.equal(calendar.tasks.length, 2);
    assert.deepEqual(day.milestones.map((milestone) => milestone.task.id).sort(), ['meeting-1', 'task-1']);
    assert.deepEqual(day.milestones.map((milestone) => milestone.task.type).sort(), ['meeting', 'task']);
});

test('the same event arriving twice is rendered once', () => {
    const duplicated = meeting(9, '2026-08-12', '2026-08-12');
    const calendar = buildMonthCalendar([duplicated, {...duplicated}], 2026, 7);
    const day = calendar.days.find((candidate) => candidate.key === '2026-08-12');

    assert.equal(calendar.tasks.length, 1);
    assert.equal(day.milestones.length, 1);
});

test('a meeting spanning midnight produces start and end milestones', () => {
    const calendar = buildMonthCalendar([meeting(4, '2026-08-14', '2026-08-15')], 2026, 7);
    const first = calendar.days.find((candidate) => candidate.key === '2026-08-14');
    const second = calendar.days.find((candidate) => candidate.key === '2026-08-15');

    assert.equal(first.milestones[0].kind, 'start');
    assert.equal(second.milestones[0].kind, 'end');
    assert.equal(first.tasks[0].type, 'meeting');
});

test('only months that were never loaded are requested', () => {
    assert.equal(calendarMonthKey(2026, 7), '2026-08');

    const loaded = ['2026-07', '2026-08', '2026-09'];
    assert.deepEqual(monthsNeedingFetch(2026, 7, loaded), []);
    // month 9 คือเดือนตุลาคม (นับจาก 0) กันชน ±1 จึงครอบ ก.ย. ที่โหลดแล้ว, ต.ค. และ พ.ย.
    assert.deepEqual(monthsNeedingFetch(2026, 9, loaded).map((item) => item.key), ['2026-10', '2026-11']);
});

test('a jump far ahead requests one bounded range and marks every month it covers', () => {
    const missing = monthsNeedingFetch(2027, 0, ['2026-08']);
    const range = rangeForMonths(missing);

    assert.deepEqual(missing.map((item) => item.key), ['2026-12', '2027-01', '2027-02']);
    assert.equal(range.start, '2026-12-01');
    assert.equal(range.end, '2027-02-28');
    assert.deepEqual(range.keys, ['2026-12', '2027-01', '2027-02']);
    assert.equal(rangeForMonths([]), null);
});

test('a non contiguous gap still marks the months inside the fetched span', () => {
    // ขาดเฉพาะ 2026-12 กับ 2027-02 แต่คำขอเดียวครอบ 2027-01 ไปด้วย จึงถือว่าโหลดแล้วทั้งสามเดือน
    const range = rangeForMonths([{year: 2026, month: 11, key: '2026-12'}, {year: 2027, month: 1, key: '2027-02'}]);

    assert.deepEqual(range.keys, ['2026-12', '2027-01', '2027-02']);
});
