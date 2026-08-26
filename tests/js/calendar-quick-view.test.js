import test from 'node:test';
import assert from 'node:assert/strict';
import {click, mountDom, pressKey} from './helpers/dom.js';
import {
    clamp,
    computePopoverPlacement,
    createCalendarQuickView,
    isStaleResponse,
    nextRequestToken,
    shouldUseSheet,
} from '../../resources/js/pages/mytasks/calendar-quick-view.js';

/**
 * Calendar Event Popover — คลิกรายการแล้วดูข้อมูลย่อโดยไม่ออกจากหน้าปฏิทิน ไม่ใช่ modal
 *
 * โครงสร้าง shell ต้องตรงกับ resources/views/calendar/quick-view-modal.blade.php
 * และ chip ต้องตรงกับที่ calendar.js สร้าง (data-calendar-quick-view / data-calendar-detail-url)
 *
 * jsdom ไม่คำนวณ layout จริง (getBoundingClientRect คืนศูนย์เสมอ) การทดสอบตำแหน่งที่ต้องการ
 * ค่าจริงจึง mock getBoundingClientRect ของ element ที่เกี่ยวข้องเอง ส่วนฟังก์ชันคำนวณล้วน
 * (computePopoverPlacement ฯลฯ) ทดสอบตรงด้วยค่าที่กำหนดเองได้โดยไม่ต้องพึ่ง DOM เลย
 */

const SHELL = `
<div class="calendar-quick-view-popover" id="calendar-quick-view-popover" data-quick-view-popover hidden>
    <span class="calendar-quick-view-popover__caret" data-quick-view-caret aria-hidden="true"></span>
    <section class="calendar-quick-view-popover__card" role="dialog" aria-modal="false" aria-labelledby="calendar-quick-view-title" tabindex="-1">
        <header>
            <div>
                <span data-quick-view-kicker>ดูอย่างย่อ</span>
                <strong id="calendar-quick-view-title" data-quick-view-title>กำลังโหลด...</strong>
            </div>
            <button type="button" data-close-quick-view aria-label="ปิด">x</button>
        </header>
        <div class="calendar-quick-view-popover__body" data-quick-view-body aria-live="polite"></div>
        <a data-quick-view-detail href="#" hidden>ดูรายละเอียดทั้งหมด</a>
    </section>
</div>`;

const TASK_HTML = '<article class="quick-view" data-quick-view-type="task" data-quick-view-title-text="งานทดสอบ" data-quick-view-kicker-text="โปรเจกต์ทดสอบ"><p>เนื้อหางาน</p></article>';
const MEETING_HTML = '<article class="quick-view" data-quick-view-type="meeting" data-quick-view-title-text="ประชุมทดสอบ" data-quick-view-kicker-text="การประชุม"><p>เนื้อหาประชุม</p></article>';

