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

test('multi-day tasks render start and end milestones across week boundaries', () => {
    const calendar = buildMonthCalendar([
        {id: 1, title: 'Cross-week task', start: '2026-08-07', due: '2026-08-11'},
    ], 2026, 7);
    const startDay = calendar.days.find((day) => day.key === '2026-08-07');
    const endDay = calendar.days.find((day) => day.key === '2026-08-11');

    assert.equal(startDay.visibleMilestones[0].kind, 'start');
    assert.equal(endDay.visibleMilestones[0].kind, 'end');
    assert.equal(calendar.weeks[2].days[1].tasks.length, 1);
});

test('a task keeps start and end milestones when its range crosses into the next month', () => {
    const calendar = buildMonthCalendar([
        {id: 1, title: 'Month boundary', start: '2026-08-30', due: '2026-09-02'},
    ], 2026, 7);

    assert.equal(calendar.days.find((day) => day.key === '2026-08-30').visibleMilestones[0].kind, 'start');
    assert.equal(calendar.days.find((day) => day.key === '2026-09-02').visibleMilestones[0].kind, 'end');
    assert.equal(calendar.weeks[5].days[2].key, '2026-09-02');
    assert.equal(calendar.weeks[5].days[2].tasks.length, 1);
});

test('only three overlapping milestones render and each day reports its hidden task count', () => {
    const tasks = Array.from({length: 4}, (_, index) => ({
        id: index + 1,
        title: `Task ${index + 1}`,
        start: '2026-08-10',
        due: '2026-08-12',
    }));
    const calendar = buildMonthCalendar(tasks, 2026, 7);
    const week = calendar.weeks[2];
    const augustTenth = week.days.find((day) => day.key === '2026-08-10');

    assert.equal(augustTenth.visibleMilestones.length, 3);
    assert.equal(augustTenth.milestones.length, 4);
    assert.equal(augustTenth.tasks.length, 4);
    assert.equal(augustTenth.hiddenCount, 1);
});

test('a hidden start milestone does not hide its later end milestone', () => {
    const tasks = [
        {id: 1, title: 'Blocker 1', start: '2026-08-03', due: '2026-08-05'},
        {id: 2, title: 'Blocker 2', start: '2026-08-03', due: '2026-08-05'},
        {id: 3, title: 'Blocker 3', start: '2026-08-03', due: '2026-08-05'},
        {id: 4, title: 'Continues after crowd', start: '2026-08-05', due: '2026-08-07'},
    ];
    const week = buildMonthCalendar(tasks, 2026, 7).weeks[1];
    const wednesday = week.days.find((day) => day.key === '2026-08-05');
    const thursday = week.days.find((day) => day.key === '2026-08-06');
    const friday = week.days.find((day) => day.key === '2026-08-07');
    const visibleEnd = friday.visibleMilestones.find((milestone) => milestone.task.id === '4');

    assert.equal(wednesday.hiddenCount, 1);
    assert.equal(thursday.hiddenCount, 0);
    assert.equal(friday.hiddenCount, 0);
    assert.equal(wednesday.visibleMilestones.some((milestone) => milestone.task.id === '4'), false);
    assert.equal(visibleEnd.kind, 'end');
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
