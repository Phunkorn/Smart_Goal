import {statusMeta, taskPriorityMeta} from './priority-meta.js';
import {buddhistYear, buildMonthCalendar, calendarMonthForDate, moveCalendarMonth, resetCalendarMonth} from './calendar-model.js';

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

    const readTasks = () => {
        const unique = new Map();
        source.querySelectorAll('[data-row]').forEach((row) => {
            unique.set(String(row.dataset.id), {
                id: row.dataset.id,
                title: row.dataset.topic || 'ไม่มีชื่องาน',
                project: row.dataset.project || 'งานทั่วไป',
                status: Number(row.dataset.status) || 1,
                priority: Number(row.dataset.priority) || 2,
                start: row.dataset.start || '',
                due: row.dataset.due || '',
            });
        });
        return [...unique.values()];
    };

    const taskRangeLabel = (task) => {
        const start = dateFormatter.format(new Date(task.startStamp));
        if (task.startStamp === task.dueStamp) return start;
        return `${start} – ${dateFormatter.format(new Date(task.dueStamp))}`;
    };

    const taskAriaLabel = (task) => {
        const status = statusMeta[task.status]?.label || statusMeta[1].label;
        const priority = taskPriorityMeta[task.priority]?.label || taskPriorityMeta[2].label;
        return `${task.title}, ${task.project}, ${status}, ${priority}, ${taskRangeLabel(task)}`;
    };

    const closePopover = (restoreFocus = false) => {
        if (popover.hidden) return;
        popover.hidden = true;
        activeOverflow?.setAttribute('aria-expanded', 'false');
        if (restoreFocus) activeOverflow?.focus();
        activeOverflow = null;
    };

    const rowForTask = (id) => [...source.querySelectorAll('[data-row]')].find((candidate) => String(candidate.dataset.id) === String(id));
    const displayDate = (value) => value ? dateFormatter.format(new Date(`${value}T00:00:00Z`)) : 'ไม่ระบุ';
    const fillList = (target, items, empty) => target.replaceChildren(...(items.length ? items.map((item) => element('p', '', item)) : [element('p', 'is-empty', empty)]));
    const closeDetail = () => { detail.hidden = true; detail.removeAttribute('data-task-id'); document.body.classList.remove('modal-open'); };
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
        detail.querySelector('[data-calendar-detail-description]').textContent = row.dataset.details || 'ไม่มีรายละเอียดเพิ่มเติม';
        fillList(detail.querySelector('[data-calendar-detail-attachments]'), files.map((file) => file.name), 'ไม่มีไฟล์แนบ');
        detail.hidden = false;
        document.body.classList.add('modal-open');
        requestAnimationFrame(() => detail.querySelector('[data-calendar-detail-close]')?.focus());
    };

    const makePopoverTask = (task) => {
        const button = element('button', 'mytasks-calendar__popover-task');
        button.type = 'button';
        button.dataset.calendarTask = task.id;
        button.setAttribute('aria-label', taskAriaLabel(task));

        const copy = element('span');
        copy.append(element('strong', '', task.title), element('small', '', task.project));
        const meta = element('span', 'mytasks-calendar__popover-meta');
        meta.append(
            element('span', statusMeta[task.status]?.className || statusMeta[1].className, statusMeta[task.status]?.label || statusMeta[1].label),
            element('span', taskPriorityMeta[task.priority]?.className || taskPriorityMeta[2].className, taskPriorityMeta[task.priority]?.label || taskPriorityMeta[2].label),
        );
        button.append(copy, meta);
        return button;
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
        popoverTitle.textContent = `งานวันที่ ${dateFormatter.format(new Date(day.stamp))}`;
        popoverList.replaceChildren(...day.tasks.map(makePopoverTask));
        popover.hidden = false;
        requestAnimationFrame(() => {
            positionPopover(trigger);
            popover.querySelector('[data-calendar-task]')?.focus({preventScroll: true});
        });
    };

    const milestoneLabel = ({task, kind}) => {
        if (kind === 'start') {
            return `เริ่ม: ${task.title} · ${shortDateFormatter.format(new Date(task.startStamp))}–${shortDateFormatter.format(new Date(task.dueStamp))}`;
        }
        if (kind === 'end') return `สิ้นสุด: ${task.title}`;
        return task.title;
    };

    const makeMilestone = (milestone) => {
        const task = milestone.task;
        const status = statusMeta[task.status] || statusMeta[1];
        const button = element('button', `mytasks-calendar__task mytasks-calendar__task--${milestone.kind} ${status.className}`);
        button.type = 'button';
        button.dataset.calendarTask = task.id;
        button.setAttribute('aria-label', taskAriaLabel(task));
        button.title = taskAriaLabel(task);

        const priority = element('i', `priority-${task.priority}`);
        priority.setAttribute('aria-hidden', 'true');
        button.append(priority, element('span', '', milestoneLabel(milestone)));
        return button;
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
            const more = element('button', 'mytasks-calendar__more', `+ ${day.hiddenCount} งาน`);
            more.type = 'button';
            more.dataset.calendarMore = day.key;
            more.setAttribute('aria-haspopup', 'dialog');
            more.setAttribute('aria-expanded', 'false');
            more.setAttribute('aria-label', `ดูงานทั้งหมดวันที่ ${dateFormatter.format(new Date(day.stamp))}`);
            cell.append(more);
        }
        return cell;
    };

    const render = () => {
        closePopover();
        monthData = buildMonthCalendar(readTasks(), selectedYear, selectedMonth);
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

    const moveMonth = (offset) => {
        const target = moveCalendarMonth(selectedYear, selectedMonth, offset);
        selectedYear = target.year;
        selectedMonth = target.month;
        render();
    };

    const resetCalendar = () => {
        // Keep Calendar-only reset behavior centralized so future filters can join this action.
        ({year: selectedYear, month: selectedMonth} = resetCalendarMonth(initialSelection));
        render();
    };

    calendar.addEventListener('click', (event) => {
        const task = event.target.closest('[data-calendar-task]');
        if (task) {
            event.preventDefault();
            closePopover();
            openReadOnlyTask(task.dataset.calendarTask);
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
            ({year: selectedYear, month: selectedMonth} = calendarMonthForDate(new Date()));
            render();
        }
        if (event.target.closest('[data-calendar-popover-close]')) closePopover(true);
        if (event.target.closest('[data-calendar-detail-close]')) closeDetail();
    });

    calendar.addEventListener('change', (event) => {
        if (!event.target.matches('[data-calendar-month], [data-calendar-year]')) return;
        selectedMonth = Number(monthSelect.value);
        selectedYear = Number(yearSelect.value);
        render();
    });

    document.addEventListener('click', (event) => {
        if (!popover.hidden && !popover.contains(event.target) && !event.target.closest('[data-calendar-more]')) closePopover();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !detail.hidden) closeDetail();
        else if (event.key === 'Escape' && !popover.hidden) closePopover(true);
    });
    document.addEventListener('mytasks:viewchange', (event) => {
        if (event.detail?.view === 'calendar') render();
        else closePopover();
    });
    document.addEventListener('mytasks:changed', render);
    window.addEventListener('resize', () => closePopover());
    window.addEventListener('scroll', (event) => {
        if (!popover.contains(event.target)) closePopover();
    }, true);

    render();
});
