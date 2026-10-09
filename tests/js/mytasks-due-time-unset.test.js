import test from 'node:test';
import assert from 'node:assert/strict';
import {click, clickCheckbox} from './helpers/dom.js';
import {mountTaskWorkspace} from './helpers/task-workspace-fixture.js';

/**
 * "ไม่ระบุเวลากำหนดส่ง" ในเซลล์กำหนดส่งของ Task Workspace
 *
 * ช่อง datetime-local ของเบราว์เซอร์แสดงได้แต่ "วัน + เวลา" เสมอ งานที่ไม่ระบุเวลา
 * จะขึ้น 23:59 น. ซึ่งเป็นขณะเวลาที่ระบบใช้ตัดสินความล่าช้า ไม่ใช่เวลาที่ผู้ใช้ตั้ง
 * เซลล์จึงต้องสลับไปแสดงข้อความแทน โดยที่ช่องยังอยู่ในฟอร์มและเปิดปฏิทินใบเดิมได้
 * ไม่ใช่ UI สองชุดที่ต้องซิงก์กันเอง
 */

const openWorkspace = async (t, row = {}) => {
    const ui = await mountTaskWorkspace();
    t.after(ui.cleanup);

    // ปฏิทินผูกแบบ delegated ที่ document เหมือน pages/mytasks/index.js ของหน้าจริง
    const picker = await import(`../../resources/js/components/date-picker.js?due-cell=${Math.random()}`);
    picker.useDatePickers();

    Object.assign(ui.document.querySelector('[data-row]').dataset, row);
    click(ui.openTask());

    return {
        ...ui,
        dueInput: () => ui.document.querySelector('[data-task-form] input[name="job_due_at"]'),
        dueUnsetOutput: () => ui.document.querySelector('[data-workspace-due-unset]'),
        popover: () => ui.document.querySelector('.sg-date-picker'),
    };
};

test('a task with a real due time shows the editable field, not the stand-in text', async (t) => {
    const ui = await openWorkspace(t);

    assert.equal(ui.dueInput().hidden, false);
    assert.equal(ui.dueInput().value, '2026-08-05T17:00');
    assert.equal(ui.dueUnsetOutput().hidden, true);
});

test('opening a task with no due time swaps the field for the intent', async (t) => {
    const ui = await openWorkspace(t, {dueTimeUnset: '1', dueTime: '23:59'});

    assert.equal(ui.dueInput().hidden, true, 'ช่องของเบราว์เซอร์จะขึ้น 23:59 น. ที่ผู้ใช้ไม่ได้ตั้ง');
    assert.equal(ui.dueUnsetOutput().hidden, false);
    assert.match(ui.dueUnsetOutput().textContent, /^5 สิงหาคม 2569 · ไม่ระบุเวลา$/);
    // ช่องยังอยู่ในฟอร์มและไม่ถูกปิดการใช้งาน การกดเซลล์จึงเปิดปฏิทินใบเดิมและติ๊กกลับได้
    assert.equal(ui.dueInput().disabled, false);
    assert.equal(ui.dueInput().dataset.timeUnset, '1');
});

test('ticking the option in the picker repaints the cell without leaving the modal', async (t) => {
    const ui = await openWorkspace(t);

    click(ui.dueInput());
    const unsetRow = ui.popover().querySelector('.sg-date-picker__time-unset');
    assert.equal(unsetRow.hidden, false, 'เซลล์กำหนดส่งของโมดัลต้องมีตัวเลือกนี้เหมือนบอร์ดและตาราง');

    clickCheckbox(unsetRow.querySelector('input'));

    assert.equal(ui.dueInput().hidden, true);
    assert.equal(ui.dueUnsetOutput().hidden, false);
    assert.match(ui.dueUnsetOutput().textContent, /ไม่ระบุเวลา$/);
    assert.equal(ui.taskModal.hidden, false, 'ติ๊กแล้วต้องยังอยู่ในโมดัลเดิม');

    // ติ๊กกลับแล้วช่องต้องกลับมาแก้ได้ ไม่ค้างอยู่ที่ข้อความ
    clickCheckbox(unsetRow.querySelector('input'));

    assert.equal(ui.dueInput().hidden, false);
    assert.equal(ui.dueUnsetOutput().hidden, true);
});
