export const CALENDAR_DAY_MS = 24 * 60 * 60 * 1000;

export const moveCalendarMonth = (year, month, offset) => {
    const target = new Date(Date.UTC(Number(year), Number(month) + Number(offset), 1));
    return {year: target.getUTCFullYear(), month: target.getUTCMonth()};
};

export const calendarMonthForDate = (date = new Date()) => ({year: date.getFullYear(), month: date.getMonth()});

export const resetCalendarMonth = (initialSelection) => ({year: Number(initialSelection.year), month: Number(initialSelection.month)});

export const buddhistYear = (gregorianYear) => Number(gregorianYear) + 543;

export const parseCalendarDate = (value) => {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value || '').trim());
    if (!match) return null;

    const year = Number(match[1]);
    const month = Number(match[2]) - 1;
    const day = Number(match[3]);
    const stamp = Date.UTC(year, month, day);
    const parsed = new Date(stamp);

    if (parsed.getUTCFullYear() !== year || parsed.getUTCMonth() !== month || parsed.getUTCDate() !== day) return null;
    return stamp;
};

export const calendarDateKey = (stamp) => {
    const date = new Date(stamp);
    const year = String(date.getUTCFullYear()).padStart(4, '0');
    const month = String(date.getUTCMonth() + 1).padStart(2, '0');
    const day = String(date.getUTCDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
};

export const normalizeCalendarTask = (task) => {
    const parsedStart = parseCalendarDate(task.start);
    const parsedDue = parseCalendarDate(task.due);
    if (parsedStart === null && parsedDue === null) return null;

    const first = parsedStart ?? parsedDue;
    const last = parsedDue ?? parsedStart;
    const startStamp = Math.min(first, last);
    const dueStamp = Math.max(first, last);

    return {
        ...task,
        id: String(task.id),
        status: Number(task.status) || 1,
        priority: Number(task.priority) || 2,
        startStamp,
        dueStamp,
        start: calendarDateKey(startStamp),
        due: calendarDateKey(dueStamp),
    };
};

const taskOrder = (left, right) => (
    left.startStamp - right.startStamp
    || left.dueStamp - right.dueStamp
    || String(left.title || '').localeCompare(String(right.title || ''), 'th')
    || left.id.localeCompare(right.id)
);

export const buildMonthGrid = (year, month) => {
    const firstOfMonth = Date.UTC(year, month, 1);
    const mondayOffset = (new Date(firstOfMonth).getUTCDay() + 6) % 7;
    const gridStart = firstOfMonth - (mondayOffset * CALENDAR_DAY_MS);

    return Array.from({length: 42}, (_, index) => {
        const stamp = gridStart + (index * CALENDAR_DAY_MS);
        const date = new Date(stamp);
        return {
            stamp,
            key: calendarDateKey(stamp),
            year: date.getUTCFullYear(),
            month: date.getUTCMonth(),
            day: date.getUTCDate(),
            isCurrentMonth: date.getUTCFullYear() === year && date.getUTCMonth() === month,
        };
    });
};

const milestoneFor = (task, stamp) => {
    if (task.startStamp === task.dueStamp) {
        return {task, kind: 'single', stamp};
    }

    if (stamp === task.startStamp) {
        return {task, kind: 'start', stamp};
    }

    if (stamp === task.dueStamp) {
        return {task, kind: 'end', stamp};
    }

    return null;
};

export const buildMonthCalendar = (tasks, year, month, maxVisible = 3) => {
    const days = buildMonthGrid(year, month);
    const normalizedTasks = tasks.map(normalizeCalendarTask).filter(Boolean).sort(taskOrder);

    const renderedDays = days.map((day) => {
        const activeTasks = normalizedTasks.filter((task) => task.startStamp <= day.stamp && task.dueStamp >= day.stamp);
        const milestones = normalizedTasks
            .map((task) => milestoneFor(task, day.stamp))
            .filter(Boolean)
            .sort((left, right) => taskOrder(left.task, right.task));

        return {
            ...day,
            tasks: activeTasks,
            milestones,
            visibleMilestones: milestones.slice(0, maxVisible),
            hiddenCount: Math.max(0, milestones.length - maxVisible),
        };
    });

    const weeks = Array.from({length: 6}, (_, weekIndex) => ({
        days: renderedDays.slice(weekIndex * 7, (weekIndex + 1) * 7),
    }));

    return {days: renderedDays, tasks: normalizedTasks, weeks};
};
