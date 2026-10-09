export const CALENDAR_DAY_MS = 24 * 60 * 60 * 1000;

export const moveCalendarMonth = (year, month, offset) => {
    const target = new Date(Date.UTC(Number(year), Number(month) + Number(offset), 1));
    return {year: target.getUTCFullYear(), month: target.getUTCMonth()};
};

export const calendarMonthForDate = (date = new Date()) => ({year: date.getFullYear(), month: date.getMonth()});

export const resetCalendarMonth = (initialSelection) => ({year: Number(initialSelection.year), month: Number(initialSelection.month)});

/*
 * โหมดเริ่มต้นของปฏิทินเดือน
 *
 * ช่องวันบนจอโทรศัพท์กว้างราว 45px เส้นช่วงงานถูกตัดเหลือ "PM…" และชิป "+9 งาน"
 * ทับเส้นงาน อ่านไม่ออก ขณะที่ "ภาพรวมสี" แสดงจุดสีกับจำนวนงานต่อวันได้ครบในช่องแคบ
 * จอโทรศัพท์จึงเริ่มที่ภาพรวมสี จอที่กว้างกว่ายังเริ่มที่เส้นช่วงงานเหมือนเดิม
 * ผู้ใช้ยังสลับโหมดเองได้ทุกขนาดจอ
 */
export const COMPACT_CALENDAR_QUERY = '(max-width: 575.98px)';

export const defaultCalendarMode = (isCompactScreen = false) => (isCompactScreen ? 'summary' : 'timeline');

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
 * งานต้องเก็บช่วงวันจริงไว้เป็น source of truth เพราะมุมมองเส้นใช้ช่วงตั้งแต่วันเริ่ม
 * ถึงวันสิ้นสุด ส่วน agenda ยังคงเลือกงานด้วย dueStamp โดยตรง จึงไม่เปลี่ยนความหมาย
 * ของรายการ "ครบกำหนดวันนี้" และ "กำหนดส่งในเดือนนี้"
 */
export const normalizeCalendarEvent = (event) => {
    const normalized = normalizeCalendarTask(event);
    if (!normalized) return null;

    return {
        ...normalized,
        type: 'task',
        scheduleStart: normalized.start,
        scheduleStartStamp: normalized.startStamp,
    };
};

const eventOrder = (left, right) => (
    left.startStamp - right.startStamp
    || left.dueStamp - right.dueStamp
    || String(left.title || '').localeCompare(String(right.title || ''), 'th')
    || left.id.localeCompare(right.id)
);

/**
 * วันที่ที่แถวหนึ่งในการ์ดรายเดือน "หมายถึง" จริง ๆ
 *
 * งานถูกวางไว้ที่กำหนดส่ง เพราะการ์ดนี้ชื่อว่า "กำหนดส่งและนัดหมายในเดือนนี้"
 * ส่วนประชุมถูกวางไว้ที่เวลาเริ่ม เพราะประชุมเป็นนัดหมายไม่ใช่ช่วงงาน
 */
export const agendaStamp = (event) => event.dueStamp;

/**
 * เรียงตามวันที่ที่แถวนั้นแสดงจริง ไม่ใช่ตามวันเริ่มงาน
 *
 * eventOrder() เรียงด้วย startStamp ก่อน ซึ่งถูกสำหรับปฏิทินที่วาดเป็นเส้นช่วงงาน
 * แต่ในการ์ดรายเดือนแถวหนึ่งแสดง "กำหนดส่ง" ของงาน ผลคือรายการดูเหมือนข้ามวันไปมา
 * (งานที่เริ่มวันที่ 1 แต่ครบกำหนดวันที่ 20 ไปนั่งอยู่ก่อนงานที่ครบกำหนดวันที่ 5)
 */
