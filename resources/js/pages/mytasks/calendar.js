import {statusMeta, taskPriorityMeta} from './priority-meta.js';
import {modalStack} from '../../components/modal-stack.js';
import {createCalendarQuickView} from './calendar-quick-view.js';
import {
    buddhistYear,
    buildMonthCalendar,
    calendarMonthForDate,
    calendarMonthKey,
    monthsNeedingFetch,
    moveCalendarMonth,
    parseCalendarDate,
    rangeForMonths,
    resetCalendarMonth,
} from './calendar-model.js';

const monthFormatter = new Intl.DateTimeFormat('th-TH', {month: 'long', year: 'numeric', timeZone: 'UTC'});
const dateFormatter = new Intl.DateTimeFormat('th-TH', {day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC'});
const shortDateFormatter = new Intl.DateTimeFormat('th-TH', {day: 'numeric', month: 'short', timeZone: 'UTC'});

const element = (tag, className = '', text = '') => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text) node.textContent = text;
    return node;
};

document.querySelectorAll('[data-workspace]').forEach((workspace) => {
    const calendar = workspace.querySelector('[data-calendar]');
    const source = workspace.querySelector('[data-workspace-task-source]');
    const grid = calendar?.querySelector('[data-calendar-grid]');
    const title = calendar?.querySelector('[data-calendar-title]');
    const popover = calendar?.querySelector('[data-calendar-popover]');
    const popoverTitle = calendar?.querySelector('[data-calendar-popover-title]');
    const popoverList = calendar?.querySelector('[data-calendar-popover-list]');
    const detail = workspace.querySelector('[data-calendar-detail]');
    const monthSelect = calendar?.querySelector('[data-calendar-month]');
    const yearSelect = calendar?.querySelector('[data-calendar-year]');
    if (!calendar || !source || !grid || !title || !popover || !popoverTitle || !popoverList || !detail || !monthSelect || !yearSelect) return;

    const json = (selector) => { const node = document.querySelector(selector); return node ? JSON.parse(node.textContent || '{}') : {}; };
    const teamData = json('[data-team-data]');
    const attachmentData = json('[data-attachment-data]');

    const loadingIndicator = calendar.querySelector('[data-calendar-loading]');
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
    let activeOverflow = null;

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
                status: Number(row.dataset.status) || 1,
                priority: Number(row.dataset.priority) || 2,
                start: row.dataset.start || '',
                due: row.dataset.due || '',
            });
        });
        meetingsById.forEach((meeting, id) => unique.set(id, meeting));
        return [...unique.values()];
    };

    const taskRangeLabel = (task) => {
        const start = dateFormatter.format(new Date(task.startStamp));
        if (task.startStamp === task.dueStamp) return start;
        return `${start} – ${dateFormatter.format(new Date(task.dueStamp))}`;
    };

    const eventAriaLabel = (event) => {
        if (event.type === 'meeting') {
            return `การประชุม: ${event.title}, ${event.startTime}–${event.endTime} น., ${event.location}, ผู้จัด ${event.organizer}, ${taskRangeLabel(event)}`;
        }

        const status = statusMeta[event.status]?.label || statusMeta[1].label;
        const priority = taskPriorityMeta[event.priority]?.label || taskPriorityMeta[2].label;
        return `งาน: ${event.title}, ${event.project}, ${status}, ${priority}, ${taskRangeLabel(event)}`;
    };

    const closePopover = (restoreFocus = false) => {
        if (popover.hidden) return;
        popover.hidden = true;
        activeOverflow?.setAttribute('aria-expanded', 'false');
        if (restoreFocus) activeOverflow?.focus();
        activeOverflow = null;
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
        detail.querySelector('[data-calendar-detail-status]').textContent = (statusMeta[Number(row.dataset.status)] || statusMeta[1]).label;
        detail.querySelector('[data-calendar-detail-priority]').textContent = (taskPriorityMeta[Number(row.dataset.priority)] || taskPriorityMeta[2]).label;
        detail.querySelector('[data-calendar-detail-start]').textContent = displayDate(row.dataset.start);
        detail.querySelector('[data-calendar-detail-due]').textContent = displayDate(row.dataset.due);
        detail.querySelector('[data-calendar-detail-assignee]').textContent = team.assignee?.name || row.dataset.assignee || 'ไม่ระบุ';
        detail.querySelector('[data-calendar-detail-collaborators]').textContent = (team.collaborators || []).map((person) => `${person.name}${person.status === 'pending' ? ' (รอตอบรับ)' : ''}`).join(', ') || 'ไม่มีผู้ร่วมงาน';
        fillList(detail.querySelector('[data-calendar-detail-attachments]'), files.map((file) => file.name), 'ไม่มีไฟล์แนบ');
        modalStack(document).open(detail);
    };

    const makePopoverTask = (event) => {
        const isMeeting = event.type === 'meeting';
        const node = element(isMeeting ? 'a' : 'button', `mytasks-calendar__popover-task${isMeeting ? ' is-meeting' : ''}`);
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

        const copy = element('span');
        copy.append(
            element('strong', '', event.title),
            element('small', '', isMeeting ? `${event.startTime}–${event.endTime} น. · ${event.location}` : event.project),
        );
        const meta = element('span', 'mytasks-calendar__popover-meta');
        if (isMeeting) {
            meta.append(element('span', 'status-review', 'ประชุม'));
        } else {
            meta.append(
                element('span', statusMeta[event.status]?.className || statusMeta[1].className, statusMeta[event.status]?.label || statusMeta[1].label),
                element('span', taskPriorityMeta[event.priority]?.className || taskPriorityMeta[2].className, taskPriorityMeta[event.priority]?.label || taskPriorityMeta[2].label),
            );
        }
        node.append(copy, meta);
        return node;
    };

    const positionPopover = (trigger) => {
        const gutter = 12;
        const triggerRect = trigger.getBoundingClientRect();
        const width = Math.min(360, window.innerWidth - (gutter * 2));
        popover.style.width = `${width}px`;
        const popoverRect = popover.getBoundingClientRect();
        const left = Math.max(gutter, Math.min(triggerRect.left, window.innerWidth - width - gutter));
        let top = triggerRect.bottom + 8;
        if (top + popoverRect.height > window.innerHeight - gutter) top = triggerRect.top - popoverRect.height - 8;
        top = Math.max(gutter, Math.min(top, window.innerHeight - popoverRect.height - gutter));
        popover.style.left = `${left}px`;
        popover.style.top = `${top}px`;
    };

    const openPopover = (trigger, day) => {
        closePopover();
        activeOverflow = trigger;
        trigger.setAttribute('aria-expanded', 'true');
        popoverTitle.textContent = `รายการวันที่ ${dateFormatter.format(new Date(day.stamp))}`;
        popoverList.replaceChildren(...day.tasks.map(makePopoverTask));
        popover.hidden = false;
        requestAnimationFrame(() => {
            positionPopover(trigger);
            popover.querySelector('[data-calendar-task]')?.focus({preventScroll: true});
        });
    };

    const milestoneLabel = ({task, kind}) => {
        if (task.type === 'meeting') {
            if (kind === 'end') return `สิ้นสุด: ${task.title}`;
            return `${task.startTime} ${task.title}`;
        }
        if (kind === 'start') {
            return `เริ่ม: ${task.title} · ${shortDateFormatter.format(new Date(task.startStamp))}–${shortDateFormatter.format(new Date(task.dueStamp))}`;
        }
        if (kind === 'end') return `สิ้นสุด: ${task.title}`;
        return task.title;
    };

    const makeMilestone = (milestone) => {
        const event = milestone.task;
        const isMeeting = event.type === 'meeting';
        const tone = isMeeting ? 'mytasks-calendar__task--meeting' : (statusMeta[event.status] || statusMeta[1]).className;
        const node = element(isMeeting ? 'a' : 'button', `mytasks-calendar__task mytasks-calendar__task--${milestone.kind} ${tone}`);
        if (isMeeting) node.href = event.url;
        else node.type = 'button';
        node.dataset.calendarTask = event.id;
        node.dataset.calendarEventType = event.type;
        if (event.quickViewUrl) node.dataset.calendarQuickView = event.quickViewUrl;
        if (event.detailUrl) node.dataset.calendarDetailUrl = event.detailUrl;
        node.setAttribute('aria-label', eventAriaLabel(event));
        node.title = eventAriaLabel(event);
        if (event.quickViewUrl) {
            node.setAttribute('aria-haspopup', 'dialog');
            node.setAttribute('aria-expanded', 'false');
            node.setAttribute('aria-controls', 'calendar-quick-view-popover');
        }

        // ไอคอนนำหน้าทำให้แยกประเภทได้โดยไม่ต้องพึ่งสีอย่างเดียว
        const marker = element('i', isMeeting ? 'bi bi-calendar-event-fill' : `priority-${event.priority}`);
        marker.setAttribute('aria-hidden', 'true');
        node.append(marker, element('span', '', milestoneLabel(milestone)));
        return node;
    };

    const makeDayCell = (day) => {
        const cell = element('div', 'mytasks-calendar__day');
        cell.setAttribute('role', 'gridcell');
        cell.dataset.calendarDate = day.key;
        cell.setAttribute('aria-label', dateFormatter.format(new Date(day.stamp)));
        cell.classList.toggle('is-outside', !day.isCurrentMonth);
        cell.classList.toggle('is-today', day.key === todayKey);
        if (day.key === todayKey) cell.setAttribute('aria-current', 'date');

        cell.append(element('span', 'mytasks-calendar__day-number', String(day.day)));

        const events = element('div', 'mytasks-calendar__events');
        events.append(...day.visibleMilestones.map(makeMilestone));
        cell.append(events);

        if (day.hiddenCount > 0) {
            const more = element('button', 'mytasks-calendar__more', `+ ${day.hiddenCount} รายการ`);
            more.type = 'button';
            more.dataset.calendarMore = day.key;
            more.setAttribute('aria-haspopup', 'dialog');
            more.setAttribute('aria-expanded', 'false');
            more.setAttribute('aria-label', `ดูรายการทั้งหมดวันที่ ${dateFormatter.format(new Date(day.stamp))}`);
            cell.append(more);
        }
        return cell;
    };

    const render = () => {
        closePopover();
        // ห้ามปิด Quick View ตรงนี้ — render() ถูกเรียกจาก ensureMeetingsForSelectedMonth()
        // ทุกครั้งที่ fetch ประชุมพื้นหลังเสร็จ (ทุกครั้งที่เปิดหน้า/เปลี่ยนเดือน) ถ้าปิดที่นี่
        // Quick View ที่เพิ่งเปิดจะถูกปิดทิ้งเองทันทีที่ fetch นั้นตอบกลับ ทำให้ดูเหมือนคลิก
        // Event ไม่ได้ผลเลย จุดที่ต้องปิดจริงคือตอน "เปลี่ยนเดือน" (goToMonth) และตอนข้อมูล
        // ถูก invalidate จริง (mytasks:viewchange / mytasks:changed) เท่านั้น
        monthData = buildMonthCalendar(readEvents(), selectedYear, selectedMonth);
        synchronizeSelectors();
        title.textContent = monthFormatter.format(new Date(Date.UTC(selectedYear, selectedMonth, 1)));
        const weekNodes = monthData.weeks.map((week) => {
            const weekNode = element('div', 'mytasks-calendar__week');
            weekNode.setAttribute('role', 'row');
            weekNode.append(...week.days.map(makeDayCell));
            return weekNode;
        });
        grid.replaceChildren(...weekNodes);
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
        // เดือนใหม่รื้อ chip เดิมทั้งหมด anchor ของ Quick View ที่เปิดอยู่จะหลุดออกจาก DOM
        // ต้องปิดก่อนเสมอ ต่างจาก render() เฉย ๆ ที่อาจถูกเรียกจาก fetch พื้นหลังโดยเดือนไม่เปลี่ยน
        quickView?.close();
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
        const target = resetCalendarMonth(initialSelection);
        goToMonth(target.year, target.month);
    };

    calendar.addEventListener('click', (event) => {
        const chip = event.target.closest('[data-calendar-task]');
        if (chip) {
            // เปิดในแท็บใหม่ด้วย Ctrl/Cmd/Shift ยังต้องทำงานตามปกติของลิงก์
            if (event.metaKey || event.ctrlKey || event.shiftKey) return;

            event.preventDefault();
            closePopover();

            const quickViewUrl = chip.dataset.calendarQuickView;
            if (quickView && quickViewUrl) {
                // detailUrl มาจาก event ที่ระบบสร้างเอง ไม่ใช่จาก HTML ที่ endpoint ตอบกลับ
                quickView.open(quickViewUrl, chip, chip.dataset.calendarDetailUrl || '');
                return;
            }

            // ไม่มี Quick View (เช่นหน้าที่ไม่ได้ฝัง shell) จึงค่อยตกไปใช้เส้นทางเดิม
            if (chip.dataset.calendarEventType === 'meeting') {
                if (chip.href) window.location.assign(chip.href);
                return;
            }

            const taskId = String(chip.dataset.calendarTask).replace(/^task-/, '');
            if (!openTaskWorkspace(taskId)) openReadOnlyTask(taskId);
            return;
        }

        const more = event.target.closest('[data-calendar-more]');
        if (more) {
            const day = monthData?.weeks.flatMap((week) => week.days).find((candidate) => candidate.key === more.dataset.calendarMore);
            if (day) openPopover(more, day);
            return;
        }

        if (event.target.closest('[data-calendar-previous]')) moveMonth(-1);
        if (event.target.closest('[data-calendar-next]')) moveMonth(1);
        if (event.target.closest('[data-calendar-reset]')) resetCalendar();
        if (event.target.closest('[data-calendar-today]')) {
            const target = calendarMonthForDate(new Date());
            goToMonth(target.year, target.month);
        }
        if (event.target.closest('[data-calendar-popover-close]')) closePopover(true);
        if (event.target.closest('[data-calendar-detail-close]')) closeDetail();
    });

    calendar.addEventListener('change', (event) => {
        if (!event.target.matches('[data-calendar-month], [data-calendar-year]')) return;
        goToMonth(Number(yearSelect.value), Number(monthSelect.value));
    });

    document.addEventListener('click', (event) => {
        if (!popover.hidden && !popover.contains(event.target) && !event.target.closest('[data-calendar-more]')) closePopover();
    });
    calendar.addEventListener('keydown', (event) => {
        if (event.key !== ' ' && event.key !== 'Spacebar') return;
        const chip = event.target.closest('[data-calendar-task]');
        if (!chip || chip.tagName !== 'A') return;

        event.preventDefault();
        chip.click();
    });

    document.addEventListener('keydown', (event) => {
        // การ์ดรายละเอียดปิดผ่าน modal stack เพื่อไม่ให้ Escape ทะลุไปปิดชั้นอื่น
        if (event.key === 'Escape' && detail.hidden && !popover.hidden) closePopover(true);
    });
    detail.addEventListener('modalstack:dismiss', closeDetail);

    document.addEventListener('mytasks:viewchange', (event) => {
        quickView?.close();
        if (event.detail?.view === 'calendar') render();
        else closePopover();
    });
    document.addEventListener('mytasks:changed', () => {
        // ข้อมูลงานถูกแก้จากที่อื่น (เช่น Task Workspace) เนื้อหาที่ Quick View แสดงอยู่อาจไม่ตรงแล้ว
        quickView?.close();
        render();
    });
    window.addEventListener('resize', () => closePopover());
    window.addEventListener('scroll', (event) => {
        if (!popover.contains(event.target)) closePopover();
    }, true);

    render();
    ensureMeetingsForSelectedMonth();
});
