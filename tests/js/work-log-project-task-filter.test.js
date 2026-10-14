import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {mountDom} from './helpers/dom.js';
import {initProjectTaskFilters, taskMatchesProject} from '../../resources/js/pages/daily-logs/project-task-filter.js';
import {enhanceSelect, readableOptions} from '../../resources/js/components/select-dropdown.js';

const formHtml = (suffix) => `<form data-form="${suffix}">
  <select id="project-${suffix}" name="work_order_list_id">
    <option value="">ไม่เกี่ยวข้อง</option><option value="1">A</option><option value="2">B</option>
  </select>
  <select id="task-${suffix}" name="job_id">
    <option value="">ไม่เกี่ยวข้อง</option>
    <option value="10" data-project-id="1">งาน A1</option>
    <option value="11" data-project-id="1">งาน A2</option>
    <option value="20" data-project-id="2">งาน B1</option>
    <option value="30" data-project-id="">งานไม่มีโปรเจกต์</option>
  </select>
</form>`;

const fixture = () => mountDom(`<!doctype html><html><body><div data-daily-log>${formHtml('entry')}${formHtml('routine')}</div></body></html>`);

const choose = (select, value) => {
    select.value = value;
    select.dispatchEvent(new select.ownerDocument.defaultView.Event('change', {bubbles: true}));
};

const visibleTasks = (form) => readableOptions(form.querySelector('[name="job_id"]')).map((option) => option.value);

test('taskMatchesProject: ไม่เลือกโปรเจกต์เห็นทุกงาน เลือกแล้วเห็นเฉพาะงานของโปรเจกต์นั้น', () => {
    assert.equal(taskMatchesProject('1', ''), true);
    assert.equal(taskMatchesProject('', ''), true);
    assert.equal(taskMatchesProject('1', '1'), true);
    assert.equal(taskMatchesProject('2', '1'), false);
    assert.equal(taskMatchesProject('', '1'), false);
    assert.equal(taskMatchesProject(null, '1'), false);
});

test('เลือกโปรเจกต์แล้วช่องงานเหลือเฉพาะงานของโปรเจกต์นั้น ทั้งฟอร์มบันทึกงานและงานประจำ', () => {
    const dom = fixture();
    try {
        const root = dom.document.querySelector('[data-daily-log]');
        initProjectTaskFilters({root});

        for (const name of ['entry', 'routine']) {
            const form = root.querySelector(`[data-form="${name}"]`);
            assert.deepEqual(visibleTasks(form), ['', '10', '11', '20', '30']);

            choose(form.querySelector('[name="work_order_list_id"]'), '1');
            assert.deepEqual(visibleTasks(form), ['', '10', '11']);

            choose(form.querySelector('[name="work_order_list_id"]'), '2');
            assert.deepEqual(visibleTasks(form), ['', '20']);

            choose(form.querySelector('[name="work_order_list_id"]'), '');
            assert.deepEqual(visibleTasks(form), ['', '10', '11', '20', '30']);
        }
    } finally { dom.cleanup(); }
});

test('เปลี่ยนโปรเจกต์แล้วงานที่เลือกค้างซึ่งไม่ใช่ของโปรเจกต์ใหม่ถูกล้าง แต่งานของโปรเจกต์เดิมคงอยู่', () => {
    const dom = fixture();
    try {
        const root = dom.document.querySelector('[data-daily-log]');
        initProjectTaskFilters({root});
        const form = root.querySelector('[data-form="entry"]');
        const project = form.querySelector('[name="work_order_list_id"]');
        const task = form.querySelector('[name="job_id"]');

        choose(project, '1');
        choose(task, '10');
        choose(project, '1');
        assert.equal(task.value, '10');

        choose(project, '2');
        assert.equal(task.value, '');
    } finally { dom.cleanup(); }
});

test('form.reset() คืนช่องงานให้เห็นทุกงานตามโปรเจกต์ที่ถูกรีเซ็ต', async () => {
    const dom = fixture();
    try {
        const root = dom.document.querySelector('[data-daily-log]');
        initProjectTaskFilters({root});
        const form = root.querySelector('[data-form="routine"]');

        choose(form.querySelector('[name="work_order_list_id"]'), '2');
        assert.deepEqual(visibleTasks(form), ['', '20']);

        form.reset();
        await Promise.resolve();
        assert.deepEqual(visibleTasks(form), ['', '10', '11', '20', '30']);
    } finally { dom.cleanup(); }
});

test('ดร็อปดาวน์แบบสไลด์แสดงเฉพาะงานของโปรเจกต์ และป้ายถูกล้างเมื่องานเดิมไม่ตรง', () => {
    const dom = fixture();
    try {
        const root = dom.document.querySelector('[data-daily-log]');
        initProjectTaskFilters({root});
        const form = root.querySelector('[data-form="entry"]');
        const project = form.querySelector('[name="work_order_list_id"]');
        const task = form.querySelector('[name="job_id"]');
        const dropdown = enhanceSelect(task, 0);

        choose(task, '20');
        assert.equal(dropdown.trigger.textContent.trim(), 'งาน B1');

        choose(project, '1');
        assert.equal(dropdown.trigger.textContent.trim(), 'ไม่เกี่ยวข้อง');

        dropdown.setOpen(true);
        assert.deepEqual(
            [...dropdown.panel.querySelectorAll('.sg-select__option')].map((node) => node.dataset.value),
            ['', '10', '11'],
        );
    } finally { dom.cleanup(); }
});

test('เริ่มต้นซ้ำไม่ผูก listener ซ้อน และ Blade ทั้งสองฟอร์มส่ง data-project-id ของงานมาด้วย', () => {
    const dom = fixture();
    try {
        const root = dom.document.querySelector('[data-daily-log]');
        assert.ok(initProjectTaskFilters({root}));
        assert.equal(initProjectTaskFilters({root}), null);
    } finally { dom.cleanup(); }

    const blade = fs.readFileSync(new URL('../../resources/views/daily-logs/components/entry-modal.blade.php', import.meta.url), 'utf8');
    const taskOptions = blade.match(/<option value="\{\{ \$task->job_id \}\}"[^\n]*?>(?=\{\{ \$task->job_topic)/g) || [];
    assert.equal(taskOptions.length, 2);
    taskOptions.forEach((option) => assert.match(option, /data-project-id="\{\{ \$task->work_order_list_id \}\}"/));
});
