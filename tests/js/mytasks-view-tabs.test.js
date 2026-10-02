import test from 'node:test';
import assert from 'node:assert/strict';
import {mountDom} from './helpers/dom.js';

/*
 * ประชุมของฟิกซ์เจอร์ต้อง "ยังไม่เลิก" เสมอ ไม่ว่าเทสต์จะถูกรันตอนกี่โมง
 *
 * ปฏิทินตัดประชุมที่เลิกไปแล้วออก ฟิกซ์เจอร์ที่ตรึงเวลาไว้ (เช่น 10:00-11:00 ของวันนี้)
 * จึงหายไปเองเมื่อรันหลัง 11 โมง และการบวกเวลาไปข้างหน้าก็ไม่ปลอดภัย เพราะถ้าบวกแล้ว
 * ข้ามเที่ยงคืน เวลาที่ได้จะกลายเป็นช่วงเช้าของ "วันเดียวกัน" ซึ่งเป็นอดีตไปแล้ว
 *
 * จึงให้ประชุมเริ่ม ณ ตอนนี้และเลิกสิ้นวัน — อยู่ระหว่างดำเนินการเสมอ
 */
const nowClock = () => {
    const at = new Date();

    return `${String(at.getHours()).padStart(2, '0')}:${String(at.getMinutes()).padStart(2, '0')}`;
};

/**
 * Regression: ปฏิทินคลิกอะไรไม่ได้เลย เพราะ "ตัวครอบ panel" ถูกนับเป็นปุ่มสลับมุมมอง
 *
 * Root cause จริง (พิสูจน์บนเบราว์เซอร์ด้วย stack trace ตอนพบบั๊ก):
 *   attribute `data-view` ถูกใช้สองความหมายในหน้า Task Workspace
 *     1) ปุ่มสลับมุมมองบนแถบ .notion-viewbar  → <button role="tab" data-view="calendar">
 *     2) สถานะมุมมองปัจจุบันของตัวครอบ panel → <section class="notion-database" data-view="calendar">
 *   mytasks-views.js เดิมเลือกด้วย querySelectorAll('[data-view]') เปล่า ๆ จึงเหมาะกับ (1)
 *   แต่ไปจับ (2) มาด้วย แล้วผูก click listener ของ "ปุ่มสลับมุมมอง" ไว้กับ section ที่ครอบ
 *   ปฏิทินทั้งอัน ผลคือคลิกอะไรก็ตามข้างใน (chip งาน/ประชุม, ปุ่มเปลี่ยนเดือน, ช่องวันที่)
 *   จะ bubble ขึ้นไปโดน listener นั้น → selectView() → applyView() → dispatch
 *   'mytasks:viewchange' ทุกครั้ง ซึ่ง calendar.js ตอบสนองด้วยการ re-render ทั้งที่ไม่มีมุมมองไหนเปลี่ยนจริง
 *
 * เหตุผลที่ test เดิมไม่จับ: ทุก fixture ของปฏิทินโหลดเฉพาะ calendar.js และไม่เคยมี
 * <section class="notion-database" data-view> ครอบ ทั้งไม่เคยโหลด mytasks-views.js
 * ซึ่งเป็นตัวผูก listener ที่ผิด — บั๊กจึงอยู่นอกขอบเขตของ fixture เดิมทั้งหมด
 *
 * หมายเหตุ: ตอนพบบั๊กครั้งแรก อาการที่สังเกตได้คือ Quick View popover ที่เพิ่งเปิดถูกปิดทิ้ง
 * ทันทีในเฟรมเดียวกัน แต่ Quick View ถูกถอดออกจากระบบไปแล้ว การตรวจตอนนี้จึงจับที่ต้นเหตุ
 * โดยตรง (จำนวนครั้งที่ mytasks:viewchange ยิง) แทนการอิงผลข้างเคียงของฟีเจอร์ที่ไม่มีอยู่แล้ว
 */

