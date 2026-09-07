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

async function bootCalendar(t) {
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
                <script type="application/json" data-calendar-meetings>[]</script>
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
