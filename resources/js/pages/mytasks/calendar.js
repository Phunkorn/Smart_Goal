import {statusMeta, taskPriorityMeta, unsupportedStatusMeta} from './priority-meta.js';
import {modalStack} from '../../components/modal-stack.js';
import {createCalendarQuickView} from './calendar-quick-view.js';
import {
    buddhistYear,
    buildCalendarAgenda,
    buildMonthCalendar,
    calendarMonthForDate,
    calendarMonthKey,
    daysUntilDue,
    monthsNeedingFetch,
    moveCalendarMonth,
    parseCalendarDate,
    rangeForMonths,
    resetCalendarMonth,
} from './calendar-model.js';

const monthFormatter = new Intl.DateTimeFormat('th-TH', {month: 'long', year: 'numeric', timeZone: 'UTC'});
const dateFormatter = new Intl.DateTimeFormat('th-TH', {day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC'});
const shortDateFormatter = new Intl.DateTimeFormat('th-TH', {day: 'numeric', month: 'short', year: '2-digit', timeZone: 'UTC'});

const element = (tag, className = '', text = '') => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text) node.textContent = text;
    return node;
};

const cell = (className, ...children) => {
    const node = element('span', `calendar-table__cell${className ? ` ${className}` : ''}`);
    node.setAttribute('role', 'cell');
    node.append(...children);
    return node;
};