const VIEWBAR = `
<nav class="notion-viewbar" role="tablist" aria-label="รูปแบบการแสดงงาน">
    <button type="button" data-view="table" role="tab" aria-selected="false">ตาราง</button>
    <button type="button" data-view="board" role="tab" aria-selected="false">บอร์ด</button>
    <button type="button" data-view="calendar" role="tab" aria-selected="true" class="active">ปฏิทิน</button>
    <a href="/meetings" data-view="meeting" data-view-navigate role="tab" aria-selected="false">ประชุม</a>
</nav>`;

const months = Array.from({length: 12}, (_, i) => `<option value="${i}">${i + 1}</option>`).join('');

let fixtureCount = 0;

/**
 * โครงสร้างต้องสะท้อนหน้าจริง: viewbar อยู่นอก .notion-database และ .notion-database
 * เป็นตัวครอบ panel ทั้งหมด (รวมปฏิทิน) พร้อม data-view เป็น "สถานะ" ไม่ใช่ปุ่ม
 */
async function boot(t, {withCalendar = true, viewHistory = true, statusFilterHidden = false} = {}) {
    const env = mountDom();
    t.after(env.cleanup);

    const today = new Date();
    const iso = [today.getFullYear(), String(today.getMonth() + 1).padStart(2, '0'), String(today.getDate()).padStart(2, '0')].join('-');
    const meeting = {
        id: 'meeting-1', type: 'meeting', title: 'ประชุมทดสอบ', location: 'ห้องประชุม', organizer: 'ผู้จัด',
        start: iso, due: iso, startTime: nowClock(), endTime: '23:59', entityId: 1,
        url: '/meetings/1',
    };

    env.document.body.innerHTML = `
        <div data-workspace data-context="user"${viewHistory ? ' data-view-history="true"' : ''}>
            ${VIEWBAR}
            <div class="notion-filter" data-board-status-filter data-sg-select${statusFilterHidden ? ' hidden' : ''}>
                <select data-filter><option value="">ทุกสถานะ</option></select>
            </div>
            <section class="notion-database" data-view="calendar">
                <div data-workspace-task-source hidden>
                    <div data-row data-id="1" data-topic="งานทดสอบ" data-project="โปรเจกต์"
                         data-status="2" data-priority="2" data-start="${iso}" data-due="${iso}"></div>
                </div>
                <div data-view-panel="table"></div>
                <div data-view-panel="calendar">
                    <section data-calendar data-meetings-endpoint="/my-tasks/calendar/meetings">
                        <h2 data-calendar-title></h2>
                        <button type="button" data-calendar-today>วันนี้</button>
                        <button type="button" data-calendar-previous>ก่อนหน้า</button>
                        <button type="button" data-calendar-next>ถัดไป</button>
                        <button type="button" data-calendar-reset>รีเซ็ต</button>
                        <select data-calendar-month>${months}</select>
                        <select data-calendar-year></select>
                        <span data-calendar-loading hidden></span>
                        <div data-calendar-grid></div>
                        <div data-calendar-agenda>
                            <strong data-calendar-today-count></strong>
                            <div class="calendar-table"><div class="calendar-table__body" data-calendar-today-list></div></div>
                            <p data-calendar-today-empty></p>
                            <h3 data-calendar-month-agenda-title></h3>
                            <strong data-calendar-month-count></strong>
                            <div class="calendar-table"><div class="calendar-table__body" data-calendar-month-list></div></div>
                            <p data-calendar-month-empty></p>
                        </div>
                        <script type="application/json" data-calendar-meetings>${JSON.stringify([meeting])}</script>
                    </section>
                    <!-- ต้องมีครบทุก data-calendar-detail-* เพราะไม่มี Quick View แล้ว คลิก chip งานจึงเปิดกล่องนี้ตรง ๆ -->
                    <div data-calendar-detail hidden>
                        <h2 data-calendar-detail-title></h2>
                        <small data-calendar-detail-project></small>
                        <dd data-calendar-detail-status></dd>
                        <dd data-calendar-detail-priority></dd>
                        <dd data-calendar-detail-start></dd>
                        <dd data-calendar-detail-due></dd>
                        <dd data-calendar-detail-assignee></dd>
                        <dd data-calendar-detail-collaborators></dd>
                        <div data-calendar-detail-attachments></div>
                        <button type="button" data-calendar-detail-close></button>
                    </div>
                    <div data-calendar-day-modal hidden>
                        <h2 data-calendar-day-title></h2>
                        <button type="button" data-calendar-day-close></button>
                        <section data-calendar-day-tasks hidden><b data-calendar-day-task-count></b><div data-calendar-day-task-list></div></section>
                        <section data-calendar-day-meetings hidden><b data-calendar-day-meeting-count></b><div data-calendar-day-meeting-list></div></section>
                        <small data-calendar-day-count></small>
                    </div>
                    <div data-calendar-agenda-modal hidden><div data-calendar-agenda-modal-list></div></div>
                </div>
            </section>
        </div>
        <div data-toast></div>`;

    globalThis.fetch = (url) => {
        const href = String(url);
        if (href.includes('/calendar/meetings')) {
            return Promise.resolve({ok: true, json: async () => ({meetings: [meeting]}), text: async () => ''});
        }
        return Promise.resolve({ok: true, text: async () => '', json: async () => ({})});
    };
    t.after(() => { delete globalThis.fetch; });

    fixtureCount += 1;
    await import(`../../resources/js/mytasks-views.js?viewtabs=${fixtureCount}`);
    if (withCalendar) await import(`../../resources/js/pages/mytasks/calendar.js?viewtabs=${fixtureCount}`);

    return {
        ...env,
        database: env.document.querySelector('.notion-database'),
        statusFilter: env.document.querySelector('[data-board-status-filter]'),
        detail: env.document.querySelector('[data-calendar-detail]'),
        title: () => env.document.querySelector('[data-calendar-title]').textContent,
        // ช่องวันที่สรุปเป็นจำนวนงานต่อความสำคัญแล้ว แถวที่คลิกได้อยู่ในการ์ดสรุปใต้ปฏิทิน
        chip: () => env.document.querySelector('[data-calendar-agenda] [data-calendar-task]'),
        // เจาะจง chip ของงาน (ไม่ใช่ประชุม) เพราะลำดับการ์ดขึ้นกับการจัดเรียงของวาระวันนี้
        taskChip: () => env.document.querySelector('[data-calendar-agenda] [data-calendar-task="task-1"]'),
        click: (node) => {
            const el = typeof node === 'string' ? env.document.querySelector(node) : node;
            assert.ok(el, `ไม่พบ element: ${node}`);
            el.dispatchEvent(new env.window.MouseEvent('click', {bubbles: true, cancelable: true}));
        },
    };
}

