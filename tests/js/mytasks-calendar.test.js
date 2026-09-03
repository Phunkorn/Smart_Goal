import test from 'node:test';
import assert from 'node:assert/strict';
import {
    buildCalendarAgenda,
    buildMonthCalendar,
    buildMonthGrid,
    daysUntilDue,
    normalizeCalendarTask,
    toggleCalendarDatePoint,
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

test('the default calendar keeps the complete task range and splits its line by week', () => {
    const calendar = buildMonthCalendar([
        {id: 1, title: 'Cross-week task', priority: 3, start: '2026-08-07', due: '2026-08-11'},
    ], 2026, 7);
    const daysWithTask = calendar.days.filter((day) => day.events.length > 0).map((day) => day.key);

    assert.deepEqual(daysWithTask, [
        '2026-08-07',
        '2026-08-08',
        '2026-08-09',
        '2026-08-10',
        '2026-08-11',
    ]);
    assert.deepEqual(
        calendar.days.find((day) => day.key === '2026-08-11').groups,
        [{key: 'priority-3', type: 'task', priority: 3, count: 1}],
    );

    assert.deepEqual(
        calendar.weeks.flatMap((week) => week.segments).map((segment) => ({
            startDay: segment.startDay,
            spanDays: segment.spanDays,
            continuesBefore: segment.continuesBefore,
            continuesAfter: segment.continuesAfter,
        })),
        [
            {startDay: 4, spanDays: 3, continuesBefore: false, continuesAfter: true},
            {startDay: 0, spanDays: 2, continuesBefore: true, continuesAfter: false},
        ],
    );
});

test('a task crossing the month boundary stays continuous inside the six-week grid', () => {
    const calendar = buildMonthCalendar([
        {id: 1, title: 'Month boundary', priority: 2, start: '2026-08-30', due: '2026-09-02'},
    ], 2026, 7);

    assert.equal(calendar.days.find((day) => day.key === '2026-08-30').events.length, 1);
    assert.equal(calendar.days.find((day) => day.key === '2026-08-31').events.length, 1);
    assert.equal(calendar.days.find((day) => day.key === '2026-09-02').events.length, 1);
    assert.equal(calendar.weeks[5].days[2].key, '2026-09-02');
    assert.equal(calendar.weeks[4].segments[0].continuesAfter, true);
    assert.equal(calendar.weeks[5].segments[0].continuesBefore, true);
    assert.equal(calendar.weeks[5].segments[0].showDueMarker, true);
});

test('start and due controls can collapse a task to either endpoint', () => {
    const task = [{id: 1, title: 'Selectable dates', priority: 2, start: '2026-08-07', due: '2026-08-11'}];
    const visibleDates = (datePoints) => buildMonthCalendar(task, 2026, 7, 3, {datePoints})
        .days.filter((day) => day.tasks.length).map((day) => day.key);

    assert.deepEqual(visibleDates({start: true, due: false}), ['2026-08-07']);
    assert.deepEqual(visibleDates({start: false, due: true}), ['2026-08-11']);
    assert.deepEqual(visibleDates({start: false, due: false}), ['2026-08-11']);
});

test('the summary placement colors selected endpoints without coloring the days between them', () => {
    const calendar = buildMonthCalendar([
        {id: 1, title: 'Endpoint summary', priority: 3, start: '2026-08-07', due: '2026-08-11'},
    ], 2026, 7, 3, {
        placement: 'points',
        datePoints: {start: true, due: true},
    });

    assert.deepEqual(
        calendar.days.filter((day) => day.tasks.length).map((day) => day.key),
        ['2026-08-07', '2026-08-11'],
    );
});

test('date-point controls always leave at least one visible endpoint', () => {
    assert.deepEqual(toggleCalendarDatePoint({start: true, due: true}, 'start'), {start: false, due: true});
    assert.deepEqual(toggleCalendarDatePoint({start: true, due: false}, 'start'), {start: true, due: false});
    assert.deepEqual(toggleCalendarDatePoint({start: true, due: false}, 'due'), {start: true, due: true});
});

test('timeline reserves four overlapping lanes by priority and reports the fifth task per day', () => {
    const tasks = [
        {id: 1, title: 'routine', priority: 1, start: '2026-08-10', due: '2026-08-12'},
        {id: 2, title: 'flexible', priority: 5, start: '2026-08-10', due: '2026-08-12'},
        {id: 3, title: 'important', priority: 2, start: '2026-08-10', due: '2026-08-12'},
        {id: 4, title: 'quick', priority: 4, start: '2026-08-10', due: '2026-08-12'},
        {id: 5, title: 'urgent', priority: 3, start: '2026-08-10', due: '2026-08-12'},
    ];
    const calendar = buildMonthCalendar(tasks, 2026, 7, 3, {maxTimelineLanes: 4});
    const week = calendar.weeks.find((candidate) => candidate.days.some((day) => day.key === '2026-08-10'));

    assert.deepEqual(week.segments.map((segment) => segment.event.priority), [3, 4, 2, 5]);
    assert.deepEqual(week.segments.map((segment) => segment.lane), [0, 1, 2, 3]);
    for (const key of ['2026-08-10', '2026-08-11', '2026-08-12']) {
        assert.equal(calendar.days.find((day) => day.key === key).hiddenTimelineTasks, 1);
    }
});

test('a low-priority range is hidden only on the crowded day and continues on both sides', () => {
    const tasks = [
        {id: 1, title: 'routine range', priority: 1, start: '2026-08-10', due: '2026-08-12'},
        {id: 2, title: 'urgent', priority: 3, start: '2026-08-11', due: '2026-08-11'},
        {id: 3, title: 'quick', priority: 4, start: '2026-08-11', due: '2026-08-11'},
        {id: 4, title: 'important', priority: 2, start: '2026-08-11', due: '2026-08-11'},
        {id: 5, title: 'flexible', priority: 5, start: '2026-08-11', due: '2026-08-11'},
    ];
    const calendar = buildMonthCalendar(tasks, 2026, 7);
    const week = calendar.weeks.find((candidate) => candidate.days[0].key === '2026-08-10');
    const routineSegments = week.segments.filter((segment) => segment.event.id === '1');

    assert.deepEqual(routineSegments.map((segment) => [segment.startDay, segment.spanDays]), [[0, 1], [2, 1]]);
    assert.equal(calendar.days.find((day) => day.key === '2026-08-10').hiddenTimelineTasks, 0);
    assert.equal(calendar.days.find((day) => day.key === '2026-08-11').hiddenTimelineTasks, 1);
    assert.equal(calendar.days.find((day) => day.key === '2026-08-12').hiddenTimelineTasks, 0);
});

test('non-overlapping tasks reuse a lane instead of triggering the four-line overflow', () => {
    const tasks = Array.from({length: 7}, (_, index) => ({
        id: index + 1,
        title: `day ${index + 1}`,
        priority: 3,
        start: `2026-08-${String(10 + index).padStart(2, '0')}`,
        due: `2026-08-${String(10 + index).padStart(2, '0')}`,
    }));
    const calendar = buildMonthCalendar(tasks, 2026, 7);
    const week = calendar.weeks.find((candidate) => candidate.days[0].key === '2026-08-10');

    assert.equal(week.segments.length, 7);
    assert.ok(week.segments.every((segment) => segment.lane === 0));
    assert.ok(week.days.every((day) => day.hiddenTimelineTasks === 0));
});

test('meetings stay in day summaries but never consume task timeline lanes', () => {
    const calendar = buildMonthCalendar([
        {id: 1, title: 'งาน', priority: 3, start: '2026-08-12', due: '2026-08-12'},
        {id: 'meeting-1', type: 'meeting', title: 'ประชุม', start: '2026-08-12', due: '2026-08-13'},
    ], 2026, 7);
    const day = calendar.days.find((candidate) => candidate.key === '2026-08-12');
    const week = calendar.weeks.find((candidate) => candidate.days.includes(day));

    assert.equal(day.meetings.length, 1);
    assert.deepEqual(day.groups.map((group) => group.key), ['priority-3', 'meeting']);
    assert.equal(week.segments.length, 1);
    assert.equal(week.segments[0].event.type, 'task');
});

test('agenda remains due-based even though the month grid uses full task ranges', () => {
    const agenda = buildCalendarAgenda([
        {id: 1, title: 'Range task', priority: 3, start: '2026-08-01', due: '2026-09-02'},
    ], 2026, 7, '2026-08-01');

    assert.equal(agenda.todayTasks.length, 0);
    assert.equal(agenda.monthTasks.length, 0);
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
