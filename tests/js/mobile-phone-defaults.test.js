import test from 'node:test';
import assert from 'node:assert/strict';
import {mountDom} from './helpers/dom.js';
import {COMPACT_CALENDAR_QUERY, defaultCalendarMode} from '../../resources/js/pages/mytasks/calendar-model.js';

/*
 * ค่าเริ่มต้นบนจอโทรศัพท์
 *
 * ปฏิทินเดือน: ช่องวันกว้างราว 45px เส้นช่วงงานถูกตัดจนอ่านไม่ออก จอโทรศัพท์จึงเริ่มที่
 * "ภาพรวมสี" ส่วนจอที่กว้างกว่ายังเริ่มที่ "เส้นช่วงงาน" เหมือนเดิม
 *
 * Kanban: แท็บที่เลือกไว้ตั้งต้นว่างเปล่า แต่มีงานล่าช้าในแท็บที่เลื่อนหลุดจอ
 * ผู้ใช้จึงเห็นบอร์ดเปล่าทั้งที่มีงาน ต้องเปิดมาเจอคอลัมน์ที่มีงานก่อน
 */

test('calendar starts on the summary view only on phone-width screens', () => {
    assert.equal(COMPACT_CALENDAR_QUERY, '(max-width: 575.98px)');
    assert.equal(defaultCalendarMode(true), 'summary');
    assert.equal(defaultCalendarMode(false), 'timeline');
    assert.equal(defaultCalendarMode(), 'timeline');
});

const months = Array.from({length: 12}, (_, index) => `<option value="${index}">${index + 1}</option>`).join('');
let calendarFixture = 0;

async function bootCalendar(t, {phone}) {
    const env = mountDom();
    t.after(env.cleanup);

    // jsdom ไม่มี matchMedia จึงจำลองเฉพาะ query ที่ปฏิทินถาม
    env.window.matchMedia = (query) => ({matches: phone && query === COMPACT_CALENDAR_QUERY, media: query});

    env.document.body.innerHTML = `
        <div data-workspace>
            <div data-workspace-task-source hidden></div>
            <section data-calendar>
                <div role="group">
                    <button type="button" data-calendar-mode-option="timeline" aria-pressed="true">เส้นช่วงงาน</button>
                    <button type="button" data-calendar-mode-option="summary" aria-pressed="false">ภาพรวมสี</button>
                </div>
                <button type="button" data-calendar-reset>คืนค่า</button>
                <h2 data-calendar-title></h2>
                <p data-calendar-display-note></p>
                <select data-calendar-month>${months}</select>
                <select data-calendar-year></select>
                <div data-calendar-grid></div>
                <div data-calendar-agenda>
                    <strong data-calendar-today-count></strong><div data-calendar-today-list></div><p data-calendar-today-empty></p>
                    <h3 data-calendar-month-agenda-title></h3><strong data-calendar-month-count></strong>
                    <div data-calendar-month-list></div><p data-calendar-month-empty></p>
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
            <div data-calendar-agenda-modal hidden><div data-calendar-agenda-modal-list></div></div>
        </div>`;

    globalThis.fetch = () => Promise.resolve({ok: true, json: async () => ({meetings: []}), text: async () => ''});
    t.after(() => { delete globalThis.fetch; });

    calendarFixture += 1;
    await import(`../../resources/js/pages/mytasks/calendar.js?phone-defaults=${calendarFixture}`);

    const calendar = env.document.querySelector('[data-calendar]');
    const pressed = () => [...calendar.querySelectorAll('[data-calendar-mode-option]')]
        .find((button) => button.getAttribute('aria-pressed') === 'true')?.dataset.calendarModeOption;

    return {...env, calendar, pressed};
}

test('a phone opens the month grid in summary mode and the toggle reflects it', async (t) => {
    const ui = await bootCalendar(t, {phone: true});

    assert.equal(ui.calendar.dataset.calendarMode, 'summary');
    assert.equal(ui.pressed(), 'summary');

    // ผู้ใช้ยังสลับกลับไปเส้นช่วงงานได้เองบนจอโทรศัพท์
    ui.calendar.querySelector('[data-calendar-mode-option="timeline"]').click();
    assert.equal(ui.calendar.dataset.calendarMode, 'timeline');
    assert.equal(ui.pressed(), 'timeline');
});