const flush = (ms = 0) => new Promise((r) => setTimeout(r, ms));

test('ตัวครอบ .notion-database[data-view] ต้องไม่ถูกนับเป็นปุ่มสลับมุมมอง', async (t) => {
    const ui = await boot(t, {withCalendar: false});
    await flush(30);

    // applyView() ใส่ active/aria-selected ให้ "ทุกตัวที่ถูกนับเป็น tab" — ตัวครอบต้องไม่โดน
    assert.equal(ui.database.classList.contains('active'), false,
        'section ตัวครอบไม่ใช่ปุ่ม จึงต้องไม่ได้รับ class active');
    assert.equal(ui.database.getAttribute('aria-selected'), null,
        'section ตัวครอบไม่ใช่ role=tab จึงต้องไม่มี aria-selected');
});

test('คลิกภายในตัวครอบ panel ต้องไม่ยิง mytasks:viewchange (ไม่ถูกเข้าใจผิดว่าเป็นการกดปุ่มสลับมุมมอง)', async (t) => {
    const ui = await boot(t, {withCalendar: false});
    await flush(30);

    let viewchanges = 0;
    ui.document.addEventListener('mytasks:viewchange', () => { viewchanges += 1; });

    ui.click('[data-view-panel="calendar"]');
    ui.click('.notion-database');
    ui.click('[data-workspace-task-source]');

    assert.equal(viewchanges, 0, 'คลิกในพื้นที่เนื้อหาต้องไม่ถูกตีความเป็นการสลับมุมมอง');
});

