import test from 'node:test';
import assert from 'node:assert/strict';
import {click, mountDom, pressKey} from './helpers/dom.js';

let fixtureCount = 0;

async function boot(t, feedback = {}, swalCalls = null) {
    const env = mountDom(`<!doctype html><html><body>
        <button type="button" data-open-project-task-request data-list-id="7" data-action="/projects/7/task-requests" data-project-name="Project A" data-parent-tasks='[{"id":11,"name":"Main task"},{"id":12,"name":"Second task"}]'>open</button>
        <script type="application/json" data-project-task-request-feedback>${JSON.stringify(feedback)}</script>
        <div data-task-modal hidden></div>
        <div data-project-task-request-modal hidden>
            <span data-project-task-request-name></span>
            <button type="button" data-close-project-task-request>close</button>
            <form data-project-task-request-form>
                <input type="radio" name="request_type" value="task" checked>
                <input type="radio" name="request_type" value="subtask">
                <div data-project-task-request-error="request_type"></div>
                <label data-project-task-request-parent hidden>
                    <select name="parent_job_id" data-project-task-request-parent-select></select>
                    <small data-project-task-request-parent-empty hidden></small>
                </label>
                <input name="job_topic">
                <div data-project-task-request-error="job_topic"></div>
                <select name="job_priority"><option value="2">two</option><option value="3">three</option></select>
                <input name="job_start_at" type="datetime-local" data-default-time="00:00">
                <input name="job_due_at" type="datetime-local" data-default-time="17:00">
                <div data-project-task-request-error="job_due_at"></div>
                <div data-project-task-request-general-error hidden></div>
            </form>
        </div>
    </body></html>`);
    t.after(env.cleanup);
    if (swalCalls) env.window.Swal = {fire: (options) => swalCalls.push(options)};
    fixtureCount += 1;
    await import(`../../resources/js/pages/mytasks/task-request.js?fixture=${fixtureCount}`);
    return env;
}

test('request and Task Workspace modals are closed initially and details field is absent', async (t) => {
    const {document} = await boot(t);

    assert.equal(document.querySelector('[data-project-task-request-modal]').hidden, true);
    assert.equal(document.querySelector('[data-task-modal]').hidden, true);
    assert.equal(document.querySelector('[name="request_details"]'), null);
});

test('request button opens the correct project form with Bangkok date defaults', async (t) => {
    const {document} = await boot(t);
    const modal = document.querySelector('[data-project-task-request-modal]');
    const form = document.querySelector('[data-project-task-request-form]');

    click(document.querySelector('[data-open-project-task-request]'));

    assert.equal(modal.hidden, false);
    assert.equal(form.action, 'http://localhost/projects/7/task-requests');
    assert.equal(document.querySelector('[data-project-task-request-name]').textContent, 'Project A');
    // ต้องเป็นรูปแบบ datetime-local ถ้าใส่วันที่ล้วน เบราว์เซอร์จะทิ้งค่าแล้ว required บล็อกการส่ง
    assert.match(form.elements.job_start_at.value, /^\d{4}-\d{2}-\d{2}T00:00$/);
    assert.match(form.elements.job_due_at.value, /^\d{4}-\d{2}-\d{2}T17:00$/);
    assert.ok(form.elements.job_due_at.value >= form.elements.job_start_at.value);
    assert.equal(document.activeElement, form.elements.job_topic);
    assert.equal(form.elements.request_type.value, 'task');
    assert.equal(form.elements.parent_job_id.disabled, true);
});

test('subtask choice reveals and populates the parent task selector', async (t) => {
    const {document, window} = await boot(t);
    const form = document.querySelector('[data-project-task-request-form]');
    click(document.querySelector('[data-open-project-task-request]'));

    const subtask = form.querySelector('[name="request_type"][value="subtask"]');
    subtask.checked = true;
    subtask.dispatchEvent(new window.Event('change', {bubbles: true}));

    assert.equal(document.querySelector('[data-project-task-request-parent]').hidden, false);
    assert.equal(form.elements.parent_job_id.disabled, false);
    assert.equal(form.elements.parent_job_id.required, true);
    assert.deepEqual([...form.elements.parent_job_id.options].map((option) => option.textContent), [
        'เลือกงานหลักที่ต้องการเพิ่มงานย่อย',
        'Main task',
        'Second task',
    ]);
});

test('request type validation feedback marks the radio choice without breaking the modal', async (t) => {
    const {document} = await boot(t, {
        open_modal: true,
        list_id: 7,
        old: {request_type: 'subtask'},
        errors: {request_type: ['เลือกประเภทคำขอ']},
    });

    assert.equal(document.querySelector('[data-project-task-request-modal]').hidden, false);
    assert.equal(document.querySelector('[name="request_type"][value="task"]').classList.contains('is-invalid'), true);
    assert.equal(document.querySelector('[data-project-task-request-error="request_type"]').textContent, 'เลือกประเภทคำขอ');
});

