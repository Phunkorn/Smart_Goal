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
        status: Number(task.status) || 0,
        priority: Number(task.priority) || 2,
        startStamp,
        dueStamp,
        start: calendarDateKey(startStamp),
        due: calendarDateKey(dueStamp),
    };
};

/**
 * ปฏิทินวางได้ทั้งงานและการประชุม รูปทรงข้อมูลจึงเหมือนกัน ต่างที่ `type`
 * รายการที่ไม่ระบุ type ถือเป็นงานเสมอ เพื่อให้ผู้เรียกเดิมทำงานได้เหมือนก่อน
 */
export const normalizeCalendarEvent = (event) => {
    const normalized = normalizeCalendarTask(event);
    if (!normalized) return null;

    return {...normalized, type: event?.type === 'meeting' ? 'meeting' : 'task'};
};

export const calendarMonthKey = (year, month) => `${year}-${String(Number(month) + 1).padStart(2, '0')}`;

/**
 * เดือนรอบ ๆ เดือนที่กำลังดูซึ่งยังไม่เคยโหลดประชุมมาก่อน
 * แยกเป็นฟังก์ชันบริสุทธิ์เพื่อให้ทดสอบ logic การ fetch ได้โดยไม่ต้องมี DOM
 */
export const monthsNeedingFetch = (year, month, loadedKeys = [], padding = 1) => {
    const loaded = new Set(loadedKeys);
    const missing = [];

    for (let offset = -padding; offset <= padding; offset += 1) {
        const target = moveCalendarMonth(year, month, offset);
        const key = calendarMonthKey(target.year, target.month);
        if (!loaded.has(key)) missing.push({...target, key});
    }

    return missing;
};

/**
 * ช่วงวันที่ต่อเนื่องที่ครอบคลุมทุกเดือนที่ยังขาด
 * `keys` คือทุกเดือนภายในช่วง ไม่ใช่เฉพาะเดือนที่ขาด เพราะ 1 คำขอได้ข้อมูลมาทั้งช่วง
 */
export const rangeForMonths = (months) => {
    if (!months.length) return null;

    const sorted = [...months].sort((left, right) => Date.UTC(left.year, left.month) - Date.UTC(right.year, right.month));
    const first = sorted[0];
    const last = sorted[sorted.length - 1];
    const lastStamp = Date.UTC(last.year, last.month, 1);
    const keys = [];
    let cursor = {year: first.year, month: first.month};

    while (Date.UTC(cursor.year, cursor.month, 1) <= lastStamp) {
        keys.push(calendarMonthKey(cursor.year, cursor.month));
        cursor = moveCalendarMonth(cursor.year, cursor.month, 1);
    }

    return {
        start: calendarDateKey(Date.UTC(first.year, first.month, 1)),
        end: calendarDateKey(Date.UTC(last.year, last.month + 1, 0)),
        keys,
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
    // id ของงานและประชุมใช้คนละ prefix การกันซ้ำจึงตัดรายการที่มาถึงสองรอบออกได้ตรง ๆ
    const unique = new Map();
    tasks.map(normalizeCalendarEvent).filter(Boolean).forEach((event) => {
        if (!unique.has(event.id)) unique.set(event.id, event);
    });
    const normalizedTasks = [...unique.values()].sort(taskOrder);

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
