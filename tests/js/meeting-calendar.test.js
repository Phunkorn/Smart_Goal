import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
    DAY_EVENT_LIMIT,
    attendeeSummaryLabel,
    buildMonthCells,
    eventSelectionKey,
    exportItemLabel,
    groupEventsByDate,
    initializeCalendarCreateTypeSelector,
    initializeCalendarExportForm,
    initializeMeetingCalendar,
    monthGridRange,
    renderMonthGrid,
    shiftMonth,
    splitDayEvents,
    thaiDayLabel,
    thaiMonthLabel,
} from '../../resources/js/pages/meeting-calendar/index.js';
import {click, mountDom} from './helpers/dom.js';

const source = readFileSync(new URL('../../resources/js/pages/meeting-calendar/index.js', import.meta.url), 'utf8');
const calendarBlade = readFileSync(new URL('../../resources/views/meetings/components/meeting-appointment-calendar.blade.php', import.meta.url), 'utf8');
const typeModalBlade = readFileSync(new URL('../../resources/views/meetings/components/calendar-create-type-modal.blade.php', import.meta.url), 'utf8');
const exportModalBlade = readFileSync(new URL('../../resources/views/meetings/components/calendar-export-modal.blade.php', import.meta.url), 'utf8');
const appointmentFormBlade = readFileSync(new URL('../../resources/views/appointments/components/form-modal.blade.php', import.meta.url), 'utf8');
const calendarCss = readFileSync(new URL('../../resources/css/pages/meeting-calendar.css', import.meta.url), 'utf8');

const event = (type, date, title, startTime = '09:00') => ({
    type,
    id: 1,
    title,
    date,
    startTime,
    endTime: '10:00',
    detailUrl: `/${type}s/1`,
    typeLabel: type === 'meeting' ? 'ประชุม' : 'นัดหมาย',
});

/*
 * รอให้การค้นหาอัตโนมัติทำงานจบ — ต้องนานกว่า AUTO_SEARCH_DELAY_MS (250ms)
 * ที่หน่วงไว้กันคำขอซ้ำตอนเลือกวันที่สองช่องติดกัน บวกเวลาให้ promise ของ fetch คลายตัว
 */
const settle = () => new Promise((resolve) => setTimeout(resolve, 320));

/* ---------- ตัวแบบของเดือน ---------- */

test('ป้ายเดือนเป็นปี พ.ศ. ตรงกับที่เซิร์ฟเวอร์เรนเดอร์', () => {
    assert.equal(thaiMonthLabel('2026-10'), 'ตุลาคม 2569');
    assert.equal(thaiMonthLabel('2027-01'), 'มกราคม 2570');
    assert.equal(thaiMonthLabel('ไม่ใช่เดือน'), '');
});

test('เลื่อนเดือนข้ามปีได้ทั้งสองทิศทาง', () => {
    assert.equal(shiftMonth('2026-12', 1), '2027-01');
    assert.equal(shiftMonth('2026-01', -1), '2025-12');
    assert.equal(shiftMonth('2026-10', 1), '2026-11');
});

test('ช่วงของตารางเริ่มวันอาทิตย์และจบวันเสาร์ ครอบวันที่ล้นออกจากเดือน', () => {
    // 1 ต.ค. 2026 เป็นวันพฤหัส ตารางจึงเริ่มที่ 27 ก.ย.
    assert.deepEqual(monthGridRange('2026-10'), {start: '2026-09-27', end: '2026-10-31'});
});

test('ตารางมี 42 ช่องเสมอ ความสูงจึงไม่กระโดดตอนเปลี่ยนเดือน', () => {
    const cells = buildMonthCells('2026-10', [], '2026-10-07');

    assert.equal(cells.length, 42);
    assert.equal(cells[0].date, '2026-09-27');
    assert.equal(cells[0].isOtherMonth, true);
    assert.equal(cells.find((cell) => cell.date === '2026-10-07').isToday, true);
    assert.equal(cells.find((cell) => cell.date === '2026-10-07').isOtherMonth, false);
});

test('เหตุการณ์ถูกจัดกลุ่มตามวันและรายการที่ไม่มีวันถูกทิ้ง', () => {
    const grouped = groupEventsByDate([
        event('meeting', '2026-10-07', 'ประชุมเช้า'),
        event('appointment', '2026-10-07', 'นัดบ่าย'),
        {type: 'meeting', title: 'ไม่มีวัน'},
    ]);

    assert.equal(grouped.get('2026-10-07').length, 2);
    assert.equal(grouped.size, 1);
});

/* ---------- การวาด ---------- */

test('ชิปเหตุการณ์บอกประเภทด้วยไอคอนและข้อความ ไม่ใช่สีเพียงอย่างเดียว', (t) => {
    const env = mountDom();
    t.after(env.cleanup);
    const grid = env.document.createElement('div');
    env.document.body.append(grid);

    renderMonthGrid(grid, '2026-10', [
        event('meeting', '2026-10-07', 'ประชุมทีม'),
        event('appointment', '2026-10-08', 'นัดหมอ', '13:30'),
    ], '2026-10-07');

    const meetingChip = grid.querySelector('[data-calendar-event="meeting"]');
    const appointmentChip = grid.querySelector('[data-calendar-event="appointment"]');

    assert.ok(meetingChip.querySelector('i.bi-people'), 'ประชุมต้องมีไอคอนของตัวเอง');
    assert.ok(appointmentChip.querySelector('i.bi-bookmark-star'), 'นัดหมายต้องมีไอคอนของตัวเอง');
    assert.match(meetingChip.getAttribute('aria-label'), /^ประชุม 09:00 ประชุมทีม$/);
    assert.match(appointmentChip.getAttribute('aria-label'), /^นัดหมาย 13:30 นัดหมอ$/);
    // กดแล้วไปหน้ารายละเอียดของ domain นั้น
    assert.equal(meetingChip.tagName, 'A');
    assert.equal(meetingChip.getAttribute('href'), '/meetings/1');
    assert.equal(appointmentChip.getAttribute('href'), '/appointments/1');
});

