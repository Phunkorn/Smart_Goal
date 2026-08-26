import test from 'node:test';
import assert from 'node:assert/strict';
import {mountDom} from './helpers/dom.js';

/**
 * Regression: หลังเปลี่ยน Calendar Quick View จาก Modal เป็น Popover ปฏิทินทั้งหน้ากดอะไรไม่ได้เลย
 *
 * Root cause ที่พิสูจน์ได้จริง (ผ่านการโหลดหน้า /my-tasks?view=calendar จริงด้วย jsdom แล้วสืบ stack
 * trace ตอน popover.hidden ถูกสลับ): render() เดิมเรียก quickView?.close() แบบไม่มีเงื่อนไขทุกครั้ง
 * ที่ถูกเรียก — และ render() ถูกเรียกจาก ensureMeetingsForSelectedMonth() ทุกครั้งที่ fetch ประชุม
 * พื้นหลังสำเร็จ (เกิดขึ้นเองทุกครั้งที่เปิดหน้า/เปลี่ยนเดือน ไม่ต้องมีใครคลิกอะไรเลย) ผลคือถ้าผู้ใช้
 * เปิด Popover ในช่วงเวลาที่ fetch พื้นหลังนี้ยังไม่ตอบกลับ พอ fetch ตอบกลับ Popover จะถูกปิดตัวเอง
 * ทันที ทำให้ดูเหมือนคลิก Event บนปฏิทินไม่ได้ผลเลย
 *
 * เหตุผลที่ test เดิม (calendar-quick-view-wiring.test.js) ไม่จับบั๊กนี้: fetch mock เดิมมีแค่
 * `text()` ไม่มี `json()` — ensureMeetingsForSelectedMonth() เรียก response.json() แล้ว throw เข้า
 * catch เงียบ ๆ ทุกครั้ง เส้นทาง "fetch ประชุมสำเร็จแล้วเรียก render()" จึงไม่เคยถูก exercise เลย
 * ไฟล์นี้จึงต้องแยก mock fetch ตาม endpoint จริง (JSON สำหรับ meetings, HTML สำหรับ quick-view)
 * และต้องมีปุ่มควบคุมปฏิทินจริงครบ (วันนี้ ก่อนหน้า ถัดไป รีเซ็ต เดือน ปี) ซึ่ง fixture เดิมไม่มี
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

const TASK_HTML = '<article data-quick-view-type="task" data-quick-view-title-text="งานทดสอบ" data-quick-view-kicker-text="โปรเจกต์"><p>เนื้อหา</p></article>';

const months = Array.from({length: 12}, (_, index) => `<option value="${index}">${index + 1}</option>`).join('');

let fixtureCount = 0;

/**
 * fixture ที่ครบทุกปุ่มควบคุมปฏิทินจริง (วันนี้/ก่อนหน้า/ถัดไป/รีเซ็ต/เดือน/ปี) ต่างจาก fixture
 * เดิมของ calendar-quick-view-wiring.test.js ที่มีแค่ grid กับ select เท่านั้น
 *
 * fetch mock แยกตาม endpoint จริง: /calendar/meetings คืน JSON (เส้นทางที่มีบั๊ก),
 * /calendar/quick-view คืน HTML (เนื้อหา popover) — ผิดจากของเดิมที่ตอบแบบเดียวกันทุก endpoint
 */
