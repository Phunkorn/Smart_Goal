import test from 'node:test';
import assert from 'node:assert/strict';
import {mountDom} from './helpers/dom.js';
import {synchronizeTaskSource} from '../../resources/js/pages/mytasks/task-state.js';

/*
 * ปฏิทินแสดงเฉพาะงานที่ยังต้องทำ
 *
 * ของเดิมวาดงานทุกใบรวมงานที่ปิดแล้ว เดือนที่ผ่านมาจึงเต็มไปด้วยงานที่จบไปแล้ว
 * และเมื่องานถูกเปิดขึ้นมาแก้ต่อ (พนักงาน หัวหน้าแผนก หรือ Admin ก็ตาม)
 * มันต้องกลับเข้าปฏิทินทันทีโดยไม่ต้องรีโหลดหน้า จึงต้องทดสอบทั้งสองทิศทาง
 */

const isoDate = (date) => [
    date.getFullYear(),
    String(date.getMonth() + 1).padStart(2, '0'),
    String(date.getDate()).padStart(2, '0'),
].join('-');

const months = Array.from({length: 12}, (_, index) => `<option value="${index}">${index + 1}</option>`).join('');

let fixtureCount = 0;

async function bootCalendar(t, meetings = []) {
    const env = mountDom();
    t.after(env.cleanup);

    const today = isoDate(new Date());

    env.document.body.innerHTML = `
        <div data-workspace>
            <div data-workspace-task-source hidden>
                <div data-row data-id="1" data-topic="งานที่ยังทำอยู่" data-project="โปรเจกต์"
                     data-status="2" data-priority="2" data-start="${today}" data-due="${today}"></div>
                <div data-row data-id="2" data-topic="งานที่ปิดแล้ว" data-project="โปรเจกต์"
                     data-status="4" data-priority="2" data-start="${today}" data-due="${today}"></div>
            </div>
            <section data-calendar
                     data-task-quickview-template="/my-tasks/calendar/quick-view/task/__ID__"
                     data-task-detail-template="/my-tasks?view=calendar&amp;open_task=__ID__">
                <h2 data-calendar-title></h2>
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
                <script type="application/json" data-calendar-meetings>${JSON.stringify(meetings)}</script>
            </section>
            <script type="application/json" data-team-data>{}</script>
            <div data-calendar-detail hidden></div>
            <div data-calendar-day-modal hidden>
                <h2 data-calendar-day-title></h2>
                <button type="button" data-calendar-day-close></button>
                <section data-calendar-day-tasks hidden><b data-calendar-day-task-count></b><div data-calendar-day-task-list></div></section>
                <section data-calendar-day-meetings hidden><b data-calendar-day-meeting-count></b><div data-calendar-day-meeting-list></div></section>
                <small data-calendar-day-count></small>
            </div>
        </div>`;

    globalThis.fetch = () => Promise.resolve({ok: true, json: async () => ({meetings: []}), text: async () => ''});
    t.after(() => { delete globalThis.fetch; });

    fixtureCount += 1;
    await import(`../../resources/js/pages/mytasks/calendar.js?completed-tasks=${fixtureCount}`);

    return {
        ...env,
        workspace: env.document.querySelector('[data-workspace]'),
        agendaTask: (id) => env.document.querySelector(`[data-calendar-today-list] [data-calendar-task="task-${id}"]`),
        agendaMeeting: (id) => env.document.querySelector(`[data-calendar-today-list] [data-calendar-task="meeting-${id}"]`),
    };
}

test('งานที่เสร็จแล้วไม่ถูกวาดในปฏิทิน', async (t) => {
    const ui = await bootCalendar(t);

    assert.ok(ui.agendaTask(1), 'งานที่ยังทำอยู่ต้องอยู่ในปฏิทิน');
    assert.equal(ui.agendaTask(2), null, 'งานที่ปิดแล้วต้องไม่อยู่ในปฏิทิน');
    assert.equal(ui.document.querySelector('[data-calendar-today-count]').textContent, '1 รายการ');
});

test('งานที่ถูกดึงกลับมาแก้ต่อกลับเข้าปฏิทินทันที', async (t) => {
    const ui = await bootCalendar(t);

    synchronizeTaskSource(ui.workspace, '2', {status: 2}, ui.document);

    assert.ok(ui.agendaTask(2), 'งานที่คืนสถานะแล้วต้องกลับมาแสดงในปฏิทิน');
    assert.equal(ui.document.querySelector('[data-calendar-today-count]').textContent, '2 รายการ');
});

test('งานที่เพิ่งปิดหายออกจากปฏิทินโดยไม่ต้องรีโหลด', async (t) => {
    const ui = await bootCalendar(t);

    synchronizeTaskSource(ui.workspace, '1', {status: 4}, ui.document);

    assert.equal(ui.agendaTask(1), null, 'งานที่เพิ่งปิดต้องหายจากปฏิทิน');
    assert.equal(ui.document.querySelector('[data-calendar-today-count]').textContent, '0 รายการ');
});