test('ไอคอนในชิปเป็นตัวเสริม ข้อความยังอ่านได้เองทั้งเวลาและชื่อเรื่อง', (t) => {
    const env = mountDom();
    t.after(env.cleanup);
    const grid = env.document.createElement('div');
    env.document.body.append(grid);

    renderMonthGrid(grid, '2026-10', [event('meeting', '2026-10-07', 'ประชุมทีม')], '2026-10-07');
    const chip = grid.querySelector('[data-calendar-event]');

    assert.equal(chip.querySelector('i').getAttribute('aria-hidden'), 'true');
    assert.equal(chip.querySelector('.meeting-calendar__event-time').textContent, '09:00');
    assert.equal(chip.querySelector('.meeting-calendar__event-title').textContent, 'ประชุมทีม');
});

/* ---------- ตัวกรองและการเปลี่ยนเดือน ---------- */

function calendarMarkup(type = 'all') {
    return `
        <section data-meeting-calendar data-calendar-events-endpoint="/meeting-calendar/events"
            data-calendar-month="2026-10" data-calendar-type="${type}" data-calendar-today="2026-10-07">
            <button type="button" data-calendar-type-filter="all" class="is-active" aria-pressed="true">ทั้งหมด</button>
            <button type="button" data-calendar-type-filter="meeting" aria-pressed="false">ประชุม</button>
            <button type="button" data-calendar-type-filter="appointment" aria-pressed="false">นัดหมาย</button>
            <button type="button" data-calendar-month-step="previous">ก่อน</button>
            <button type="button" data-calendar-month-step="next">ถัดไป</button>
            <button type="button" data-calendar-today-step>วันนี้</button>
            <strong data-calendar-month-label>ตุลาคม 2569</strong>
            <div data-calendar-grid></div>
            <script type="application/json" data-calendar-payload>{"month":"2026-10","type":"all","events":[{"type":"meeting","id":1,"title":"ประชุมทีม","date":"2026-10-07","startTime":"09:00","endTime":"10:00","detailUrl":"/meetings/1"}]}</script>
        </section>`;
}

test('เปิดหน้ามาวาดจาก JSON ที่ฝังไว้ทันที ไม่ต้องรอ fetch', (t) => {
    const env = mountDom();
    t.after(env.cleanup);
    env.document.body.innerHTML = calendarMarkup();

    initializeMeetingCalendar(env.document, () => { throw new Error('ต้องไม่ fetch ตอนเปิดหน้า'); });

    assert.equal(env.document.querySelectorAll('[data-calendar-day]').length, 42);
    assert.equal(env.document.querySelectorAll('[data-calendar-event]').length, 1);
    assert.equal(env.document.querySelector('[data-calendar-month-label]').textContent, 'ตุลาคม 2569');
});

test('กดตัวกรองประเภทแล้วดึงข้อมูลใหม่โดยไม่โหลดหน้าใหม่', async (t) => {
    const env = mountDom();
    t.after(env.cleanup);
    env.document.body.innerHTML = calendarMarkup();

    const requested = [];
    const fetchImpl = (url) => {
        requested.push(String(url));

        return Promise.resolve({
            ok: true,
            json: async () => ({type: 'appointment', events: [event('appointment', '2026-10-09', 'นัดหมอ')]}),
        });
    };

    initializeMeetingCalendar(env.document, fetchImpl);
    click(env.document.querySelector('[data-calendar-type-filter="appointment"]'));
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(requested.length, 1);
    assert.match(requested[0], /type=appointment/);
    assert.match(requested[0], /start=2026-09-27/);
    assert.match(requested[0], /end=2026-10-31/);

    assert.equal(env.document.querySelector('[data-calendar-event="appointment"]').textContent.includes('นัดหมอ'), true);
    assert.equal(env.document.querySelector('[data-calendar-type-filter="appointment"]').getAttribute('aria-pressed'), 'true');
    assert.equal(env.document.querySelector('[data-calendar-type-filter="all"]').getAttribute('aria-pressed'), 'false');
});

test('กดเดือนถัดไปแล้วป้ายเดือนเปลี่ยนเป็น พ.ศ. ของเดือนใหม่', async (t) => {
    const env = mountDom();
    t.after(env.cleanup);
    env.document.body.innerHTML = calendarMarkup();

    const fetchImpl = () => Promise.resolve({ok: true, json: async () => ({type: 'all', events: []})});
    initializeMeetingCalendar(env.document, fetchImpl);

    click(env.document.querySelector('[data-calendar-month-step="next"]'));
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(env.document.querySelector('[data-calendar-month-label]').textContent, 'พฤศจิกายน 2569');
});