async function bootCalendar(t, {includeQuickViewShell = true, meetingsDelayMs = 0} = {}) {
    const env = mountDom();
    t.after(env.cleanup);

    const today = new Date();
    const todayIso = [today.getFullYear(), String(today.getMonth() + 1).padStart(2, '0'), String(today.getDate()).padStart(2, '0')].join('-');

    const meeting = {
        id: 'meeting-1',
        type: 'meeting',
        title: 'ประชุมทดสอบ',
        location: 'ห้องประชุม',
        organizer: 'ผู้จัด',
        start: todayIso,
        due: todayIso,
        startTime: '10:00',
        endTime: '11:00',
        entityId: 1,
        quickViewUrl: '/my-tasks/calendar/quick-view/meeting/1',
        detailUrl: '/meetings/1',
        url: '/meetings/1',
    };

    env.document.body.innerHTML = `
        <div data-workspace>
            <div data-workspace-task-source hidden></div>
            <section data-calendar
                     data-task-quickview-template="/my-tasks/calendar/quick-view/task/__ID__"
                     data-task-detail-template="/my-tasks?view=calendar&open_task=__ID__"
                     data-meetings-endpoint="/my-tasks/calendar/meetings">
                <h2 data-calendar-title></h2>
                <button type="button" data-calendar-today>วันนี้</button>
                <button type="button" data-calendar-previous>ก่อนหน้า</button>
                <button type="button" data-calendar-next>ถัดไป</button>
                <button type="button" data-calendar-reset>รีเซ็ต</button>
                <select data-calendar-month>${months}</select>
                <select data-calendar-year></select>
                <span data-calendar-loading hidden></span>
                <div data-calendar-grid></div>
                <div data-calendar-popover hidden>
                    <strong data-calendar-popover-title></strong>
                    <div data-calendar-popover-list></div>
                </div>
                <script type="application/json" data-calendar-meetings>${JSON.stringify([meeting])}</script>
            </section>
            <div data-calendar-detail hidden></div>
            ${includeQuickViewShell ? POPOVER_SHELL : ''}
        </div>
        <div data-toast></div>`;

    const requests = [];
    globalThis.fetch = (url) => {
        const href = String(url);
        requests.push(href);

        if (href.includes('/calendar/meetings')) {
            return new Promise((resolve) => {
                setTimeout(() => resolve({
                    ok: true,
                    json: async () => ({meetings: [meeting]}),
                    text: async () => '',
                }), meetingsDelayMs);
            });
        }

        return Promise.resolve({ok: true, text: async () => TASK_HTML, json: async () => ({})});
    };
    t.after(() => { delete globalThis.fetch; });

    fixtureCount += 1;
    await import(`../../resources/js/pages/mytasks/calendar.js?regression=${fixtureCount}`);

    return {
        ...env,
        requests,
        popover: env.document.querySelector('[data-quick-view-popover]'),
        title: () => env.document.querySelector('[data-calendar-title]').textContent,
        chip: (id) => env.document.querySelector(`[data-calendar-grid] [data-calendar-task="${id}"]`),
        click(selectorOrNode) {
            const node = typeof selectorOrNode === 'string' ? env.document.querySelector(selectorOrNode) : selectorOrNode;
            assert.ok(node, `ไม่พบ element: ${selectorOrNode}`);
            const event = new env.window.MouseEvent('click', {bubbles: true, cancelable: true});
            node.dispatchEvent(event);
            return event;
        },
        change(selector) {
            const node = env.document.querySelector(selector);
            assert.ok(node, `ไม่พบ element: ${selector}`);
            node.dispatchEvent(new env.window.Event('change', {bubbles: true}));
        },
    };
}

const flush = (ms = 0) => new Promise((resolve) => setTimeout(resolve, ms));

/* ---------- 1. initial state ---------- */

test('1) ตอนเริ่มต้น Popover ต้องถูกซ่อนและไม่อยู่ใน open state', async (t) => {
    const ui = await bootCalendar(t);

    assert.equal(ui.popover.hidden, true);
    assert.equal(ui.popover.hasAttribute('data-mode'), false);
    assert.equal(ui.document.body.classList.contains('modal-open'), false);

    await flush(30); // ปล่อยให้ fetch ประชุมตอน page-load เคลียร์ตัวก่อนจบ test
});

/* ---------- 2-5. init ไม่ขัดขวางปุ่มควบคุมปฏิทิน ---------- */

test('2-5) initialization ของ Quick View ไม่ขัดขวางปุ่มควบคุมปฏิทินอื่น (วันนี้/ก่อนหน้า/ถัดไป/เดือน/ปี)', async (t) => {
    const ui = await bootCalendar(t);
    await flush();

    const before = ui.title();
    ui.click('[data-calendar-next]');
    assert.notEqual(ui.title(), before, 'ปุ่มถัดไปต้องเปลี่ยนเดือน');

    const afterNext = ui.title();
    ui.click('[data-calendar-previous]');
    assert.notEqual(ui.title(), afterNext, 'ปุ่มก่อนหน้าต้องเปลี่ยนเดือน');

    ui.click('[data-calendar-today]');
    assert.equal(ui.title(), before, 'ปุ่มวันนี้ต้องกลับมาเดือนปัจจุบัน');

    ui.click('[data-calendar-next]');
    const monthSelect = ui.document.querySelector('[data-calendar-month]');
    monthSelect.value = String((Number(monthSelect.value) + 2) % 12);
    ui.change('[data-calendar-month]');
    assert.notEqual(ui.title(), before, 'เปลี่ยนตัวเลือกเดือนต้องทำงาน');

    ui.click('[data-calendar-reset]');
    assert.equal(ui.title(), before, 'ปุ่มรีเซ็ตต้องกลับมาเดือนเริ่มต้น');
    await flush(30);
});

