import test from 'node:test';
import assert from 'node:assert/strict';
import {mountDom} from './helpers/dom.js';

/**
 * ต่อสายจริงระหว่างปฏิทินกับ Calendar Event Popover — โหลด resources/js/pages/mytasks/calendar.js ตัวจริง
 *
 * test ชุด calendar-quick-view.test.js เรียก quickView.open() เอง จึงพิสูจน์ได้แค่ตัว component
 * ไฟล์นี้พิสูจน์ว่า "การคลิกบนปฏิทินจริง" ส่ง URL และ trigger element ที่ถูกต้องเข้าไป
 * ซึ่งเป็นข้อที่สรุปเองไม่ได้ว่าได้มาฟรี ๆ
 */

const POPOVER_SHELL = `
<div class="calendar-quick-view-popover" id="calendar-quick-view-popover" data-quick-view-popover hidden>
    <span data-quick-view-caret></span>
    <section role="dialog" aria-modal="false" aria-labelledby="calendar-quick-view-title">
        <header>
            <span data-quick-view-kicker>ดูอย่างย่อ</span>
            <strong id="calendar-quick-view-title" data-quick-view-title>กำลังโหลด...</strong>
            <button type="button" data-close-quick-view aria-label="ปิด">x</button>
        </header>
        <div data-quick-view-body aria-live="polite"></div>
        <a data-quick-view-detail href="#" hidden>ดูรายละเอียดทั้งหมด</a>
    </section>
</div>`;

const TASK_HTML = '<article data-quick-view-type="task" data-quick-view-title-text="งานทดสอบปฏิทิน" data-quick-view-kicker-text="โปรเจกต์ปฏิทิน"><p>เนื้อหางาน</p></article>';
const MEETING_HTML = '<article data-quick-view-type="meeting" data-quick-view-title-text="ประชุมทดสอบปฏิทิน" data-quick-view-kicker-text="การประชุม"><p>เนื้อหาประชุม</p></article>';

const isoDate = (date) => [
    date.getFullYear(),
    String(date.getMonth() + 1).padStart(2, '0'),
    String(date.getDate()).padStart(2, '0'),
].join('-');

const months = Array.from({length: 12}, (_, index) => `<option value="${index}">${index + 1}</option>`).join('');

let fixtureCount = 0;

/**
 * ปฏิทินตัวจริงจะ bootstrap ตอน import จึงต้องเตรียม DOM ให้ครบก่อน
 * แล้ว cache-bust เพื่อให้แต่ละ fixture ผูกกับ document ของตัวเอง
 */
