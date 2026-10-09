/*
 * ปฏิทินประชุมและนัดหมาย — ตัววาดตารางเดือนและตัวเปลี่ยนเดือน/ตัวกรองประเภท
 *
 * แยกจาก pages/mytasks/calendar.js โดยเจตนา: ปฏิทินโปรเจกต์วาดงานกับกำหนดส่งซึ่งมี
 * ไทม์ไลน์ข้ามวัน ลำดับความสำคัญ และแผงสรุปของตัวเอง ส่วนปฏิทินนี้วาดเหตุการณ์ที่มี
 * วันเดียวกับช่วงเวลาเท่านั้น การยัดสอง use case ลงไฟล์เดียวทำให้ทั้งคู่อ่านยากขึ้น
 *
 * เหตุการณ์มาจากเซิร์ฟเวอร์เป็น event ที่ normalize แล้ว (MeetingAppointmentCalendarQuery)
 * ไฟล์นี้จึงไม่รู้ว่า event มาจากตารางไหน รู้แค่ว่า type เป็น meeting หรือ appointment
 */

const TYPE_ICONS = {
    meeting: 'bi-people',
    appointment: 'bi-bookmark-star',
};

const TYPE_LABELS = {
    meeting: 'ประชุม',
    appointment: 'นัดหมาย',
};

const THAI_MONTHS = [
    'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
    'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม',
];

/*
 * จำนวนรายการสูงสุดที่ช่องวันที่หนึ่งช่องแสดงได้
 *
 * ช่องวันที่มีความสูงจำกัดตามตาราง 6 สัปดาห์ วันที่มีประชุมสิบรายการจะดันช่องนั้น
 * ให้สูงกว่าชาวบ้านจนตารางเสียรูป หรือถ้าล็อกความสูงไว้ รายการที่เกินก็จะถูกตัดหาย
 * ไปเงียบ ๆ โดยผู้ใช้ไม่รู้ว่ามีอยู่ จึงตัดที่ห้ารายการแล้วบอกจำนวนที่เหลือตรง ๆ
 */
export const DAY_EVENT_LIMIT = 5;

/**
 * แบ่งรายการของวันหนึ่งเป็น "ที่แสดงในช่อง" กับ "จำนวนที่เหลือ"
 *
 * แยกเป็นฟังก์ชันบริสุทธิ์เพื่อให้ทดสอบกฎการตัดได้โดยไม่ต้องมี DOM
 * ไม่ตัดเมื่อเกินมาเพียงหนึ่งรายการ เพราะปุ่ม "+1 รายการ" กินที่เท่ากับรายการนั้นเอง
 * ผู้ใช้จึงต้องกดเปิด modal เพื่อดูของที่พอจะแสดงตรงนั้นได้อยู่แล้ว
 */
export function splitDayEvents(events, limit = DAY_EVENT_LIMIT) {
    const all = Array.isArray(events) ? events : [];
    if (all.length <= limit + 1) return {shown: all, hiddenCount: 0};

    return {shown: all.slice(0, limit), hiddenCount: all.length - limit};
}

/** ป้ายเดือนต้องเป็นปี พ.ศ. เหมือนฝั่งเซิร์ฟเวอร์ (App\Support\ThaiDate::month) */
export function thaiMonthLabel(monthValue) {
    const [year, month] = String(monthValue).split('-').map(Number);
    if (! year || ! month || month < 1 || month > 12) return '';

    return `${THAI_MONTHS[month - 1]} ${year + 543}`;
}

export function shiftMonth(monthValue, step) {
    const [year, month] = String(monthValue).split('-').map(Number);
    if (! year || ! month) return monthValue;

    const shifted = new Date(Date.UTC(year, month - 1 + step, 1));

    return `${shifted.getUTCFullYear()}-${String(shifted.getUTCMonth() + 1).padStart(2, '0')}`;
}