test('validation feedback reopens the right modal and preserves old field values', async (t) => {
    const {document} = await boot(t, {
        open_modal: true,
        list_id: 7,
        old: {request_type: 'subtask', parent_job_id: '12', job_topic: 'Keep this title', job_priority: '3', job_start_at: '2026-08-28T00:00', job_due_at: '2026-08-27T17:00'},
        errors: {job_due_at: ['กำหนดส่งต้องไม่น้อยกว่าวันที่เริ่ม']},
    });
    const modal = document.querySelector('[data-project-task-request-modal]');
    const form = document.querySelector('[data-project-task-request-form]');

    assert.equal(modal.hidden, false);
    assert.equal(form.elements.job_topic.value, 'Keep this title');
    assert.equal(form.elements.job_priority.value, '3');
    assert.equal(form.elements.request_type.value, 'subtask');
    assert.equal(form.elements.parent_job_id.value, '12');
    assert.equal(document.querySelector('[data-project-task-request-parent]').hidden, false);
    assert.equal(form.elements.job_due_at.value, '2026-08-27T17:00');
    assert.equal(form.elements.job_due_at.classList.contains('is-invalid'), true);
    assert.equal(document.querySelector('[data-project-task-request-error="job_due_at"]').textContent, 'กำหนดส่งต้องไม่น้อยกว่าวันที่เริ่ม');
    assert.equal(document.activeElement, form.elements.job_due_at);
    assert.equal(document.querySelector('[data-task-modal]').hidden, true);
});

test('rate-limit feedback is shown inline while preserving the request form', async (t) => {
    const message = 'ส่งคำขอถี่เกินไป กรุณารอสักครู่แล้วลองใหม่';
    const {document} = await boot(t, {
        open_modal: true,
        list_id: 7,
        old: {job_topic: 'Try later'},
        errors: {task_request: [message]},
    });
    const error = document.querySelector('[data-project-task-request-general-error]');

    assert.equal(document.querySelector('[data-project-task-request-modal]').hidden, false);
    assert.equal(document.querySelector('[name="job_topic"]').value, 'Try later');
    assert.equal(error.hidden, false);
    assert.equal(error.textContent, message);
});

test('successful request feedback uses SweetAlert2', async (t) => {
    const successCalls = [];
    const {document} = await boot(t, {success: 'ส่งคำขอเพิ่มงานแล้ว'}, successCalls);

    assert.equal(successCalls[0].icon, 'success');
    assert.equal(successCalls[0].text, 'ส่งคำขอเพิ่มงานแล้ว');
    assert.equal(document.querySelector('[data-project-task-request-modal]').hidden, true);
    assert.equal(document.querySelector('[data-task-modal]').hidden, true);
});

test('stale-decision feedback uses SweetAlert2 instead of a raw error page', async (t) => {
    const errorCalls = [];
    await boot(t, {error: 'คำขอนี้ถูกพิจารณาโดยผู้ใช้อื่นแล้ว'}, errorCalls);

    assert.equal(errorCalls[0].icon, 'error');
    assert.equal(errorCalls[0].text, 'คำขอนี้ถูกพิจารณาโดยผู้ใช้อื่นแล้ว');
});

test('request modal closes from its button and Escape and restores page scrolling', async (t) => {
    const {document} = await boot(t);
    const modal = document.querySelector('[data-project-task-request-modal]');
    const open = document.querySelector('[data-open-project-task-request]');

    click(open);
    assert.equal(document.body.style.overflow, 'hidden');
    click(document.querySelector('[data-close-project-task-request]'));
    assert.equal(modal.hidden, true);
    assert.equal(document.body.style.overflow, '');

    click(open);
    pressKey(document, 'Escape');
    assert.equal(modal.hidden, true);
    assert.equal(document.body.style.overflow, '');
    assert.equal(document.activeElement, open);
});

test('notification deep link opens the request panel and scrolls the requested card into view', async (t) => {
    const env = mountDom(`<!doctype html><html><body>
        <details class="project-task-requests">
            <summary>requests</summary>
            <article>other request</article>
            <article class="is-highlighted" data-project-task-request-target tabindex="-1">requested</article>
        </details>
    </body></html>`);
    t.after(env.cleanup);
    const {document, window} = env;
    const scrolled = [];
    window.HTMLElement.prototype.scrollIntoView = function (options) {
        scrolled.push([this.textContent, options]);
    };

    fixtureCount += 1;
    await import(`../../resources/js/pages/mytasks/task-request.js?fixture=${fixtureCount}`);

    const target = document.querySelector('[data-project-task-request-target]');
    assert.equal(document.querySelector('.project-task-requests').open, true);
    assert.deepEqual(scrolled, [['requested', {block: 'center'}]]);
    assert.equal(document.activeElement, target);
});
