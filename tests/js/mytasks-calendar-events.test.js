import test from 'node:test';
import assert from 'node:assert/strict';
import {
    buildCalendarAgenda,
    buildMonthCalendar,
    normalizeCalendarEvent,
} from '../../resources/js/pages/mytasks/calendar-model.js';

const task = (id, start, due) => ({id: `task-${id}`, taskId: String(id), type: 'task', title: `งาน ${id}`, project: 'โปรเจกต์', status: 2, priority: 2, start, due});

/*
 * ปฏิทินนี้เป็นปฏิทินของโปรเจกต์และงานเท่านั้น การประชุมและนัดหมายย้ายไปอยู่ที่
 * "ปฏิทินประชุมและนัดหมาย" แล้ว (ดู MeetingAppointmentCalendarTest และ
 * tests/js/meeting-calendar.test.js) ตัวแบบนี้จึงรู้จักเฉพาะงาน
 */
test('every calendar event is a task and unusable dates are dropped', () => {
    assert.equal(normalizeCalendarEvent({id: '7', start: '2026-08-10', due: '2026-08-10'}).type, 'task');
    // ชนิดที่ส่งมาจากภายนอกถูกมองเป็นงานเสมอ ปฏิทินนี้ไม่มีชนิดอื่นอีกแล้ว
    assert.equal(normalizeCalendarEvent({id: '8', type: 'meeting', start: '2026-08-10', due: '2026-08-10'}).type, 'task');
    assert.equal(normalizeCalendarEvent({id: 'x', start: '', due: ''}), null);
});

test('the same event arriving twice is counted once', () => {
    const duplicated = task(9, '2026-08-12', '2026-08-12');
    const calendar = buildMonthCalendar([duplicated, {...duplicated}], 2026, 7);
    const day = calendar.days.find((candidate) => candidate.key === '2026-08-12');

    assert.equal(calendar.events.length, 1);
    assert.equal(day.events.length, 1);
    assert.deepEqual(day.groups, [{key: 'priority-2', type: 'task', priority: 2, count: 1}]);
});

/*
 * การ์ดใต้ปฏิทินแบ่งงานเป็นสองกลุ่ม: วันนี้ และเดือนที่เลือก
 * งานยึด "วันสิ้นสุด" เท่านั้น ส่วนประชุมวันนี้ใช้ช่วงจริงและรายเดือนยึดวันเริ่ม
 */
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
        task(3, '2026-08-03', '2026-08-03'),
        task(4, '2026-08-02', '2026-08-12'),
    ], 2026, 7, '2026-08-01');

    assert.deepEqual(
        agenda.monthEvents.map((event) => event.id),
        ['task-3', 'task-2', 'task-4', 'task-1'],
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
