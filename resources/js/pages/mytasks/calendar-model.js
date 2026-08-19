export const CALENDAR_DAY_MS = 24 * 60 * 60 * 1000;

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
    || right.dueStamp - left.dueStamp
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

export const buildMonthCalendar = (tasks, year, month, maxVisible = 3) => {
    const days = buildMonthGrid(year, month);
    const normalizedTasks = tasks.map(normalizeCalendarTask).filter(Boolean).sort(taskOrder);
    const weeks = Array.from({length: 6}, (_, weekIndex) => {
        const weekDays = days.slice(weekIndex * 7, (weekIndex + 1) * 7);
        const weekStart = weekDays[0].stamp;
        const weekEnd = weekDays[6].stamp;

        const segments = normalizedTasks
            .filter((task) => task.startStamp <= weekEnd && task.dueStamp >= weekStart)
            .map((task) => ({
                task,
                startStamp: Math.max(task.startStamp, weekStart),
                dueStamp: Math.min(task.dueStamp, weekEnd),
            }))
            .sort((left, right) => (
                left.startStamp - right.startStamp
                || right.dueStamp - left.dueStamp
                || taskOrder(left.task, right.task)
            ));

        let previousLanes = new Map();
        const dayLayouts = weekDays.map((day) => {
            const dayTasks = normalizedTasks.filter((task) => task.startStamp <= day.stamp && task.dueStamp >= day.stamp);
            const lanes = Array.from({length: maxVisible}, () => null);
            const laneByTask = new Map();

            dayTasks
                .filter((task) => previousLanes.has(task.id))
                .sort((left, right) => previousLanes.get(left.id) - previousLanes.get(right.id))
                .forEach((task) => {
                    const lane = previousLanes.get(task.id);
                    if (lane >= maxVisible || lanes[lane] !== null) return;
                    lanes[lane] = task.id;
                    laneByTask.set(task.id, lane);
                });

            dayTasks.forEach((task) => {
                if (laneByTask.has(task.id)) return;
                const lane = lanes.findIndex((taskId) => taskId === null);
                if (lane === -1) return;
                lanes[lane] = task.id;
                laneByTask.set(task.id, lane);
            });

            previousLanes = laneByTask;

            return {
                ...day,
                tasks: dayTasks,
                laneByTask,
                hiddenCount: dayTasks.filter((task) => !laneByTask.has(task.id)).length,
            };
        });

        const openPieces = new Map();
        const visibleSegments = [];
        dayLayouts.forEach((day, dayIndex) => {
            const visibleToday = new Set();
            day.laneByTask.forEach((lane, taskId) => {
                visibleToday.add(taskId);
                const existing = openPieces.get(taskId);
                if (existing && existing.lane === lane && existing.columnEnd === dayIndex + 1) {
                    existing.dueStamp = day.stamp;
                    existing.columnEnd = dayIndex + 2;
                    return;
                }

                const task = day.tasks.find((candidate) => candidate.id === taskId);
                const piece = {
                    task,
                    startStamp: day.stamp,
                    dueStamp: day.stamp,
                    lane,
                    columnStart: dayIndex + 1,
                    columnEnd: dayIndex + 2,
                };
                visibleSegments.push(piece);
                openPieces.set(taskId, piece);
            });

            [...openPieces.keys()].forEach((taskId) => {
                if (!visibleToday.has(taskId)) openPieces.delete(taskId);
            });
        });

        visibleSegments.forEach((segment) => {
            segment.continuesBefore = segment.task.startStamp < segment.startStamp;
            segment.continuesAfter = segment.task.dueStamp > segment.dueStamp;
        });

        const renderedDays = dayLayouts.map(({laneByTask, ...day}) => day);

        return {days: renderedDays, segments, visibleSegments};
    });

    return {days, tasks: normalizedTasks, weeks};
};