test('กดตัวกรองเดิมซ้ำไม่ยิงคำขอใหม่', async (t) => {
    const env = mountDom();
    t.after(env.cleanup);
    env.document.body.innerHTML = calendarMarkup();

    let calls = 0;
    initializeMeetingCalendar(env.document, () => {
        calls += 1;

        return Promise.resolve({ok: true, json: async () => ({type: 'all', events: []})});
    });

    click(env.document.querySelector('[data-calendar-type-filter="all"]'));
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(calls, 0);
});

test('initializeMeetingCalendar ไม่ผูก listener ซ้ำเมื่อเรียกซ้ำ', async (t) => {
    const env = mountDom();
    t.after(env.cleanup);
    env.document.body.innerHTML = calendarMarkup();

    let calls = 0;
    const fetchImpl = () => {
        calls += 1;

        return Promise.resolve({ok: true, json: async () => ({type: 'meeting', events: []})});
    };

    initializeMeetingCalendar(env.document, fetchImpl);
    initializeMeetingCalendar(env.document, fetchImpl);

    click(env.document.querySelector('[data-calendar-type-filter="meeting"]'));
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(calls, 1, 'เรียกซ้ำต้องไม่ทำให้กดหนึ่งครั้งยิงสองคำขอ');
});

/* ---------- การ์ดเลือกประเภท ---------- */

test('เลือกประเภทแล้วสลับไปเปิดฟอร์มของประเภทนั้น ไม่ซ้อน modal สองใบ', (t) => {
    const env = mountDom();
    t.after(env.cleanup);
    env.document.body.innerHTML = `
        <div class="modal" id="calendarCreateTypeModal">
            <button type="button" data-calendar-create-kind="appointment" data-calendar-create-target="createAppointmentModal">นัดหมาย</button>
        </div>
        <div class="modal" id="createAppointmentModal"></div>`;

    const instances = new Map();
    const bootstrapApi = {Modal: {getOrCreateInstance: (element) => {
        if (! instances.has(element)) {
            const view = element.ownerDocument.defaultView;
            instances.set(element, {
                show: () => element.classList.add('show'),
                hide: () => {
                    element.classList.remove('show');
                    element.dispatchEvent(new view.Event('hidden.bs.modal', {bubbles: true}));
                },
            });
        }

        return instances.get(element);
    }}};

    const typeModal = env.document.getElementById('calendarCreateTypeModal');
    const formModal = env.document.getElementById('createAppointmentModal');
    typeModal.classList.add('show');

    initializeCalendarCreateTypeSelector(env.document, bootstrapApi);
    initializeCalendarCreateTypeSelector(env.document, bootstrapApi);

    click(typeModal.querySelector('[data-calendar-create-target]'));

    assert.equal(typeModal.classList.contains('show'), false, 'ใบเลือกประเภทต้องปิดก่อน');
    assert.equal(formModal.classList.contains('show'), true, 'แล้วค่อยเปิดฟอร์มของประเภทที่เลือก');
});

/* ---------- Export ---------- */

test('ช่วงวันที่ของ Export ถูกตรวจก่อนเปิดแท็บใหม่', (t) => {
    const env = mountDom();
    t.after(env.cleanup);
    env.document.body.innerHTML = `
        <form data-calendar-export-form>
            <input type="date" data-calendar-export-from name="from_date">
            <input type="date" data-calendar-export-to name="to_date">
            <p data-calendar-export-error hidden></p>
        </form>`;

    initializeCalendarExportForm(env.document);
    const form = env.document.querySelector('[data-calendar-export-form]');
    const errorBox = env.document.querySelector('[data-calendar-export-error]');
    const submit = () => {
        const domEvent = new env.window.Event('submit', {bubbles: true, cancelable: true});
        form.dispatchEvent(domEvent);

        return domEvent.defaultPrevented;
    };

    assert.equal(submit(), true, 'ไม่กรอกวันที่ต้องถูกกั้นไว้');
    assert.match(errorBox.textContent, /กรุณาระบุทั้งวันที่เริ่มต้นและวันที่สิ้นสุด/);

    form.querySelector('[data-calendar-export-from]').value = '2026-10-31';
    form.querySelector('[data-calendar-export-to]').value = '2026-10-01';
    assert.equal(submit(), true, 'วันที่สิ้นสุดก่อนวันที่เริ่มต้นต้องถูกกั้นไว้');
    assert.match(errorBox.textContent, /ต้องไม่อยู่ก่อนวันที่เริ่มต้น/);

    form.querySelector('[data-calendar-export-to]').value = '2026-11-01';
    assert.equal(submit(), false, 'ช่วงที่ถูกต้องต้องส่งได้');
    assert.equal(errorBox.hidden, true);
});