/** ช่วงวันที่ของตาราง 6 สัปดาห์ เริ่มวันอาทิตย์ ให้ตรงกับ calendarPageData() ฝั่งเซิร์ฟเวอร์ */
export function monthGridRange(monthValue) {
    const [year, month] = String(monthValue).split('-').map(Number);
    const firstOfMonth = new Date(Date.UTC(year, month - 1, 1));
    const gridStart = new Date(firstOfMonth);
    gridStart.setUTCDate(gridStart.getUTCDate() - gridStart.getUTCDay());

    const lastOfMonth = new Date(Date.UTC(year, month, 0));
    const gridEnd = new Date(lastOfMonth);
    gridEnd.setUTCDate(gridEnd.getUTCDate() + (6 - gridEnd.getUTCDay()));

    return {start: isoDate(gridStart), end: isoDate(gridEnd)};
}

function isoDate(date) {
    return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}-${String(date.getUTCDate()).padStart(2, '0')}`;
}

export function groupEventsByDate(events) {
    const byDate = new Map();

    (Array.isArray(events) ? events : []).forEach((event) => {
        if (! event?.date) return;
        if (! byDate.has(event.date)) byDate.set(event.date, []);
        byDate.get(event.date).push(event);
    });

    return byDate;
}

/**
 * ช่องวันที่ทั้งหมดของเดือน — เสมอ 6 สัปดาห์ เพื่อให้ความสูงตารางไม่กระโดดตอนเปลี่ยนเดือน
 */
export function buildMonthCells(monthValue, events, todayValue) {
    const {start} = monthGridRange(monthValue);
    const currentMonth = String(monthValue).split('-')[1];
    const byDate = groupEventsByDate(events);
    const cells = [];
    const cursor = new Date(`${start}T00:00:00Z`);

    for (let index = 0; index < 42; index += 1) {
        const dateValue = isoDate(cursor);
        cells.push({
            date: dateValue,
            dayNumber: cursor.getUTCDate(),
            isOtherMonth: dateValue.split('-')[1] !== currentMonth,
            isToday: dateValue === todayValue,
            events: byDate.get(dateValue) ?? [],
        });
        cursor.setUTCDate(cursor.getUTCDate() + 1);
    }

    return cells;
}

function element(doc, tag, className, text) {
    const node = doc.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;

    return node;
}

/*
 * ชิปเหตุการณ์หนึ่งใบ — เป็น <a> เพราะกดแล้วไปหน้ารายละเอียดของ domain นั้น
 * (ประชุม → meetings.show ซึ่งมี RSVP เดิมครบ, นัดหมาย → appointments.show)
 *
 * ประเภทต้องอ่านออกโดยไม่พึ่งสี จึงมีทั้งไอคอนและข้อความประเภทใน aria-label
 * ส่วนชื่อเรื่องถูกตัดด้วย CSS (text-overflow) ไม่ตัดด้วย JS เพื่อไม่ให้ความกว้างจอ
 * เปลี่ยนความหมายของข้อความที่ screen reader อ่าน
 */
function eventChip(doc, event) {
    const chip = element(doc, 'a', `meeting-calendar__event meeting-calendar__event--${event.type}`);
    chip.href = event.detailUrl || '#';
    chip.dataset.calendarEvent = event.type;
    chip.setAttribute('aria-label', `${TYPE_LABELS[event.type] ?? event.type} ${event.startTime} ${event.title}`);

    const icon = element(doc, 'i', `bi ${TYPE_ICONS[event.type] ?? 'bi-dot'}`);
    icon.setAttribute('aria-hidden', 'true');
    chip.append(icon);
    chip.append(element(doc, 'time', 'meeting-calendar__event-time', event.startTime || ''));
    chip.append(element(doc, 'span', 'meeting-calendar__event-title', event.title || ''));

    return chip;
}

export function renderMonthGrid(grid, monthValue, events, todayValue) {
    if (! grid?.ownerDocument) return;

    const doc = grid.ownerDocument;
    const cells = buildMonthCells(monthValue, events, todayValue);

    grid.replaceChildren(...cells.map((cell) => {
        const node = element(doc, 'div', 'meeting-calendar__day');
        node.dataset.calendarDay = cell.date;
        node.setAttribute('role', 'gridcell');
        if (cell.isOtherMonth) node.classList.add('is-other-month');
        if (cell.isToday) node.classList.add('is-today');

        node.append(element(doc, 'span', 'meeting-calendar__day-number', String(cell.dayNumber)));

        const list = element(doc, 'div', 'meeting-calendar__day-events');
        const {shown, hiddenCount} = splitDayEvents(cell.events);
        shown.forEach((event) => list.append(eventChip(doc, event)));

        /*
         * ปุ่ม "+X รายการ" เปิด modal ที่แสดงรายการทั้งหมดของวันนั้น
         *
         * เป็น <button> ไม่ใช่ <a> เพราะไม่ได้พาไปที่อื่น และต้องไม่ถูกอ่านเป็น
         * เหตุการณ์อีกใบ aria-label จึงบอกวันที่กำกับไว้ด้วย
         */
        if (hiddenCount > 0) {
            const more = element(doc, 'button', 'meeting-calendar__day-more', `+${hiddenCount} รายการ`);
            more.type = 'button';
            more.dataset.calendarDayMore = cell.date;
            more.setAttribute('aria-label', `ดูรายการทั้งหมด ${cell.events.length} รายการของวันที่ ${cell.date}`);
            list.append(more);
        }

        node.append(list);

        return node;
    }));
}

/** '7 ตุลาคม 2569' — ต้องตรงกับ App\Support\ThaiDate::longDay ฝั่งเซิร์ฟเวอร์ */
export function thaiDayLabel(dateValue) {
    const [year, month, day] = String(dateValue).split('-').map(Number);
    if (! year || ! month || month < 1 || month > 12 || ! day) return '';

    return `${day} ${THAI_MONTHS[month - 1]} ${year + 543}`;
}

/**
 * รายชื่อผู้เข้าร่วมแบบย่อสำหรับหนึ่งบรรทัด — ชื่อไม่เกิน limit คน แล้วต่อด้วย "+N"
 *
 * แยกเป็นฟังก์ชันบริสุทธิ์เพื่อทดสอบกฎการตัดได้โดยไม่ต้องมี DOM
 * เหมือน splitDayEvents และ exportItemLabel
 *
 * คืนข้อความว่างเมื่อไม่มีผู้เข้าร่วม ให้ผู้เรียกตัดสินว่าจะวาดบรรทัดนั้นไหม
 */
export function attendeeSummaryLabel(event, limit = 3) {
    const attendees = Array.isArray(event?.attendees) ? event.attendees : [];
    if (attendees.length === 0) return '';

    const names = attendees.slice(0, limit).map((person) => person?.name).filter(Boolean);
    const hidden = attendees.length - names.length;

    return hidden > 0 ? `${names.join(', ')} +${hidden}` : names.join(', ');
}

/*
 * modal "รายการทั้งหมดของวันนี้" — เปิดจากปุ่ม "+X รายการ" ในช่องวันที่
 *
 * เนื้อหาถูกเขียนใหม่ทุกครั้งที่เปิด จาก event ชุดเดียวกับที่วาดตาราง จึงไม่มีทาง
 * ที่ modal กับช่องวันที่จะไม่ตรงกัน และไม่มีการยิงคิวรีเพิ่มเมื่อกดดู
 *
 * ใช้ modal ของ Bootstrap ชุดเดียวกับทั้งหน้า เจ้าของสถานะเปิด/ปิด โฟกัส และ Escape
 * จึงมีเพียงตัวเดียวตามกฎของโปรเจกต์ ไม่ได้ผูก listener เองซ้ำ
 */
function fillDayModal(modal, dateValue, events) {
    const doc = modal.ownerDocument;
    const title = modal.querySelector('[data-calendar-day-modal-title]');
    const list = modal.querySelector('[data-calendar-day-modal-list]');

    if (title) title.textContent = thaiDayLabel(dateValue) || dateValue;
    if (! list) return;

    list.replaceChildren(...events.map((event) => {
        const row = element(doc, 'li', 'meeting-calendar__day-modal-item');
        const head = element(doc, 'div', 'meeting-calendar__day-modal-head');
        head.append(eventChip(doc, event));
        head.append(element(doc, 'span', 'meeting-calendar__day-modal-type', event.typeLabel || TYPE_LABELS[event.type] || ''));
        row.append(head);

        /*
         * บรรทัดรายชื่อผู้เข้าร่วม — "ชื่อ" เท่านั้น ไม่มีสถานะตอบรับ
         *
         * payload อาจมีสถานะมาให้ด้วยเมื่อผู้เปิดเป็นผู้สร้างรายการนั้น แต่ที่นี่
         * ไม่แสดงโดยเจตนา: Modal นี้คือตัวช่วยกวาดตาว่าวันนั้นมีอะไรและใครเกี่ยว
         * ส่วนสถานะตอบรับเป็นข้อมูลที่มีความหมายตอนทำเอกสาร (ดูเอกสาร Export)
         * การยัดป้ายสถานะลงบรรทัดแคบ ๆ นี้ทำให้ชื่อถูกตัดเร็วขึ้นโดยไม่ได้อะไรเพิ่ม
         * และยังลดพื้นที่ที่ข้อมูลของผู้สร้างจะหลุดออกไปโดยไม่ตั้งใจ
         */
        const roster = attendeeSummaryLabel(event);
        if (roster !== '') {
            row.append(element(doc, 'span', 'meeting-calendar__day-modal-people', `ผู้เข้าร่วม: ${roster}`));
        }

        return row;
    }));
}

function readPayload(root) {
    try {
        return JSON.parse(root.querySelector('[data-calendar-payload]')?.textContent || '{}');
    } catch {
        return {};
    }
}

/*
 * ตัวกรองประเภทและการเปลี่ยนเดือนดึงข้อมูลใหม่จาก endpoint แล้ววาดทับ ไม่โหลดหน้าใหม่
 * URL ถูกอัปเดตด้วย replaceState ให้ refresh แล้วยังอยู่เดือน/ตัวกรองเดิม
 */
export function initializeMeetingCalendar(
    root = document,
    fetchImpl = globalThis.fetch?.bind(globalThis),
    bootstrapApi = globalThis.window?.bootstrap
) {
    const calendar = root.querySelector?.('[data-meeting-calendar]');
    if (! calendar || calendar.dataset.meetingCalendarReady === 'true') return;
    calendar.dataset.meetingCalendarReady = 'true';

    /*
     * ที่อยู่ฐานต้องมาจากเอกสารของปฏิทินเอง ไม่ใช่ globalThis.location
     * มิฉะนั้นเมื่อปฏิทินอยู่ในเอกสารอื่น (หรือใน test ที่ไม่ได้ตั้ง global location)
     * การประกอบ URL จะ throw เงียบ ๆ แล้วเส้นทางเปลี่ยนเดือน/ตัวกรองจะไม่เคยถูกเรียกจริง
     */
    const calendarView = calendar.ownerDocument?.defaultView ?? globalThis;
    const grid = calendar.querySelector('[data-calendar-grid]');
    const monthLabel = calendar.querySelector('[data-calendar-month-label]');
    const endpoint = calendar.dataset.calendarEventsEndpoint || '';
    const todayValue = calendar.dataset.calendarToday || '';
    const payload = readPayload(calendar);

    let currentMonth = payload.month || calendar.dataset.calendarMonth || '';
    let currentType = payload.type || calendar.dataset.calendarType || 'all';
    let pending = false;
    // ชุด event ล่าสุดที่วาดอยู่ ใช้เติม modal รายการของวัน โดยไม่ต้องถามเซิร์ฟเวอร์ซ้ำ
    let currentEvents = [];
    // วันที่ผู้ใช้เลือกไว้ ต้องคงอยู่ข้ามการวาดใหม่ (เปลี่ยนตัวกรองประเภทแล้ววันที่เลือกไม่ควรหลุด)
    let selectedDate = '';

    const markSelectedDay = () => {
        grid?.querySelectorAll('[data-calendar-day]').forEach((day) => {
            day.classList.toggle('is-selected', Boolean(selectedDate) && day.dataset.calendarDay === selectedDate);
        });
    };

    const paint = (events) => {
        currentEvents = Array.isArray(events) ? events : [];
        renderMonthGrid(grid, currentMonth, currentEvents, todayValue);
        markSelectedDay();
        if (monthLabel) monthLabel.textContent = thaiMonthLabel(currentMonth);
        calendar.dataset.calendarMonth = currentMonth;
        calendar.dataset.calendarType = currentType;

        calendar.querySelectorAll('[data-calendar-type-filter]').forEach((button) => {
            const isActive = button.dataset.calendarTypeFilter === currentType;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-pressed', String(isActive));
        });
    };

    const syncLocation = () => {
        try {
            const url = new URL(calendarView.location.href);
            url.searchParams.set('month', currentMonth);
            url.searchParams.set('type', currentType);
            calendarView.history?.replaceState?.({}, '', url);
        } catch {
            // ประวัติเบราว์เซอร์ใช้ไม่ได้ในบางบริบท (เช่น iframe ที่ปิดไว้) ปฏิทินยังทำงานต่อได้
        }
    };

    const reload = async () => {
        if (pending || ! endpoint || ! fetchImpl) return;
        pending = true;
        calendar.classList.add('is-loading');

        try {
            const {start, end} = monthGridRange(currentMonth);
            const url = new URL(endpoint, calendarView.location?.href);
            url.searchParams.set('start', start);
            url.searchParams.set('end', end);
            url.searchParams.set('type', currentType);

            const response = await fetchImpl(url, {headers: {'X-Requested-With': 'XMLHttpRequest'}});
            if (! response.ok) throw new Error(`HTTP ${response.status}`);

            const body = await response.json();
            paint(body.events ?? []);
            syncLocation();
        } catch {
            window.Swal?.fire({
                icon: 'error',
                title: 'ไม่สำเร็จ',
                text: 'โหลดปฏิทินไม่สำเร็จ กรุณาลองใหม่อีกครั้ง',
                confirmButtonText: 'ตกลง',
            });
        } finally {
            pending = false;
            calendar.classList.remove('is-loading');
        }
    };

    paint(payload.events ?? []);

    /*
     * การกดในตารางถูกจับที่ตัวตารางครั้งเดียว ไม่ผูกต่อช่องวันที่
     *
     * ช่องวันที่ถูกวาดใหม่ทั้งหมดทุกครั้งที่เปลี่ยนเดือนหรือตัวกรอง การผูก listener
     * ต่อช่องจะกลายเป็นการผูกใหม่ 42 ตัวทุกครั้ง (ดูกฎ "event delegation" ใน CLAUDE.md)
     */
    grid?.addEventListener('click', (event) => {
        const moreButton = event.target.closest?.('[data-calendar-day-more]');
        const dayCell = event.target.closest?.('[data-calendar-day]');

        if (moreButton) {
            const dateValue = moreButton.dataset.calendarDayMore;
            selectedDate = dateValue;
            markSelectedDay();

            const modal = root.getElementById?.('calendarDayEventsModal')
                ?? root.querySelector?.('#calendarDayEventsModal');
            if (! modal || typeof bootstrapApi?.Modal?.getOrCreateInstance !== 'function') return;

            fillDayModal(modal, dateValue, currentEvents.filter((item) => item.date === dateValue));
            bootstrapApi.Modal.getOrCreateInstance(modal).show();

            return;
        }

        // กดบนชิปเหตุการณ์คือการไปหน้ารายละเอียด ไม่ใช่การเลือกวัน จึงปล่อยให้ลิงก์ทำงาน
        if (event.target.closest?.('[data-calendar-event]')) return;

        if (dayCell) {
            selectedDate = selectedDate === dayCell.dataset.calendarDay ? '' : dayCell.dataset.calendarDay;
            markSelectedDay();
        }
    });

    calendar.querySelectorAll('[data-calendar-type-filter]').forEach((button) => {
        button.addEventListener('click', () => {
            if (button.dataset.calendarTypeFilter === currentType) return;
            currentType = button.dataset.calendarTypeFilter;
            reload();
        });
    });

    calendar.querySelectorAll('[data-calendar-month-step]').forEach((button) => {
        button.addEventListener('click', () => {
            currentMonth = shiftMonth(currentMonth, button.dataset.calendarMonthStep === 'next' ? 1 : -1);
            reload();
        });
    });

    calendar.querySelector('[data-calendar-today-step]')?.addEventListener('click', () => {
        const todayMonth = todayValue.slice(0, 7);
        if (! todayMonth || todayMonth === currentMonth) return;
        currentMonth = todayMonth;
        reload();
    });
}

/*
 * การ์ดเลือกประเภทใน "+ เพิ่ม" สลับไปเปิด modal ของประเภทที่เลือก
 *
 * modal ของ Bootstrap สองใบเปิดพร้อมกันจริงไม่ได้ จึงปิดใบเลือกประเภทก่อนแล้วค่อย
 * เปิดใบฟอร์ม — เหตุผลเดียวกับ initializeMeetingAttendeeModals ใน pages/meetings/index.js
 */
export function initializeCalendarCreateTypeSelector(root = document, bootstrapApi = globalThis.window?.bootstrap) {
    const typeModal = root.querySelector?.('#calendarCreateTypeModal');
    if (! typeModal || typeof bootstrapApi?.Modal?.getOrCreateInstance !== 'function') return;
    if (typeModal.dataset.calendarTypeSelectorReady === 'true') return;
    typeModal.dataset.calendarTypeSelectorReady = 'true';

    typeModal.querySelectorAll('[data-calendar-create-target]').forEach((choice) => {
        choice.addEventListener('click', () => {
            const target = root.getElementById?.(choice.dataset.calendarCreateTarget);
            if (! target) return;

            typeModal.addEventListener('hidden.bs.modal', () => {
                bootstrapApi.Modal.getOrCreateInstance(target).show();
            }, {once: true});
            bootstrapApi.Modal.getOrCreateInstance(typeModal).hide();
        });
    });
}

/**
 * คีย์ของเหตุการณ์หนึ่งรายการ — ต้องตรงกับ CalendarPdfExportService::selectionKey()
 * ทั้งสองฝั่งประกอบจาก type กับ id เสมอ เพราะ id ซ้ำกันได้ระหว่างสอง domain
 */
export function eventSelectionKey(event) {
    return `${event?.type}:${event?.id}`;
}

/** ป้ายหนึ่งบรรทัดของรายการใน modal export — วันที่ พ.ศ. เวลา ชื่อ และประเภท */
export function exportItemLabel(event) {
    const time = event?.endTime ? `${event.startTime}–${event.endTime}` : (event?.startTime || '');

    return [thaiDayLabel(event?.date), time, event?.title].filter(Boolean).join(' · ');
}

/*
 * modal Export — เลือกช่วงวันที่ โหลดรายการที่มีสิทธิ์เห็น แล้วติ๊กเฉพาะที่ต้องการ
 *
 * ช่วงวันที่ถูกตรวจฝั่งหน้าจอเพื่อบอกผู้ใช้ทันทีก่อนเปิดแท็บใหม่ และเซิร์ฟเวอร์ตรวจ
 * ซ้ำอีกชั้นที่ MeetingCalendarController::exportCalendarPdf() เสมอ
 *
 * รายการที่โหลดมาเป็นเพียง "ตัวช่วยเลือก" ไม่ใช่ตัวกรองสิทธิ์ — เอกสารฝั่งเซิร์ฟเวอร์
 * สร้างจากคิวรีที่จำกัดสิทธิ์ของตัวเองใหม่ทุกครั้ง
 */
export function initializeCalendarExportForm(root = document, fetchImpl = globalThis.fetch?.bind(globalThis)) {
    const form = root.querySelector?.('[data-calendar-export-form]');
    if (! form || form.dataset.calendarExportReady === 'true') return;
    form.dataset.calendarExportReady = 'true';

    const fromInput = form.querySelector('[data-calendar-export-from]');
    const toInput = form.querySelector('[data-calendar-export-to]');
    const errorBox = form.querySelector('[data-calendar-export-error]');
    const itemsBox = form.querySelector('[data-calendar-export-items]');
    const countLabel = form.querySelector('[data-calendar-export-count]');
    const selectAllButton = form.querySelector('[data-calendar-export-select-all]');
    const clearButton = form.querySelector('[data-calendar-export-clear]');
    const endpoint = form.dataset.calendarExportEndpoint || '';
    const formView = form.ownerDocument?.defaultView ?? globalThis;
    const doc = form.ownerDocument;

    let loaded = false;

    const showError = (message) => {
        if (! errorBox) return;
        errorBox.textContent = message;
        errorBox.hidden = message === '';
    };

    const checkboxes = () => Array.from(form.querySelectorAll('[data-calendar-export-item]'));

    const syncCount = () => {
        const all = checkboxes();
        const chosen = all.filter((box) => box.checked).length;

        if (countLabel) {
            countLabel.textContent = loaded
                ? (all.length === 0 ? 'ไม่มีรายการในช่วงวันที่นี้' : `เลือกแล้ว ${chosen} จาก ${all.length} รายการ`)
                : 'เลือกวันที่เริ่มต้นและวันที่สิ้นสุด แล้วระบบจะค้นหารายการให้';
        }

        if (selectAllButton) selectAllButton.disabled = all.length === 0;
        if (clearButton) clearButton.disabled = chosen === 0;
    };

    /**
     * ตรวจช่วงวันที่ คืนค่าเมื่อใช้ได้ และคืน null เมื่อใช้ไม่ได้
     *
     * quiet=true สำหรับการค้นหาอัตโนมัติ: ระหว่างที่ผู้ใช้ยังเลือกวันไม่ครบ
     * การขึ้นข้อความ "กรุณาระบุทั้งสองวัน" ทุกครั้งที่แตะช่องคือการดุผู้ใช้
     * เรื่องที่เขากำลังทำอยู่ ส่วน "วันสิ้นสุดอยู่ก่อนวันเริ่ม" ยังบอกทันทีเสมอ
     * เพราะนั่นคือค่าที่กรอกครบแล้วแต่ขัดกันเอง ผู้ใช้ต้องรู้ตรงนั้น
     */
    const validRange = ({quiet = false} = {}) => {
        const from = fromInput?.value || '';
        const to = toInput?.value || '';

        if (! from || ! to) {
            if (! quiet) showError('กรุณาระบุทั้งวันที่เริ่มต้นและวันที่สิ้นสุด');

            return null;
        }

        if (to < from) {
            showError('วันที่สิ้นสุดต้องไม่อยู่ก่อนวันที่เริ่มต้น');

            return null;
        }

        showError('');

        return {from, to};
    };

    const renderItems = (events) => {
        if (! itemsBox) return;

        if (events.length === 0) {
            const empty = element(doc, 'li', 'meeting-calendar__export-empty', 'ไม่มีประชุมหรือนัดหมายในช่วงวันที่นี้');
            itemsBox.replaceChildren(empty);

            return;
        }

        itemsBox.replaceChildren(...events.map((event) => {
            const row = element(doc, 'li', 'meeting-calendar__export-item');
            const label = element(doc, 'label');

            const box = doc.createElement('input');
            box.type = 'checkbox';
            box.name = 'selected[]';
            box.value = eventSelectionKey(event);
            box.checked = true;
            box.dataset.calendarExportItem = event.type;

            label.append(box);
            label.append(element(doc, 'span', 'meeting-calendar__export-item-label', exportItemLabel(event)));
            label.append(element(doc, 'span', 'meeting-calendar__export-item-type', event.typeLabel || TYPE_LABELS[event.type] || ''));
            row.append(label);

            return row;
        }));
    };

    const load = async ({quiet = false} = {}) => {
        const range = validRange({quiet});
        if (! range || ! endpoint || ! fetchImpl) return;

        form.classList.add('is-loading');

        try {
            const url = new URL(endpoint, formView.location?.href);
            url.searchParams.set('start', range.from);
            url.searchParams.set('end', range.to);
            url.searchParams.set('type', form.querySelector('[name="type"]:checked')?.value || 'all');

            const response = await fetchImpl(url, {headers: {'X-Requested-With': 'XMLHttpRequest'}});
            if (! response.ok) throw new Error(`HTTP ${response.status}`);

            const body = await response.json();
            loaded = true;
            renderItems(Array.isArray(body.events) ? body.events : []);
            syncCount();
        } catch {
            showError('โหลดรายการไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
        } finally {
            form.classList.remove('is-loading');
        }
    };

    /*
     * ค้นหาเองทันทีที่ช่วงวันที่ครบ — ไม่มีปุ่มให้กด
     *
     * ของเดิมต้องกด "โหลดรายการ" ซึ่งเป็นขั้นที่ผู้ใช้ต้องจำเอง เลือกวันที่เสร็จแล้ว
     * รายการยังว่างอยู่โดยไม่มีอะไรบอกว่าต้องทำอะไรต่อ
     *
     * หน่วงไว้สั้น ๆ เพราะการเลือก "จากวันที่" แล้ว "ถึงวันที่" เป็นสองเหตุการณ์ติดกัน
     * ถ้ายิงทันทีทุกครั้งจะได้คำขอสองรอบ โดยรอบแรกเป็นช่วงที่ผู้ใช้ยังไม่ได้ตั้งใจ
     * และคำตอบอาจกลับมาสลับลำดับกันจนรายการที่เห็นไม่ตรงกับช่วงวันที่บนหน้าจอ
     */
    const AUTO_SEARCH_DELAY_MS = 250;
    let autoSearchTimer = null;

    const scheduleLoad = () => {
        if (autoSearchTimer) formView.clearTimeout(autoSearchTimer);
        autoSearchTimer = formView.setTimeout(() => {
            autoSearchTimer = null;
            load({quiet: true});
        }, AUTO_SEARCH_DELAY_MS);
    };

    selectAllButton?.addEventListener('click', () => {
        checkboxes().forEach((box) => { box.checked = true; });
        syncCount();
    });

    clearButton?.addEventListener('click', () => {
        checkboxes().forEach((box) => { box.checked = false; });
        syncCount();
    });

    // รายการถูกวาดใหม่ทุกครั้งที่โหลด จึงฟังที่กล่องรายการครั้งเดียว ไม่ผูกต่อ checkbox
    itemsBox?.addEventListener('change', syncCount);

    /*
     * เปลี่ยนช่วงวันที่หรือประเภทแล้วรายการเดิมไม่ตรงกับที่จะได้อีก
     * จึงล้างทิ้งทันทีแล้วค้นหาใหม่ ไม่ปล่อยให้ผู้ใช้ Export ของที่เห็นอยู่แต่ไม่ใช่ของจริง
     *
     * ล้างก่อนค้นหาเสมอ เพื่อไม่ให้ช่วงเวลาสั้น ๆ ระหว่างรอคำตอบมีรายการของช่วงเก่า
     * ค้างอยู่พร้อม checkbox ที่ติ๊กไว้ ซึ่งผู้ใช้อาจกด Export ทับไปได้
     */
    const resetAndSearch = () => {
        loaded = false;

        // ช่วงวันที่ยังไม่ครบหรือกลับหัวกลับหาง ก็ไม่มีอะไรให้ค้นหา ต้องไม่ค้าง
        // ข้อความ "กำลังค้นหา…" ไว้ตลอดโดยที่ไม่มีคำขอวิ่งอยู่จริง
        const ready = validRange({quiet: true}) !== null;

        if (itemsBox) {
            itemsBox.replaceChildren(element(
                doc,
                'li',
                'meeting-calendar__export-empty',
                ready ? 'กำลังค้นหารายการ…' : 'เลือกวันที่เริ่มต้นและวันที่สิ้นสุดเพื่อค้นหารายการ'
            ));
        }

        syncCount();

        if (ready) scheduleLoad();
    };

    [fromInput, toInput].forEach((input) => input?.addEventListener('change', resetAndSearch));
    form.querySelectorAll('[name="type"]').forEach((radio) => radio.addEventListener('change', resetAndSearch));

    form.addEventListener('submit', (event) => {
        if (! validRange()) {
            event.preventDefault();

            return;
        }

        /*
         * โหลดรายการแล้วแต่ไม่ติ๊กอะไรเลย = เอกสารว่าง ซึ่งแทบไม่มีใครตั้งใจ
         * จึงกันไว้ ส่วนกรณี "ยังไม่เคยโหลดรายการ" ปล่อยผ่านได้ เพราะไม่มี selected[]
         * ถูกส่งไป เซิร์ฟเวอร์จึงออกเอกสารทั้งช่วงวันที่ตามพฤติกรรมเดิมของปุ่มนี้
         */
        if (loaded && checkboxes().length > 0 && checkboxes().every((box) => ! box.checked)) {
            event.preventDefault();
            showError('กรุณาเลือกอย่างน้อยหนึ่งรายการที่จะ Export');
        }
    });

    syncCount();
}

export function initializeMeetingCalendarPage(root = document, bootstrapApi = globalThis.window?.bootstrap) {
    initializeMeetingCalendar(root, globalThis.fetch?.bind(globalThis), bootstrapApi);
    initializeCalendarCreateTypeSelector(root, bootstrapApi);
    initializeCalendarExportForm(root, globalThis.fetch?.bind(globalThis));
}

if (typeof document !== 'undefined') initializeMeetingCalendarPage(document);
