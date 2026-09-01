import test from 'node:test';
import assert from 'node:assert/strict';
import {
    buildMonthCalendar,
    buildMonthGrid,
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

test('multi-day tasks render continuous weekly segments across week boundaries', () => {
    const calendar = buildMonthCalendar([
        {id: 1, title: 'Cross-week task', start: '2026-08-07', due: '2026-08-11'},
    ], 2026, 7);
    const firstSegment = calendar.weeks[1].segments[0];
    const secondSegment = calendar.weeks[2].segments[0];

    assert.deepEqual(
        [firstSegment.startColumn, firstSegment.endColumn, firstSegment.continuesBefore, firstSegment.continuesAfter],
        [5, 7, false, true],
    );
    assert.deepEqual(
        [secondSegment.startColumn, secondSegment.endColumn, secondSegment.continuesBefore, secondSegment.continuesAfter],
        [1, 2, true, false],
    );
    assert.equal(firstSegment.lane, secondSegment.lane);
    assert.equal(secondSegment.event.title, 'Cross-week task');
});

test('a task keeps continuous segments when its range crosses into the next month', () => {
    const calendar = buildMonthCalendar([
        {id: 1, title: 'Month boundary', start: '2026-08-30', due: '2026-09-02'},
    ], 2026, 7);

    assert.deepEqual(
        [calendar.weeks[4].segments[0].startColumn, calendar.weeks[4].segments[0].endColumn],
        [7, 7],
    );
    assert.deepEqual(
        [calendar.weeks[5].segments[0].startColumn, calendar.weeks[5].segments[0].endColumn],
        [1, 3],
    );
    assert.equal(calendar.weeks[4].segments[0].continuesAfter, true);
    assert.equal(calendar.weeks[5].segments[0].continuesBefore, true);
    assert.equal(calendar.weeks[5].days[2].key, '2026-09-02');
    assert.equal(calendar.weeks[5].days[2].tasks.length, 1);
});

test('only three overlapping range bars render and each day reports its hidden task count', () => {
    const tasks = Array.from({length: 4}, (_, index) => ({
        id: index + 1,
        title: `Task ${index + 1}`,
        start: '2026-08-10',
        due: '2026-08-12',
    }));
    const calendar = buildMonthCalendar(tasks, 2026, 7);
    const week = calendar.weeks[2];
    const augustTenth = week.days.find((day) => day.key === '2026-08-10');

    assert.equal(augustTenth.visibleTasks.length, 3);
    assert.equal(augustTenth.tasks.length, 4);
    assert.equal(augustTenth.hiddenCount, 1);
    assert.equal(week.segments.length, 3);
    assert.ok(week.segments.every((segment) => segment.startColumn === 1 && segment.endColumn === 3));
});

test('a hidden range returns to a stable lane when the crowded dates end', () => {
    const tasks = [
        {id: 1, title: 'Blocker 1', start: '2026-08-03', due: '2026-08-05'},
        {id: 2, title: 'Blocker 2', start: '2026-08-03', due: '2026-08-05'},
        {id: 3, title: 'Blocker 3', start: '2026-08-03', due: '2026-08-05'},
        {id: 4, title: 'Continues after crowd', start: '2026-08-05', due: '2026-08-07'},
    ];
    const week = buildMonthCalendar(tasks, 2026, 7).weeks[1];
    const wednesday = week.days.find((day) => day.key === '2026-08-05');
    const thursday = week.days.find((day) => day.key === '2026-08-06');
    const visibleRange = week.segments.find((segment) => segment.event.id === '4');

    assert.equal(wednesday.hiddenCount, 1);
    assert.equal(thursday.hiddenCount, 0);
    assert.equal(wednesday.visibleTasks.some((task) => task.id === '4'), false);
    assert.deepEqual([visibleRange.startColumn, visibleRange.endColumn], [4, 5]);
    assert.equal(visibleRange.continuesBefore, true);
    assert.equal(visibleRange.continuesAfter, false);
});

test('lane assignment is deterministic for the same overlapping ranges', () => {
    const tasks = [
        {id: 2, title: 'Second', start: '2026-08-10', due: '2026-08-14'},
        {id: 1, title: 'First', start: '2026-08-10', due: '2026-08-12'},
        {id: 3, title: 'Third', start: '2026-08-11', due: '2026-08-13'},
    ];
    const shape = (calendar) => calendar.weeks[2].segments.map((segment) => ({
        id: segment.event.id,
        lane: segment.lane,
        startColumn: segment.startColumn,
        endColumn: segment.endColumn,
    }));

    assert.deepEqual(shape(buildMonthCalendar(tasks, 2026, 7)), shape(buildMonthCalendar([...tasks].reverse(), 2026, 7)));
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