test('เลือกวันที่ครบแล้วค้นหาเองทันที ติ๊กเลือกได้ และส่งเฉพาะรายการที่เลือก', async (t) => {
    const env = mountDom();
    t.after(env.cleanup);
    env.document.body.innerHTML = `
        <form data-calendar-export-form data-calendar-export-endpoint="/calendar/events">
            <input type="radio" name="type" value="all" checked>
            <input type="date" data-calendar-export-from name="from_date">
            <input type="date" data-calendar-export-to name="to_date">
            <button type="button" data-calendar-export-select-all disabled></button>
            <button type="button" data-calendar-export-clear disabled></button>
            <span data-calendar-export-count></span>
            <ul data-calendar-export-items></ul>
            <p data-calendar-export-error hidden></p>
        </form>`;

    const events = [
        {...event('meeting', '2026-10-15', 'ประชุมทีม'), id: 11},
        {...event('appointment', '2026-10-16', 'นัดหมอ'), id: 22},
    ];
    const requested = [];
    const fetchStub = async (url) => {
        requested.push(String(url));

        return {ok: true, json: async () => ({events})};
    };

    initializeCalendarExportForm(env.document, fetchStub);
    const form = env.document.querySelector('[data-calendar-export-form]');
    const errorBox = form.querySelector('[data-calendar-export-error]');

    // เลือกวันเริ่มอย่างเดียวยังไม่ครบ ต้องไม่ยิงคำขอและต้องไม่ขึ้นข้อความเตือน
    const fromInput = form.querySelector('[data-calendar-export-from]');
    fromInput.value = '2026-10-01';
    fromInput.dispatchEvent(new env.window.Event('change', {bubbles: true}));
    await settle();

    assert.deepEqual(requested, [], 'วันที่ยังไม่ครบต้องไม่ค้นหา');
    assert.equal(errorBox.hidden, true, 'ยังเลือกไม่ครบไม่ใช่ความผิดพลาดของผู้ใช้');

    // ครบแล้วต้องค้นหาเองโดยไม่ต้องกดปุ่มอะไร
    const toInput = form.querySelector('[data-calendar-export-to]');
    toInput.value = '2026-10-31';
    toInput.dispatchEvent(new env.window.Event('change', {bubbles: true}));
    await settle();

    assert.equal(requested.length, 1, 'ช่วงวันที่ครบแล้วต้องค้นหาเองหนึ่งครั้ง');
    assert.match(requested[0], /start=2026-10-01/);
    assert.match(requested[0], /end=2026-10-31/);

    const boxes = () => Array.from(form.querySelectorAll('[data-calendar-export-item]'));
    assert.equal(boxes().length, 2, 'ต้องวาดรายการที่โหลดมาเป็น checkbox');
    assert.deepEqual(boxes().map((box) => box.value), ['meeting:11', 'appointment:22']);
    assert.deepEqual(boxes().map((box) => box.name), ['selected[]', 'selected[]']);
    assert.ok(boxes().every((box) => box.checked), 'โหลดมาแล้วติ๊กไว้ทั้งหมดเป็นค่าเริ่มต้น');
    assert.match(form.querySelector('[data-calendar-export-count]').textContent, /เลือกแล้ว 2 จาก 2 รายการ/);

    // ยกเลิกการเลือกทั้งหมดแล้วส่ง ต้องถูกกั้นไว้ เอกสารว่างแทบไม่มีใครตั้งใจ
    click(form.querySelector('[data-calendar-export-clear]'));
    assert.ok(boxes().every((box) => ! box.checked));

    const domEvent = new env.window.Event('submit', {bubbles: true, cancelable: true});
    form.dispatchEvent(domEvent);
    assert.equal(domEvent.defaultPrevented, true, 'ไม่เลือกอะไรเลยต้องถูกกั้นไว้');
    assert.match(form.querySelector('[data-calendar-export-error]').textContent, /เลือกอย่างน้อยหนึ่งรายการ/);

    // เลือกทั้งหมดกลับมาแล้วส่งได้
    click(form.querySelector('[data-calendar-export-select-all]'));
    assert.ok(boxes().every((box) => box.checked));

    const resend = new env.window.Event('submit', {bubbles: true, cancelable: true});
    form.dispatchEvent(resend);
    assert.equal(resend.defaultPrevented, false);
});

test('เปลี่ยนช่วงวันที่แล้วรายการเดิมถูกล้างทันที แล้วค้นหาช่วงใหม่ให้เอง', async (t) => {
    const env = mountDom();
    t.after(env.cleanup);
    env.document.body.innerHTML = `
        <form data-calendar-export-form data-calendar-export-endpoint="/calendar/events">
            <input type="radio" name="type" value="all" checked>
            <input type="date" data-calendar-export-from name="from_date" value="2026-10-01">
            <input type="date" data-calendar-export-to name="to_date" value="2026-10-31">
            <button type="button" data-calendar-export-select-all disabled></button>
            <button type="button" data-calendar-export-clear disabled></button>
            <span data-calendar-export-count></span>
            <ul data-calendar-export-items></ul>
            <p data-calendar-export-error hidden></p>
        </form>`;

    const requested = [];
    const fetchStub = async (url) => {
        requested.push(String(url));

        return {ok: true, json: async () => ({events: [event('meeting', '2026-10-15', 'ประชุมทีม')]})};
    };
    initializeCalendarExportForm(env.document, fetchStub);
    const form = env.document.querySelector('[data-calendar-export-form]');
    const items = form.querySelector('[data-calendar-export-items]');

    const fromInput = form.querySelector('[data-calendar-export-from]');
    fromInput.dispatchEvent(new env.window.Event('change', {bubbles: true}));
    await settle();
    assert.equal(form.querySelectorAll('[data-calendar-export-item]').length, 1);

    // ช่วงใหม่ต้องยังถูกต้อง (วันเริ่มไม่เลยวันสิ้นสุด) ไม่งั้นจะเป็นการทดสอบเคสผิดพลาดแทน
    fromInput.value = '2026-10-05';
    fromInput.dispatchEvent(new env.window.Event('change', {bubbles: true}));

    // ล้างทันทีก่อนรอคำตอบ ไม่ปล่อยให้รายการของช่วงเก่าค้างอยู่พร้อม checkbox ที่ติ๊กไว้
    assert.equal(form.querySelectorAll('[data-calendar-export-item]').length, 0, 'รายการเดิมต้องถูกล้าง');
    assert.match(items.textContent, /กำลังค้นหารายการ/);

    await settle();
    assert.equal(requested.length, 2, 'เปลี่ยนวันที่แล้วต้องค้นหาใหม่เอง');
    assert.match(requested[1], /start=2026-10-05/);
    assert.equal(form.querySelectorAll('[data-calendar-export-item]').length, 1);
});

