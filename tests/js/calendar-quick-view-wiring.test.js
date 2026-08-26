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
                <select data-calendar-month>${months}</select>
                <select data-calendar-year></select>
                <div data-calendar-grid></div>
                <div data-calendar-popover hidden>
                    <strong data-calendar-popover-title></strong>
                    <div data-calendar-popover-list></div>
                </div>
                <script type="application/json" data-calendar-meetings>${JSON.stringify([meeting])}</script>
            </section>
            <div data-calendar-detail hidden></div>
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
        chip: (id) => env.document.querySelector(`[data-calendar-grid] [data-calendar-task="${id}"]`),
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

    const {node, event} = ui.clickChip('meeting-1');
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