/* ---------- 6. capture listener ต้องไม่ preventDefault/stopPropagation กับปุ่มที่ไม่ใช่ event ---------- */

test('6) document capture listener ต้องไม่ preventDefault หรือขวางปุ่มที่ไม่ใช่ Task/Meeting chip', async (t) => {
    const ui = await bootCalendar(t);
    await flush();

    const beforeTitle = ui.title();
    const event = ui.click('[data-calendar-next]');

    assert.equal(event.defaultPrevented, false, 'ปุ่มเปลี่ยนเดือนไม่ใช่ลิงก์ ไม่จำเป็นต้อง preventDefault');
    assert.notEqual(ui.title(), beforeTitle, 'ปุ่มต้องยังทำงานได้ปกติ (handler ของ calendar.js ต้องได้รับ event)');
    await flush(30);
});

/* ---------- 7. เปิด Popover แล้วคลิกปุ่มเปลี่ยนเดือนต้องปิด Popover และปุ่มยังทำงานในคลิกเดียวกัน ---------- */

test('7) เมื่อ Popover เปิดอยู่ คลิกปุ่มเปลี่ยนเดือนต้องปิด Popover และเปลี่ยนเดือนในคลิกเดียวกัน', async (t) => {
    const ui = await bootCalendar(t);
    await flush();

    ui.click(ui.chip('meeting-1'));
    await flush();
    assert.equal(ui.popover.hidden, false, 'Popover ต้องเปิดก่อน');

    const beforeTitle = ui.title();
    ui.click('[data-calendar-next]');

    assert.equal(ui.popover.hidden, true, 'คลิกปุ่มเปลี่ยนเดือนต้องปิด Popover');
    assert.notEqual(ui.title(), beforeTitle, 'ปุ่มเปลี่ยนเดือนต้องยังทำงานในคลิกเดียวกันนั้นเอง ไม่ใช่ถูกกลืนไป');
    await flush(30);
});

/**
 * ---------- Root cause โดยตรง: fetch ประชุมพื้นหลังสำเร็จ "ระหว่าง" Popover เปิดอยู่ ต้องไม่ปิด Popover ----------
 *
 * นี่คือ regression test ที่ตรงกับบั๊กจริงที่สุด: จำลองผู้ใช้เปิด Popover ในช่วงเวลาที่
 * ensureMeetingsForSelectedMonth() ยังไม่ตอบกลับ (meetingsDelayMs หน่วงไว้ให้คลิกได้ทัน)
 * แล้วปล่อยให้ fetch ตอบกลับทีหลัง ต้องไม่ทำให้ Popover ที่เพิ่งเปิดถูกปิดไปเอง
 */
test('root cause: fetch ประชุมพื้นหลังสำเร็จระหว่าง Popover เปิดอยู่ต้องไม่ปิด Popover ทิ้ง', async (t) => {
    const ui = await bootCalendar(t, {meetingsDelayMs: 30});

    // ตอนนี้ fetch ประชุมของเดือนปัจจุบันกำลังค้างอยู่ (หน่วง 30ms) — เปิด Popover ระหว่างนี้
    ui.click(ui.chip('meeting-1'));
    await flush(5);
    assert.equal(ui.popover.hidden, false, 'Popover ต้องเปิดได้ระหว่างที่ fetch ประชุมพื้นหลังยังไม่ตอบกลับ');

    // รอให้ fetch ประชุมพื้นหลังตอบกลับและ render() ถูกเรียกจากมัน
    await flush(50);

    assert.equal(ui.popover.hidden, false, 'fetch ประชุมพื้นหลังตอบกลับสำเร็จต้องไม่ปิด Popover ที่ผู้ใช้เพิ่งเปิด (นี่คือบั๊กที่พบจริง)');
});

/* ---------- 8. เมื่อ Popover ปิด shell ต้องไม่มี full-viewport hit area ---------- */

