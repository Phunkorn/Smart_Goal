import test from 'node:test';
import assert from 'node:assert/strict';
import {click, mountDom, pressKey} from './helpers/dom.js';

let fixtureCount = 0;

async function boot(t, feedback = {}, swalCalls = null) {
    const env = mountDom(`<!doctype html><html><body>
        <button type="button" data-open-project-task-request data-list-id="7" data-action="/projects/7/task-requests" data-project-name="Project A">open</button>
        <script type="application/json" data-project-task-request-feedback>${JSON.stringify(feedback)}</script>
        <div data-task-modal hidden></div>
        <div data-project-task-request-modal hidden>
            <span data-project-task-request-name></span>
            <button type="button" data-close-project-task-request>close</button>
            <form data-project-task-request-form>
                <input name="job_topic">
                <div data-project-task-request-error="job_topic"></div>
                <select name="job_priority"><option value="2">two</option><option value="3">three</option></select>
                <input name="job_start_at" type="date">
                <input name="job_due_at" type="date">
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
    assert.match(form.elements.job_start_at.value, /^\d{4}-\d{2}-\d{2}$/);
    assert.ok(form.elements.job_due_at.value >= form.elements.job_start_at.value);
    assert.equal(document.activeElement, form.elements.job_topic);
});

test('validation feedback reopens the right modal and preserves old field values', async (t) => {
    const {document} = await boot(t, {
        open_modal: true,
        list_id: 7,
        old: {job_topic: 'Keep this title', job_priority: '3', job_start_at: '2026-08-28', job_due_at: '2026-08-27'},
        errors: {job_due_at: ['กำหนดส่งต้องไม่น้อยกว่าวันที่เริ่ม']},
    });
    const modal = document.querySelector('[data-project-task-request-modal]');
    const form = document.querySelector('[data-project-task-request-form]');

    assert.equal(modal.hidden, false);
    assert.equal(form.elements.job_topic.value, 'Keep this title');
    assert.equal(form.elements.job_priority.value, '3');
    assert.equal(form.elements.job_due_at.value, '2026-08-27');
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