async function bootCalendar(t, {detailTemplate}) {
    const env = mountDom();
    t.after(env.cleanup);

    const today = isoDate(new Date());
    const meeting = {
        id: 'meeting-1',
        type: 'meeting',
        title: 'ประชุมทดสอบปฏิทิน',
        location: 'ห้องประชุมชั้น 2',
        organizer: 'ผู้จัด',
        organizerAvatar: '/media/profile/20',
        attendees: [
            {name: 'ผู้เข้าร่วมหนึ่ง', avatar_url: '/media/profile/21'},
            {name: 'ผู้เข้าร่วมสอง', avatar_url: null},
        ],
        start: today,
        due: today,
        startTime: '10:00',
        endTime: '11:00',
        entityId: 1,
        quickViewUrl: '/my-tasks/calendar/quick-view/meeting/1',
        detailUrl: '/meetings/1',
        url: '/meetings/1',
    };

    env.document.body.innerHTML = `
        <div data-workspace>
            <div data-workspace-task-source hidden>
                <div data-row data-id="1" data-topic="งานทดสอบปฏิทิน" data-project="โปรเจกต์ปฏิทิน"
                     data-status="2" data-priority="2" data-start="${today}" data-due="${today}"></div>
            </div>
            <section data-calendar
                     data-task-quickview-template="/my-tasks/calendar/quick-view/task/__ID__"
                     data-task-detail-template="${detailTemplate}">
                <h2 data-calendar-title></h2>
                <button type="button" data-calendar-mode-option="timeline" aria-pressed="true">เส้นช่วงงาน</button>
                <button type="button" data-calendar-mode-option="summary" aria-pressed="false">ภาพรวมสี</button>
                <button type="button" data-calendar-date-point="start" aria-pressed="true">วันเริ่ม</button>
                <button type="button" data-calendar-date-point="due" aria-pressed="true">วันสิ้นสุด</button>
                <p data-calendar-display-note></p>
                <input type="search" data-calendar-search>
                <select data-calendar-month>${months}</select>
                <select data-calendar-year></select>
                <div data-calendar-grid></div>
                <div data-calendar-agenda>
                    <strong data-calendar-today-count></strong>
                    <div data-calendar-today-list></div>
                    <p data-calendar-today-empty></p>
                    <h3 data-calendar-month-agenda-title></h3>
                    <strong data-calendar-month-count></strong>
                    <div data-calendar-month-list></div>
                    <p data-calendar-month-empty></p>
                </div>
                <script type="application/json" data-calendar-meetings>${JSON.stringify([meeting])}</script>
            </section>
            <script type="application/json" data-team-data>{
                "1": {
                    "assignee": {"name": "เจ้าของงาน", "avatar_url": "/media/profile/9"},
                    "collaborators": [
                        {"name": "หนึ่ง", "avatar_url": "/media/profile/10", "status": "accepted"},
                        {"name": "สอง", "status": "pending"}
                    ]
                }
            }</script>
            <div data-calendar-detail hidden></div>
            <div data-calendar-day-modal hidden>
                <h2 data-calendar-day-title></h2>
                <button type="button" data-calendar-day-close></button>
                <section data-calendar-day-tasks hidden><b data-calendar-day-task-count></b><div data-calendar-day-task-list></div></section>
                <section data-calendar-day-meetings hidden><b data-calendar-day-meeting-count></b><div data-calendar-day-meeting-list></div></section>
                <small data-calendar-day-count></small>
            </div>
            ${POPOVER_SHELL}
        </div>`;

    const requests = [];
    let respondWith = TASK_HTML;
    globalThis.fetch = (url) => {
        requests.push(url);
        return Promise.resolve({ok: true, text: async () => respondWith});
    };
    t.after(() => { delete globalThis.fetch; });

    fixtureCount += 1;
    await import(`../../resources/js/pages/mytasks/calendar.js?fixture=${fixtureCount}`);

    return {
        ...env,
        requests,
        respond: (html) => { respondWith = html; },
        popover: env.document.querySelector('[data-quick-view-popover]'),
        detailLink: env.document.querySelector('[data-quick-view-detail]'),
        title: env.document.querySelector('[data-quick-view-title]'),
        // ช่องวันที่สรุปเป็นจำนวนงานแล้ว แถวที่คลิกได้จึงอยู่ในการ์ดสรุปใต้ปฏิทิน
        chip: (id) => env.document.querySelector(`[data-calendar-agenda] [data-calendar-task="${id}"]`),
        agendaItem: (list, id) => env.document.querySelector(`[data-calendar-${list}-list] [data-calendar-task="${id}"]`),
        clickChip(id) {
            const node = this.chip(id);
            assert.ok(node, `ไม่พบ chip ${id} บนปฏิทิน`);
            const event = new env.window.MouseEvent('click', {bubbles: true, cancelable: true});
            node.dispatchEvent(event);
            return {node, event};
        },
    };
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

test('today and month agenda cards combine tasks with meetings', async (t) => {
    const ui = await bootCalendar(t, {detailTemplate: '/my-tasks?view=calendar&amp;open_task=__ID__'});
    const todayList = ui.document.querySelector('[data-calendar-today-list]');
    const monthList = ui.document.querySelector('[data-calendar-month-list]');

    assert.equal(todayList.querySelectorAll('[data-calendar-task]').length, 2);
    assert.ok(ui.agendaItem('today', 'task-1'));
    assert.ok(ui.agendaItem('today', 'meeting-1'));

    assert.equal(monthList.querySelectorAll('[data-calendar-task]').length, 2);
    assert.ok(ui.agendaItem('month', 'task-1'));
    assert.ok(ui.agendaItem('month', 'meeting-1'));

    assert.equal(ui.document.querySelector('[data-calendar-today-count]').textContent, '2 รายการ');
    assert.equal(ui.document.querySelector('[data-calendar-month-count]').textContent, '2 รายการ');
    assert.equal(ui.document.querySelector('[data-calendar-meeting-list]'), null);

    ui.document.querySelector('[data-calendar-day]').dispatchEvent(new ui.window.MouseEvent('click', {bubbles: true, cancelable: true}));
    assert.ok(ui.document.querySelector('[data-calendar-day-meeting-list] [data-calendar-task="meeting-1"]'));
});

test('agenda cards show task collaborators and meeting attendees', async (t) => {
    const ui = await bootCalendar(t, {detailTemplate: '/my-tasks?view=calendar&amp;open_task=__ID__'});
    const item = ui.agendaItem('today', 'task-1');

    assert.equal(item.querySelector('.calendar-avatar.is-owner img').getAttribute('src'), '/media/profile/9');
    assert.equal(item.querySelectorAll('.calendar-people__stack .calendar-avatar').length, 2);
    const monthItem = ui.agendaItem('month', 'task-1');
    assert.match(monthItem.textContent, /โปรเจกต์ปฏิทิน/);
    assert.equal(monthItem.querySelectorAll('.calendar-people__stack .calendar-avatar').length, 2);

    const meetingItem = ui.agendaItem('today', 'meeting-1');
    assert.equal(meetingItem.querySelector('.calendar-avatar.is-owner img').getAttribute('src'), '/media/profile/20');
    assert.equal(meetingItem.querySelectorAll('.calendar-people__stack .calendar-avatar').length, 2);

    ui.document.querySelector('[data-calendar-day]').dispatchEvent(new ui.window.MouseEvent('click', {bubbles: true, cancelable: true}));
    const dayItem = ui.document.querySelector('[data-calendar-day-task-list] [data-calendar-task="task-1"]');
    const collaborators = dayItem.querySelectorAll('.calendar-people__stack .calendar-avatar');
    // รายละเอียดผู้ร่วมงานยังอยู่ครบใน Modal รายวัน
    assert.equal(collaborators.length, 2);
    assert.equal(collaborators[0].querySelector('img').getAttribute('src'), '/media/profile/10');
    assert.equal(collaborators[1].querySelector('img'), null);
    assert.equal(collaborators[1].textContent, 'ส');
    assert.equal(collaborators[1].classList.contains('is-pending'), true);
});

test('the calendar opens as a task timeline and can switch to the priority summary', async (t) => {
    const ui = await bootCalendar(t, {detailTemplate: '/my-tasks?view=calendar&amp;open_task=__ID__'});
    const today = isoDate(new Date());
    const cell = ui.document.querySelector(`[data-calendar-grid] [data-calendar-date="${today}"]`);

    assert.equal(ui.document.querySelector('[data-calendar]').dataset.calendarMode, 'timeline');
    assert.equal(cell.querySelectorAll('[data-calendar-task]').length, 0);
    const line = cell.closest('.mytasks-calendar__week').querySelector('.mytasks-calendar__task-line');
    assert.ok(line);
    assert.equal(line.textContent.includes('งานทดสอบปฏิทิน'), true);
    assert.equal(line.classList.contains('priority-important'), true);
    assert.ok(line.querySelector('.calendar-dot'));
    assert.ok(line.querySelector('.bi-play-fill'));
    assert.ok(line.querySelector('.bi-flag-fill'));

    /*
     * มุมมองเส้นไม่มีชิปนับรายการของตัวเองแล้ว
     * งานได้เลนทั้งสี่ก่อนเสมอ ส่วนประชุมถูกบอกไว้ในปุ่มสรุปมุมล่างขวา ปุ่มเดียวกับงานที่ล้น
     * ผู้ใช้จึงยังรู้ว่าวันนั้นมีประชุม แม้เลนทั้งสี่จะถูกงานด่วนใช้ไปหมดแล้ว
     */
    assert.deepEqual([...cell.querySelectorAll('.mytasks-calendar__count')], []);
    const overflow = cell.querySelector('.mytasks-calendar__timeline-more');
    assert.ok(overflow, 'วันที่มีประชุมต้องมีปุ่มสรุปบอกไว้เสมอ');
    assert.equal(overflow.querySelector('.is-meeting-count').textContent, '1 ประชุม');
    assert.equal(overflow.dataset.calendarDay, today, 'กดแล้วต้องเปิดรายการของวันนั้น');

    ui.document.querySelector('[data-calendar-mode-option="summary"]')
        .dispatchEvent(new ui.window.MouseEvent('click', {bubbles: true, cancelable: true}));
    const summaryCell = ui.document.querySelector(`[data-calendar-grid] [data-calendar-date="${today}"]`);
    const counts = [...summaryCell.querySelectorAll('.mytasks-calendar__count')].map((node) => node.textContent);
    assert.deepEqual(counts, ['1 งาน', '1 ประชุม']);
    assert.equal(summaryCell.querySelector('.mytasks-calendar__count.priority-important') !== null, true);
    assert.equal(summaryCell.querySelector('.mytasks-calendar__count.is-meeting') !== null, true);
    assert.equal(ui.document.querySelectorAll('.mytasks-calendar__task-line').length, 0);
});

test('date controls cannot leave the calendar with both endpoints disabled', async (t) => {
    const ui = await bootCalendar(t, {detailTemplate: '/my-tasks?view=calendar&amp;open_task=__ID__'});
    const start = ui.document.querySelector('[data-calendar-date-point="start"]');
    const due = ui.document.querySelector('[data-calendar-date-point="due"]');

    start.dispatchEvent(new ui.window.MouseEvent('click', {bubbles: true, cancelable: true}));
    assert.equal(start.getAttribute('aria-pressed'), 'false');
    assert.equal(due.getAttribute('aria-pressed'), 'true');

    due.dispatchEvent(new ui.window.MouseEvent('click', {bubbles: true, cancelable: true}));
    assert.equal(due.getAttribute('aria-pressed'), 'true');
});

test('the day cell carries the tone of its most urgent item', async (t) => {
    const ui = await bootCalendar(t, {detailTemplate: '/my-tasks?view=calendar&amp;open_task=__ID__'});
    const today = isoDate(new Date());
    const cell = ui.document.querySelector(`[data-calendar-grid] [data-calendar-date="${today}"]`);
    const empty = [...ui.document.querySelectorAll('[data-calendar-grid] [data-calendar-date]')]
        .find((node) => node.dataset.calendarDate !== today);

    // งาน priority 2 (สำคัญไม่ด่วน) ชนะประชุมเสมอ เพราะประชุมอยู่ท้ายลำดับ
    assert.equal(cell.classList.contains('is-tone-important'), true);
    assert.equal(cell.classList.contains('is-busy'), true);
    assert.equal(cell.dataset.calendarTone, 'priority-2');

    // วันที่ไม่มีรายการต้องไม่ถูกระบายสีเลย
    assert.equal(empty.className.includes('is-tone-'), false);
    assert.equal(empty.classList.contains('is-busy'), false);
});

test('the calendar search narrows both the grid and the summary cards', async (t) => {
    const ui = await bootCalendar(t, {detailTemplate: '/my-tasks?view=calendar&amp;open_task=__ID__'});
    const search = ui.document.querySelector('[data-calendar-search]');
    const today = isoDate(new Date());

    search.value = 'ประชุมทดสอบ';
    search.dispatchEvent(new ui.window.Event('input', {bubbles: true}));

    assert.equal(ui.agendaItem('today', 'task-1'), null, 'งานที่ไม่ตรงคำค้นต้องหายจากการ์ด');
    assert.ok(ui.agendaItem('today', 'meeting-1'), 'ประชุมที่ตรงคำค้นต้องอยู่ในการ์ดวันนี้');
    assert.equal(ui.document.querySelector('[data-calendar-meeting-list]'), null);

    // โหมดเส้นบอกจำนวนประชุมผ่านปุ่มสรุป ไม่ใช่ชิปนับรายการอีกต่อไป
    const meetingSummary = ui.document.querySelector(`[data-calendar-date="${today}"] .mytasks-calendar__timeline-more .is-meeting-count`);
    assert.equal(meetingSummary?.textContent, '1 ประชุม');
    ui.document.querySelector('[data-calendar-day]').dispatchEvent(new ui.window.MouseEvent('click', {bubbles: true, cancelable: true}));
    assert.ok(ui.document.querySelector('[data-calendar-day-meeting-list] [data-calendar-task="meeting-1"]'));

    search.value = '';
    search.dispatchEvent(new ui.window.Event('input', {bubbles: true}));
    assert.ok(ui.agendaItem('today', 'task-1'), 'ล้างคำค้นแล้วต้องกลับมาครบ');
});

test('agenda follows the selected month and reuses the existing task quick view handler', async (t) => {
    const ui = await bootCalendar(t, {detailTemplate: '/my-tasks?view=calendar&amp;open_task=__ID__'});
    const agendaTask = ui.agendaItem('month', 'task-1');
    agendaTask.dispatchEvent(new ui.window.MouseEvent('click', {bubbles: true, cancelable: true}));

    assert.deepEqual(ui.requests, ['/my-tasks/calendar/quick-view/task/1']);
    await flush();
    assert.equal(ui.popover.hidden, false);

    const monthSelect = ui.document.querySelector('[data-calendar-month]');
    monthSelect.value = String((new Date().getMonth() + 1) % 12);
    monthSelect.dispatchEvent(new ui.window.Event('change', {bubbles: true}));

    assert.equal(ui.document.querySelector('[data-calendar-month-list]').children.length, 0);
    assert.equal(ui.document.querySelector('[data-calendar-month-empty]').hidden, false);
    assert.match(ui.document.querySelector('[data-calendar-month-agenda-title]').textContent, /^กำหนดส่งและนัดหมายใน/);
});

test('คลิกงานบนปฏิทินเปิด Popover โดยไม่ navigate และคืน focus ให้ chip ที่กด', async (t) => {
    const ui = await bootCalendar(t, {detailTemplate: '/my-tasks?view=calendar&amp;open_task=__ID__'});

    const {node, event} = ui.clickChip('task-1');
    assert.equal(event.defaultPrevented, true, 'ต้องกัน default ไม่ให้เบราว์เซอร์เปลี่ยนหน้า');
    assert.equal(ui.popover.hidden, false);
    assert.equal(node.getAttribute('aria-expanded'), 'true');
    assert.deepEqual(ui.requests, ['/my-tasks/calendar/quick-view/task/1']);

    await flush();
    assert.equal(ui.title.textContent, 'งานทดสอบปฏิทิน');
    assert.equal(ui.detailLink.getAttribute('href'), '/my-tasks?view=calendar&open_task=1');

    ui.document.querySelector('[data-close-quick-view]').dispatchEvent(
        new ui.window.MouseEvent('click', {bubbles: true, cancelable: true})
    );
    assert.equal(ui.popover.hidden, true);
    assert.equal(node.getAttribute('aria-expanded'), 'false');
    assert.equal(ui.document.activeElement, node, 'ปิดแล้ว focus ต้องกลับไปที่รายการบนปฏิทิน');
});

test('คลิกประชุมบนปฏิทินเปิด Popover ของประชุม ไม่ใช่ลิงก์ไปหน้าประชุม', async (t) => {
    const ui = await bootCalendar(t, {detailTemplate: '/my-tasks?view=calendar&amp;open_task=__ID__'});
    ui.respond(MEETING_HTML);

    ui.document.querySelector('[data-calendar-day]').dispatchEvent(new ui.window.MouseEvent('click', {bubbles: true, cancelable: true}));
    const node = ui.document.querySelector('[data-calendar-day-meeting-list] [data-calendar-task="meeting-1"]');
    const event = new ui.window.MouseEvent('click', {bubbles: true, cancelable: true});
    node.dispatchEvent(event);
    assert.equal(node.tagName, 'A', 'ประชุมยังเป็นลิงก์จริงเพื่อให้เปิดแท็บใหม่ได้');
    assert.equal(node.getAttribute('href'), '/meetings/1');
    assert.equal(event.defaultPrevented, true);
    assert.deepEqual(ui.requests, ['/my-tasks/calendar/quick-view/meeting/1']);

    await flush();
    assert.equal(ui.title.textContent, 'ประชุมทดสอบปฏิทิน');
    assert.equal(ui.detailLink.getAttribute('href'), '/meetings/1', 'ปลายทางของประชุมมาจาก payload ของ server');
});

test('หน้า Admin ส่ง detailUrl ของหน้าตัวเองให้ chip ไม่ใช่ /my-tasks', async (t) => {
    const adminTemplate = '/admin/work-board/3/member/9?open_task=__ID__';
    const ui = await bootCalendar(t, {detailTemplate: adminTemplate});

    const {node} = ui.clickChip('task-1');
    assert.equal(node.dataset.calendarDetailUrl, '/admin/work-board/3/member/9?open_task=1');

    await flush();
    assert.equal(ui.detailLink.getAttribute('href'), '/admin/work-board/3/member/9?open_task=1');
    assert.doesNotMatch(ui.detailLink.getAttribute('href'), /^\/my-tasks/);
});

test('เปิดแล้วปิด Popover ไม่รีเซ็ตเดือนหรือวาดปฏิทินใหม่', async (t) => {
    const ui = await bootCalendar(t, {detailTemplate: '/my-tasks?view=calendar&amp;open_task=__ID__'});
    const grid = ui.document.querySelector('[data-calendar-grid]');
    const before = {
        title: ui.document.querySelector('[data-calendar-title]').textContent,
        month: ui.document.querySelector('[data-calendar-month]').value,
        year: ui.document.querySelector('[data-calendar-year]').value,
        html: grid.innerHTML,
    };

    ui.clickChip('task-1');
    await flush();
    ui.document.dispatchEvent(new ui.window.KeyboardEvent('keydown', {key: 'Escape', bubbles: true, cancelable: true}));

    assert.equal(ui.popover.hidden, true);
    assert.equal(ui.document.querySelector('[data-calendar-title]').textContent, before.title);
    assert.equal(ui.document.querySelector('[data-calendar-month]').value, before.month);
    assert.equal(ui.document.querySelector('[data-calendar-year]').value, before.year);
    assert.equal(grid.innerHTML, before.html, 'ตารางเดิมต้องอยู่ครบ ไม่ถูกวาดใหม่หรือเลื่อนเดือน');
});

test('เปลี่ยนเดือน (re-render ปฏิทิน) ต้องปิด Popover ที่เปิดค้างอยู่ก่อนเสมอ', async (t) => {
    const ui = await bootCalendar(t, {detailTemplate: '/my-tasks?view=calendar&amp;open_task=__ID__'});

    ui.clickChip('task-1');
    await flush();
    assert.equal(ui.popover.hidden, false);

    // ปฏิทินรื้อ chip เดิมทั้งหมดตอน re-render เดือนใหม่ ผ่าน event เดียวกับตอนแก้ไขงานสำเร็จ
    ui.document.dispatchEvent(new ui.window.CustomEvent('mytasks:changed'));

    assert.equal(ui.popover.hidden, true, 'anchor เดิมหลุดจาก DOM แล้ว Popover ต้องไม่ค้างเปิดชี้ไปที่ chip ที่ไม่มีอยู่แล้ว');
});

/*
 * modal รายวัน — แทนที่ popover ของปุ่ม "+N รายการ" เดิมทั้งชุด
 * ต้องพิสูจน์เส้นทางจริงตั้งแต่คลิกช่องวันที่จนเห็นรายการ ไม่ใช่แค่เรียกฟังก์ชันภายใน
 */
test('clicking a calendar date opens a modal listing that day items and Escape closes it', async (t) => {
    const ui = await bootCalendar(t, {detailTemplate: '/my-tasks?view=calendar&amp;open_task=__ID__'});
    const modal = ui.document.querySelector('[data-calendar-day-modal]');
    const today = isoDate(new Date());
    const trigger = ui.document.querySelector(`[data-calendar-grid] [data-calendar-day="${today}"]`);

    assert.equal(modal.hidden, true);
    assert.ok(trigger, 'วันที่มีรายการต้องมีปุ่มเปิดกล่องของวันนั้น');
    // วันที่ไม่มีรายการต้องไม่มีปุ่มให้โฟกัสหลอก ๆ
    // นับเป็น "วัน" ไม่ใช่ "ปุ่ม" เพราะวันเดียวกันมีทั้งปุ่มเต็มช่องและปุ่มสรุปมุมล่างขวาได้
    const daysWithTriggers = new Set(
        [...ui.document.querySelectorAll('[data-calendar-grid] [data-calendar-day]')].map((node) => node.dataset.calendarDay),
    );
    assert.deepEqual([...daysWithTriggers], [today]);

    trigger.dispatchEvent(new ui.window.MouseEvent('click', {bubbles: true, cancelable: true}));

    assert.equal(modal.hidden, false);
    assert.deepEqual(
        [...modal.querySelectorAll('[data-calendar-task]')].map((node) => node.dataset.calendarTask),
        ['task-1', 'meeting-1'],
    );
    assert.equal(ui.document.querySelector('[data-calendar-day-count]').textContent, 'ทั้งหมด 2 รายการ');
    // งานกับประชุมต้องอยู่คนละ section ไม่ปนกันในตารางเดียว
    assert.equal(ui.document.querySelectorAll('[data-calendar-day-task-list] [data-calendar-task]').length, 1);
    assert.equal(ui.document.querySelectorAll('[data-calendar-day-meeting-list] [data-calendar-task]').length, 1);
    assert.equal(
        ui.document.querySelector('[data-calendar-day-task-list] [data-calendar-task="task-1"]').querySelectorAll('.calendar-table__cell.is-due').length,
        2,
        'Modal รายวันต้องแสดงวันที่เริ่มและกำหนดส่งเป็นคนละช่อง',
    );
    assert.equal(ui.document.querySelector('[data-calendar-day-tasks]').hidden, false);
    assert.equal(ui.document.querySelector('[data-calendar-day-meetings]').hidden, false);

    ui.document.dispatchEvent(new ui.window.KeyboardEvent('keydown', {key: 'Escape', bubbles: true}));
    assert.equal(modal.hidden, true);
});

test('reopening the day modal does not accumulate rows or listeners', async (t) => {
    const ui = await bootCalendar(t, {detailTemplate: '/my-tasks?view=calendar&amp;open_task=__ID__'});
    const modal = ui.document.querySelector('[data-calendar-day-modal]');
    const today = isoDate(new Date());
    const click = () => ui.document
        .querySelector(`[data-calendar-grid] [data-calendar-day="${today}"]`)
        .dispatchEvent(new ui.window.MouseEvent('click', {bubbles: true, cancelable: true}));

    for (let round = 0; round < 3; round += 1) {
        click();
        assert.equal(modal.hidden, false);
        assert.equal(modal.querySelectorAll('[data-calendar-task]').length, 2);
        ui.document.querySelector('[data-calendar-day-close]')
            .dispatchEvent(new ui.window.MouseEvent('click', {bubbles: true, cancelable: true}));
        assert.equal(modal.hidden, true);
    }

    assert.equal(ui.document.body.classList.contains('modal-open'), false);
});

test('opening a task from the day modal closes it and hands over to the shared quick view', async (t) => {
    const ui = await bootCalendar(t, {detailTemplate: '/my-tasks?view=calendar&amp;open_task=__ID__'});
    const modal = ui.document.querySelector('[data-calendar-day-modal]');
    const today = isoDate(new Date());

    ui.document.querySelector(`[data-calendar-grid] [data-calendar-day="${today}"]`)
        .dispatchEvent(new ui.window.MouseEvent('click', {bubbles: true, cancelable: true}));

    const item = modal.querySelector('[data-calendar-task="task-1"]');
    const opened = new ui.window.MouseEvent('click', {bubbles: true, cancelable: true});
    item.dispatchEvent(opened);

    assert.equal(opened.defaultPrevented, true, 'ต้องกัน default ไม่ให้เบราว์เซอร์เปลี่ยนหน้า');
    // กล่องรายวันเป็นชั้นทึบ ต้องปิดก่อน Quick View จะไม่ไปอยู่ใต้ backdrop
    assert.equal(modal.hidden, true);
    assert.deepEqual(ui.requests, ['/my-tasks/calendar/quick-view/task/1']);

    await flush();
    assert.equal(ui.popover.hidden, false);
    assert.equal(ui.title.textContent, 'งานทดสอบปฏิทิน');
});