test('ช่วงวันที่กลับหัวกลับหางบอกทันทีและไม่ค้นหา', async (t) => {
    const env = mountDom();
    t.after(env.cleanup);
    env.document.body.innerHTML = `
        <form data-calendar-export-form data-calendar-export-endpoint="/calendar/events">
            <input type="radio" name="type" value="all" checked>
            <input type="date" data-calendar-export-from name="from_date" value="2026-10-31">
            <input type="date" data-calendar-export-to name="to_date" value="2026-10-01">
            <button type="button" data-calendar-export-select-all disabled></button>
            <button type="button" data-calendar-export-clear disabled></button>
            <span data-calendar-export-count></span>
            <ul data-calendar-export-items></ul>
            <p data-calendar-export-error hidden></p>
        </form>`;

    const requested = [];
    const fetchStub = async (url) => {
        requested.push(String(url));

        return {ok: true, json: async () => ({events: []})};
    };

    initializeCalendarExportForm(env.document, fetchStub);
    const form = env.document.querySelector('[data-calendar-export-form]');

    form.querySelector('[data-calendar-export-to]')
        .dispatchEvent(new env.window.Event('change', {bubbles: true}));
    await settle();

    // ค่ากรอกครบแล้วแต่ขัดกันเอง ต้องบอกทันที ไม่ใช่เงียบแบบกรณี "ยังเลือกไม่ครบ"
    assert.deepEqual(requested, [], 'ช่วงที่ใช้ไม่ได้ต้องไม่ยิงคำขอ');
    assert.equal(form.querySelector('[data-calendar-export-error]').hidden, false);
    assert.match(
        form.querySelector('[data-calendar-export-error]').textContent,
        /ต้องไม่อยู่ก่อนวันที่เริ่มต้น/
    );
});

/* ---------- รายการเกิน 5 ในหนึ่งวัน ---------- */

test('หนึ่งวันแสดงได้ไม่เกิน 5 รายการ ส่วนที่เหลือถูกนับไว้', () => {
    assert.equal(DAY_EVENT_LIMIT, 5);

    const five = Array.from({length: 5}, (_, index) => event('meeting', '2026-10-15', `ประชุม ${index}`));
    const eight = Array.from({length: 8}, (_, index) => event('meeting', '2026-10-15', `ประชุม ${index}`));

    // ห้ารายการพอดีต้องแสดงครบ ไม่มีปุ่ม
    assert.deepEqual(splitDayEvents(five), {shown: five, hiddenCount: 0});

    // เกินมาหนึ่งรายการยังแสดงครบ เพราะปุ่ม "+1 รายการ" กินที่เท่ากับรายการนั้นเอง
    const six = Array.from({length: 6}, (_, index) => event('meeting', '2026-10-15', `ประชุม ${index}`));
    assert.equal(splitDayEvents(six).hiddenCount, 0);
    assert.equal(splitDayEvents(six).shown.length, 6);

    const split = splitDayEvents(eight);
    assert.equal(split.shown.length, 5);
    assert.equal(split.hiddenCount, 3);

    assert.deepEqual(splitDayEvents(undefined), {shown: [], hiddenCount: 0});
});

test('ช่องวันที่ที่มีรายการเกินห้า วาดปุ่ม "+X รายการ" ไว้ท้ายสุด', () => {
    const {document} = mountDom('<div data-calendar-grid></div>');
    const grid = document.querySelector('[data-calendar-grid]');
    const events = Array.from({length: 9}, (_, index) => event('meeting', '2026-10-15', `ประชุม ${index}`));

    renderMonthGrid(grid, '2026-10', events, '2026-10-07');

    const day = grid.querySelector('[data-calendar-day="2026-10-15"]');
    assert.equal(day.querySelectorAll('[data-calendar-event]').length, 5, 'ต้องวาดชิปแค่ห้าใบ');

    const more = day.querySelector('[data-calendar-day-more]');
    assert.equal(more.textContent, '+4 รายการ');
    assert.equal(more.tagName, 'BUTTON', 'ปุ่มดูเพิ่มต้องไม่ใช่ลิงก์ เพราะไม่ได้พาไปที่อื่น');
    assert.equal(more.dataset.calendarDayMore, '2026-10-15');
});

test('วันที่มีรายการไม่เกินห้าไม่มีปุ่มดูเพิ่ม', () => {
    const {document} = mountDom('<div data-calendar-grid></div>');
    const grid = document.querySelector('[data-calendar-grid]');

    renderMonthGrid(grid, '2026-10', [event('meeting', '2026-10-15', 'ประชุมเดียว')], '2026-10-07');

    const day = grid.querySelector('[data-calendar-day="2026-10-15"]');
    assert.equal(day.querySelector('[data-calendar-day-more]'), null);
});