test('กดปุ่มสลับมุมมองจริงบนแถบ viewbar ต้องยังยิง mytasks:viewchange ตามปกติ', async (t) => {
    const ui = await boot(t, {withCalendar: false});
    await flush(30);

    const seen = [];
    ui.document.addEventListener('mytasks:viewchange', (e) => seen.push(e.detail.view));

    ui.click('[role="tab"][data-view="table"]');

    assert.deepEqual(seen, ['table'], 'ปุ่มบน viewbar ต้องยังทำงานเหมือนเดิม');
    assert.equal(ui.database.dataset.view, 'table');
});

test('root cause: คลิก chip บนปฏิทินต้องไม่ถูกเข้าใจผิดว่าเป็นปุ่มสลับมุมมอง', async (t) => {
    const ui = await boot(t);
    await flush(50);

    const chip = ui.taskChip();
    assert.ok(chip, 'ต้องมี chip ของงานบนปฏิทิน');

    let viewchanges = 0;
    ui.document.addEventListener('mytasks:viewchange', () => { viewchanges += 1; });

    ui.click(chip);
    await flush(50);

    assert.equal(viewchanges, 0,
        'คลิก chip ต้องไม่ถูกเข้าใจผิดว่าเป็นปุ่มสลับมุมมอง — บั๊กเดิมคือ mytasks:viewchange ยิงผิดแล้วลบสถานะที่เพิ่งเปิดทิ้งในเฟรมเดียวกัน');
    assert.equal(ui.detail.hidden, false, 'คลิก chip ของงานต้องเปิดกล่องรายละเอียดงานจริง ๆ (ไม่มี Quick View แล้ว)');
});

test('ปุ่มควบคุมปฏิทินยังทำงานครบหลังคลิก chip', async (t) => {
    const ui = await boot(t);
    await flush(50);

    const before = ui.title();
    ui.click(ui.taskChip());
    await flush(50);
    assert.equal(ui.detail.hidden, false, 'เปิดกล่องรายละเอียดงานก่อน');

    ui.click('[data-calendar-next]');
    assert.notEqual(ui.title(), before, 'ปุ่มเปลี่ยนเดือนต้องยังทำงานได้ตามปกติหลังเปิดกล่องรายละเอียดงาน');

    ui.click('[data-calendar-today]');
    assert.equal(ui.title(), before, 'ปุ่มวันนี้ต้องกลับมาเดือนปัจจุบัน');
    await flush(30);
});

/**
 * View state ฝั่ง server: หน้าที่ resolve ?view= เองต้องประกาศ data-view-history
 * เพื่อให้ deep link / refresh / back-forward ทำงาน ส่วนหน้าที่ไม่ได้อ่าน ?view=
 * ต้องไม่ถูกเขียน History เปื้อน — เดิมกฎนี้ hardcode ไว้ที่ data-context="user"
 * ซึ่งกันหน้า Admin Member Workspace ที่ตอนนี้ resolve ?view= แล้วออกไปด้วย
 */