export const agendaOrder = (left, right) => (
    agendaStamp(left) - agendaStamp(right)
    || left.startStamp - right.startStamp
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
    const monthTasks = normalizedEvents.filter((event) => (
        event.type === 'task'
        && event.dueStamp >= monthStart
        && event.dueStamp <= monthEnd
    ));

    return {
        // งานยึดวันสิ้นสุดแล้ว เงื่อนไขนี้จึงหมายถึง "ครบกำหนดวันนี้" โดยตรง
        todayTasks,
        todayEvents: [...todayTasks].sort(eventOrder),
        monthTasks,
        // เรียงตามวันที่ของแถว (กำหนดส่งของงาน / เวลาเริ่มของประชุม) จึงไล่วันที่ 1 ไปจนสิ้นเดือน
        monthEvents: [...monthTasks].sort(agendaOrder),
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
// ระดับ 1 ("routine") ถูกเลิกใช้แล้ว ค่าที่ไม่อยู่ในลำดับนี้ตกไปอยู่อันดับของระดับ 2 ตามเดิม
export const CALENDAR_PRIORITY_ORDER = [3, 4, 2, 5];

const priorityRank = (event) => {
    const rank = CALENDAR_PRIORITY_ORDER.indexOf(event.priority);
    return rank === -1 ? CALENDAR_PRIORITY_ORDER.indexOf(2) : rank;
};

const timelineEventOrder = (left, right) => (
    priorityRank(left) - priorityRank(right)
    || left.displayStartStamp - right.displayStartStamp
    || left.displayDueStamp - right.displayDueStamp
    || eventOrder(left, right)
);

/**
 * เมื่อเลือกทั้งสองจุด งานจะแสดงเป็นช่วงจริง เมื่อเลือกจุดเดียว งานจะยุบไปที่จุดนั้น
 * ประชุมไม่ใช้ตัวกรองนี้และยังคงช่วงเวลาเดิมเสมอ
 */
const displayRangeForEvent = (event, datePoints) => {
    const showStart = datePoints.start !== false;
    const showDue = datePoints.due !== false;
    if (showStart && !showDue) {
        return {displayStartStamp: event.startStamp, displayDueStamp: event.startStamp};
    }
    if (!showStart && showDue) {
        return {displayStartStamp: event.dueStamp, displayDueStamp: event.dueStamp};
    }

    return {displayStartStamp: event.startStamp, displayDueStamp: event.dueStamp};
};

export const toggleCalendarDatePoint = (datePoints, point) => {
    const current = {
        start: datePoints?.start !== false,
        due: datePoints?.due !== false,
    };
    if (!Object.hasOwn(current, point)) return current;

    const next = {...current, [point]: !current[point]};
    return next.start || next.due ? next : current;
};

/**
 * แบ่งเส้นงานทีละสัปดาห์และจัด lane โดยให้ความสำคัญสูงกว่าจองพื้นที่ก่อน
 * lane หนึ่งรับงานหลายชิ้นได้ถ้าช่วงวันไม่ทับกัน และทุกวันมีเส้นได้สูงสุดตาม limit
 */
export const buildTimelineWeek = (events, weekDays, maxLanes = 4, datePoints = {start: true, due: true}) => {
    if (!weekDays.length) return {segments: [], hiddenByDate: {}};

    const weekStart = weekDays[0].stamp;
    const weekEnd = weekDays[weekDays.length - 1].stamp;
    const limit = Math.max(1, Math.floor(Number(maxLanes) || 4));
    const lanes = Array.from({length: limit}, () => []);
    const hiddenByDate = {};
    const candidates = events
        .filter((event) => event.type === 'task')
        .map((event) => ({...event, ...displayRangeForEvent(event, datePoints)}))
        .filter((event) => event.displayStartStamp <= weekEnd && event.displayDueStamp >= weekStart)
        .sort(timelineEventOrder);

    // เลือกสี่งานที่สำคัญที่สุดแยกในแต่ละวันก่อน จึงไม่ซ่อนงานด่วนเพียงเพราะ
    // lane ของวันข้างเคียงถูกใช้อยู่ และค่า +N จะตรงกับจำนวนที่ล้นของวันนั้นจริง
    const visibleIdsByDay = weekDays.map((day) => {
        const active = candidates.filter((event) => (
            event.displayStartStamp <= day.stamp && event.displayDueStamp >= day.stamp
        ));
        if (active.length > limit) hiddenByDate[day.key] = active.length - limit;
        return new Set(active.slice(0, limit).map((event) => event.id));
    });

    const unclottedSegments = [];
    candidates.forEach((event) => {
        const visibleDays = weekDays
            .map((_, dayIndex) => dayIndex)
            .filter((dayIndex) => visibleIdsByDay[dayIndex].has(event.id));
        if (!visibleDays.length) return;

        let runStart = visibleDays[0];
        let previous = visibleDays[0];
        [...visibleDays.slice(1), null].forEach((dayIndex) => {
            if (dayIndex !== null && dayIndex === previous + 1) {
                previous = dayIndex;
                return;
            }

            const runStartStamp = weekDays[runStart].stamp;
            const runDueStamp = weekDays[previous].stamp;
            unclottedSegments.push({
                event,
                startDay: runStart,
                endDay: previous,
                spanDays: previous - runStart + 1,
                continuesBefore: event.displayStartStamp < runStartStamp,
                continuesAfter: event.displayDueStamp > runDueStamp,
                showStartMarker: datePoints.start !== false && event.startStamp >= runStartStamp && event.startStamp <= runDueStamp,
                showDueMarker: datePoints.due !== false && event.dueStamp >= runStartStamp && event.dueStamp <= runDueStamp,
            });

            if (dayIndex !== null) runStart = dayIndex;
            previous = dayIndex;
        });
    });

    unclottedSegments.sort((left, right) => (
        left.startDay - right.startDay
        || timelineEventOrder(left.event, right.event)
        || left.endDay - right.endDay
    ));

    const segments = unclottedSegments.map((segment) => {
        const lane = lanes.findIndex((occupied) => occupied.every((range) => (
            segment.endDay < range.startDay || segment.startDay > range.endDay
        )));
        // การเลือกต่อวันรับประกัน clique ไม่เกิน limit ดังนั้น interval coloring ต้องหา lane ได้เสมอ
        if (lane === -1) return null;

        lanes[lane].push({startDay: segment.startDay, endDay: segment.endDay});
        return {
            ...segment,
            lane,
        };
    }).filter(Boolean);

    return {segments, hiddenByDate};
};

/**
 * สรุปรายการของหนึ่งวันเป็น "จำนวนงานต่อความสำคัญ" แทนการวางชื่องานทีละชิ้น
 * ช่องวันที่จึงอ่านออกในพริบตาแม้วันนั้นจะมีงานหลายสิบชิ้น
 * ประชุมถูกนับแยกออกมาเป็นกลุ่มของตัวเองเสมอ และต่อท้ายกลุ่มงานทั้งหมด
 */
export const summarizeDayEvents = (events, maxVisible = 3) => {
    const counts = new Map();

    events.forEach((event) => {
        const priority = CALENDAR_PRIORITY_ORDER.includes(event.priority) ? event.priority : 2;
        counts.set(priority, (counts.get(priority) || 0) + 1);
    });

    const groups = CALENDAR_PRIORITY_ORDER
        .filter((priority) => counts.has(priority))
        .map((priority) => ({key: `priority-${priority}`, type: 'task', priority, count: counts.get(priority)}));

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

export const buildMonthCalendar = (events, year, month, maxVisible = 3, options = {}) => {
    const days = buildMonthGrid(year, month);
    const placement = options.placement === 'points' ? 'points' : 'range';
    const datePoints = {
        start: options.datePoints?.start !== false,
        due: options.datePoints?.due !== false,
    };
    // UI ป้องกันไม่ให้ปิดทั้งคู่ แต่ model ต้องมี fallback ที่ปลอดภัยสำหรับผู้เรียกอื่นด้วย
    if (!datePoints.start && !datePoints.due) datePoints.due = true;
    const unique = new Map();
    // id ของงานและประชุมใช้คนละ prefix การกันซ้ำจึงตัดรายการที่มาถึงสองรอบออกได้ตรง ๆ
    events.map(normalizeCalendarEvent).filter(Boolean).forEach((event) => {
        if (!unique.has(event.id)) unique.set(event.id, event);
    });
    const normalizedEvents = [...unique.values()].sort(eventOrder);
    const placedEvents = normalizedEvents.map((event) => ({...event, ...displayRangeForEvent(event, datePoints)}));

    const renderedDays = days.map((day) => {
        const dayEvents = placedEvents.filter((event) => {
            if (placement === 'points') {
                return (datePoints.start && event.startStamp === day.stamp)
                    || (datePoints.due && event.dueStamp === day.stamp);
            }
            return event.displayStartStamp <= day.stamp && event.displayDueStamp >= day.stamp;
        });

        return {
            ...day,
            events: dayEvents,
            tasks: dayEvents.filter((event) => event.type === 'task'),
            ...summarizeDayEvents(dayEvents, maxVisible),
        };
    });

    const maxTimelineLanes = Math.max(1, Math.floor(Number(options.maxTimelineLanes) || 4));
    const weeks = Array.from({length: 6}, (_, index) => {
        const weekDays = renderedDays.slice(index * 7, (index + 1) * 7);
        const timeline = buildTimelineWeek(normalizedEvents, weekDays, maxTimelineLanes, datePoints);
        timeline.segments.forEach((segment) => { segment.weekIndex = index; });
        weekDays.forEach((day) => { day.hiddenTimelineTasks = timeline.hiddenByDate[day.key] || 0; });
        return {...timeline, days: weekDays};
    });

    return {
        days: renderedDays,
        events: normalizedEvents,
        weeks,
        maxVisible: Math.max(1, Math.floor(Number(maxVisible) || 3)),
        maxTimelineLanes,
        datePoints,
        placement,
    };
};
