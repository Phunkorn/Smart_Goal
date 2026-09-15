import test from 'node:test';
import assert from 'node:assert/strict';
import {mountDom, click} from './helpers/dom.js';
import {initEntryForm} from '../../resources/js/pages/daily-logs/entry-form.js';

const fixture = () => mountDom(`<!doctype html><html><body><div data-daily-log>
  <div data-log-entry-modal hidden>
    <h2 data-entry-modal-title></h2>
    <div data-entry-panel="choice">
      <button type="button" data-entry-select-kind="routine">งานประจำ</button>
      <button type="button" data-entry-select-kind="field">งานนอกสถานที่</button>
    </div>
    <form data-log-entry-form data-entry-panel="once" hidden>
      <input data-entry-method value="POST"><input data-entry-kind value="routine">
      <div class="log-modal__body">
        <div class="log-field-row">
          <div class="log-field"><select data-entry-category required><option value="">เลือก</option><option value="1">ตรวจเช็ก</option></select></div>
          <div class="log-field"><input data-entry-date type="date" value="2026-09-15"></div>
        </div>
        <div class="log-field"><input data-entry-title required></div>
        <div class="log-field" data-entry-only-kind="field" hidden><input data-entry-location></div>
        <div class="log-field-row"><input data-entry-start type="time"><input data-entry-end type="time"></div>
        <p class="log-field__hint">เวลา</p>
        <details class="routine-form__more" data-entry-more><div class="routine-form__more-body">
          <div class="log-field"><textarea data-entry-details></textarea></div>
          <div class="log-field"><select data-entry-project><option value=""></option></select></div>
          <div class="log-field"><select data-entry-task><option value=""></option></select></div>
        </div></details>
        <p data-entry-error hidden></p>
      </div>
      <footer><button type="button" data-entry-step-back hidden>ย้อนกลับ</button>
        <button type="button" data-entry-step-next>ถัดไป</button><button type="submit" data-entry-submit hidden>บันทึก</button></footer>
    </form>
    <div data-entry-panel="routine" hidden>
      <form data-routine-form data-routine-store-url="/templates">
        <input data-routine-method value="POST">
        <div class="log-modal__body">
          <div class="log-field-row">
            <div class="log-field"><select name="work_log_category_id"><option value="">เลือก</option><option value="1">ตรวจเช็ก</option></select></div>
            <div class="log-field"><input name="title" required></div>
          </div>
          <div class="log-field" data-routine-plan-date><input name="plan_date" type="date" min="2026-09-15" value="2026-09-15"></div>
          <fieldset data-routine-weekdays><input name="weekdays[]" type="checkbox" value="1" checked></fieldset>
          <fieldset class="routine-form__window"><input name="default_start_time" type="time"></fieldset>
          <details class="routine-form__more"><div class="log-field"><textarea name="details"></textarea></div></details>
        </div>
        <footer><button type="button" data-entry-step-back hidden>ย้อนกลับ</button>
          <button type="button" data-entry-step-next>ถัดไป</button><button type="submit" hidden>เพิ่ม</button></footer>
      </form>
    </div>
  </div>
</div></body></html>`);

test('ปุ่มเพิ่มงานเปิดขั้นเลือกประเภทก่อนฟอร์ม และงานนอกสถานที่ไปขั้นข้อมูล/วันเวลา', () => {
    const dom = fixture();
    try {
        const root = dom.document.querySelector('[data-daily-log]');
        const modal = root.querySelector('[data-log-entry-modal]');
        const wizard = initEntryForm({root, stack: {open: () => { modal.hidden = false; }, close: () => { modal.hidden = true; }}, storeAction: '/logs'});
        wizard.openForCreate({mode: 'choice'});
        assert.equal(root.querySelector('[data-entry-panel="choice"]').hidden, false);
        assert.equal(root.querySelector('[data-log-entry-form]').hidden, true);
        click(root.querySelector('[data-entry-select-kind="field"]'));
        assert.equal(root.querySelector('[data-entry-kind]').value, 'field');
        assert.equal(root.querySelector('[data-entry-location]').required, true);
        assert.equal(root.querySelector('[data-wizard-step="3"]').hidden, true);
        root.querySelector('[data-entry-category]').value = '1';
        root.querySelector('[data-entry-title]').value = 'ติดตั้งอุปกรณ์';
        root.querySelector('[data-entry-location]').value = 'สาขา';
        click(root.querySelector('[data-log-entry-form] [data-entry-step-next]'));
        assert.equal(root.querySelector('[data-log-entry-form] [data-wizard-step="3"]').hidden, false);
        assert.equal(root.querySelector('[data-entry-date]').closest('[data-wizard-step]').dataset.wizardStep, '3');
        click(root.querySelector('[data-log-entry-form] [data-entry-step-back]'));
        assert.equal(root.querySelector('[data-log-entry-form] [data-wizard-step="2"]').hidden, false);
    } finally { dom.cleanup(); }
});

test('งานประจำเลือกวันเดียวโดยปิด weekday input เก่า แต่ไม่ลบ editor ของแม่แบบเดิม', () => {
    const dom = fixture();
    try {
        const root = dom.document.querySelector('[data-daily-log]');
        const modal = root.querySelector('[data-log-entry-modal]');
        const wizard = initEntryForm({root, stack: {open: () => { modal.hidden = false; }, close: () => { modal.hidden = true; }}});
        wizard.openForCreate({mode: 'choice'});
        click(root.querySelector('[data-entry-select-kind="routine"]'));
        const routine = root.querySelector('[data-routine-form]');
        assert.equal(root.querySelector('[data-entry-panel="routine"]').hidden, false);
        assert.equal(routine.querySelector('[name="plan_date"]').required, true);
        assert.equal(routine.querySelector('[name="weekdays[]"]').disabled, true);
        assert.equal(routine.querySelector('[name="work_log_category_id"]').required, true);
        assert.equal(root.querySelector('[data-routine-management]'), null);
        assert.equal(routine.querySelector('[name="plan_date"]').closest('[data-wizard-step]').dataset.wizardStep, '3');
        // งานประจำใหม่ต้องเลือกได้หลายวัน เหมือนงานนอกสถานที่
        assert.equal(routine.querySelector('[name="plan_date"]').dataset.datePickerMultiple, 'on');
    } finally { dom.cleanup(); }
});