test('ตัวกรองสถานะกลับมาเมื่อกลับจากหน้าประชุม และไม่โผล่ในปฏิทิน', async (t) => {
    /*
     * มุมมองประชุมเป็นการโหลดหน้าใหม่จาก server หน้านั้นจึงส่งตัวกรองมาแบบ hidden
     * ส่วนการกดกลับมาที่ตาราง/บอร์ดเป็นการสลับฝั่ง client ล้วน ๆ
     *
     * ของเดิม applyView() สลับ hidden ให้เฉพาะ toolbar ไม่เคยแตะตัวกรอง
     * ผู้ใช้จึงเสียตัวกรองไปจนกว่าจะรีโหลดหน้าเอง — เริ่มเทสต์จากสภาพนั้นตรง ๆ
     */
    const ui = await boot(t, {withCalendar: false, statusFilterHidden: true});
    await flush(30);

    ui.click('[role="tab"][data-view="table"]');
    assert.equal(ui.statusFilter.hidden, false, 'กลับมาที่ตารางแล้วตัวกรองต้องกลับมาด้วย');

    ui.click('[role="tab"][data-view="board"]');
    assert.equal(ui.statusFilter.hidden, false, 'บอร์ดก็ต้องมีตัวกรอง');

    // ปฏิทินจัดวางงานตามวัน การกรองสถานะทำให้ช่องวันหายไปเฉย ๆ โดยไม่บอกอะไร
    ui.click('[role="tab"][data-view="calendar"]');
    assert.equal(ui.statusFilter.hidden, true, 'ปฏิทินต้องไม่มีตัวกรองสถานะ');

    ui.click('[role="tab"][data-view="table"]');
    assert.equal(ui.statusFilter.hidden, false, 'สลับกลับไปกลับมาต้องยังถูกต้อง');
});

test('หน้าที่ประกาศ data-view-history ต้องเขียน ?view= ลง History เมื่อผู้ใช้สลับมุมมอง', async (t) => {
    const ui = await boot(t, {withCalendar: false});
    await flush(30);

    // replaceState ตอน init ต้องปักมุมมองตั้งต้นไว้เป็นจุดอ้างอิงของ Back/Forward
    assert.equal(new URL(ui.window.location.href).searchParams.get('view'), 'calendar');

    const before = ui.window.history.length;
    ui.click('[role="tab"][data-view="table"]');

    assert.equal(new URL(ui.window.location.href).searchParams.get('view'), 'table');
    assert.ok(ui.window.history.length > before, 'การสลับมุมมองต้องสร้าง History entry ให้ย้อนกลับได้');

    // กดปุ่มเดิมซ้ำต้องไม่สร้าง entry ซ้อน
    const afterFirst = ui.window.history.length;
    ui.click('[role="tab"][data-view="table"]');
    assert.equal(ui.window.history.length, afterFirst);
});

test('หน้าที่ไม่ประกาศ data-view-history ต้องสลับมุมมองได้โดยไม่แตะ URL เลย', async (t) => {
    const ui = await boot(t, {withCalendar: false, viewHistory: false});
    await flush(30);

    assert.equal(new URL(ui.window.location.href).searchParams.has('view'), false,
        'หน้าที่ไม่ได้อ่าน ?view= ต้องไม่ถูก replaceState ใส่ query ให้');

    const seen = [];
    ui.document.addEventListener('mytasks:viewchange', (e) => seen.push(e.detail.view));
    ui.click('[role="tab"][data-view="table"]');

    assert.deepEqual(seen, ['table'], 'การสลับมุมมองฝั่ง client ต้องยังทำงาน');
    assert.equal(ui.database.dataset.view, 'table');
    assert.equal(new URL(ui.window.location.href).searchParams.has('view'), false);
});

test('ปุ่มมุมมองที่ต้องโหลดหน้าใหม่ (data-view-navigate) ต้องไม่ถูก JS ดักคลิกหรือแตะ History', async (t) => {
    const ui = await boot(t, {withCalendar: false});
    await flush(30);

    const seen = [];
    ui.document.addEventListener('mytasks:viewchange', (e) => seen.push(e.detail.view));
    const before = ui.window.history.length;

    ui.click('[data-view="meeting"]');

    assert.deepEqual(seen, [], 'ปุ่มประชุมต้องปล่อยให้เบราว์เซอร์ navigate เอง');
    assert.equal(ui.database.dataset.view, 'calendar', 'มุมมองปัจจุบันต้องไม่ถูกสลับฝั่ง client');
    assert.equal(ui.window.history.length, before);
});