test('8) เมื่อ Popover ปิด shell ต้องไม่รับ click ใด ๆ เลย (ไม่มี full-viewport hit area แอบอยู่)', async (t) => {
    const ui = await bootCalendar(t);
    await flush();
    assert.equal(ui.popover.hidden, true);

    let popoverReceivedClick = false;
    ui.popover.addEventListener('click', () => { popoverReceivedClick = true; });

    // คลิกทั่วปฏิทิน รวมถึงพื้นที่ grid ว่าง ๆ และปุ่มต่าง ๆ — ต้องไม่มีครั้งไหนที่ event ไป "ผ่าน" popover
    ui.click('[data-calendar-grid]');
    ui.click('[data-calendar-today]');
    ui.click('[data-calendar-next]');

    assert.equal(popoverReceivedClick, false, 'popover ที่ hidden ต้องไม่อยู่ใน event path ของคลิกอื่นเลย');
    await flush(30);
});

/* ---------- 9. เปิด/ปิดหลายครั้งไม่มี listener ซ้ำ ---------- */

test('9) เปิด/ปิด Popover หลายครั้งไม่มี listener ซ้ำและไม่มี element ซ้ำ', async (t) => {
    const ui = await bootCalendar(t);
    await flush();

    for (let round = 0; round < 4; round += 1) {
        ui.click(ui.chip('meeting-1'));
        await flush();
        assert.equal(ui.popover.hidden, false);
        ui.document.dispatchEvent(new ui.window.KeyboardEvent('keydown', {key: 'Escape', bubbles: true, cancelable: true}));
        assert.equal(ui.popover.hidden, true);
    }

    assert.equal(ui.document.querySelectorAll('[data-quick-view-popover]').length, 1);
    assert.equal(ui.requests.filter((u) => u.includes('/calendar/quick-view/meeting/1')).length, 4, 'ต้องยิง fetch ใหม่ทุกรอบ ไม่ถูกดักไว้จาก listener ค้าง');
    await flush(30);
});

/* ---------- 10. เปลี่ยนเดือนหลายครั้งไม่ทำให้ initializer ซ้ำ ---------- */

test('10) เปลี่ยนเดือนหลายครั้งไม่ทำให้เกิด initializer ซ้ำหรือพฤติกรรมเพี้ยนสะสม', async (t) => {
    const ui = await bootCalendar(t);
    await flush();

    for (let i = 0; i < 6; i += 1) ui.click('[data-calendar-next]');
    await flush();

    // ยังต้องเหลือ popover เดียว และเปิดได้ปกติหลังเปลี่ยนเดือนไปมาหลายรอบ
    assert.equal(ui.document.querySelectorAll('[data-quick-view-popover]').length, 1);
    ui.click(ui.chip('meeting-1'));
    await flush();
    assert.equal(ui.popover.hidden, false, 'ต้องยังเปิด Popover ได้ตามปกติหลังเปลี่ยนเดือนไปมาหลายครั้ง');
    await flush(30);
});

/* ---------- 11. Task และ Meeting chip ยังเปิด Popover ---------- */

test('11) Meeting chip ยังเปิด Popover ได้ตามปกติ', async (t) => {
    const ui = await bootCalendar(t);
    await flush();

    ui.click(ui.chip('meeting-1'));
    await flush();
    assert.equal(ui.popover.hidden, false);
    assert.deepEqual(ui.requests.filter((u) => u.includes('quick-view')), ['/my-tasks/calendar/quick-view/meeting/1']);
});

/* ---------- 12. JS init failure ของ Quick View ไม่ทำลาย Calendar หลัก ---------- */

test('12) ไม่มี Quick View shell ในหน้า (initialization ล้มเหลว) ปฏิทินหลักต้องยังคลิกได้ปกติ', async (t) => {
    const ui = await bootCalendar(t, {includeQuickViewShell: false});
    await flush();

    assert.equal(ui.document.querySelector('[data-quick-view-popover]'), null);

    const before = ui.title();
    ui.click('[data-calendar-next]');
    assert.notEqual(ui.title(), before, 'ปุ่มเปลี่ยนเดือนต้องยังทำงานแม้ไม่มี Quick View shell');

    ui.click('[data-calendar-today]');
    ui.click('[data-calendar-previous]');
    ui.click('[data-calendar-reset]');
    // ไม่มี exception ใด ๆ ตลอดทั้งหมดนี้ถือว่าผ่าน (จะไม่ผ่าน test ถ้ามี unhandled throw ทำให้ process ล้ม)
    await flush(30);
});