/** คิวคำตอบที่ควบคุมเวลาได้ เพื่อจำลองคำขอที่กลับมาสลับลำดับ */
function deferredFetch() {
    const pending = [];
    const fetchImpl = (url) => new Promise((resolve, reject) => {
        pending.push({url, resolve, reject});
    });

    return {
        fetchImpl,
        calls: () => pending.map((entry) => entry.url),
        resolveAt: (index, html) => {
            pending[index].resolve({ok: true, text: async () => html});
            return Promise.resolve();
        },
        failAt: (index) => {
            pending[index].resolve({ok: false, text: async () => ''});
            return Promise.resolve();
        },
    };
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

/** ให้ rect คงที่แก่ element ตัวหนึ่ง เพราะ jsdom ไม่คำนวณ layout จริง */
function stubRect(element, rect) {
    element.getBoundingClientRect = () => ({left: 0, top: 0, right: 0, bottom: 0, width: 0, height: 0, ...rect});
}

function mountQuickView(t, {fetchImpl = null, innerWidth = 1024, innerHeight = 768} = {}) {
    const env = mountDom();
    t.after(env.cleanup);
    env.window.innerWidth = innerWidth;
    env.window.innerHeight = innerHeight;

    env.document.body.innerHTML = `
        <div class="mytasks-calendar" data-calendar>
            <button type="button" class="mytasks-calendar__task" data-calendar-task="task-1"
                    data-calendar-event-type="task"
                    data-calendar-quick-view="/my-tasks/calendar/quick-view/task/1"
                    data-calendar-detail-url="/my-tasks?view=calendar&open_task=1">งานทดสอบ</button>
            <a class="mytasks-calendar__task" data-calendar-task="meeting-1"
               data-calendar-event-type="meeting"
               href="/meetings/1"
               data-calendar-quick-view="/my-tasks/calendar/quick-view/meeting/1"
               data-calendar-detail-url="/meetings/1">ประชุมทดสอบ</a>
        </div>
        ${SHELL}`;

    const quickView = createCalendarQuickView(env.document, fetchImpl);
    const popover = env.document.querySelector('[data-quick-view-popover]');
    stubRect(popover, {width: 410, height: 220});

    return {
        ...env,
        quickView,
        popover,
        taskChip: env.document.querySelector('[data-calendar-task="task-1"]'),
        meetingChip: env.document.querySelector('[data-calendar-task="meeting-1"]'),
        body: env.document.querySelector('[data-quick-view-body]'),
        title: env.document.querySelector('[data-quick-view-title]'),
        detailLink: env.document.querySelector('[data-quick-view-detail]'),
        closeButton: env.document.querySelector('[data-close-quick-view]'),
    };
}

/* ---------- pure: token/stale ---------- */

test('token เดินหน้าเสมอและตรวจ stale ได้ถูกต้อง', () => {
    assert.equal(nextRequestToken(0), 1);
    assert.equal(nextRequestToken(undefined), 1);
    assert.equal(nextRequestToken(7), 8);
    assert.equal(isStaleResponse(3, 3), false);
    assert.equal(isStaleResponse(4, 3), true);
});

/* ---------- pure: positioning ---------- */

test('clamp คุมค่าให้อยู่ในขอบเขตเสมอ', () => {
    assert.equal(clamp(5, 0, 10), 5);
    assert.equal(clamp(-5, 0, 10), 0);
    assert.equal(clamp(50, 0, 10), 10);
    assert.equal(clamp(999, 20, 10), 20, 'max < min ต้องไม่ทำให้ค่าเพี้ยน');
});

test('shouldUseSheet ตัดสินที่ 640px', () => {
    assert.equal(shouldUseSheet(375), true);
    assert.equal(shouldUseSheet(640), true);
    assert.equal(shouldUseSheet(641), false);
    assert.equal(shouldUseSheet(1024), false);
});

test('วางใต้ Event ชิดซ้ายเมื่อมีที่ว่างพอ', () => {
    const placement = computePopoverPlacement({
        anchorRect: {left: 300, top: 200, right: 380, bottom: 220, width: 80, height: 20},
        width: 410,
        height: 300,
        viewportWidth: 1200,
        viewportHeight: 900,
    });

    assert.equal(placement.placement, 'bottom');
    assert.equal(placement.top, 228); // bottom(220) + offset(8)
    assert.equal(placement.left, 300);
    assert.equal(placement.caretHidden, false);
});

test('ด้านล่างไม่พอต้อง flip ขึ้นด้านบน', () => {
    const placement = computePopoverPlacement({
        anchorRect: {left: 300, top: 700, right: 380, bottom: 720, width: 80, height: 20},
        width: 410,
        height: 300,
        viewportWidth: 1200,
        viewportHeight: 800,
    });

    assert.equal(placement.placement, 'top');
    assert.equal(placement.top, 392); // top(700) - height(300) - offset(8)
});

test('ด้านขวาไม่พอต้อง shift ไปทางซ้ายแต่ไม่หลุดขอบ viewport', () => {
    const placement = computePopoverPlacement({
        anchorRect: {left: 1100, top: 200, right: 1180, bottom: 220, width: 80, height: 20},
        width: 410,
        height: 300,
        viewportWidth: 1200,
        viewportHeight: 900,
    });

    assert.equal(placement.left, 778); // 1200 - 410 - gutter(12)
    assert.ok(placement.left + 410 <= 1200 - 12);
});

test('viewport เล็กมากก็ต้องไม่หลุดออกนอกขอบ', () => {
    // width สอดคล้องกับ CSS จริง (width: min(410px, calc(100vw - 24px))) บนจอ 360px
    // คือ 336px พอดี — ฟังก์ชันนี้ไม่ต้องรับผิดชอบ input ที่กว้างเกินกว่า CSS จะผลิตออกมาได้
    const placement = computePopoverPlacement({
        anchorRect: {left: 10, top: 10, right: 60, bottom: 30, width: 50, height: 20},
        width: 336,
        height: 600,
        viewportWidth: 360,
        viewportHeight: 400,
    });

    // ความสูงของ popover (600) เกิน viewport (400) เอง เป็นหน้าที่ของ CSS max-height/scroll ภายใน
    // ไม่ใช่ของฟังก์ชันนี้ แต่ top ที่คำนวณต้องยังคงอยู่ในขอบเขต gutter เสมอ
    assert.equal(placement.top, 12);
    assert.ok(placement.left >= 12);
    assert.ok(placement.left + 336 <= 360 - 12 + 0.001);
});

/* ---------- DOM ---------- */

test('เปิด Popover ของงานได้และไม่ navigate ปฏิทินยังโต้ตอบได้ปกติ', async (t) => {
    const gate = deferredFetch();
    const ui = mountQuickView(t, {fetchImpl: gate.fetchImpl});

    ui.quickView.open(ui.taskChip.dataset.calendarQuickView, ui.taskChip, ui.taskChip.dataset.calendarDetailUrl);
    assert.equal(ui.popover.hidden, false);
    assert.match(ui.body.textContent, /กำลังโหลด/);

    await gate.resolveAt(0, TASK_HTML);
    await flush();

    assert.equal(ui.title.textContent, 'งานทดสอบ');
    assert.match(ui.body.innerHTML, /data-quick-view-type="task"/);
    assert.deepEqual(gate.calls(), ['/my-tasks/calendar/quick-view/task/1']);
});

test('ไม่มี backdrop และไม่ล็อก body เหมือน modal', async (t) => {
    const gate = deferredFetch();
    const ui = mountQuickView(t, {fetchImpl: gate.fetchImpl});

    ui.quickView.open('/quick/task/1', ui.taskChip, '/detail/task/1');

    assert.equal(ui.document.body.classList.contains('modal-open'), false);
    assert.equal(ui.popover.hasAttribute('data-modal-backdrop'), false);
    assert.equal(ui.popover.hasAttribute('inert'), false);
});

test('markup ของ shell เป็น dialog แบบ non-modal', (t) => {
    const ui = mountQuickView(t, {fetchImpl: deferredFetch().fetchImpl});
    const dialog = ui.popover.querySelector('[role="dialog"]');

    assert.ok(dialog);
    assert.equal(dialog.getAttribute('aria-modal'), 'false');
    assert.equal(dialog.getAttribute('aria-labelledby'), 'calendar-quick-view-title');
    assert.equal(ui.closeButton.getAttribute('aria-label'), 'ปิด');
    assert.equal(ui.body.getAttribute('aria-live'), 'polite');
});

test('เปิด Quick View ของประชุมใช้ shell เดียวกันกับงาน', async (t) => {
    const gate = deferredFetch();
    const ui = mountQuickView(t, {fetchImpl: gate.fetchImpl});

    ui.quickView.open(ui.meetingChip.dataset.calendarQuickView, ui.meetingChip, ui.meetingChip.dataset.calendarDetailUrl);
    await gate.resolveAt(0, MEETING_HTML);
    await flush();

    assert.equal(ui.title.textContent, 'ประชุมทดสอบ');
    assert.equal(ui.detailLink.textContent, 'ดูรายละเอียดทั้งหมด');
    assert.equal(ui.detailLink.getAttribute('href'), '/meetings/1');
});

test('detailUrl ของงานมาจาก chip ไม่ใช่จาก HTML ที่ endpoint ตอบกลับ', async (t) => {
    const gate = deferredFetch();
    const ui = mountQuickView(t, {fetchImpl: gate.fetchImpl});

    const hostileHtml = TASK_HTML.replace('<p>', '<p data-quick-view-detail-url="https://evil.example/steal">');
    ui.quickView.open('/quick/1', ui.taskChip, ui.taskChip.dataset.calendarDetailUrl);
    await gate.resolveAt(0, hostileHtml);
    await flush();

    assert.equal(ui.detailLink.getAttribute('href'), '/my-tasks?view=calendar&open_task=1');
});

test('detailUrl ของหน้า Admin ยังอยู่บนหน้า Admin เดิม', async (t) => {
    const gate = deferredFetch();
    const ui = mountQuickView(t, {fetchImpl: gate.fetchImpl});
    const adminDetail = '/admin/work-board/3/member/9?open_task=1';

    ui.quickView.open('/quick/1', ui.taskChip, adminDetail);
    await gate.resolveAt(0, TASK_HTML);
    await flush();

    assert.equal(ui.detailLink.getAttribute('href'), adminDetail);
    assert.doesNotMatch(ui.detailLink.getAttribute('href'), /^\/my-tasks/, 'Admin ต้องไม่ถูกพาไป /my-tasks');
});

test('คำตอบของรายการเก่าต้องไม่เขียนทับรายการที่คลิกทีหลัง', async (t) => {
    const gate = deferredFetch();
    const ui = mountQuickView(t, {fetchImpl: gate.fetchImpl});

    ui.quickView.open('/quick/task/1', ui.taskChip, '/detail/task/1');
    ui.quickView.open('/quick/meeting/1', ui.meetingChip, '/meetings/1');

    await gate.resolveAt(1, MEETING_HTML);
    await flush();
    await gate.resolveAt(0, TASK_HTML);
    await flush();

    assert.equal(ui.title.textContent, 'ประชุมทดสอบ', 'ต้องยังเป็นรายการล่าสุด');
    assert.match(ui.body.innerHTML, /data-quick-view-type="meeting"/);
});

test('ปิดหน้าต่างระหว่างโหลดแล้วคำตอบที่กลับมาต้องไม่เปิดขึ้นใหม่', async (t) => {
    const gate = deferredFetch();
    const ui = mountQuickView(t, {fetchImpl: gate.fetchImpl});

    ui.quickView.open('/quick/task/1', ui.taskChip, '/detail/task/1');
    ui.quickView.close();
    assert.equal(ui.popover.hidden, true);

    await gate.resolveAt(0, TASK_HTML);
    await flush();

    assert.equal(ui.popover.hidden, true, 'คำตอบที่ค้างต้องไม่เปิดหน้าต่างขึ้นมาเอง');
    assert.doesNotMatch(ui.body.innerHTML, /data-quick-view-type/);
});

test('loading แล้ว error แล้วกดลองใหม่จนสำเร็จ', async (t) => {
    const gate = deferredFetch();
    const ui = mountQuickView(t, {fetchImpl: gate.fetchImpl});

    ui.quickView.open('/quick/task/1', ui.taskChip, '/detail/task/1');
    assert.ok(ui.body.querySelector('[data-quick-view-loading]'));

    await gate.failAt(0);
    await flush();

    assert.ok(ui.body.querySelector('[data-quick-view-error]'));
    assert.match(ui.body.textContent, /โหลดข้อมูลไม่สำเร็จ/);
    assert.equal(ui.detailLink.hidden, true);

    click(ui.body.querySelector('[data-quick-view-retry]'));
    assert.deepEqual(gate.calls(), ['/quick/task/1', '/quick/task/1'], 'ลองใหม่ต้องยิงคำขอใหม่');

    await gate.resolveAt(1, TASK_HTML);
    await flush();
    assert.equal(ui.title.textContent, 'งานทดสอบ');
    assert.equal(ui.detailLink.hidden, false);
});

test('เปิดรายการใหม่ต้องล้าง error และเนื้อหาเดิมทิ้ง', async (t) => {
    const gate = deferredFetch();
    const ui = mountQuickView(t, {fetchImpl: gate.fetchImpl});

    ui.quickView.open('/quick/task/1', ui.taskChip, '/detail/task/1');
    await gate.failAt(0);
    await flush();
    assert.ok(ui.body.querySelector('[data-quick-view-error]'));

    ui.quickView.open('/quick/meeting/1', ui.meetingChip, '/meetings/1');
    assert.equal(ui.body.querySelector('[data-quick-view-error]'), null, 'error เดิมต้องหาย');
    assert.ok(ui.body.querySelector('[data-quick-view-loading]'), 'ต้องไม่ค้างเนื้อหาเดิมระหว่างโหลด');
});

test('Escape ปิดได้ และ focus กลับไปยัง event ที่กด', async (t) => {
    const gate = deferredFetch();
    const ui = mountQuickView(t, {fetchImpl: gate.fetchImpl});

    ui.taskChip.focus();
    ui.quickView.open('/quick/task/1', ui.taskChip, '/detail/task/1');
    await gate.resolveAt(0, TASK_HTML);
    await flush();

    pressKey(ui.document, 'Escape');
    assert.equal(ui.popover.hidden, true);
    assert.equal(ui.document.activeElement, ui.taskChip, 'ปิดแล้วต้องกลับไปที่ chip เดิม');
});

test('ปุ่ม × ปิดได้และคืน focus ให้ chip เดิม', async (t) => {
    const gate = deferredFetch();
    const ui = mountQuickView(t, {fetchImpl: gate.fetchImpl});

    ui.quickView.open('/quick/task/1', ui.taskChip, '/detail/task/1');
    await gate.resolveAt(0, TASK_HTML);
    await flush();

    click(ui.closeButton);
    assert.equal(ui.popover.hidden, true);
    assert.equal(ui.document.activeElement, ui.taskChip);
});

test('คลิกพื้นที่นอก Popover แล้วปิด', async (t) => {
    const gate = deferredFetch();
    const ui = mountQuickView(t, {fetchImpl: gate.fetchImpl});

    ui.quickView.open('/quick/task/1', ui.taskChip, '/detail/task/1');
    await gate.resolveAt(0, TASK_HTML);
    await flush();

    click(ui.document.body);
    assert.equal(ui.popover.hidden, true);
});

test('คลิก Event บนปฏิทินไม่ถูกนับเป็น "คลิกนอกกล่อง"', async (t) => {
    const gate = deferredFetch();
    const ui = mountQuickView(t, {fetchImpl: gate.fetchImpl});

    ui.quickView.open('/quick/task/1', ui.taskChip, '/detail/task/1');
    await gate.resolveAt(0, TASK_HTML);
    await flush();

    click(ui.meetingChip);
    assert.equal(ui.popover.hidden, false, 'การคลิก Event อื่นต้องปล่อยให้ calendar.js เป็นผู้ตัดสินใจ ไม่ใช่ปิดไปเฉย ๆ');
});

test('aria-expanded ของ trigger เปลี่ยนตามการเปิด/ปิด', async (t) => {
    const gate = deferredFetch();
    const ui = mountQuickView(t, {fetchImpl: gate.fetchImpl});
    ui.taskChip.setAttribute('aria-expanded', 'false');

    ui.quickView.open('/quick/task/1', ui.taskChip, '/detail/task/1');
    assert.equal(ui.taskChip.getAttribute('aria-expanded'), 'true');

    ui.quickView.close();
    assert.equal(ui.taskChip.getAttribute('aria-expanded'), 'false');
});

test('เปิดปิดหลายครั้งไม่เกิด listener ซ้ำและไม่มี id ซ้ำ', async (t) => {
    const gate = deferredFetch();
    const ui = mountQuickView(t, {fetchImpl: gate.fetchImpl});

    for (let round = 0; round < 3; round += 1) {
        ui.quickView.open('/quick/task/1', ui.taskChip, '/detail/task/1');
        await gate.resolveAt(round, TASK_HTML);
        await flush();
        click(ui.closeButton);
    }

    assert.equal(ui.document.querySelectorAll('[data-quick-view-popover]').length, 1);
    assert.equal(ui.document.querySelectorAll('#calendar-quick-view-title').length, 1, 'ห้ามเกิด id ซ้ำ');
    assert.equal(ui.document.querySelectorAll('[data-quick-view-type]').length, 0, 'ปิดแล้วเนื้อหาเดิมไม่ค้างซ้อน');
});

test('task-1 และ meeting-1 เป็นคนละรายการแม้เลขเท่ากัน', async (t) => {
    const gate = deferredFetch();
    const ui = mountQuickView(t, {fetchImpl: gate.fetchImpl});

    ui.quickView.open(ui.taskChip.dataset.calendarQuickView, ui.taskChip, ui.taskChip.dataset.calendarDetailUrl);
    await gate.resolveAt(0, TASK_HTML);
    await flush();
    assert.equal(ui.title.textContent, 'งานทดสอบ');

    ui.quickView.open(ui.meetingChip.dataset.calendarQuickView, ui.meetingChip, ui.meetingChip.dataset.calendarDetailUrl);
    await gate.resolveAt(1, MEETING_HTML);
    await flush();
    assert.equal(ui.title.textContent, 'ประชุมทดสอบ');
    assert.deepEqual(gate.calls(), [
        '/my-tasks/calendar/quick-view/task/1',
        '/my-tasks/calendar/quick-view/meeting/1',
    ]);
});

/* ---------- DOM: positioning wiring ---------- */

test('เปิดแล้ววางตำแหน่งจริงจากขนาด/พิกัดของ anchor', async (t) => {
    const gate = deferredFetch();
    const ui = mountQuickView(t, {fetchImpl: gate.fetchImpl, innerWidth: 1200, innerHeight: 900});
    stubRect(ui.taskChip, {left: 100, top: 100, right: 180, bottom: 120, width: 80, height: 20});

    ui.quickView.open('/quick/task/1', ui.taskChip, '/detail/task/1');

    assert.equal(ui.popover.style.left, '100px');
    assert.equal(ui.popover.style.top, '128px');
    assert.equal(ui.popover.dataset.placement, 'bottom');
});

test('จอแคบกว่า breakpoint เปลี่ยนเป็น bottom sheet แทนการอิงตำแหน่ง anchor', async (t) => {
    const gate = deferredFetch();
    const ui = mountQuickView(t, {fetchImpl: gate.fetchImpl, innerWidth: 375, innerHeight: 700});
    stubRect(ui.taskChip, {left: 20, top: 300, right: 100, bottom: 320, width: 80, height: 20});

    ui.quickView.open('/quick/task/1', ui.taskChip, '/detail/task/1');

    assert.equal(ui.popover.dataset.mode, 'sheet');
    assert.equal(ui.popover.hasAttribute('data-placement'), false);
});

test('resize ข้ามจุดตัด breakpoint สลับโหมดโดยไม่ปิด popover', async (t) => {
    const gate = deferredFetch();
    const ui = mountQuickView(t, {fetchImpl: gate.fetchImpl, innerWidth: 1200, innerHeight: 900});
    stubRect(ui.taskChip, {left: 100, top: 100, right: 180, bottom: 120, width: 80, height: 20});

    ui.quickView.open('/quick/task/1', ui.taskChip, '/detail/task/1');
    assert.notEqual(ui.popover.dataset.mode, 'sheet');

    ui.window.innerWidth = 375;
    ui.window.dispatchEvent(new ui.window.Event('resize'));

    assert.equal(ui.popover.hidden, false, 'resize ต้องไม่ปิด popover เอง');
    assert.equal(ui.popover.dataset.mode, 'sheet');
});

test('scroll นอกกล่องปิด popover แบบปลอดภัย แต่เลื่อนเนื้อหาภายในไม่ปิด', async (t) => {
    const gate = deferredFetch();
    const ui = mountQuickView(t, {fetchImpl: gate.fetchImpl});
    stubRect(ui.taskChip, {left: 100, top: 100, right: 180, bottom: 120, width: 80, height: 20});

    ui.quickView.open('/quick/task/1', ui.taskChip, '/detail/task/1');

    // capture-phase listener บน window ยังดักได้แม้ scroll ไม่ bubble ปกติ ปลายทางจริงคือ target ที่ dispatch
    ui.body.dispatchEvent(new ui.window.Event('scroll'));
    assert.equal(ui.popover.hidden, false, 'เลื่อนเนื้อหาในกล่องเองต้องไม่ปิด');

    ui.document.body.dispatchEvent(new ui.window.Event('scroll'));
    assert.equal(ui.popover.hidden, true, 'เลื่อนหน้านอกกล่องต้องปิดอย่างปลอดภัย');
});