test('รายชื่อผู้เข้าร่วมแบบย่อตัดที่ 3 คนแล้วบอกจำนวนที่เหลือ', () => {
    const withAttendees = (count) => ({
        ...event('meeting', '2026-10-15', 'ประชุมทีม'),
        attendees: Array.from({length: count}, (_, index) => ({name: `คนที่ ${index + 1}`})),
    });

    assert.equal(attendeeSummaryLabel(withAttendees(2)), 'คนที่ 1, คนที่ 2');
    assert.equal(attendeeSummaryLabel(withAttendees(3)), 'คนที่ 1, คนที่ 2, คนที่ 3');
    assert.equal(attendeeSummaryLabel(withAttendees(5)), 'คนที่ 1, คนที่ 2, คนที่ 3 +2');

    // ไม่มีผู้เข้าร่วมคืนข้อความว่าง ให้ผู้เรียกตัดสินว่าจะวาดบรรทัดนั้นไหม
    assert.equal(attendeeSummaryLabel(withAttendees(0)), '');
    assert.equal(attendeeSummaryLabel(undefined), '');
    assert.equal(attendeeSummaryLabel({}), '');
});

test('กด "+X รายการ" แล้ว modal แสดงรายชื่อผู้เข้าร่วม แต่ไม่แสดงสถานะตอบรับ', (t) => {
    const env = mountDom();
    t.after(env.cleanup);

    const shown = [];
    const bootstrapApi = {
        Modal: {
            getOrCreateInstance: (node) => ({
                show: () => shown.push(node.id),
                hide: () => {},
            }),
        },
    };

    const events = Array.from({length: 9}, (_, index) => ({
        ...event('meeting', '2026-10-15', `ประชุม ${index}`),
        id: index + 1,
        attendees: [
            {name: 'สมหญิง รักงาน', roleLabel: 'พนักงาน · ไอที'},
            {name: 'วิชัย ตั้งใจ', roleLabel: 'หัวหน้าแผนก · ไอที'},
        ],
    }));

    env.document.body.innerHTML = `
        <section data-meeting-calendar data-calendar-month="2026-10" data-calendar-today="2026-10-07">
            <strong data-calendar-month-label></strong>
            <div data-calendar-grid></div>
            <script type="application/json" data-calendar-payload>${JSON.stringify({month: '2026-10', type: 'all', events})}</script>
        </section>
        <div id="calendarDayEventsModal">
            <h2 data-calendar-day-modal-title></h2>
            <ul data-calendar-day-modal-list></ul>
        </div>`;

    initializeMeetingCalendar(env.document, undefined, bootstrapApi);

    const more = env.document.querySelector('[data-calendar-day-more]');
    assert.ok(more, 'เก้ารายการในวันเดียวต้องมีปุ่มดูเพิ่ม');
    click(more);

    assert.deepEqual(shown, ['calendarDayEventsModal'], 'ต้องเปิด modal ของวัน');

    const listText = env.document.querySelector('[data-calendar-day-modal-list]').textContent;
    assert.match(listText, /สมหญิง รักงาน/);
    assert.match(listText, /วิชัย ตั้งใจ/);

    // payload ไม่พาสถานะตอบรับมาเลย ทั้ง modal และเอกสารจึงไม่มีอะไรให้หลุด
    assert.doesNotMatch(listText, /ไม่ว่าง/);
    assert.doesNotMatch(listText, /รอตอบรับ/);
    assert.doesNotMatch(listText, /สถานะ/);
});