test('a wider screen keeps the timeline as the starting view', async (t) => {
    const ui = await bootCalendar(t, {phone: false});

    assert.equal(ui.calendar.dataset.calendarMode, 'timeline');
    assert.equal(ui.pressed(), 'timeline');
});

const statuses = [['5', 'พักงาน'], ['2', 'กำลังทำ'], ['3', 'รอตรวจสอบ'], ['6', 'ล่าช้า'], ['4', 'เสร็จแล้ว']];
let kanbanFixture = 0;

/** cardsByStatus: จำนวนการ์ดในแต่ละคอลัมน์ Blade เลือก "กำลังทำ" ไว้ก่อนเหมือนของจริง */
async function bootKanban(t, cardsByStatus) {
    const env = mountDom();
    t.after(env.cleanup);

    let id = 0;
    const tabs = statuses.map(([status, label]) => `
        <button type="button" data-kanban-status-tab="${status}" class="${status === '2' ? 'is-selected' : ''}"
                aria-selected="${status === '2' ? 'true' : 'false'}"><span>${label}</span><b data-kanban-tab-count>0</b></button>`).join('');
    const columns = statuses.map(([status]) => `
        <section data-kanban-column="${status}" class="${status === '2' ? 'is-mobile-selected' : ''}">
            <header><b data-kanban-count>0</b></header>
            <div class="mytasks-kanban__cards">${Array.from({length: cardsByStatus[status] ?? 0}, () => {
                id += 1;
                return `<article data-kanban-card data-id="${id}" data-status="${status}" data-priority="2"></article>`;
            }).join('')}</div>
        </section>`).join('');

    env.document.body.innerHTML = `
        <div data-workspace data-status-template="/tasks/__ID__/status">
            <select data-filter><option value="">ทุกสถานะ</option></select>
            <section data-kanban>
                <div data-kanban-panel="0"><nav data-kanban-status-tabs>${tabs}</nav><div>${columns}</div></div>
            </section>
        </div>
        <script type="application/json" data-task-management-data>{}</script>`;

    kanbanFixture += 1;
    const module = await import(`../../resources/js/pages/mytasks/table-kanban.js?phone-defaults=${kanbanFixture}`);
    const panel = env.document.querySelector('[data-kanban-panel]');
    const selected = () => panel.querySelector('[data-kanban-status-tab][aria-selected="true"]')?.dataset.kanbanStatusTab;
    const shownColumn = () => panel.querySelector('[data-kanban-column].is-mobile-selected')?.dataset.kanbanColumn;

    return {...env, ...module, panel, selected, shownColumn};
}

test('pickInitialMobileStatus keeps a non-empty preference and otherwise follows the action order', async (t) => {
    const {pickInitialMobileStatus} = await bootKanban(t, {});

    assert.equal(pickInitialMobileStatus('2', {2: 3, 6: 5}), '2');
    assert.equal(pickInitialMobileStatus('2', {2: 0, 5: 1, 6: 4}), '6');
    assert.equal(pickInitialMobileStatus('2', {2: 0, 3: 2, 5: 1}), '3');
    assert.equal(pickInitialMobileStatus('2', {2: 0, 4: 7, 5: 1}), '5');
    assert.equal(pickInitialMobileStatus('2', {4: 7}), '4');
    assert.equal(pickInitialMobileStatus('2', {}), '2', 'ทุกคอลัมน์ว่าง ต้องคงแท็บเดิมไว้');
});

test('an empty "in progress" column hands the first view to the late column', async (t) => {
    const ui = await bootKanban(t, {6: 6, 5: 1});

    assert.equal(ui.selected(), '6');
    assert.equal(ui.shownColumn(), '6');
    assert.equal(ui.panel.querySelector('[data-kanban-status-tab="6"] b').textContent, '6');
});

test('a non-empty "in progress" column is still the first view', async (t) => {
    const ui = await bootKanban(t, {2: 2, 6: 6});

    assert.equal(ui.selected(), '2');
    assert.equal(ui.shownColumn(), '2');
});

test('the user can still open the empty column by tapping its tab', async (t) => {
    const ui = await bootKanban(t, {6: 1});

    ui.panel.querySelector('[data-kanban-status-tab="2"]').click();

    assert.equal(ui.selected(), '2');
    assert.equal(ui.shownColumn(), '2');
});