document.querySelectorAll('[data-workspace]').forEach((workspace) => {
    const calendar = workspace.querySelector('[data-calendar]');
    const source = workspace.querySelector('[data-workspace-task-source]');
    const grid = calendar?.querySelector('[data-calendar-grid]');
    const title = calendar?.querySelector('[data-calendar-title]');
    const dayModal = workspace.querySelector('[data-calendar-day-modal]');
    const dayTitle = dayModal?.querySelector('[data-calendar-day-title]');
    const dayCount = dayModal?.querySelector('[data-calendar-day-count]');
    const dayTaskGroup = dayModal?.querySelector('[data-calendar-day-tasks]');
    const dayTaskList = dayModal?.querySelector('[data-calendar-day-task-list]');
    const dayTaskCount = dayModal?.querySelector('[data-calendar-day-task-count]');
    const dayMeetingGroup = dayModal?.querySelector('[data-calendar-day-meetings]');
    const dayMeetingList = dayModal?.querySelector('[data-calendar-day-meeting-list]');
    const dayMeetingCount = dayModal?.querySelector('[data-calendar-day-meeting-count]');
    const detail = workspace.querySelector('[data-calendar-detail]');
    const monthSelect = calendar?.querySelector('[data-calendar-month]');
    const yearSelect = calendar?.querySelector('[data-calendar-year]');
    if (!calendar || !source || !grid || !title || !dayModal || !dayTitle || !dayTaskList || !dayMeetingList || !detail || !monthSelect || !yearSelect) return;

    const json = (selector) => { const node = document.querySelector(selector); return node ? JSON.parse(node.textContent || '{}') : {}; };
    const teamData = json('[data-team-data]');
    const attachmentData = json('[data-attachment-data]');

    const loadingIndicator = calendar.querySelector('[data-calendar-loading]');
    const filteredIndicator = calendar.querySelector('[data-calendar-filtered]');
    const searchInput = calendar.querySelector('[data-calendar-search]');
    const todayList = calendar.querySelector('[data-calendar-today-list]');
    const todayEmpty = calendar.querySelector('[data-calendar-today-empty]');
    const todayCount = calendar.querySelector('[data-calendar-today-count]');
    const monthList = calendar.querySelector('[data-calendar-month-list]');
    const monthEmpty = calendar.querySelector('[data-calendar-month-empty]');
    const monthCount = calendar.querySelector('[data-calendar-month-count]');
    const monthAgendaTitle = calendar.querySelector('[data-calendar-month-agenda-title]');
    const meetingsEndpoint = calendar.dataset.meetingsEndpoint || '';
    const meetingsById = new Map();
    const loadedMonths = new Set();
    const pendingRanges = new Set();
    let toastTimer;

    const now = new Date();
    const initialSelection = Object.freeze(calendarMonthForDate(now));
    const todayKey = [now.getFullYear(), String(now.getMonth() + 1).padStart(2, '0'), String(now.getDate()).padStart(2, '0')].join('-');
    let selectedYear = initialSelection.year;
    let selectedMonth = initialSelection.month;
    let monthData = null;
    let activeDayKey = null;

    const ensureYearOption = (year) => {
        if (!yearSelect.querySelector(`option[value="${year}"]`)) {
            const option = element('option', '', String(buddhistYear(year)));
            option.value = String(year);
            yearSelect.append(option);
            [...yearSelect.options].sort((left, right) => Number(left.value) - Number(right.value)).forEach((item) => yearSelect.append(item));
        }
    };
    for (let year = now.getFullYear() - 5; year <= now.getFullYear() + 5; year += 1) ensureYearOption(year);

    const synchronizeSelectors = () => {
        ensureYearOption(selectedYear);
        monthSelect.value = String(selectedMonth);
        yearSelect.value = String(selectedYear);
    };

    const rememberMeetings = (meetings) => {
        meetings.forEach((meeting) => {
            if (meeting?.id) meetingsById.set(String(meeting.id), meeting);
        });
    };

    const markMonthsLoaded = (startDate, endDate) => {
        const start = parseCalendarDate(startDate);
        const end = parseCalendarDate(endDate);
        if (start === null || end === null) return;

        let cursor = new Date(start);
        while (Date.UTC(cursor.getUTCFullYear(), cursor.getUTCMonth(), 1) <= end) {
            loadedMonths.add(calendarMonthKey(cursor.getUTCFullYear(), cursor.getUTCMonth()));
            cursor = new Date(Date.UTC(cursor.getUTCFullYear(), cursor.getUTCMonth() + 1, 1));
        }
    };

    // ประชุมของช่วงเดือนตั้งต้นมากับ HTML แล้ว รอบแรกจึงวาดได้ทันทีโดยไม่ต้องรอเครือข่าย
    const preloaded = calendar.querySelector('[data-calendar-meetings]');
    if (preloaded) {
        try {
            rememberMeetings(JSON.parse(preloaded.textContent || '[]'));
            markMonthsLoaded(calendar.dataset.meetingsLoadedStart, calendar.dataset.meetingsLoadedEnd);
        } catch (_) { /* ข้อมูลเสียหายให้ถือว่ายังไม่โหลด แล้วปล่อยให้ fetch เติมภายหลัง */ }
    }

    const showToast = (message) => {
        const node = document.querySelector('[data-toast]');
        if (!node) return;
        node.textContent = message;
        node.style.background = '#dc2626';
        node.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => node.classList.remove('show'), 2600);
    };

    const setLoading = (isLoading) => {
        if (loadingIndicator) loadingIndicator.hidden = !isLoading;
    };

    const quickViewTemplate = calendar.dataset.taskQuickviewTemplate || '';
    const taskDetailTemplate = calendar.dataset.taskDetailTemplate || '';
    const quickView = createCalendarQuickView(document);

    const readEvents = () => {
        const unique = new Map();
        source.querySelectorAll('[data-row]').forEach((row) => {
            const id = `task-${row.dataset.id}`;
            unique.set(id, {
                id,
                taskId: row.dataset.id,
                type: 'task',
                entityId: Number(row.dataset.id),
                quickViewUrl: quickViewTemplate.replace('__ID__', row.dataset.id),
                detailUrl: taskDetailTemplate.replace('__ID__', row.dataset.id),
                title: row.dataset.topic || 'ไม่มีชื่องาน',
                project: row.dataset.project || 'งานทั่วไป',
                assignee: row.dataset.assignee || '',
                status: Number(row.dataset.status) || 0,
                priority: Number(row.dataset.priority) || 2,
                start: row.dataset.start || '',
                due: row.dataset.due || '',
            });
        });
        meetingsById.forEach((meeting, id) => unique.set(id, meeting));
        return [...unique.values()];
    };

    /**
     * ตัวกรองของปฏิทินย่อ "สิ่งที่แสดง" เท่านั้น ไม่ได้ขยายสิทธิ์ใด ๆ
     * ข้อมูลที่กรองมาจากรายการที่ผู้ใช้มีสิทธิ์เห็นอยู่แล้วทั้งหมด
     */
    const matchesSearch = (event, term) => {
        if (!term) return true;
        const haystack = event.type === 'meeting'
            ? [event.title, event.location, event.organizer, ...(event.attendees || []).map((person) => person.name)]
            : [event.title, event.project, event.assignee, ...(teamOf(event.taskId).collaborators || []).map((person) => person.name)];

        return haystack.filter(Boolean).some((value) => String(value).toLowerCase().includes(term));
    };

    const visibleEvents = () => {
        const term = String(searchInput?.value || '').trim().toLowerCase();
        const all = readEvents();
        const shown = all.filter((event) => matchesSearch(event, term));

        if (filteredIndicator) {
            filteredIndicator.hidden = !term;
            filteredIndicator.textContent = term ? `กรองด้วย "${searchInput.value.trim()}" · ${shown.length} จาก ${all.length} รายการ` : '';
        }
        return shown;
    };

    const eventDateLabel = (event) => {
        const due = dateFormatter.format(new Date(event.dueStamp));
        if (event.startStamp === event.dueStamp) return due;
        return `${dateFormatter.format(new Date(event.startStamp))} – ${due}`;
    };

    /** ข้อความบอกระยะถึงกำหนดส่ง อ่านแล้วรู้ทันทีว่าต้องรีบแค่ไหน */
    const dueDistanceLabel = (event) => {
        const remaining = daysUntilDue(event.dueStamp, todayKey);
        if (remaining === null) return '';
        if (remaining === 0) return 'ครบกำหนดวันนี้';
        return remaining > 0 ? `เหลือ ${remaining} วัน` : `เลยกำหนด ${Math.abs(remaining)} วัน`;
    };

    const eventAriaLabel = (event) => {
        if (event.type === 'meeting') {
            return `การประชุม: ${event.title}, ${event.startTime}–${event.endTime} น., ${event.location}, ผู้จัด ${event.organizer}, ${eventDateLabel(event)}`;
        }

        const status = statusMeta[event.status]?.label || unsupportedStatusMeta.label;
        const priority = taskPriorityMeta[event.priority]?.label || taskPriorityMeta[2].label;
        return `งาน: ${event.title}, ${event.project}, ${status}, ${priority}, กำหนดส่ง ${eventDateLabel(event)}`;
    };

    const rowForTask = (id) => [...source.querySelectorAll('[data-row]')].find((candidate) => String(candidate.dataset.id) === String(id));

    /**
     * คลิกงานบนปฏิทินต้องเปิด Task Workspace ชุดเดียวกับตารางและบอร์ด
     * ถ้าหน้านั้นไม่ได้ฝัง Workspace ไว้ ให้ตกกลับไปใช้การ์ดอ่านอย่างเดียวเหมือนเดิม
     */
    const openTaskWorkspace = (id) => {
        const trigger = rowForTask(id)?.querySelector('[data-open-task-modal]');
        if (!document.querySelector('[data-task-modal]') || !trigger) return false;
        trigger.click();
        return true;
    };
    const displayDate = (value) => value ? dateFormatter.format(new Date(`${value}T00:00:00Z`)) : 'ไม่ระบุ';
    const fillList = (target, items, empty) => target.replaceChildren(...(items.length ? items.map((item) => element('p', '', item)) : [element('p', 'is-empty', empty)]));
    const closeDetail = () => { modalStack(document).close(detail); detail.removeAttribute('data-task-id'); };
    const openReadOnlyTask = (id) => {
        const row = rowForTask(id);
        if (!row) return;
        const key = String(id);
        const team = teamData[key] || {};
        const files = attachmentData[key]?.files || [];
        detail.dataset.taskId = key;
        detail.querySelector('[data-calendar-detail-title]').textContent = row.dataset.topic || 'ไม่มีชื่องาน';
        detail.querySelector('[data-calendar-detail-project]').textContent = row.dataset.project || 'งานทั่วไป';
        detail.querySelector('[data-calendar-detail-status]').textContent = (statusMeta[Number(row.dataset.status)] || unsupportedStatusMeta).label;
        detail.querySelector('[data-calendar-detail-priority]').textContent = (taskPriorityMeta[Number(row.dataset.priority)] || taskPriorityMeta[2]).label;
        detail.querySelector('[data-calendar-detail-start]').textContent = displayDate(row.dataset.start);
        detail.querySelector('[data-calendar-detail-due]').textContent = displayDate(row.dataset.due);
        detail.querySelector('[data-calendar-detail-assignee]').textContent = team.assignee?.name || row.dataset.assignee || 'ไม่ระบุ';
        detail.querySelector('[data-calendar-detail-collaborators]').textContent = (team.collaborators || []).map((person) => `${person.name}${person.status === 'pending' ? ' (รอตอบรับ)' : ''}`).join(', ') || 'ไม่มีผู้ร่วมงาน';
        fillList(detail.querySelector('[data-calendar-detail-attachments]'), files.map((file) => file.name), 'ไม่มีไฟล์แนบ');
        modalStack(document).open(detail);
    };

    const configureCalendarEventNode = (node, event) => {
        const isMeeting = event.type === 'meeting';
        if (isMeeting) node.href = event.url;
        else node.type = 'button';
        node.dataset.calendarTask = event.id;
        node.dataset.calendarEventType = event.type;
        if (event.quickViewUrl) node.dataset.calendarQuickView = event.quickViewUrl;
        if (event.detailUrl) node.dataset.calendarDetailUrl = event.detailUrl;
        node.setAttribute('aria-label', eventAriaLabel(event));
        if (event.quickViewUrl) {
            node.setAttribute('aria-haspopup', 'dialog');
            node.setAttribute('aria-expanded', 'false');
            node.setAttribute('aria-controls', 'calendar-quick-view-popover');
        }
        return node;
    };

    /* ---------- avatar ---------- */

    /**
     * แหล่งข้อมูลของงานคือ [data-team-data] ที่ workspace-interactions ฝังไว้แล้ว
     * ส่วนประชุมมากับ payload ของ MeetingQueryService ทั้งคู่เป็นข้อมูลจริงของระบบ
     * ไม่มีการประกอบ path รูปเอง ทุก URL ผ่าน MediaController แล้ว
     */
    const makeAvatar = (person, className = '') => {
        const name = person?.name || 'ไม่ระบุ';
        const node = element('i', `calendar-avatar${className ? ` ${className}` : ''}`);
        node.title = person?.status === 'pending' ? `${name} — รอตอบรับ` : name;

        if (person?.avatar_url) {
            const image = element('img');
            image.src = person.avatar_url;
            image.alt = '';
            image.loading = 'lazy';
            node.append(image);
        } else {
            node.textContent = name.substring(0, 1);
        }
        return node;
    };

    const makeAvatarStack = (people, emptyLabel) => {
        const node = element('span', 'calendar-people__stack');
        people.slice(0, 3).forEach((person) => {
            node.append(makeAvatar(person, person?.status === 'pending' ? 'is-pending' : ''));
        });
        if (people.length > 3) node.append(element('b', 'calendar-people__more', `+${people.length - 3}`));
        if (!people.length) node.append(element('small', 'calendar-people__none', emptyLabel));
        return node;
    };

    const teamOf = (taskId) => teamData[String(taskId)] || {};

    /* ---------- ตัวสร้างช่องของตาราง ---------- */

    const toneOf = (event) => (taskPriorityMeta[event.priority] || taskPriorityMeta[2]).className;

    const titleCell = (event) => {
        const marker = element('i', event.type === 'meeting'
            ? 'calendar-table__marker bi bi-calendar-event'
            : `calendar-table__marker calendar-dot ${toneOf(event)}`);
        marker.setAttribute('aria-hidden', 'true');
        return cell('is-title', marker, element('span', '', event.title));
    };

    const tag = (className, label) => element('span', `calendar-tag ${className}`, label);

    const taskCellFactory = {
        title: titleCell,
        project: (event) => cell('is-muted', element('span', '', event.project)),
        owner: (event) => cell('is-people', makeAvatar(teamOf(event.taskId).assignee, 'is-owner')),
        collaborators: (event) => cell('is-people', makeAvatarStack(teamOf(event.taskId).collaborators || [], 'ไม่มี')),
        priority: (event) => {
            const meta = taskPriorityMeta[event.priority] || taskPriorityMeta[2];
            return cell('', tag(meta.className, meta.label));
        },
        status: (event) => {
            const meta = statusMeta[event.status] || unsupportedStatusMeta;
            return cell('', tag(meta.className, meta.label));
        },
        start: (event) => cell(
            'is-due',
            element('strong', '', shortDateFormatter.format(new Date(event.scheduleStartStamp))),
        ),
        due: (event) => cell(
            'is-due',
            element('strong', '', shortDateFormatter.format(new Date(event.dueStamp))),
            element('small', '', dueDistanceLabel(event)),
        ),
        time: (event) => cell(
            'is-due',
            element('strong', '', shortDateFormatter.format(new Date(event.dueStamp))),
            element('small', '', dueDistanceLabel(event)),
        ),
    };

    const meetingCellFactory = {
        title: titleCell,
        project: (event) => cell('is-muted', element('span', '', event.location || 'ไม่ระบุสถานที่')),
        time: (event) => cell('is-muted', element('span', '', `${event.startTime} - ${event.endTime}`)),
        organizer: (event) => cell('is-people', makeAvatar({name: event.organizer, avatar_url: event.organizerAvatar}, 'is-owner')),
        attendees: (event) => cell('is-people', makeAvatarStack(Array.isArray(event.attendees) ? event.attendees : [], 'ไม่มี')),
        location: (event) => cell('is-muted', element('span', '', event.location)),
        date: (event) => cell('is-due', element('strong', '', shortDateFormatter.format(new Date(event.startStamp)))),
        blank: () => cell('is-muted', element('span', 'calendar-table__not-applicable', '—')),
    };

    // ลำดับช่องต้องตรงกับหัวตารางใน calendar.blade.php ทุกตัว
    const TASK_LAYOUTS = {
        today: ['title', 'project', 'owner', 'collaborators', 'priority', 'time'],
        due: ['title', 'project', 'owner', 'collaborators', 'priority', 'time'],
        day: ['title', 'project', 'owner', 'collaborators', 'priority', 'status', 'start', 'due'],
    };
    const MEETING_LAYOUTS = {
        today: ['title', 'project', 'organizer', 'attendees', 'blank', 'time'],
        due: ['title', 'project', 'organizer', 'attendees', 'blank', 'time'],
        day: ['title', 'time', 'organizer', 'attendees', 'location'],
    };
    const CELL_LABELS = {
        title: 'รายการ',
        project: 'โปรเจกต์ / สถานที่',
        owner: 'เจ้าของ',
        organizer: 'ผู้จัด',
        collaborators: 'ผู้ร่วมโปรเจกต์',
        attendees: 'ผู้เข้าร่วม',
        priority: 'ความสำคัญ',
        status: 'สถานะ',
        time: 'เวลา',
        start: 'วันที่เริ่ม',
        due: 'กำหนดส่ง',
        date: 'วันที่',
        location: 'สถานที่',
        blank: 'ความสำคัญ',
    };

    const makeRow = (event, layout) => {
        const isMeeting = event.type === 'meeting';
        const factory = isMeeting ? meetingCellFactory : taskCellFactory;
        const node = element(isMeeting ? 'a' : 'button', `calendar-table__row${isMeeting ? ' is-meeting' : ''}`);
        configureCalendarEventNode(node, event);
        node.setAttribute('role', 'row');
        node.append(...layout.map((column) => {
            const item = factory[column](event);
            item.dataset.label = CELL_LABELS[column] || '';
            return item;
        }));
        return node;
    };

    const taskRow = (variant) => (event) => makeRow(event, TASK_LAYOUTS[variant]);
    const meetingRow = (variant) => (event) => makeRow(event, MEETING_LAYOUTS[variant]);
    const agendaRow = (variant) => (event) => (
        event.type === 'meeting' ? meetingRow(variant)(event) : taskRow(variant)(event)
    );

    /* ---------- modal ของวันที่ที่ถูกคลิกบนปฏิทิน ---------- */

    const dayForKey = (key) => monthData?.days.find((candidate) => candidate.key === key) || null;

    const fillDayModal = (day) => {
        dayTitle.textContent = `งานวันที่ ${dateFormatter.format(new Date(day.stamp))}`;
        if (dayCount) dayCount.textContent = `ทั้งหมด ${day.events.length} รายการ`;

        dayTaskList.replaceChildren(...day.tasks.map(taskRow('day')));
        dayMeetingList.replaceChildren(...day.meetings.map(meetingRow('day')));
        if (dayTaskCount) dayTaskCount.textContent = `${day.tasks.length} งาน`;
        if (dayMeetingCount) dayMeetingCount.textContent = `${day.meetings.length} การประชุม`;
        // section ที่ว่างถูกซ่อนทั้งใบ วันที่มีแต่งานจึงไม่เห็นหัวตารางประชุมลอยอยู่
        if (dayTaskGroup) dayTaskGroup.hidden = day.tasks.length === 0;
        if (dayMeetingGroup) dayMeetingGroup.hidden = day.meetings.length === 0;
    };

    const closeDayModal = () => {
        if (dayModal.hidden) return;
        modalStack(document).close(dayModal);
        activeDayKey = null;
    };

    const openDayModal = (key) => {
        const day = dayForKey(key);
        if (!day || !day.events.length) return;

        activeDayKey = key;
        fillDayModal(day);
        modalStack(document).open(dayModal);
        dayModal.querySelector('[data-calendar-task]')?.focus({preventScroll: true});
    };

    /* ---------- การ์ดสรุปใต้ปฏิทิน ---------- */

    const paintSection = (list, empty, count, items, unit, makeItem) => {
        if (!list || !empty || !count) return;
        list.replaceChildren(...items.map(makeItem));
        empty.hidden = items.length > 0;
        count.textContent = `${items.length} ${unit}`;
        // ตารางว่างต้องหายไปทั้งใบ ไม่ให้เหลือหัวตารางลอยอยู่เหนือข้อความ "ไม่มีรายการ"
        const table = list.closest('.calendar-table');
        if (table) table.hidden = items.length === 0;
    };

    const renderAgenda = (events) => {
        const agenda = buildCalendarAgenda(events, selectedYear, selectedMonth, todayKey);
        const monthLabel = monthFormatter.format(new Date(Date.UTC(selectedYear, selectedMonth, 1)));

        paintSection(todayList, todayEmpty, todayCount, agenda.todayEvents, 'รายการ', agendaRow('today'));
        paintSection(monthList, monthEmpty, monthCount, agenda.monthEvents, 'รายการ', agendaRow('due'));

        if (monthAgendaTitle) monthAgendaTitle.textContent = `กำหนดส่งและนัดหมายใน${monthLabel}`;
    };

    /* ---------- ช่องวันที่ ---------- */

    const groupLabel = (group) => `${group.count} ${group.type === 'meeting' ? 'ประชุม' : 'งาน'}`;

    const makeCountChip = (group) => {
        const tone = group.type === 'meeting' ? 'is-meeting' : (taskPriorityMeta[group.priority] || taskPriorityMeta[2]).className;
        const node = element('span', `mytasks-calendar__count ${tone}`);
        const dot = element('i', 'calendar-dot');
        dot.setAttribute('aria-hidden', 'true');
        node.append(dot, element('span', '', groupLabel(group)));
        return node;
    };

    /**
     * โทนของทั้งช่องวันที่ มาจากความสำคัญสูงสุดที่มีในวันนั้น (ประชุมล้วน = โทนประชุม)
     * ชื่อคลาสถอดมาจาก taskPriorityMeta เพื่อไม่ให้มีตารางสีชุดที่สองในระบบ
     */
    const dayToneClass = (tone) => {
        if (!tone) return '';
        if (tone === 'meeting') return 'is-tone-meeting';

        const priority = Number(String(tone).replace('priority-', ''));
        const meta = taskPriorityMeta[priority] || taskPriorityMeta[2];
        return `is-tone-${meta.className.replace('priority-', '')}`;
    };

    const makeDayCell = (day) => {
        const cellNode = element('div', 'mytasks-calendar__day');
        cellNode.setAttribute('role', 'gridcell');
        cellNode.dataset.calendarDate = day.key;
        cellNode.classList.toggle('is-outside', !day.isCurrentMonth);
        cellNode.classList.toggle('is-today', day.key === todayKey);
        cellNode.classList.toggle('is-busy', day.events.length > 0);

        const toneClass = dayToneClass(day.tone);
        if (toneClass) {
            cellNode.classList.add(toneClass);
            cellNode.dataset.calendarTone = day.tone;
        }
        if (day.key === todayKey) cellNode.setAttribute('aria-current', 'date');

        const summary = day.groups.map(groupLabel).join(', ');
        cellNode.setAttribute('aria-label', summary
            ? `${dateFormatter.format(new Date(day.stamp))}: ${summary}`
            : dateFormatter.format(new Date(day.stamp)));

        // เฉพาะวันที่มีรายการเท่านั้นที่กดได้ วันว่างจึงไม่มีปุ่มให้โฟกัสหลอก ๆ
        if (day.events.length) {
            const open = element('button', 'mytasks-calendar__day-open');
            open.type = 'button';
            open.dataset.calendarDay = day.key;
            open.setAttribute('aria-haspopup', 'dialog');
            open.setAttribute('aria-label', `ดูรายการทั้งหมดของวันที่ ${dateFormatter.format(new Date(day.stamp))} — ${summary}`);
            cellNode.append(open);
        }

        cellNode.append(element('span', 'mytasks-calendar__day-number', String(day.day)));

        const counts = element('span', 'mytasks-calendar__counts');
        counts.append(...day.visibleGroups.map(makeCountChip));
        if (day.hiddenGroups > 0) counts.append(element('span', 'mytasks-calendar__more', `+${day.hiddenGroups}`));
        cellNode.append(counts);

        return cellNode;
    };

    const render = () => {
        // ห้ามปิด Quick View ตรงนี้ — render() ถูกเรียกจาก ensureMeetingsForSelectedMonth()
        // ทุกครั้งที่ fetch ประชุมพื้นหลังเสร็จ (ทุกครั้งที่เปิดหน้า/เปลี่ยนเดือน) ถ้าปิดที่นี่
        // Quick View ที่เพิ่งเปิดจะถูกปิดทิ้งเองทันทีที่ fetch นั้นตอบกลับ ทำให้ดูเหมือนคลิก
        // Event ไม่ได้ผลเลย จุดที่ต้องปิดจริงคือตอน "เปลี่ยนเดือน" (goToMonth) และตอนข้อมูล
        // ถูก invalidate จริง (mytasks:viewchange / mytasks:changed) เท่านั้น
        // modal รายวันก็เช่นกัน: เติมเนื้อใหม่ให้แทนการปิดทิ้ง
        const events = visibleEvents();
        monthData = buildMonthCalendar(events, selectedYear, selectedMonth);
        synchronizeSelectors();
        title.textContent = monthFormatter.format(new Date(Date.UTC(selectedYear, selectedMonth, 1)));

        grid.replaceChildren(...monthData.weeks.map((week) => {
            const weekNode = element('div', 'mytasks-calendar__week');
            weekNode.setAttribute('role', 'row');
            weekNode.append(...week.days.map(makeDayCell));
            return weekNode;
        }));
        renderAgenda(events);

        if (activeDayKey) {
            const day = dayForKey(activeDayKey);
            if (day && day.events.length) fillDayModal(day);
            else closeDayModal();
        }
    };

    /**
     * เติมประชุมของเดือนที่ยังไม่เคยโหลด
     *
     * รายการที่แสดงอยู่จะไม่ถูกล้างระหว่างรอ และคำขอช่วงเดิมที่ยังค้างอยู่จะไม่ถูกยิงซ้ำ
     * เมื่อล้มเหลวจะไม่ทำเครื่องหมายว่าโหลดแล้ว เพื่อให้ครั้งถัดไปลองใหม่ได้
     */
    const ensureMeetingsForSelectedMonth = async () => {
        if (!meetingsEndpoint) return;

        const missing = monthsNeedingFetch(selectedYear, selectedMonth, loadedMonths);
        const range = rangeForMonths(missing);
        if (!range) return;

        const rangeKey = `${range.start}:${range.end}`;
        if (pendingRanges.has(rangeKey)) return;
        pendingRanges.add(rangeKey);
        setLoading(true);

        try {
            const url = new URL(meetingsEndpoint, window.location.origin);
            url.searchParams.set('start', range.start);
            url.searchParams.set('end', range.end);
            if (calendar.dataset.meetingsSubjectUserId) {
                url.searchParams.set('subject_user_id', calendar.dataset.meetingsSubjectUserId);
            }

            const response = await fetch(url, {
                headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                credentials: 'same-origin',
            });
            if (!response.ok) throw new Error('meeting request failed');

            const payload = await response.json();
            rememberMeetings(Array.isArray(payload.meetings) ? payload.meetings : []);
            range.keys.forEach((key) => loadedMonths.add(key));
            render();
        } catch (_) {
            showToast('โหลดการประชุมของเดือนนี้ไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
        } finally {
            pendingRanges.delete(rangeKey);
            if (!pendingRanges.size) setLoading(false);
        }
    };

    const goToMonth = (year, month) => {
        // เดือนใหม่รื้อช่องวันที่เดิมทั้งหมด anchor ของ Quick View ที่เปิดอยู่จะหลุดออกจาก DOM
        // ต้องปิดก่อนเสมอ ต่างจาก render() เฉย ๆ ที่อาจถูกเรียกจาก fetch พื้นหลังโดยเดือนไม่เปลี่ยน
        quickView?.close();
        closeDayModal();
        selectedYear = year;
        selectedMonth = month;
        render();
        ensureMeetingsForSelectedMonth();
    };

    const moveMonth = (offset) => {
        const target = moveCalendarMonth(selectedYear, selectedMonth, offset);
        goToMonth(target.year, target.month);
    };

    const resetCalendar = () => {
        // Keep Calendar-only reset behavior centralized so future filters can join this action.
        if (searchInput) searchInput.value = '';
        const target = resetCalendarMonth(initialSelection);
        goToMonth(target.year, target.month);
    };

    /**
     * เปิดรายการหนึ่งรายการ — เส้นทางเดียวกันทั้งจากการ์ดใต้ปฏิทินและ modal รายวัน
     * สิทธิ์ยังถูกตรวจที่ server ทุกครั้งผ่าน quick-view endpoint และ Task Workspace เดิม
     */
    const activateEvent = (chip, domEvent) => {
        // เปิดในแท็บใหม่ด้วย Ctrl/Cmd/Shift ยังต้องทำงานตามปกติของลิงก์
        if (domEvent.metaKey || domEvent.ctrlKey || domEvent.shiftKey) return false;

        domEvent.preventDefault();

        // Quick View วางตำแหน่งจาก rect ของ anchor แถวที่อยู่ใน modal รายวันจึงใช้เป็น anchor ไม่ได้
        // เพราะ modal ถูกปิดไปก่อน (z-index ของ Quick View เท่ากับชั้นล่างสุดของ modal stack)
        // จุดยึดที่ยังมองเห็นอยู่จริงคือช่องวันที่บนปฏิทินซึ่งเป็นที่มาของแถวนั้นเอง
        const anchor = dayModal.contains(chip)
            ? (calendar.querySelector(`[data-calendar-day="${activeDayKey}"]`) || chip)
            : chip;

        // modal รายวันเป็นชั้นทึบ ต้องปิดก่อนเปิดชั้นถัดไป ไม่ให้ Quick View ไปอยู่ใต้ backdrop
        closeDayModal();

        const quickViewUrl = chip.dataset.calendarQuickView;
        if (quickView && quickViewUrl) {
            // detailUrl มาจาก event ที่ระบบสร้างเอง ไม่ใช่จาก HTML ที่ endpoint ตอบกลับ
            quickView.open(quickViewUrl, anchor, chip.dataset.calendarDetailUrl || '');
            return true;
        }

        // ไม่มี Quick View (เช่นหน้าที่ไม่ได้ฝัง shell) จึงค่อยตกไปใช้เส้นทางเดิม
        if (chip.dataset.calendarEventType === 'meeting') {
            if (chip.href) window.location.assign(chip.href);
            return true;
        }

        const taskId = String(chip.dataset.calendarTask).replace(/^task-/, '');
        if (!openTaskWorkspace(taskId)) openReadOnlyTask(taskId);
        return true;
    };

    calendar.addEventListener('click', (event) => {
        const chip = event.target.closest('[data-calendar-task]');
        if (chip) {
            activateEvent(chip, event);
            return;
        }

        const dayTrigger = event.target.closest('[data-calendar-day]');
        if (dayTrigger) {
            openDayModal(dayTrigger.dataset.calendarDay);
            return;
        }

        if (event.target.closest('[data-calendar-previous]')) moveMonth(-1);
        if (event.target.closest('[data-calendar-next]')) moveMonth(1);
        if (event.target.closest('[data-calendar-reset]')) resetCalendar();
        if (event.target.closest('[data-calendar-today]')) {
            const target = calendarMonthForDate(new Date());
            goToMonth(target.year, target.month);
        }
        if (event.target.closest('[data-calendar-detail-close]')) closeDetail();
    });

    // modal รายวันอยู่นอก [data-calendar] จึงต้องมี listener ของตัวเอง แต่ยังเรียก activateEvent ตัวเดิม
    dayModal.addEventListener('click', (event) => {
        if (event.target.closest('[data-calendar-day-close]')) {
            closeDayModal();
            return;
        }

        const chip = event.target.closest('[data-calendar-task]');
        if (chip) activateEvent(chip, event);
    });
    dayModal.addEventListener('modalstack:dismiss', closeDayModal);

    calendar.addEventListener('change', (event) => {
        if (!event.target.matches('[data-calendar-month], [data-calendar-year]')) return;
        goToMonth(Number(yearSelect.value), Number(monthSelect.value));
    });

    if (searchInput) {
        searchInput.addEventListener('input', () => {
            quickView?.close();
            render();
        });
        // Enter ในช่องค้นหาไม่ควร submit ฟอร์มใด ๆ ที่อาจครอบอยู่
        searchInput.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') event.preventDefault();
        });
    }

    const activateWithSpace = (event) => {
        if (event.key !== ' ' && event.key !== 'Spacebar') return;
        const chip = event.target.closest('[data-calendar-task]');
        if (!chip || chip.tagName !== 'A') return;

        event.preventDefault();
        chip.click();
    };
    calendar.addEventListener('keydown', activateWithSpace);
    dayModal.addEventListener('keydown', activateWithSpace);

    detail.addEventListener('modalstack:dismiss', closeDetail);

    document.addEventListener('mytasks:viewchange', (event) => {
        quickView?.close();
        if (event.detail?.view === 'calendar') render();
        else closeDayModal();
    });
    document.addEventListener('mytasks:changed', () => {
        // ข้อมูลงานถูกแก้จากที่อื่น (เช่น Task Workspace) เนื้อหาที่ Quick View แสดงอยู่อาจไม่ตรงแล้ว
        quickView?.close();
        render();
    });

    render();
    ensureMeetingsForSelectedMonth();
});
