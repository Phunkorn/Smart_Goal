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
 *
 * งานถูกยึดไว้ที่ "วันสิ้นสุด" วันเดียวตาม requirement ของปฏิทิน ไม่ลากเป็นแถบ
 * ตั้งแต่วันเริ่มอีกต่อไป การยุบ startStamp ให้เท่ากับ dueStamp ตรงนี้ทำให้เครื่องมือ
 * lane/segment เดิมได้ช่วง 1 วันโดยอัตโนมัติ จึงไม่ต้องแก้ buildMonthCalendar เลย
 * ส่วนวันเริ่มจริงถูกเก็บไว้ที่ scheduleStart* เพื่อให้การ์ดและ modal ยังแสดงได้
 *
 * ประชุมคงช่วงจริงไว้ เพราะประชุมที่คร่อมเที่ยงคืนต้องต่อเป็นแท่งเดียว
 */
export const normalizeCalendarEvent = (event) => {
    const normalized = normalizeCalendarTask(event);
    if (!normalized) return null;

    const type = event?.type === 'meeting' ? 'meeting' : 'task';
    if (type === 'meeting') return {...normalized, type};

    return {
        ...normalized,
        type,
        scheduleStart: normalized.start,
        scheduleStartStamp: normalized.startStamp,
        start: normalized.due,
        startStamp: normalized.dueStamp,
    };
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

const eventOrder = (left, right) => (
    left.startStamp - right.startStamp
    || left.dueStamp - right.dueStamp
    || String(left.title || '').localeCompare(String(right.title || ''), 'th')
    || left.id.localeCompare(right.id)
);

export const buildCalendarAgenda = (events, year, month, todayKey) => {
    const unique = new Map();
    events.map(normalizeCalendarEvent).filter(Boolean).forEach((event) => {
        if (!unique.has(event.id)) unique.set(event.id, event);
    });

    const normalizedEvents = [...unique.values()].sort(eventOrder);
    const monthStart = Date.UTC(Number(year), Number(month), 1);
    const monthEnd = Date.UTC(Number(year), Number(month) + 1, 0);
    const todayStamp = parseCalendarDate(todayKey);
    const todayTasks = todayStamp === null ? [] : normalizedEvents.filter((event) => (
        event.type === 'task' && event.dueStamp === todayStamp
    ));
    const todayMeetings = todayStamp === null ? [] : normalizedEvents.filter((event) => (
        event.type === 'meeting'
        && event.startStamp <= todayStamp
        && event.dueStamp >= todayStamp
    ));
    const monthTasks = normalizedEvents.filter((event) => (
        event.type === 'task'
        && event.dueStamp >= monthStart
        && event.dueStamp <= monthEnd
    ));
    // การ์ดรายเดือนแสดงประชุมครั้งเดียวตามวันเริ่ม แม้ประชุมจะคร่อมหลายวัน
    const monthMeetings = normalizedEvents.filter((event) => (
        event.type === 'meeting'
        && event.startStamp >= monthStart
        && event.startStamp <= monthEnd
    ));

    return {
        // งานยึดวันสิ้นสุดแล้ว เงื่อนไขนี้จึงหมายถึง "ครบกำหนดวันนี้" โดยตรง
        todayTasks,
        todayMeetings,
        todayEvents: [...todayTasks, ...todayMeetings].sort(eventOrder),
        monthTasks,
        monthMeetings,
        monthEvents: [...monthTasks, ...monthMeetings].sort(eventOrder),
    };
};

/**
 * จำนวนวันที่เหลือถึงกำหนดส่ง — บวกคือยังไม่ถึง ศูนย์คือวันนี้ ลบคือเลยมาแล้ว
 * คิดจาก "วัน" ล้วน ๆ ไม่ใช่เวลา เพราะปฏิทินทำงานที่ระดับวันตามเวลาไทยเสมอ
 */
export const daysUntilDue = (dueStamp, todayKey) => {
    const todayStamp = parseCalendarDate(todayKey);
    if (todayStamp === null || !Number.isFinite(dueStamp)) return null;

    return Math.round((dueStamp - todayStamp) / CALENDAR_DAY_MS);
};

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

/**
 * ลำดับโทนสีบนช่องวันที่ เรียงจาก "ต้องรีบที่สุด" ไปหา "รอได้"
 * ต้องตรงกับลำดับใน legend ของ toolbar เพื่อให้กวาดตาหาสีเจอทันที
 * ค่าเหล่านี้คือ job_priority ของ WorkOrder ไม่ใช่ความสำคัญของโปรเจกต์
 */
export const CALENDAR_PRIORITY_ORDER = [3, 4, 2, 5, 1];

/**
 * สรุปรายการของหนึ่งวันเป็น "จำนวนงานต่อความสำคัญ" แทนการวางชื่องานทีละชิ้น
 * ช่องวันที่จึงอ่านออกในพริบตาแม้วันนั้นจะมีงานหลายสิบชิ้น
 * ประชุมถูกนับแยกออกมาเป็นกลุ่มของตัวเองเสมอ และต่อท้ายกลุ่มงานทั้งหมด
 */
export const summarizeDayEvents = (events, maxVisible = 3) => {
    const counts = new Map();
    let meetings = 0;

    events.forEach((event) => {
        if (event.type === 'meeting') {
            meetings += 1;
            return;
        }

        const priority = CALENDAR_PRIORITY_ORDER.includes(event.priority) ? event.priority : 2;
        counts.set(priority, (counts.get(priority) || 0) + 1);
    });

    const groups = CALENDAR_PRIORITY_ORDER
        .filter((priority) => counts.has(priority))
        .map((priority) => ({key: `priority-${priority}`, type: 'task', priority, count: counts.get(priority)}));

    if (meetings > 0) groups.push({key: 'meeting', type: 'meeting', priority: null, count: meetings});

    const limit = Math.max(1, Math.floor(Number(maxVisible) || 3));

    return {
        groups,
        visibleGroups: groups.slice(0, limit),
        hiddenGroups: Math.max(0, groups.length - limit),
        // โทนของ "ทั้งช่องวันที่" คือความสำคัญสูงสุดที่มีในวันนั้น
        // groups เรียงตาม CALENDAR_PRIORITY_ORDER อยู่แล้ว ตัวแรกจึงเป็นตัวที่ด่วนที่สุดเสมอ
        // และประชุมอยู่ท้ายสุด วันที่มีแต่ประชุมจึงได้โทนประชุมโดยอัตโนมัติ
        tone: groups.length ? groups[0].key : null,
    };
};

export const buildMonthCalendar = (events, year, month, maxVisible = 3) => {
    const days = buildMonthGrid(year, month);
    const unique = new Map();
    // id ของงานและประชุมใช้คนละ prefix การกันซ้ำจึงตัดรายการที่มาถึงสองรอบออกได้ตรง ๆ
    events.map(normalizeCalendarEvent).filter(Boolean).forEach((event) => {
        if (!unique.has(event.id)) unique.set(event.id, event);
    });
    const normalizedEvents = [...unique.values()].sort(eventOrder);

    const renderedDays = days.map((day) => {
        // งานยุบเหลือวันสิ้นสุดวันเดียวแล้ว เงื่อนไขนี้จึงเหลือผลกับ "ช่วงจริง" ของประชุมเท่านั้น
        const dayEvents = normalizedEvents.filter((event) => event.startStamp <= day.stamp && event.dueStamp >= day.stamp);

        return {
            ...day,
            events: dayEvents,
            tasks: dayEvents.filter((event) => event.type === 'task'),
            meetings: dayEvents.filter((event) => event.type === 'meeting'),
            ...summarizeDayEvents(dayEvents, maxVisible),
        };
    });

    return {
        days: renderedDays,
        events: normalizedEvents,
        weeks: Array.from({length: 6}, (_, index) => ({days: renderedDays.slice(index * 7, (index + 1) * 7)})),
        maxVisible: Math.max(1, Math.floor(Number(maxVisible) || 3)),
    };
};
