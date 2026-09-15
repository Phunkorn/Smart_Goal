import test from 'node:test';
import assert from 'node:assert/strict';
import {initCsvMenu, initReportFilter} from '../../resources/js/pages/reports/operational-controls.js';
import {mountDom} from './helpers/dom.js';

/*
 * ตัวควบคุมของรายงานปฏิบัติงาน — ทดสอบจากการคลิกจริงถึงผลที่ผู้ใช้เห็น
 *
 * เมนู CSV เป็น popover ไม่ใช่ modal: ปิดเมื่อคลิกข้างนอกหรือกด Escape แล้วคืนโฟกัสให้ปุ่ม
 * และเรียก init ซ้ำต้องไม่ผูก listener ซ้อน
 */
const mountPage = (t) => {
    const env = mountDom();
    t.after(env.cleanup);

    env.document.body.innerHTML = `
        <div class="report-operational">
            <form data-operational-filter action="/reports/operational" method="GET">
                <select name="month" data-auto-submit>
                    <option value="2026-09" selected>กันยายน 2569</option>
                    <option value="2026-08">สิงหาคม 2569</option>
                </select>
            </form>
            <div data-csv-menu>
                <button type="button" data-csv-menu-trigger aria-expanded="false">Export CSV</button>
                <div data-csv-menu-panel hidden>
                    <a href="/reports/operational/daily/export.csv">สรุปรายวัน</a>
                    <a href="/reports/operational/frequent/export.csv">งานที่ทำบ่อยที่สุด</a>
                </div>
            </div>
            <p data-outside>พื้นที่อื่นของหน้า</p>
        </div>`;

    const root = env.document.querySelector('.report-operational');

    return {
        ...env,
        root,
        trigger: root.querySelector('[data-csv-menu-trigger]'),
        panel: root.querySelector('[data-csv-menu-panel]'),
        click: (element) => element.dispatchEvent(new env.window.MouseEvent('click', {bubbles: true})),
        key: (name) => env.document.dispatchEvent(new env.window.KeyboardEvent('keydown', {key: name, bubbles: true})),
    };
};

test('the CSV menu opens beside its trigger and closes on a second click', (t) => {
    const ui = mountPage(t);
    initCsvMenu(ui.root);

    ui.click(ui.trigger);
    assert.equal(ui.panel.hidden, false);
    assert.equal(ui.trigger.getAttribute('aria-expanded'), 'true');
    assert.equal(ui.document.activeElement, ui.panel.querySelector('a'), 'โฟกัสย้ายไปรายการแรกของเมนู');

    ui.click(ui.trigger);
    assert.equal(ui.panel.hidden, true);
    assert.equal(ui.trigger.getAttribute('aria-expanded'), 'false');
});

test('the CSV menu closes on an outside click and on Escape, returning focus to the trigger', (t) => {
    const ui = mountPage(t);
    initCsvMenu(ui.root);

    ui.click(ui.trigger);
    ui.click(ui.root.querySelector('[data-outside]'));
    assert.equal(ui.panel.hidden, true, 'คลิกข้างนอกต้องปิดเมนู');

    ui.click(ui.trigger);
    ui.key('Escape');
    assert.equal(ui.panel.hidden, true, 'Escape ต้องปิดเมนู');
    assert.equal(ui.document.activeElement, ui.trigger, 'ปิดด้วย Escape แล้วโฟกัสกลับไปที่ปุ่ม');
});

test('calling init again does not stack a second listener on the trigger', (t) => {
    const ui = mountPage(t);
    initCsvMenu(ui.root);
    initCsvMenu(ui.root);

    // ถ้ามี listener สองตัว คลิกเดียวจะเปิดแล้วปิดทันที เมนูจึงยังซ่อนอยู่
    ui.click(ui.trigger);
    assert.equal(ui.panel.hidden, false);
});

test('changing the month submits the filter form once', (t) => {
    const ui = mountPage(t);
    const form = ui.root.querySelector('[data-operational-filter]');
    let submissions = 0;
    form.requestSubmit = () => { submissions += 1; };

    initReportFilter(ui.root);
    initReportFilter(ui.root);

    const select = form.querySelector('select[name="month"]');
    select.value = '2026-08';
    select.dispatchEvent(new ui.window.Event('change', {bubbles: true}));

    assert.equal(submissions, 1);
});
