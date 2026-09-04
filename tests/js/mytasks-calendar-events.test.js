import test from 'node:test';
import assert from 'node:assert/strict';
import {
    buildCalendarAgenda,
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

    assert.equal(calendar.events.length, 2);
    assert.deepEqual(day.events.map((event) => event.id).sort(), ['meeting-1', 'task-1']);
    // งานกับประชุมถูกนับคนละกลุ่มเสมอ ประชุมต่อท้ายกลุ่มงานทั้งหมด
    assert.deepEqual(day.groups.map((group) => [group.key, group.count]), [['priority-2', 1], ['meeting', 1]]);
});

test('the same event arriving twice is counted once', () => {
    const duplicated = meeting(9, '2026-08-12', '2026-08-12');
    const calendar = buildMonthCalendar([duplicated, {...duplicated}], 2026, 7);
    const day = calendar.days.find((candidate) => candidate.key === '2026-08-12');

    assert.equal(calendar.events.length, 1);
    assert.equal(day.events.length, 1);
    assert.deepEqual(day.groups, [{key: 'meeting', type: 'meeting', priority: null, count: 1}]);
});

test('a meeting spanning midnight is counted on both of its days', () => {
    const calendar = buildMonthCalendar([meeting(4, '2026-08-14', '2026-08-15')], 2026, 7);
    const first = calendar.days.find((candidate) => candidate.key === '2026-08-14');
    const second = calendar.days.find((candidate) => candidate.key === '2026-08-15');
    const before = calendar.days.find((candidate) => candidate.key === '2026-08-13');

    assert.equal(first.meetings.length, 1);
    assert.equal(second.meetings.length, 1);
    assert.equal(before.events.length, 0);
    assert.equal(first.events[0].type, 'meeting');
    assert.equal(first.tasks.length, 0);
});

/*
 * การ์ดใต้ปฏิทินรวมงานและประชุมในสองกลุ่ม: วันนี้ และเดือนที่เลือก
 * งานยึด "วันสิ้นสุด" เท่านั้น ส่วนประชุมวันนี้ใช้ช่วงจริงและรายเดือนยึดวันเริ่ม
 */
test('calendar agenda combines due tasks with meetings without changing their date rules', () => {
    const dueLastMonth = task(1, '2026-07-30', '2026-07-31');
    const crossingTask = task(2, '2026-07-30', '2026-08-02');
    const todayTask = task(3, '2026-08-15', '2026-08-18');
    const monthMeeting = meeting(4, '2026-08-18', '2026-08-18');
    const outsideTask = task(5, '2026-09-01', '2026-09-02');
    const agenda = buildCalendarAgenda([
        dueLastMonth,
        crossingTask,
        todayTask,
        monthMeeting,
        {...monthMeeting},
        outsideTask,
    ], 2026, 7, '2026-08-18');

    // งานที่ "เริ่ม" ในเดือนนี้แต่ครบกำหนดเดือนก่อน/เดือนหน้า ต้องไม่ติดมาด้วย
    assert.deepEqual(agenda.todayTasks.map((event) => event.id), ['task-3']);
    assert.deepEqual(agenda.todayMeetings.map((event) => event.id), ['meeting-4']);
    assert.deepEqual(agenda.todayEvents.map((event) => event.id), ['task-3', 'meeting-4']);
    assert.deepEqual(agenda.monthTasks.map((event) => event.id), ['task-2', 'task-3']);
    assert.deepEqual(agenda.monthMeetings.map((event) => event.id), ['meeting-4']);
    assert.deepEqual(agenda.monthEvents.map((event) => event.id), ['task-2', 'task-3', 'meeting-4']);
});

test('a meeting spanning today appears once in today agenda and once by its start month', () => {
    const spanningMeeting = meeting(8, '2026-07-31', '2026-08-02');
    const august = buildCalendarAgenda([spanningMeeting], 2026, 7, '2026-08-01');
    const july = buildCalendarAgenda([spanningMeeting], 2026, 6, '2026-08-01');

    assert.deepEqual(august.todayEvents.map((event) => event.id), ['meeting-8']);
    assert.deepEqual(august.monthMeetings, []);
    assert.deepEqual(july.monthMeetings.map((event) => event.id), ['meeting-8']);
});

test('a task that merely spans today is not counted as due today', () => {
    const agenda = buildCalendarAgenda([task(1, '2026-08-10', '2026-08-20')], 2026, 7, '2026-08-15');

    assert.deepEqual(agenda.todayTasks, []);
    assert.deepEqual(agenda.monthTasks.map((event) => event.id), ['task-1']);
});

test('calendar agenda handles an invalid today key without widening the month range', () => {
    const agenda = buildCalendarAgenda([
        task(1, '2026-08-30', '2026-08-31'),
        task(2, '2026-09-01', '2026-09-03'),
    ], 2026, 7, 'invalid');

    assert.deepEqual(agenda.todayTasks, []);
    assert.deepEqual(agenda.monthTasks.map((event) => event.id), ['task-1']);
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

/*
 * การ์ด "กำหนดส่งและนัดหมายในเดือนนี้" ต้องไล่วันที่จากต้นเดือนไปท้ายเดือน
 *
 * ของเดิมเรียงด้วย eventOrder() ซึ่งใช้วันเริ่มงานเป็นหลัก ถูกสำหรับปฏิทินที่วาดเป็นเส้นช่วงงาน
 * แต่แถวในการ์ดนี้แสดง "กำหนดส่ง" ของงาน รายการจึงดูเหมือนข้ามวันไปมา
 * เช่นงานที่เริ่มวันที่ 1 แต่ครบกำหนดวันที่ 20 ไปนั่งอยู่ก่อนงานที่ครบกำหนดวันที่ 5
 */
test('the month agenda runs in calendar order of the date each row shows', () => {
    const agenda = buildCalendarAgenda([
        task(1, '2026-08-01', '2026-08-20'),
        task(2, '2026-08-04', '2026-08-05'),
        meeting(3, '2026-08-03', '2026-08-03'),
        task(4, '2026-08-02', '2026-08-12'),
    ], 2026, 7, '2026-08-01');

    assert.deepEqual(
        agenda.monthEvents.map((event) => event.id),
        ['meeting-3', 'task-2', 'task-4', 'task-1'],
    );
});

test('two rows on the same day fall back to a stable order instead of shuffling', () => {
    const agenda = buildCalendarAgenda([
        task(2, '2026-08-01', '2026-08-10'),
        task(1, '2026-08-05', '2026-08-10'),
    ], 2026, 7, '2026-08-01');

    // วันเดียวกันเรียงด้วยวันเริ่มก่อน แล้วจึงชื่อและ id ผลลัพธ์จึงเหมือนเดิมทุกครั้งที่วาดใหม่
    assert.deepEqual(agenda.monthEvents.map((event) => event.id), ['task-2', 'task-1']);
});