/*
 * ประชุมที่เลิกไปแล้วก็ไม่ต้องอยู่บนปฏิทิน ด้วยเหตุผลเดียวกับงานที่ปิดแล้ว
 *
 * ตัดที่ "เวลาเลิกประชุม" ไม่ใช่เวลาเริ่ม ประชุมที่กำลังดำเนินอยู่จึงต้องยังเห็นได้
 * ซึ่งเป็นช่วงที่คนต้องการเห็นมันที่สุด
 */
/*
 * ระยะเวลาที่ยังอยู่ใน "วันนี้" เสมอ ไม่ว่าจะรันเทสต์ตอนกี่โมง
 *
 * ปฏิทินวางประชุมตามวัน และคำยืนยันของเทสต์อ่านจากรายการของวันนี้ ถ้าบวกนาที
 * ไปตรง ๆ การรันตอนสี่ทุ่มจะดันประชุม "ที่ยังไม่ถึง" ข้ามไปเป็นวันพรุ่งนี้
 * แล้วมันจะหายจากรายการของวันนี้ด้วยเหตุผลที่ไม่เกี่ยวกับสิ่งที่กำลังทดสอบ
 */
const minutesSinceMidnight = () => {
    const at = new Date();

    return at.getHours() * 60 + at.getMinutes();
};

/** ย้อนหลังไม่เกินเที่ยงคืนที่ผ่านมา และย้อนอย่างน้อยหนึ่งนาทีเพื่อให้เป็นอดีตจริง */
const minutesAgo = (want) => -Math.max(1, Math.min(want, minutesSinceMidnight()));

/** ล่วงหน้าไม่เกินเที่ยงคืนที่จะถึง ประชุมจึงยังอยู่ในวันของวันนี้เสมอ */
const minutesAhead = (want) => Math.min(want, 1439 - minutesSinceMidnight());

const meetingAt = (id, title, startsInMinutes, endsInMinutes) => {
    /*
     * วันและเวลาต้องมาจากช่วงเวลาเดียวกัน
     *
     * ถ้าตรึงวันไว้เป็น "วันนี้" แล้วบวกนาทีเฉพาะเวลา การบวกที่ข้ามเที่ยงคืนจะได้
     * เวลาช่วงเช้าของวันนี้ ซึ่งเป็นอดีต — ประชุมที่ตั้งใจให้เป็นอนาคตจะกลายเป็นอดีต
     * และผลของเทสต์จะขึ้นกับว่ารันตอนกี่โมง
     */
    const startsAt = new Date(Date.now() + startsInMinutes * 60 * 1000);
    const endsAt = new Date(Date.now() + endsInMinutes * 60 * 1000);
    const clock = (at) => `${String(at.getHours()).padStart(2, '0')}:${String(at.getMinutes()).padStart(2, '0')}`;

    return {
        id: `meeting-${id}`,
        type: 'meeting',
        title,
        location: 'ห้องประชุม',
        organizer: 'ผู้จัด',
        attendees: [],
        start: isoDate(startsAt),
        due: isoDate(endsAt),
        startTime: clock(startsAt),
        endTime: clock(endsAt),
        entityId: id,
        quickViewUrl: `/my-tasks/calendar/quick-view/meeting/${id}`,
        detailUrl: `/meetings/${id}`,
        url: `/meetings/${id}`,
    };
};

test('ประชุมที่เลิกไปแล้วไม่ถูกวาดในปฏิทิน', async (t) => {
    const ui = await bootCalendar(t, [
        meetingAt(1, 'ประชุมที่เลิกไปแล้ว', minutesAgo(120), minutesAgo(60)),
        meetingAt(2, 'ประชุมที่ยังไม่ถึง', minutesAhead(60), minutesAhead(120)),
    ]);

    assert.equal(ui.agendaMeeting(1), null, 'ประชุมที่เลิกแล้วต้องไม่อยู่ในปฏิทิน');
    assert.ok(ui.agendaMeeting(2), 'ประชุมที่ยังไม่ถึงต้องยังอยู่');
});

test('ประชุมที่กำลังดำเนินอยู่ต้องยังเห็นได้ ไม่ถูกตัดทิ้งไปพร้อมของที่เลิกแล้ว', async (t) => {
    const ui = await bootCalendar(t, [
        meetingAt(3, 'ประชุมที่กำลังประชุมอยู่', minutesAgo(30), minutesAhead(30)),
    ]);

    assert.ok(ui.agendaMeeting(3), 'เริ่มไปแล้วแต่ยังไม่เลิก จึงต้องยังอยู่บนปฏิทิน');
});