test('payload ของปฏิทินไม่มีสถานะตอบรับติดมาเลย', () => {
    // ตรึงไว้ที่ชั้น Support — ถ้ามีใครเพิ่ม status/statusLabel กลับเข้ามา
    // ต้องเป็นการตัดสินใจที่มาพร้อมการตรวจสิทธิ์ ไม่ใช่หลุดเข้ามาเงียบ ๆ
    const roster = readFileSync(new URL('../../app/Support/CalendarAttendeeRoster.php', import.meta.url), 'utf8');

    // ตรวจที่โค้ด ไม่ใช่ที่คอมเมนต์ — docblock อธิบายเหตุผลโดยอ้างชื่อ policy ได้
    assert.doesNotMatch(roster, /'status'\s*=>/);
    assert.doesNotMatch(roster, /'statusLabel'\s*=>/);
    // ไม่มีการตัดสินสิทธิ์ในชั้นนี้เลย
    assert.doesNotMatch(roster, /->can\(/);
    assert.match(roster, /RoleLabel::withDepartment/);
});

test('ป้ายวันที่ของ modal เป็นปี พ.ศ.', () => {
    assert.equal(thaiDayLabel('2026-10-15'), '15 ตุลาคม 2569');
    assert.equal(thaiDayLabel('ไม่ใช่วันที่'), '');
});

/* ---------- การเลือกรายการใน Export ---------- */

test('คีย์ของรายการประกอบจากประเภทกับรหัส เพราะรหัสซ้ำกันได้สอง domain', () => {
    assert.equal(eventSelectionKey({type: 'meeting', id: 7}), 'meeting:7');
    assert.equal(eventSelectionKey({type: 'appointment', id: 7}), 'appointment:7');
});

test('ป้ายรายการใน Export มีวันที่ พ.ศ. เวลา และชื่อเรื่อง', () => {
    const label = exportItemLabel(event('meeting', '2026-10-15', 'ประชุมทีม', '09:00'));

    assert.match(label, /15 ตุลาคม 2569/);
    assert.match(label, /09:00–10:00/);
    assert.match(label, /ประชุมทีม/);
});

/* ---------- สัญญาของ markup และสไตล์ ---------- */

test('modal รายการของวันเป็นเปลือกว่าง ไม่ฝังข้อมูลไว้ใน Blade', () => {
    const dayModalBlade = readFileSync(
        new URL('../../resources/views/meetings/components/calendar-day-events-modal.blade.php', import.meta.url),
        'utf8'
    );

    assert.match(dayModalBlade, /data-calendar-day-modal-title/);
    assert.match(dayModalBlade, /data-calendar-day-modal-list/);
    // ไม่คิวรีและไม่วนรายการใน Blade — เนื้อหามาจาก event ชุดเดียวกับที่วาดตาราง
    assert.doesNotMatch(dayModalBlade, /@foreach|::where\(|->get\(\)/);
    assert.match(calendarBlade, /calendar-day-events-modal/);
});

test('Export modal มีรายการให้ติ๊ก เลือกทั้งหมด และยกเลิกการเลือก', () => {
    /*
     * ไม่มีปุ่ม "โหลดรายการ" แล้ว — รายการถูกค้นหาเองเมื่อช่วงวันที่ครบ
     * ต้องถูกลบออกจาก markup จริง ไม่ใช่ซ่อนหรือ disable ไว้ (CLAUDE.md)
     */
    assert.doesNotMatch(exportModalBlade, /data-calendar-export-load/);
    assert.doesNotMatch(exportModalBlade, /โหลดรายการ/);
    assert.match(exportModalBlade, /data-calendar-export-select-all/);
    assert.match(exportModalBlade, /data-calendar-export-clear/);
    assert.match(exportModalBlade, /data-calendar-export-items/);
    // ช่องวันที่ยังใช้ตัวเลือกวันที่กลางของระบบ (ภาษาไทย/พ.ศ.) ไม่ใช่ของเบราว์เซอร์
    assert.match(exportModalBlade, /data-date-picker/);
});

test('ปฏิทินมีหัวเรื่อง ตัวกรองสามปุ่ม และปุ่มเดือน/วันนี้ ครบตามที่ออกแบบ', () => {
    /*
     * ปฏิทินเป็นระดับแผนก หัวเรื่องกับคำอธิบายจึงต้องระบุชื่อแผนกเจ้าของ
     * ไม่ใช่ถ้อยคำแบบ "ของคุณ" ซึ่งบอกขอบเขตผิดไปจากสิ่งที่ผู้ใช้เห็นจริง
     */
    assert.match(calendarBlade, /ปฏิทินประชุมและนัดหมาย แผนก\{\{ \$calendarDepartmentName \}\}/);
    assert.match(calendarBlade, /การประชุมและนัดหมายทั้งหมดของแผนก\{\{ \$calendarDepartmentName \}\}/);
    assert.doesNotMatch(calendarBlade, /จัดการการประชุมและนัดหมายของคุณในที่เดียว/);
    assert.match(calendarBlade, /data-calendar-type-filter="\{\{ \$typeValue \}\}"/);
    assert.match(calendarBlade, /data-calendar-month-step="previous"/);
    assert.match(calendarBlade, /data-calendar-month-step="next"/);
    assert.match(calendarBlade, /data-calendar-today-step/);
    assert.match(calendarBlade, /วันนี้/);
    // ไม่มีการ์ดสรุปจำนวนมาก โฟกัสคือปฏิทิน
    assert.doesNotMatch(calendarBlade, /mytasks-calendar-agenda/);
});

test('ปฏิทินไม่ตัดสินสิทธิ์เองและไม่คิวรีฐานข้อมูลใน Blade', () => {
    [calendarBlade, typeModalBlade, exportModalBlade, appointmentFormBlade].forEach((blade) => {
        assert.doesNotMatch(blade, /::where\(|->get\(\)|->first\(\)|DB::/);
        assert.doesNotMatch(blade, /->role\s*===?/);
    });
});

test('นัดหมายไม่ใช้ภาษาของการประชุมเลย', () => {
    [appointmentFormBlade, typeModalBlade].forEach((blade) => {
        assert.doesNotMatch(blade, /ผู้เข้าร่วมประชุม/);
        assert.doesNotMatch(blade, /ตอบรับประชุม/);
        assert.doesNotMatch(blade, /ไม่ว่างประชุม/);
    });

    assert.match(appointmentFormBlade, /หัวข้อนัดหมาย/);
    assert.match(appointmentFormBlade, /name="appointment_date"/);
    assert.match(appointmentFormBlade, /name="starts_at_time"/);
    assert.match(appointmentFormBlade, /name="ends_at_time"/);
    // ใช้ตัวเลือกวันที่กลางของระบบ ไม่ประกาศของตัวเอง
    assert.match(appointmentFormBlade, /data-date-picker/);
});

test('การ์ดเลือกประเภทยืมรูปทรงของ log-modal แต่ไม่ลาก overlay ของมันมาด้วย', () => {
    assert.match(typeModalBlade, /meeting-calendar__type-grid/);
    assert.match(typeModalBlade, /class="modal fade meeting-form-modal/);
    // ห้ามใช้ชั้น overlay ของบันทึกประจำวันซ้อนกับ modal ของ Bootstrap ในหน้าเดียว
    assert.doesNotMatch(typeModalBlade, /class="[^"]*log-modal/);
    assert.doesNotMatch(typeModalBlade, /data-log-entry-modal/);
    assert.match(calendarCss, /\.meeting-calendar__type-grid \{[^}]*grid-template-columns: repeat\(2/s);
    assert.match(calendarCss, /@media \(max-width: 767\.98px\)[\s\S]*\.meeting-calendar__type-grid \{ grid-template-columns: minmax\(0, 1fr\)/);
});

test('ตารางวันเป็นแผงเดียว ไม่ใช่การ์ด 42 ใบที่วางห่างกัน', () => {
    // แถวชื่อวันกับตารางวันต้องอยู่ในแผงเดียวกัน
    assert.match(calendarBlade, /meeting-calendar__board/);
    assert.match(
        calendarBlade,
        /meeting-calendar__board[\s\S]*meeting-calendar__weekdays[\s\S]*data-calendar-grid/,
        'แผงต้องครอบทั้งแถวชื่อวันและตารางวัน'
    );

    // ขอบและมุมโค้งมีครั้งเดียวที่รอบนอกแผง ส่วนช่องวันไม่มีขอบของตัวเอง
    assert.match(calendarCss, /\.meeting-calendar__board \{[^}]*border: 1px solid var\(--border\)/s);
    assert.doesNotMatch(
        calendarCss,
        /\.meeting-calendar__day \{[^}]*border: 1px solid/s,
        'ช่องวันต้องไม่มีขอบของตัวเอง เส้นแบ่งมาจากพื้นของตาราง'
    );

    // เส้นแบ่งคือช่องไฟ 1px ที่เห็นพื้นของตาราง
    assert.match(calendarCss, /\.meeting-calendar__grid \{[^}]*gap: 1px[^}]*background: var\(--border\)/s);
});

test('"วันนี้" กับ "วันที่เลือก" ใช้พื้นที่ต่างกัน จึงอ่านออกทั้งคู่แม้ซ้อนกัน', () => {
    // วันนี้ = วงกลมทึบที่ "เลขวัน" เท่านั้น ไม่ย้อมพื้นทั้งช่อง
    assert.match(
        calendarCss,
        /\.meeting-calendar__day\.is-today \.meeting-calendar__day-number \{[^}]*background: var\(--accent\)/s
    );

    // วันที่เลือก = พื้น "ทั้งช่อง" เปลี่ยน
    assert.match(calendarCss, /\.meeting-calendar__day\.is-selected \{[^}]*background: var\(--accent-dim\)/s);

    // เลขวันต้องเป็นวงกลมขนาดคงที่ เลขหลักเดียวกับสองหลักจึงไม่ทำให้แถวขยับ
    assert.match(calendarCss, /\.meeting-calendar__day-number \{[^}]*border-radius: 999px/s);
});

test('คำอธิบายสีมีทั้งสองประเภท และใช้สีชุดเดียวกับชิป', () => {
    assert.match(calendarBlade, /meeting-calendar__legend/);
    // "ทั้งหมด" เป็นตัวกรอง ไม่ใช่ประเภทของรายการ จึงต้องไม่อยู่ในคำอธิบายสี
    assert.match(calendarBlade, /@continue\(\$typeValue === .*TYPE_ALL\)/);

    assert.match(calendarCss, /\.meeting-calendar__legend-item--meeting .*\{[^}]*background: var\(--accent-dim\)/s);
    assert.match(calendarCss, /\.meeting-calendar__legend-item--appointment .*\{[^}]*background: var\(--amber-dim\)/s);
});

test('ชิปเป็นสี่เหลี่ยมมุมมน ไม่ใช่แคปซูลที่กินที่ของชื่อเรื่อง', () => {
    assert.doesNotMatch(
        calendarCss,
        /\.meeting-calendar__event \{[^}]*border-radius: 999px/s,
        'ปลายโค้งสองข้างของแคปซูลกินความกว้างที่ควรเป็นของชื่อเรื่อง'
    );
    assert.match(calendarCss, /\.meeting-calendar__event \{[^}]*border-left: 3px solid currentColor/s);
});

test('สไตล์ปฏิทินใช้โทเคนเดิม ไม่มี gradient และไม่มี Tailwind', () => {
    assert.doesNotMatch(calendarCss, /gradient/i);
    assert.doesNotMatch(calendarCss, /@tailwind|tailwind/i);
    assert.match(calendarCss, /var\(--border\)/);
    assert.match(calendarCss, /var\(--accent-dim\)/);
    assert.match(calendarCss, /var\(--amber-dim\)/);
    // ปุ่มทุกตัวยังเป็น .meetings-page__button ของเดิม
    assert.match(calendarBlade, /class="meetings-page__button/);
});

test('ชื่อเรื่องในชิปถูกตัดด้วย CSS และมือถือไม่ทำให้ตารางล้นแนวนอน', () => {
    assert.match(calendarCss, /\.meeting-calendar__event-title \{[^}]*text-overflow: ellipsis[^}]*white-space: nowrap/s);
    assert.match(calendarCss, /@media \(max-width: 575\.98px\)[\s\S]*\.meeting-calendar__event-title \{ display: none/);
    assert.match(calendarCss, /\.meeting-calendar__grid \{[^}]*grid-template-columns: repeat\(7, minmax\(0, 1fr\)\)/s);
    // ไม่มี overflow แนวนอนจากตัวปฏิทิน
    assert.doesNotMatch(calendarCss, /overflow-x:\s*(auto|scroll)/);
});

test('ปฏิทินประชุมไม่แตะโค้ดของปฏิทินโปรเจกต์', () => {
    assert.doesNotMatch(source, /mytasks-calendar/);
    assert.doesNotMatch(source, /data-calendar-task/);
    assert.doesNotMatch(source, /buildMonthCalendar/);
});
